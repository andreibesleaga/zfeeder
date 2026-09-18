<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use Zfeeder\Admin\Auth\PasswordHasher;
use Zfeeder\Config\Config;
use Zfeeder\Config\Schema;
use Zfeeder\Kernel;

/**
 * The configuration screen, which is the one place in the panel that writes
 * settings rather than data: what it saves must survive a round trip through
 * the file, and what it refuses must leave the file exactly as it was.
 */
final class ConfigScreenTest extends AdminTestCase
{
    private function configPath(): string
    {
        return $this->tempDir() . '/config.json';
    }

    /** @return array<string, mixed> */
    private function savedConfig(): array
    {
        $raw = file_get_contents($this->configPath());
        self::assertIsString($raw, 'no configuration file was written');
        $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testEveryOptionIsOnTheScreen(): void
    {
        $this->signIn();

        $body = self::bodyOf($this->send('GET', '/admin/config'));

        foreach (array_keys(Schema::all()) as $key) {
            self::assertStringContainsString('config-' . $key, $body, $key . ' is missing from the screen');
        }
    }

    public function testASavedValueSurvivesTheRoundTrip(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => [
                'max_description_chars' => '1200',
                'template_set' => 'modern',
                'default_category' => self::CATEGORY,
                'fetch_timeout' => '20',
                'powered_by' => '1',
            ],
        ]));

        self::assertSame(303, $response->getStatusCode());
        $saved = $this->savedConfig();
        self::assertSame(1200, $saved['max_description_chars']);
        self::assertSame('modern', $saved['template_set']);
        self::assertSame(20, $saved['fetch_timeout']);
        self::assertTrue($saved['powered_by']);
    }

    public function testAnUncheckedBoxIsSavedAsFalse(): void
    {
        $this->signIn();

        $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => ['default_category' => self::CATEGORY],
        ]));

        self::assertFalse($this->savedConfig()['powered_by']);
    }

    public function testAValueOutsideItsRangeIsRefused(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => ['fetch_timeout' => '9000'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('must be between 1 and 120', self::bodyOf($response));
        self::assertFileDoesNotExist($this->configPath(), 'a refused form must not write anything');
    }

    public function testAValueThatIsNotANumberIsRefused(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => ['fetch_timeout' => 'soon'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('whole number', self::bodyOf($response));
    }

    public function testAValueOutsideItsEnumerationIsRefused(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => ['template_set' => 'neon'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('must be one of: classic, modern', self::bodyOf($response));
    }

    public function testAnOptionTheEnvironmentOwnsIsShownLockedAndNotSaved(): void
    {
        $config = Config::fromResolved(
            ['template_set' => 'classic', 'data_dir' => $this->tempDir()] + Schema::defaults(),
            ['template_set' => Config::SOURCE_ENV] + array_fill_keys(Schema::keys(), Config::SOURCE_DEFAULT),
            dirname(__DIR__, 3),
            $this->configPath(),
        );
        $config = $config->with('admin_password_hash', self::passwordHash());
        $this->kernel = new Kernel($config, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));
        $this->signIn();

        $screen = self::bodyOf($this->send('GET', '/admin/config'));
        self::assertStringContainsString('ZF_TEMPLATE_SET', $screen);
        self::assertStringContainsString('disabled', $screen);

        $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => ['template_set' => 'modern'],
        ]));

        self::assertArrayNotHasKey('template_set', $this->savedConfig());
    }

    public function testTheSecretsAreNeverPrintedBack(): void
    {
        $this->bootKernel(['refresh_key' => 'a-very-secret-key']);
        $this->signIn();

        $body = self::bodyOf($this->send('GET', '/admin/config'));

        self::assertStringNotContainsString('a-very-secret-key', $body);
        self::assertStringNotContainsString(self::passwordHash(), $body);
        // The box for a secret is rendered empty, with only "set" as a hint.
        self::assertMatchesRegularExpression('/id="config-refresh_key"[^>]*value=""/', $body);
        self::assertStringContainsString('set - type a new value', $body);
    }

    public function testAnEmptySecretBoxLeavesTheSecretAlone(): void
    {
        $this->bootKernel(['refresh_key' => 'a-very-secret-key']);
        $this->signIn();

        $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'save',
            'config' => ['refresh_key' => '', 'default_category' => self::CATEGORY],
        ]));

        self::assertSame('a-very-secret-key', $this->savedConfig()['refresh_key']);
    }

    public function testChangingThePasswordWritesAHash(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'password',
            'new_password' => 'a-brand-new-password',
            'new_password_confirm' => 'a-brand-new-password',
        ]));

        self::assertSame(303, $response->getStatusCode());
        $hash = $this->savedConfig()['admin_password_hash'];
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue((new PasswordHasher())->verify('a-brand-new-password', $hash));
    }

    public function testAShortPasswordIsRefused(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'password',
            'new_password' => 'short',
            'new_password_confirm' => 'short',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('at least 12 characters', self::bodyOf($response));
        self::assertFileDoesNotExist($this->configPath());
    }

    public function testTwoDifferentPasswordsAreRefused(): void
    {
        $this->signIn();

        $response = $this->send('POST', '/admin/config', $this->withToken([
            'action' => 'password',
            'new_password' => 'a-brand-new-password',
            'new_password_confirm' => 'a-brand-new-passward',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('not the same', self::bodyOf($response));
        self::assertFileDoesNotExist($this->configPath());
    }
}
