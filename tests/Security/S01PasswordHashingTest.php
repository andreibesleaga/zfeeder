<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Zfeeder\Admin\Auth\PasswordHasher;

/**
 * S1 — the administrator password is an Argon2id hash, verified in constant
 * time, and an empty hash disables the panel instead of allowing an empty
 * secret.
 *
 * Closes 1.6 defect L1: `newsfeeds/config.php:11` shipped
 * `d41d8cd98f00b204e9800998ecf8427e`, which is `md5("")`, and
 * `newsfeeds/includes/adminfuncs.php:23` compared it with `!=`. Unsalted,
 * GPU-trivial, and not constant time.
 *
 * The control lives in `src/Admin/Auth/PasswordHasher.php` (algorithm and cost
 * parameters), `src/Admin/Auth/SessionAuth::attempt()` (which burns the same
 * time for an unknown user) and `src/Admin/AdminDispatcher::handle()` (which
 * refuses to serve the panel at all when the hash is empty).
 */
final class S01PasswordHashingTest extends SecurityTestCase
{
    private const string PLAIN = 'correct-horse-battery-staple';

    public function testTheHashIsArgon2idAndIsNeverThePasswordItself(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PLAIN);

        self::assertNotSame(self::PLAIN, $hash);
        self::assertStringNotContainsString(self::PLAIN, $hash);
        self::assertStringStartsWith('$argon2id$', $hash);

        $info = password_get_info($hash);
        self::assertSame('argon2id', $info['algoName']);
        self::assertSame(PASSWORD_ARGON2ID, $info['algo']);
    }

    public function testTheSamePasswordHashesDifferentlyEveryTimeBecauseItIsSalted(): void
    {
        $hasher = new PasswordHasher();

        // The 2004 digest was deterministic, which is what made a rainbow table
        // work. Two hashes of one password must differ, and both must verify.
        $first = $hasher->hash(self::PLAIN);
        $second = $hasher->hash(self::PLAIN);

        self::assertNotSame($first, $second);
        self::assertTrue($hasher->verify(self::PLAIN, $first));
        self::assertTrue($hasher->verify(self::PLAIN, $second));
    }

    public function testVerificationIsPasswordVerifyAndAWrongPasswordFails(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PLAIN);

        // Not "returns true": returns exactly what password_verify() returns,
        // for a right password, a wrong one, and a near miss.
        foreach ([self::PLAIN, 'wrong', self::PLAIN . ' ', strtoupper(self::PLAIN), ''] as $candidate) {
            self::assertSame(
                password_verify($candidate, $hash),
                $hasher->verify($candidate, $hash),
                'verify() disagreed with password_verify() for ' . var_export($candidate, true),
            );
        }

        self::assertTrue($hasher->verify(self::PLAIN, $hash));
        self::assertFalse($hasher->verify('wrong', $hash));
    }

    public function testAnMd5OrSha1DigestOfThePasswordNeverVerifies(): void
    {
        $hasher = new PasswordHasher();

        // The 1.6 credential, handed to the 2.0 verifier: it is not a hash this
        // program will ever accept, whatever the password.
        $legacy = '5f4dcc3b5aa765d61d8327deb882cf99';

        self::assertFalse($hasher->verify('password', $legacy));
        self::assertFalse($hasher->verify(self::PLAIN, $legacy));
        self::assertTrue($hasher->needsRehash($legacy));
    }

    public function testNeedsRehashReactsToChangedCostParameters(): void
    {
        $hasher = new PasswordHasher();

        // The weakened build: the same algorithm at a cost the class does not
        // use. If needsRehash() ignored the options this would come back false.
        $weak = password_hash(self::PLAIN, PASSWORD_ARGON2ID, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        self::assertTrue($hasher->needsRehash($weak), 'a cheaper hash was not flagged for rehashing');
        self::assertFalse($hasher->needsRehash($hasher->hash(self::PLAIN)));
        self::assertTrue($hasher->needsRehash(''), 'an empty hash must always be replaced');
    }

    public function testAnEmptyConfiguredHashRefusesEveryComparison(): void
    {
        $hasher = new PasswordHasher();

        foreach (['', '   ', "\t\n"] as $empty) {
            self::assertFalse($hasher->verify('', $empty));
            self::assertFalse($hasher->verify('anything', $empty));
        }
    }

    public function testAnEmptyConfiguredHashDisablesThePanelEntirely(): void
    {
        $this->bootKernel(['admin_password_hash' => '']);

        self::assertFalse($this->config->adminUsable());

        // Not "the login form refuses the password": the panel is not served.
        foreach (['/admin', '/admin/login', '/admin/config'] as $path) {
            $response = $this->send('GET', $path);
            self::assertSame(503, $response->getStatusCode(), $path . ' was served without a password');
            self::assertStringContainsString('no password yet', self::bodyOf($response));
        }

        $posted = $this->send('POST', '/admin/login', [
            'admin_user' => self::USER,
            'admin_pass' => '',
        ]);
        self::assertSame(503, $posted->getStatusCode());
    }

    public function testThePanelIsServedAgainAsSoonAsAHashIsConfigured(): void
    {
        // The other half of the test above: 503 is the missing hash, not a
        // broken harness.
        $this->bootKernel();

        self::assertTrue($this->config->adminUsable());
        self::assertSame(200, $this->send('GET', '/admin/login')->getStatusCode());
    }

    public function testNoMd5OrSha1OfAPasswordExistsAnywhereInTheSourceTree(): void
    {
        $offenders = [];
        // Any md5()/sha1() call whose argument mentions a credential. This is
        // the 2004 defect expressed as a grep over the 2026 tree.
        $pattern = '/\b(md5|sha1)\s*\(\s*[^)]{0,120}(pass|pwd|secret|credential|token|hash)/i';

        foreach (self::sourceFiles(self::projectRoot() . '/src') as $file) {
            foreach (explode("\n", self::readFile($file)) as $number => $line) {
                $code = trim($line);
                // Comments are allowed to discuss the old scheme; code is not.
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue;
                }
                if (preg_match($pattern, $line) === 1) {
                    $offenders[] = $file . ':' . ($number + 1) . ' ' . $code;
                }
            }
        }

        self::assertSame([], $offenders, "A password digest of the 1.6 kind is back in src/:\n" . implode("\n", $offenders));
    }

    public function testTheAuthenticationCodeNeverCallsMd5OrSha1AtAll(): void
    {
        // Narrower than the tree-wide rule above and stricter: inside
        // src/Admin/Auth there is no legitimate use of either digest.
        foreach (self::sourceFiles(self::projectRoot() . '/src/Admin/Auth') as $file) {
            foreach (explode("\n", self::readFile($file)) as $number => $line) {
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//')) {
                    continue;
                }
                self::assertDoesNotMatchRegularExpression(
                    '/\b(md5|sha1)\s*\(/',
                    $line,
                    $file . ':' . ($number + 1) . ' uses a broken digest',
                );
            }
        }
    }
}
