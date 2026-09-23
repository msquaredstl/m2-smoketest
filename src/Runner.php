<?php
declare(strict_types=1);
namespace M2Smoke;

final class Runner
{
    private Commands $commands;
    private array $seenAssets = [];

    public function __construct(
        private string $root,
        private array $config,
        private Report $report,
        private Budget $budget,
        private \DateTimeImmutable $since,
    ) {
        $this->commands = new Commands($root, $budget, $config['command_timeout']);
    }

    private function check(string $id, callable $test): void
    {
        if ($this->budget->remaining() < 0.1) {
            $this->report->add($id, 'WARN', 'Not run: total time budget exhausted.');
            return;
        }
        try {
            [$status, $message, $details] = $test();
        } catch (\Throwable $error) {
            $status = 'FAIL';
            $message = 'Check could not complete.';
            $details = ['error' => $error->getMessage(), 'exception' => get_class($error)];
        }
        $this->report->add($id, $status, $message, $details);
    }

    private function cli(string $id, string $command, callable $evaluate): void
    {
        $this->check($id, function () use ($command, $evaluate) {
            $result = $this->commands->magento($command);
            $details = ['command' => 'php bin/magento ' . $command] + $result;
            if ($result['exit'] !== 0 || $result['timeout']) {
                return ['FAIL', $result['timeout'] ? 'Magento command timed out.' : 'Magento command failed.', $details];
            }
            if ($result['truncated']) {
                return ['FAIL', 'Magento output exceeded the limit; result cannot be verified.', $details];
            }
            try {
                [$status, $message] = $evaluate($result['stdout']);
            } catch (\Throwable $error) {
                return ['FAIL', $error->getMessage(), $details];
            }
            if ($result['stderr'] !== '' && $status === 'PASS') {
                return ['WARN', $message . ' Command also wrote to stderr.', $details];
            }
            return [$status, $message, $details];
        });
    }

