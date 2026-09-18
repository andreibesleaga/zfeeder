<?php

declare(strict_types=1);

namespace Zfeeder\Fetch;

use Zfeeder\Config\Config;
use Zfeeder\Exception\SecurityException;

/**
 * The server side request forgery defence.
 *
 * zFeeder fetches URLs that an administrator (and, through OPML import, a
 * remote file) supplies, so every such URL is attacker influenced. The guard
 * answers one question: may this process open a connection to this URL?
 *
 * Two checks, in this order:
 *
 *  1. the scheme, because `file://`, `php://` and friends never reach a socket
 *     at all — they read local data and are the cheapest escalation there is;
 *  2. the address, because a public looking name may resolve to 127.0.0.1 or
 *     to a cloud metadata endpoint. The name is therefore resolved and *every*
 *     A/AAAA record is tested, not just the first, so a rebinding record set
 *     cannot slip one bad address past us.
 *
 * The resolver is injected so that the unit tests can describe any DNS answer
 * they like without touching the network, and so the fetcher can re-run the
 * guard on each redirect hop with the same rules.
 *
 * Caveat that cannot be fixed here: between this check and the connection the
 * name may be re-resolved by the HTTP client (classic TOCTOU rebinding). The
 * remaining exposure is one request to an internal address with no response
 * body disclosure path; closing it entirely needs connection-level pinning,
 * which Symfony's HTTP client does not expose.
 */
final class UrlGuard
{
    /** Only these two reach the network; everything else is refused by name. */
    public const array ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Host names that never belong to a public feed. A trailing dot is stripped
     * before the comparison, and any name *ending* in one of the suffixes is
     * refused, so `printer.local` and `db.internal` are covered too.
     */
    private const array BLOCKED_HOST_NAMES = ['localhost'];

    private const array BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa'];

    /**
     * IPv4 ranges that must never be contacted, as CIDR plus the reason used in
     * the exception message. Kept as data rather than code so that the table
     * can be read against RFC 6890 line by line.
     *
     * @var list<array{0: string, 1: int, 2: string}> address, prefix length, reason
     */
    private const array BLOCKED_V4 = [
        ['0.0.0.0', 8, 'unspecified / this-network'],
        ['10.0.0.0', 8, 'private (RFC 1918)'],
        ['100.64.0.0', 10, 'carrier-grade NAT (RFC 6598)'],
        ['127.0.0.0', 8, 'loopback'],
        ['169.254.0.0', 16, 'link-local'],
        ['172.16.0.0', 12, 'private (RFC 1918)'],
        ['192.0.0.0', 24, 'IETF protocol assignments'],
        ['192.168.0.0', 16, 'private (RFC 1918)'],
        ['198.18.0.0', 15, 'benchmarking'],
        ['224.0.0.0', 4, 'multicast'],
        ['240.0.0.0', 4, 'reserved / broadcast'],
    ];

    /**
     * IPv6 ranges. IPv4-mapped, 6to4 and NAT64 forms are not listed here: they
     * carry an IPv4 address inside them, so they are unwrapped first and then
     * judged by the IPv4 table, which is the only way to keep the two tables
     * from drifting apart.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const array BLOCKED_V6 = [
        ['::', 128, 'unspecified'],
        ['::1', 128, 'loopback'],
        ['100::', 64, 'discard-only'],
        ['fc00::', 7, 'unique local (RFC 4193)'],
        ['fe80::', 10, 'link-local'],
        ['ff00::', 8, 'multicast'],
    ];

    /** Named separately so the refusal says *why* this one matters. */
    private const string METADATA_V4 = '169.254.169.254';

    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver returns every A/AAAA
     *        record for a host name; defaults to a real DNS lookup
     */
    public function __construct(
        private readonly Config $config,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::systemResolver(...);
    }

    /** @throws SecurityException when the URL must not be fetched */
    public function assertAllowed(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new SecurityException('Refusing to fetch an empty URL.');
        }

