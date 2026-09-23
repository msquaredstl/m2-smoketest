<?php
declare(strict_types=1);
namespace M2Smoke;

use Symfony\Component\Yaml\Yaml;

final class Config
{
    public static function load(?string $path): array
    {
        $defaults = Yaml::parseFile(dirname(__DIR__) . '/config/defaults.yml');
        $user = $path === null ? [] : Yaml::parseFile($path);
        if (!is_array($user) || ($user !== [] && array_is_list($user))) {
            throw new \InvalidArgumentException('Configuration must be a YAML mapping.');
        }
        self::known($user, array_keys($defaults), 'configuration');
        foreach (['http', 'cron', 'logs', 'indexers', 'cache'] as $section) {
            if (isset($user[$section])) {
                if (!is_array($user[$section])) {
                    throw new \InvalidArgumentException("$section must be a mapping.");
                }
                self::known($user[$section], array_keys($defaults[$section]), $section);
            }
        }
        // Lists are replaced, not merged by numeric key.
        $config = array_replace($defaults, $user);
        foreach (['http', 'cron', 'logs', 'indexers', 'cache'] as $section) {
            $config[$section] = array_replace($defaults[$section], $user[$section] ?? []);
        }
        return $config;
    }

    private static function known(array $values, array $keys, string $section): void
    {
        if (array_diff(array_keys($values), $keys)) {
            throw new \InvalidArgumentException("Unknown keys in $section; see config/defaults.yml.");
        }
    }

    public static function validate(array $c): void
    {
        if ($c['base_url'] !== null) {
            self::url($c['base_url']);
        }
        foreach (['command_timeout', 'run_timeout'] as $key) {
            self::positive($c[$key], $key);
        }
        foreach (['timeout', 'max_assets', 'max_body_bytes'] as $key) {
            self::positive($c['http'][$key], "http.$key");
        }
        foreach (['stale_after_minutes', 'lookback_minutes'] as $key) {
            self::positive($c['cron'][$key], "cron.$key");
        }
        foreach (['max_failures', 'max_overdue'] as $key) {
            if (!is_int($c['cron'][$key]) || $c['cron'][$key] < 0) {
                throw new \InvalidArgumentException("cron.$key must be a nonnegative integer.");
            }
        }
        foreach (['lookback_minutes', 'max_bytes'] as $key) {
            self::positive($c['logs'][$key], "logs.$key");
        }
        foreach ([$c['cron']['enabled'], $c['indexers']['fail_on_invalid']] as $flag) {
            if (!is_bool($flag)) {
                throw new \InvalidArgumentException('enabled/fail_on_invalid must be boolean.');
            }
        }
        foreach (['username_env', 'password_env'] as $key) {
            if (!is_string($c['http'][$key]) || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $c['http'][$key])) {
                throw new \InvalidArgumentException("http.$key must name an environment variable.");
            }
        }
        foreach ([$c['indexers']['ignore'], $c['cache']['ignore'], $c['logs']['files']] as $list) {
            if (!is_array($list) || !array_is_list($list) || array_filter($list, fn ($v) => !is_string($v))) {
                throw new \InvalidArgumentException('ignore/files must be lists of strings.');
            }
        }
        foreach ($c['logs']['files'] as $file) {
            if (!preg_match('~^var/log/[a-zA-Z0-9_.-]+\.log$~', $file)) {
                throw new \InvalidArgumentException('Log files must be simple paths under var/log.');
            }
        }
        if (!in_array($c['expected_mode'], ['production', 'developer', 'default', null], true)) {
            throw new \InvalidArgumentException('Invalid expected_mode.');
        }
        if (!is_array($c['pages']) || $c['pages'] === [] || array_is_list($c['pages'])) {
            throw new \InvalidArgumentException('pages must be a nonempty mapping.');
        }
        foreach ($c['pages'] as $name => $page) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $name) || !is_array($page)) {
                throw new \InvalidArgumentException('Invalid page definition.');
            }
            self::known($page, ['path', 'type', 'expect_status', 'contains', 'not_contains', 'assets'], 'page');
            if (!in_array($page['type'] ?? 'custom', ['homepage', 'category', 'product', 'search', 'cart', 'checkout', 'login', 'custom'], true)) {
                throw new \InvalidArgumentException('Unknown page type.');
            }
            $path = $page['path'] ?? null;
            if ($path !== null && (!is_string($path) || (!str_starts_with($path, '/') && !preg_match('~^https?://~', $path)) ||
                str_starts_with($path, '//') || preg_match('/[\x00-\x20\\\\]/', $path))) {
                throw new \InvalidArgumentException('Page URLs must be HTTP(S) URLs or paths starting with one slash, without whitespace.');
            }
            if (is_string($path) && preg_match('~^https?://~', $path)) {
                if ($c['base_url'] === null || Http::origin($path) !== Http::origin($c['base_url'])) {
                    throw new \InvalidArgumentException('Absolute page URLs must match base_url origin; use a separate environment for another site.');
                }
            }
            $status = $page['expect_status'] ?? 200;
            if (!is_int($status) || $status < 200 || $status > 299) {
                throw new \InvalidArgumentException('Page expect_status must be a 2xx integer.');
            }
            if (isset($page['assets']) && !is_bool($page['assets'])) {
                throw new \InvalidArgumentException('Page assets must be boolean.');
            }
            foreach (['contains', 'not_contains'] as $key) {
                $list = $page[$key] ?? [];
                if (!is_array($list) || !array_is_list($list) ||
                    array_filter($list, fn ($v) => !is_string($v) || $v === '')) {
                    throw new \InvalidArgumentException("Page $key must be a list of nonempty strings.");
                }
            }
        }
    }

    public static function url(mixed $url): void
    {
        $p = is_string($url) ? parse_url($url) : false;
        if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) ||
            empty($p['host']) || isset($p['user']) || isset($p['pass']) ||
            isset($p['query']) || isset($p['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new \InvalidArgumentException('base_url must be an HTTP(S) URL without credentials, query, or fragment.');
        }
    }

    private static function positive(mixed $value, string $name): void
    {
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException("$name must be a positive integer.");
        }
    }

    public static function root(?string $explicit, string $start): string
    {
        $dir = realpath($explicit ?? $start);
        while ($dir !== false) {
            if (is_file($dir . '/bin/magento') && is_file($dir . '/app/bootstrap.php')) {
                return $dir;
            }
            if ($explicit !== null || dirname($dir) === $dir) {
                break;
            }
            $dir = dirname($dir);
        }
        throw new \InvalidArgumentException('Magento root not found; run inside Magento or pass --root.');
    }

    public static function since(?string $value, int $minutes): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($value === null) {
            return $now->modify("-$minutes minutes");
        }
        if (preg_match('/^([1-9][0-9]*)\s*(m|minutes?|h|hours?)$/i', $value, $m)) {
            $unit = str_starts_with(strtolower($m[2]), 'h') ? 'hours' : 'minutes';
            return $now->modify("-{$m[1]} $unit");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new \InvalidArgumentException('--since requires e.g. "15 minutes" or an ISO-8601 timestamp with timezone.');
        }
        $date = new \DateTimeImmutable($value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date > $now) {
            throw new \InvalidArgumentException('--since must be a valid timestamp in the past.');
        }
        return $date;
    }
}
