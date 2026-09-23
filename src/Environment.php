<?php
declare(strict_types=1);
namespace M2Smoke;

use Symfony\Component\Dotenv\Dotenv;

final class Environment
{
    public static function load(string $file, bool $required): void
    {
        if (!is_file($file)) {
            if ($required) {
                throw new \InvalidArgumentException('Requested --env file does not exist.');
            }
            return;
        }
        // Existing OS environment wins. No putenv: keep secrets out of inherited child environment.
        (new Dotenv())->load($file);
    }

    public static function value(string $key): ?string
    {
        $os = getenv($key);
        return $os !== false ? $os : ($_ENV[$key] ?? $_SERVER[$key] ?? null);
    }

    public static function apply(array $config): array
    {
        $fields = [
            'M2_SMOKE_BASE_URL' => ['base_url'],
            'M2_SMOKE_EXPECTED_MODE' => ['expected_mode'],
            'M2_SMOKE_COMMAND_TIMEOUT' => ['command_timeout'],
            'M2_SMOKE_RUN_TIMEOUT' => ['run_timeout'],
            'M2_SMOKE_HTTP_TIMEOUT' => ['http', 'timeout'],
            'M2_SMOKE_MAX_ASSETS' => ['http', 'max_assets'],
            'M2_SMOKE_LOG_LOOKBACK_MINUTES' => ['logs', 'lookback_minutes'],
            'M2_SMOKE_CRON_STALE_MINUTES' => ['cron', 'stale_after_minutes'],
        ];
        foreach ($fields as $key => $path) {
            $value = self::value($key);
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array($key, ['M2_SMOKE_BASE_URL', 'M2_SMOKE_EXPECTED_MODE'], true)) {
                if (!ctype_digit($value)) {
                    throw new \InvalidArgumentException("$key requires a positive integer.");
                }
                $value = (int) $value;
            }
            if (count($path) === 1) {
                $config[$path[0]] = $value;
            } else {
                $config[$path[0]][$path[1]] = $value;
            }
        }
        $all = array_merge($_SERVER, $_ENV, getenv());
        foreach (array_keys($all) as $key) {
            if (!preg_match('/^M2_SMOKE_PAGE_([A-Z0-9_]+)_URL$/', (string) $key, $match)) {
                continue;
            }
            $id = strtolower($match[1]);
            $prefix = 'M2_SMOKE_PAGE_' . $match[1];
            $url = self::value($key);
            $type = self::value($prefix . '_TYPE') ?? ($config['pages'][$id]['type'] ?? 'custom');
            $page = $config['pages'][$id] ?? [];
            $page['path'] = $url === '' ? null : $url;
            $page['type'] = $type;
            if (!isset($page['assets'])) {
                $page['assets'] = $type === 'homepage';
            }
            $config['pages'][$id] = $page;
        }
        return $config;
    }
}