        $scheme = $this->schemeOf($url);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new SecurityException(sprintf(
                'Refusing to fetch "%s": the "%s" scheme is not allowed, only http and https are.',
                $url,
                $scheme,
            ));
        }

        $host = $this->hostOf($url);

        // Development and the test fixture server talk to 127.0.0.1 on purpose.
        // The scheme check above still applies: file:// is never acceptable.
        if ($this->config->bool('allow_private_hosts')) {
            return;
        }

        $this->assertHostNameAllowed($host, $url);

        if (self::parseAddress($host) !== null) {
            $this->assertAddressAllowed($host, $url);

            return;
        }

        $addresses = ($this->resolver)($host);
        if ($addresses === []) {
            throw new SecurityException(sprintf(
                'Refusing to fetch "%s": the host "%s" has no address records.',
                $url,
                $host,
            ));
        }

        foreach ($addresses as $address) {
            $this->assertAddressAllowed($address, $url, $host);
        }
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (SecurityException) {
            return false;
        }
    }

    /**
     * True when this single address is acceptable. Exposed because the address
     * table is useful on its own (OPML import reports, diagnostics).
     */
    public function isAddressAllowed(string $address): bool
    {
        return self::reasonToBlock($address) === null;
    }

    // ---- host and address rules ---------------------------------------

    private function assertHostNameAllowed(string $host, string $url): void
    {
        $name = strtolower(rtrim($host, '.'));
        if ($name === '') {
            throw new SecurityException(sprintf('Refusing to fetch "%s": no host name.', $url));
        }

        if (in_array($name, self::BLOCKED_HOST_NAMES, true)) {
            throw new SecurityException(sprintf(
                'Refusing to fetch "%s": "%s" names this machine.',
                $url,
                $host,
            ));
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                throw new SecurityException(sprintf(
                    'Refusing to fetch "%s": "%s" is a local network name (%s).',
                    $url,
                    $host,
                    $suffix,
                ));
            }
        }
    }

    /** @param string|null $viaHost the name that resolved to this address, if any */
    private function assertAddressAllowed(string $address, string $url, ?string $viaHost = null): void
    {
        $reason = self::reasonToBlock($address);
        if ($reason === null) {
            return;
        }

        throw new SecurityException(sprintf(
            'Refusing to fetch "%s": %s%s is %s.',
            $url,
            $viaHost !== null ? $viaHost . ' resolves to ' : 'the address ',
            $address,
            $reason,
        ));
    }

    /**
     * The reason this address must not be contacted, or null when it is fine.
     *
     * Unparseable input is refused rather than ignored: if we cannot tell what
     * an address is, we cannot claim it is safe.
     */
    private static function reasonToBlock(string $address): ?string
    {
        $packed = self::parseAddress($address);
        if ($packed === null) {
            return 'not a usable IP address';
        }

        if (strlen($packed) === 16) {
            $embedded = self::embeddedIpv4($packed);
            if ($embedded !== null) {
                $reason = self::reasonToBlock($embedded);

                return $reason === null ? null : 'an IPv6 wrapper around ' . $embedded . ', which is ' . $reason;
            }

            return self::matchTable($packed, self::BLOCKED_V6);
        }

        if (inet_ntop($packed) === self::METADATA_V4) {
            return 'the cloud instance metadata endpoint';
        }

        return self::matchTable($packed, self::BLOCKED_V4);
    }

    /**
     * @param list<array{0: string, 1: int, 2: string}> $table
     */
    private static function matchTable(string $packed, array $table): ?string
    {
        foreach ($table as [$network, $prefix, $reason]) {
            $networkPacked = self::parseAddress($network);
            if ($networkPacked === null || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }
            if (self::inNetwork($packed, $networkPacked, $prefix)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Byte-and-bit mask comparison. String prefix comparison would be wrong
     * for anything that is not a whole-byte boundary (100.64.0.0/10 is the
     * obvious trap) and for the many textual spellings of one IPv6 address.
     */
    private static function inNetwork(string $packed, string $networkPacked, int $prefix): bool
    {
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && substr($packed, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }

    /**
     * The IPv4 address carried inside an IPv4-mapped (::ffff:a.b.c.d),
     * IPv4-compatible (::a.b.c.d), NAT64 (64:ff9b::/96) or 6to4 (2002::/16)
     * IPv6 address, or null when the address carries none.
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        $prefix = substr($packed, 0, 12);

        if ($prefix === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return self::toDotted(substr($packed, 12, 4));
        }

        if ($prefix === "\0\x64\xff\x9b\0\0\0\0\0\0\0\0") {
            return self::toDotted(substr($packed, 12, 4));
        }

        // ::a.b.c.d, excluding :: itself and ::1, which the IPv6 table names.
        if ($prefix === str_repeat("\0", 12) && strcmp(substr($packed, 12, 4), "\0\0\0\1") > 0) {
            return self::toDotted(substr($packed, 12, 4));
        }

        if (substr($packed, 0, 2) === "\x20\x02") {
            return self::toDotted(substr($packed, 2, 4));
        }

        return null;
    }

    private static function toDotted(string $fourBytes): string
    {
        $text = inet_ntop($fourBytes);

        return $text === false ? '0.0.0.0' : $text;
    }

    /** The packed form of a literal IPv4/IPv6 address, or null when it is a name. */
    private static function parseAddress(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            $candidate = substr($candidate, 1, -1);
        }
        // A zone index (fe80::1%eth0) is not part of the address itself.
        $percent = strpos($candidate, '%');
        if ($percent !== false) {
            $candidate = substr($candidate, 0, $percent);
        }
        if ($candidate === '') {
            return null;
        }

        $packed = @inet_pton($candidate);

        return $packed === false ? null : $packed;
    }

    // ---- URL decomposition ---------------------------------------------

    private function schemeOf(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '') {
            return strtolower($scheme);
        }

        // parse_url refuses some malformed URLs outright; recover the scheme by
        // hand so the refusal can still name it ("php://filter/..." and such).
        $colon = strpos($url, ':');
        if ($colon !== false && $colon > 0) {
            $candidate = strtolower(substr($url, 0, $colon));
            if (preg_match('/^[a-z][a-z0-9+.\-]*$/', $candidate) === 1) {
                return $candidate;
            }
        }

        throw new SecurityException(sprintf('Refusing to fetch "%s": no URL scheme.', $url));
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            throw new SecurityException(sprintf('Refusing to fetch "%s": no host name.', $url));
        }

        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * The default resolver: both address families, deduplicated.
     *
     * @return list<string>
     */
    private static function systemResolver(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[] = $address;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
