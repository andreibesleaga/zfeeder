<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Admin\Auth\PasswordHasher;
use Zfeeder\Config\Config;
use Zfeeder\Config\ConfigWriter;
use Zfeeder\Config\Schema;
use Zfeeder\Exception\ConfigException;

/**
 * The configuration screen, generated from `Schema` rather than written out.
 *
 * 1.6 rewrote a PHP file full of `define()` calls from this form, which is why
 * a config screen was a code execution primitive. Here the form is built from
 * the schema table, every value is validated against the same table, and the
 * result is written as JSON outside the web root.
 *
 * Three kinds of option are shown but not editable, and the screen says which
 * kind each one is: options the schema marks as not admin-editable (they
 * decide where data lives, and changing them from a browser is how an install
 * loses its subscriptions), options the environment owns (the file would be
 * overwritten on the next request, so pretending to save them would be a lie),
 * and the two secrets, which are reported as "set" or "not set" and never
 * echoed back into HTML.
 */
final class ConfigController extends AbstractController
{
    protected const string SCREEN = 'config';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render('config.twig', $this->screenVars([]));
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $body = $this->body($request);

        return match ($this->field($body, 'action')) {
            'password' => $this->changePassword($body),
            default => $this->saveOptions($body),
        };
    }

    // ---- saving ----------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function saveOptions(array $body): ResponseInterface
    {
        $config = $this->kernel->config();
        $submitted = $body['config'] ?? null;
        $submitted = is_array($submitted) ? $submitted : [];

        $errors = [];
        $updated = $config;

        foreach (Schema::all() as $key => $option) {
            if (!$this->isEditable($key, $option, $config)) {
                continue;
            }

            $raw = $submitted[$key] ?? null;
            $raw = is_string($raw) ? trim($raw) : null;

            // A secret is never printed into the form, so an empty box means
            // "leave it alone" rather than "erase it".
            if (in_array($key, Schema::SECRET_KEYS, true) && ($raw === null || $raw === '')) {
                continue;
            }

            // A field the form did not carry at all keeps its current value.
            // Only a checkbox means something by its absence, and it means no.
            if ($raw === null && $option['type'] !== Schema::TYPE_BOOL) {
                continue;
            }

            $value = $this->validate($key, $option, $raw, $errors);
            if ($value === null) {
                continue;
            }
            $updated = $updated->with($key, $value);
        }

        if ($errors !== []) {
            return $this->render('config.twig', $this->screenVars($errors), 422);
        }

        try {
            (new ConfigWriter())->write($updated, $this->configPath($config));
        } catch (ConfigException $e) {
            return $this->render('config.twig', $this->screenVars(['*' => $e->getMessage()]), 500);
        }

        $this->context->addFlash(AdminContext::LEVEL_SUCCESS, 'Configuration saved.');

        return $this->redirect('/admin/config');
    }

    /**
     * @param array{env: string, type: string, default: string|int|bool, group: string, values?: list<string>, min?: int, max?: int, label: string, help: string, adminEditable: bool} $option
     * @param array<string, string> $errors
     */
    private function validate(string $key, array $option, ?string $raw, array &$errors): string|int|bool|null
    {
        switch ($option['type']) {
            case Schema::TYPE_BOOL:
                // An unchecked box sends nothing at all, which is the value.
                return $raw !== null;

            case Schema::TYPE_INT:
                if ($raw === null || $raw === '') {
                    $errors[$key] = $option['label'] . ' needs a number.';

                    return null;
                }
                if (preg_match('/^-?\d+$/', $raw) !== 1) {
                    $errors[$key] = $option['label'] . ' must be a whole number.';

                    return null;
                }
                $number = (int) $raw;
                $min = $option['min'] ?? PHP_INT_MIN;
                $max = $option['max'] ?? PHP_INT_MAX;
                if ($number < $min || $number > $max) {
                    $errors[$key] = sprintf('%s must be between %d and %d.', $option['label'], $min, $max);

                    return null;
                }

                return $number;

            case Schema::TYPE_ENUM:
                $values = $option['values'] ?? [];
                if ($raw === null || !in_array($raw, $values, true)) {
                    $errors[$key] = $option['label'] . ' must be one of: ' . implode(', ', $values) . '.';

                    return null;
                }

                return $raw;

            default:
                return $raw ?? '';
        }
    }

    /** @param array<string, mixed> $body */
    private function changePassword(array $body): ResponseInterface
    {
        $config = $this->kernel->config();
        $first = $this->field($body, 'new_password');
        $second = $this->field($body, 'new_password_confirm');
        $errors = [];

        if ($config->isLockedByEnvironment('admin_password_hash')) {
            $errors['new_password'] = 'The password hash is set by the ZF_ADMIN_PASSWORD_HASH environment variable and cannot be changed here.';
        } elseif (mb_strlen($first) < PasswordHasher::MIN_LENGTH) {
            $errors['new_password'] = sprintf('The new password must be at least %d characters long.', PasswordHasher::MIN_LENGTH);
        } elseif ($first !== $second) {
            $errors['new_password_confirm'] = 'The two passwords are not the same.';
        }

        if ($errors !== []) {
            return $this->render('config.twig', $this->screenVars($errors), 422);
        }

        $hash = (new PasswordHasher())->hash($first);

        try {
            (new ConfigWriter())->write($config->with('admin_password_hash', $hash), $this->configPath($config));
        } catch (ConfigException $e) {
            return $this->render('config.twig', $this->screenVars(['*' => $e->getMessage()]), 500);
        }

        $this->kernel->logger()->notice('Admin password changed');
        $this->context->addFlash(AdminContext::LEVEL_SUCCESS, 'Password changed. Use it the next time you sign in.');

        return $this->redirect('/admin/config');
    }

    // ---- rendering -------------------------------------------------------

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function screenVars(array $errors): array
    {
        $config = $this->kernel->config();
        $groups = [];

        foreach (Schema::all() as $key => $option) {
            $secret = in_array($key, Schema::SECRET_KEYS, true);
            $locked = $config->isLockedByEnvironment($key);
            $editable = $this->isEditable($key, $option, $config);

            $groups[$option['group']][] = [
                'key' => $key,
                'label' => $option['label'],
                'help' => $option['help'],
                'type' => $option['type'],
                'values' => $option['values'] ?? [],
                'min' => $option['min'] ?? null,
                'max' => $option['max'] ?? null,
                'env' => $option['env'],
                'locked' => $locked,
                'editable' => $editable,
                'secret' => $secret,
                'source' => $config->sourceOf($key),
                'value' => $secret ? '' : $config->get($key),
                'display' => $secret
                    ? (trim((string) $config->get($key)) === '' ? 'not set' : 'set')
                    : (string) $config->get($key),
                'error' => $errors[$key] ?? '',
                'note' => $this->note($key, $option, $locked),
            ];
        }

        return [
            'title' => 'Configuration',
            'groups' => $groups,
            'errors' => $errors,
            'general_error' => $errors['*'] ?? '',
            'config_path' => $this->configPath($config),
            'password_minimum' => PasswordHasher::MIN_LENGTH,
            'password_locked' => $config->isLockedByEnvironment('admin_password_hash'),
        ];
    }

    /**
     * @param array{env: string, type: string, default: string|int|bool, group: string, values?: list<string>, min?: int, max?: int, label: string, help: string, adminEditable: bool} $option
     */
    private function isEditable(string $key, array $option, Config $config): bool
    {
        if (in_array($key, Schema::SECRET_KEYS, true) && $key === 'admin_password_hash') {
            return false;
        }

        return $option['adminEditable'] && !$config->isLockedByEnvironment($key);
    }

    /**
     * @param array{env: string, type: string, default: string|int|bool, group: string, values?: list<string>, min?: int, max?: int, label: string, help: string, adminEditable: bool} $option
     */
    private function note(string $key, array $option, bool $locked): string
    {
        if ($locked) {
            return sprintf('Set by the %s environment variable, which wins over anything saved here.', $option['env']);
        }
        if ($key === 'admin_password_hash') {
            return 'Change it in the password section below, or with bin/zfeeder hash-password.';
        }
        if (!$option['adminEditable']) {
            return sprintf('Only the %s environment variable or the configuration file can change this.', $option['env']);
        }

        return '';
    }

    private function configPath(Config $config): string
    {
        return $config->configPath() ?? $config->dataDir() . '/config.json';
    }
}
