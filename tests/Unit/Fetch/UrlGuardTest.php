<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Unit\Fetch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zfeeder\Config\Config;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Fetch\UrlGuard;

/**
 * The SSRF table, case by case.
 *
 * Every case states the DNS answer explicitly and the resolver is injected, so
 * this suite never touches the network and never changes verdict because
 * somebody's router hands out a different address today.
 */
#[CoversClass(UrlGuard::class)]
final class UrlGuardTest extends TestCase
{
    /**
     * @param list<string> $dns   what the resolver answers for the host name
     * @param string       $why   a fragment the refusal must mention (ignored when allowed)
     */
    #[DataProvider('verdicts')]
    public function testVerdict(string $url, array $dns, bool $allowed, string $why): void
    {
        $guard = self::guard($dns);

        self::assertSame($allowed, $guard->isAllowed($url), $url);

        if ($allowed) {
            $guard->assertAllowed($url);

            return;
        }

        try {
            $guard->assertAllowed($url);
            self::fail('Expected ' . $url . ' to be refused.');
        } catch (SecurityException $e) {
            self::assertStringContainsStringIgnoringCase($why, $e->getMessage(), $url);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: bool, 3: string}>
     */
    public static function verdicts(): iterable
    {
        // ---- schemes -----------------------------------------------------
        yield 'file scheme' => ['file:///etc/passwd', [], false, '"file" scheme'];
        yield 'php wrapper' => ['php://filter/resource=/etc/passwd', [], false, '"php" scheme'];
        yield 'gopher scheme' => ['gopher://example.com:70/1', ['93.184.216.34'], false, '"gopher" scheme'];
        yield 'data scheme' => ['data:text/plain;base64,aGk=', [], false, '"data" scheme'];
        yield 'ftp scheme' => ['ftp://example.com/feed.xml', ['93.184.216.34'], false, '"ftp" scheme'];
        yield 'dict scheme' => ['dict://example.com:2628/d:x', ['93.184.216.34'], false, '"dict" scheme'];
        yield 'uppercase HTTPS is fine' => ['HTTPS://example.com/feed', ['93.184.216.34'], true, ''];
        yield 'no scheme' => ['example.com/feed.xml', [], false, 'no URL scheme'];
        yield 'empty url' => ['', [], false, 'empty URL'];

        // ---- host names --------------------------------------------------
        yield 'localhost' => ['http://localhost/feed', [], false, 'names this machine'];
        yield 'localhost with trailing dot' => ['http://localhost./feed', [], false, 'names this machine'];
        yield 'sub.localhost' => ['http://api.localhost/feed', [], false, 'local network name'];
        yield 'mdns .local' => ['http://printer.local/feed', [], false, 'local network name'];
        yield '.internal' => ['http://db.internal/feed', [], false, 'local network name'];
        yield 'host with no records' => ['http://nowhere.example/feed', [], false, 'no address records'];

        // ---- literal IPv4, no DNS involved --------------------------------
        yield 'loopback literal' => ['http://127.0.0.1:8080/feed', [], false, 'loopback'];
        yield 'loopback elsewhere in 127/8' => ['http://127.1.2.3/feed', [], false, 'loopback'];
        yield 'unspecified v4' => ['http://0.0.0.0/feed', [], false, 'unspecified'];
        yield 'private 10/8' => ['http://10.0.0.7/feed', [], false, 'private'];
        yield 'private 172.16/12 low' => ['http://172.16.0.1/feed', [], false, 'private'];
        yield 'private 172.16/12 high' => ['http://172.31.255.254/feed', [], false, 'private'];
        yield 'just outside 172.16/12' => ['http://172.32.0.1/feed', [], true, ''];
        yield 'private 192.168/16' => ['http://192.168.1.10/feed', [], false, 'private'];
        yield 'link-local' => ['http://169.254.10.10/feed', [], false, 'link-local'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', [], false, 'metadata'];
        yield 'cgnat 100.64/10 low' => ['http://100.64.0.1/feed', [], false, 'carrier-grade NAT'];
        yield 'cgnat 100.64/10 high' => ['http://100.127.255.254/feed', [], false, 'carrier-grade NAT'];
        yield 'just outside cgnat' => ['http://100.128.0.1/feed', [], true, ''];
        yield 'broadcast' => ['http://255.255.255.255/feed', [], false, 'reserved'];
        yield 'multicast' => ['http://224.0.0.1/feed', [], false, 'multicast'];
        yield 'public literal' => ['http://93.184.216.34/feed.xml', [], true, ''];

        // ---- literal IPv6 -------------------------------------------------
        yield 'ipv6 loopback' => ['http://[::1]/feed', [], false, 'loopback'];
        yield 'ipv6 unspecified' => ['http://[::]/feed', [], false, 'unspecified'];
        yield 'ipv6 unique local' => ['http://[fd00::1]/feed', [], false, 'unique local'];
        yield 'ipv6 fc00 start' => ['http://[fc00::1]/feed', [], false, 'unique local'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/feed', [], false, 'link-local'];
        yield 'ipv6 link-local with zone' => ['http://[fe80::1%25eth0]/feed', [], false, 'link-local'];
        yield 'ipv6 multicast' => ['http://[ff02::1]/feed', [], false, 'multicast'];
        yield 'ipv6 public' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/feed', [], true, ''];

        // ---- IPv6 wrappers around forbidden IPv4 --------------------------
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/feed', [], false, 'loopback'];
        yield 'ipv4-mapped loopback hex' => ['http://[::ffff:7f00:1]/feed', [], false, 'loopback'];
        yield 'ipv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/feed', [], false, 'metadata'];
        yield 'ipv4-compatible private' => ['http://[::10.0.0.1]/feed', [], false, 'private'];
        yield 'nat64 loopback' => ['http://[64:ff9b::127.0.0.1]/feed', [], false, 'loopback'];
        yield '6to4 private' => ['http://[2002:c0a8:0101::1]/feed', [], false, 'private'];
        yield 'ipv4-mapped public is fine' => ['http://[::ffff:93.184.216.34]/feed', [], true, ''];

        // ---- DNS results: the rebinding shapes ----------------------------
        yield 'name resolving to loopback' => ['http://rebind.example/feed', ['127.0.0.1'], false, 'loopback'];
        yield 'name resolving to metadata' => ['http://meta.example/feed', ['169.254.169.254'], false, 'metadata'];
        yield 'name resolving to private' => ['http://intranet.example/feed', ['10.1.2.3'], false, 'private'];
        yield 'one good one bad record' => ['http://mixed.example/feed', ['93.184.216.34', '127.0.0.1'], false, 'loopback'];
        yield 'bad record first' => ['http://mixed2.example/feed', ['192.168.0.1', '93.184.216.34'], false, 'private'];
        yield 'AAAA resolving to ::1' => ['http://v6rebind.example/feed', ['::1'], false, 'loopback'];
        yield 'AAAA resolving to ULA' => ['http://v6ula.example/feed', ['fd12:3456::1'], false, 'unique local'];
        yield 'all records public' => ['https://good.example/feed.xml', ['93.184.216.34', '2606:2800::1'], true, ''];
        yield 'garbage record' => ['http://junk.example/feed', ['not-an-address'], false, 'not a usable IP address'];
    }

    public function testRefusalNamesTheOffendingHostAndAddress(): void
    {
        $guard = self::guard(['127.0.0.1']);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('rebind.example resolves to 127.0.0.1');
        $guard->assertAllowed('http://rebind.example/feed');
    }

    public function testLiteralAddressesNeverReachTheResolver(): void
    {
        $calls = 0;
        $guard = new UrlGuard(
            Config::forTesting(),
            function (string $host) use (&$calls): array {
                ++$calls;

                return ['93.184.216.34'];
            },
        );

        $guard->assertAllowed('http://93.184.216.34/feed');
        self::assertFalse($guard->isAllowed('http://127.0.0.1/feed'));
        self::assertSame(0, $calls, 'A literal address must be judged without DNS.');
    }

    public function testAllowPrivateHostsSkipsTheAddressRules(): void
    {
        $guard = new UrlGuard(
            Config::forTesting(['allow_private_hosts' => true]),
            static fn (string $host): array => ['127.0.0.1'],
        );

        self::assertTrue($guard->isAllowed('http://127.0.0.1:8099/fixtures/feed.xml'));
        self::assertTrue($guard->isAllowed('http://localhost:8099/fixtures/feed.xml'));
        self::assertTrue($guard->isAllowed('http://fixtures.local/feed.xml'));
        self::assertTrue($guard->isAllowed('http://[::1]:8099/feed.xml'));
    }

    public function testAllowPrivateHostsStillEnforcesTheSchemeAllowList(): void
    {
        $guard = new UrlGuard(
            Config::forTesting(['allow_private_hosts' => true]),
            static fn (string $host): array => ['127.0.0.1'],
        );

        self::assertFalse($guard->isAllowed('file:///etc/passwd'));
        self::assertFalse($guard->isAllowed('php://filter/resource=/etc/passwd'));
        self::assertFalse($guard->isAllowed('gopher://127.0.0.1/1'));

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('"file" scheme');
        $guard->assertAllowed('file:///etc/passwd');
    }

    public function testAllowPrivateHostsDoesNotSkipTheHostRequirement(): void
    {
        $guard = new UrlGuard(
            Config::forTesting(['allow_private_hosts' => true]),
            static fn (string $host): array => [],
        );

        self::assertFalse($guard->isAllowed('http:///feed.xml'));
    }

    public function testIsAddressAllowedExposesTheTableOnItsOwn(): void
    {
        $guard = self::guard([]);

        self::assertTrue($guard->isAddressAllowed('93.184.216.34'));
        self::assertFalse($guard->isAddressAllowed('127.0.0.1'));
        self::assertFalse($guard->isAddressAllowed('::1'));
        self::assertFalse($guard->isAddressAllowed('169.254.169.254'));
    }

    public function testSchemeAllowListIsExactlyHttpAndHttps(): void
    {
        self::assertSame(['http', 'https'], UrlGuard::ALLOWED_SCHEMES);
    }

    /** @param list<string> $dns */
    private static function guard(array $dns): UrlGuard
    {
        return new UrlGuard(Config::forTesting(), static fn (string $host): array => $dns);
    }
}