    public function run(): void
    {
        $this->report->add('runtime.php', 'PASS', 'PHP ' . PHP_VERSION);
        $this->cli('magento.version', '--version', static function ($out) {
            if (!preg_match('/Magento CLI\s+([0-9]+\.[0-9]+\.[0-9]+[^\s]*)/', $out, $m)) {
                throw new \RuntimeException('Magento version not recognized.');
            }
            preg_match('/^\d+\.\d+\.\d+/', $m[1], $core);
            return [version_compare($core[0], '2.4.7', '>=') ? 'PASS' : 'WARN', 'Magento ' . $m[1] .
                ' (patch installation is not verified).'];
        });
        $this->cli('magento.mode', 'deploy:mode:show', function ($out) {
            if (!preg_match('/Current application mode:\s*(production|developer|default)/i', $out, $m)) {
                throw new \RuntimeException('Deployment mode not recognized.');
            }
            $expected = $this->config['expected_mode'];
            return [$expected === null || strtolower($m[1]) === $expected ? 'PASS' : 'WARN',
                'Mode: ' . $m[1] . ($expected === null ? '' : '; expected ' . $expected) . '.'];
        });
        $this->cli('magento.maintenance', 'maintenance:status', static function ($out) {
            if (!preg_match('/Status:\s*maintenance mode is (not active|active)/i', $out, $m)) {
                throw new \RuntimeException('Maintenance status not recognized.');
            }
            return [strtolower($m[1]) === 'not active' ? 'PASS' : 'FAIL', 'Maintenance mode is ' . $m[1] . '.'];
        });
        $this->cli('magento.indexers', 'indexer:status', function ($out) {
            $rows = array_diff_key(Commands::indexers($out), array_flip($this->config['indexers']['ignore']));
            $bad = array_filter($rows, static fn ($state) => $state !== 'Ready');
            if ($rows === []) {
                return ['WARN', 'All indexers excluded; not verified.'];
            }
            return [$bad ? ($this->config['indexers']['fail_on_invalid'] ? 'FAIL' : 'WARN') : 'PASS',
                count($rows) . ' indexer(s) checked; ' . count($bad) . ' not ready.' .
                ($bad ? ' Inspect indexer:status and reindex affected indexes manually if appropriate.' : '')];
        });
        $this->cli('magento.cache', 'cache:status', function ($out) {
            $rows = array_diff_key(Commands::caches($out), array_flip($this->config['cache']['ignore']));
            $disabled = array_keys(array_filter($rows, static fn ($state) => $state === '0'));
            return [$disabled || !$rows ? 'WARN' : 'PASS',
                !$rows ? 'All caches excluded; not verified.' :
                    ($disabled ? 'Disabled cache(s): ' . implode(', ', $disabled) : count($rows) . ' cache(s) enabled.')];
        });
        if ($this->config['cron']['enabled']) {
            $this->check('magento.cron', function () {
                $c = $this->config['cron'];
                $result = $this->commands->cron($c['lookback_minutes'], $c['stale_after_minutes']);
                if ($result['exit'] !== 0 || $result['timeout'] ||
                    !preg_match('/^__M2SMOKE__(.+)$/m', $result['stdout'], $m)) {
                    return ['FAIL', 'Cron history could not be read.', $result];
                }
                $row = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row) || !array_key_exists('age_seconds', $row)) {
                    return ['FAIL', 'Cron helper returned an invalid result.', $result];
                }
                $stale = $row['age_seconds'] === null || (int) $row['age_seconds'] > $c['stale_after_minutes'] * 60;
                $failures = (int) $row['failures'];
                $overdue = (int) $row['overdue'];
                $bad = $failures > $c['max_failures'] || $overdue > $c['max_overdue'];
                return [$stale ? 'FAIL' : ($bad || $result['stderr'] !== '' ? 'WARN' : 'PASS'),
                    'Last successful cron: ' . ($row['last_success'] ?? 'none') .
                    " UTC; $failures error/missed job(s); $overdue overdue pending job(s).", $row + $result];
            });
        } else {
            $this->report->add('magento.cron', 'WARN', 'Cron check disabled; not verified.');
        }

        $base = $this->config['base_url'];
        if ($base === null) {
            $this->report->add('http.configuration', 'WARN', 'No base URL configured; storefront and assets not verified.');
        } else {
            $http = new Http($this->config['http'], $this->budget, $base);
            $pages = $this->config['pages'];
            uasort($pages, static fn ($a, $b) => strcmp($a['type'] ?? 'custom', $b['type'] ?? 'custom'));
            foreach ($pages as $name => $page) {
                $type = $page['type'] ?? 'custom';
                $id = 'http.' . $type . '.' . $name;
                if (($page['path'] ?? null) === null) {
                    $this->report->add($id, 'WARN', 'URL not configured; not verified.');
                    continue;
                }
                $url = preg_match('~^https?://~', $page['path']) ? $page['path'] :
                    rtrim($base, '/') . '/' . ltrim($page['path'], '/');
                $response = null;
                $this->check($id, function () use ($http, $page, $url, $type, &$response) {
                    $response = $http->request($url);
                    $errors = Http::pageErrors($response, $page, $url);
                    $details = $response;
                    unset($details['body']); // Never include full HTML, session data, or checkout configuration.
                    $details['errors'] = $errors;
                    $scope = in_array($type, ['search', 'checkout', 'cart', 'login'], true) ? ' HTML response only; interactive behavior not verified.' : '';
                    return [$errors ? 'FAIL' : 'PASS',
                        $errors ? implode(' ', $errors) : 'HTTP ' . $response['status'] . ' in ' . $response['elapsed_ms'] . 'ms.' . $scope,
                        $details];
                });
                if (($page['assets'] ?? false) && $response !== null && $response['status'] === 200) {
                    $this->check('assets.' . $type . '.' . $name, fn () => $this->assets($http, $response));
                }
            }
        }
        foreach ($this->config['logs']['files'] as $file) {
            $this->check('logs.' . basename($file), function () use ($file) {
                $result = Logs::inspect($this->root . '/' . $file, $this->since, $this->config['logs']['max_bytes']);
                return [$result['status'], $result['message'], $result['details']];
            });
        }
    }

    private function assets(Http $http, array $page): array
    {
        $assets = Http::assets($page['body'], $page['url']);
        if ($assets === []) {
            return ['WARN', 'No linked CSS/JS discovered; static content not verified.', []];
        }
        $failures = [];
        $external = $limited = $checked = $cached = 0;
        foreach ($assets as $url => $type) {
            if (Http::origin($url) !== Http::origin($page['url'])) {
                ++$external;
                continue;
            }
            if (array_key_exists($url, $this->seenAssets)) {
                ++$cached;
                if ($this->seenAssets[$url] !== null && $this->seenAssets[$url] !== '') {
                    $failures[$url] = $this->seenAssets[$url];
                }
                continue;
            }
            if (count($this->seenAssets) >= $this->config['http']['max_assets'] || $this->budget->remaining() < 0.1) {
                ++$limited;
                continue;
            }
            ++$checked;
            try {
                $r = $http->request($url, true);
                if (in_array($r['status'], [405, 501], true)) {
                    $r = $http->request($url);
                }
                $mime = strtolower(explode(';', $r['content_type'])[0]);
                $validMime = $type === 'css' ? $mime === 'text/css' :
                    in_array($mime, ['text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript'], true);
                $error = $r['status'] !== 200 ? 'HTTP ' . $r['status'] :
                    (!$validMime ? 'Unexpected asset content type: ' . $mime : '');
            } catch (\Throwable $errorObject) {
                $error = $errorObject->getMessage();
            }
            $this->seenAssets[$url] = $error;
            if ($error !== '') {
                $failures[$url] = $error;
            }
        }
        return [$failures ? 'FAIL' : ($external || $limited ? 'WARN' : 'PASS'),
            "$checked asset(s) checked, $cached reused; " . count($failures) . " failed; $external external excluded; $limited not checked due to limits.",
            ['failures' => $failures, 'external_excluded' => $external, 'limited' => $limited]];
    }
}
