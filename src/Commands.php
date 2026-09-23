<?php
declare(strict_types=1);
namespace M2Smoke;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class Commands
{
    public function __construct(private string $root, private Budget $budget, private int $timeout) {}

    public function magento(string $command): array
    {
        if (!in_array($command, ['--version', 'deploy:mode:show', 'maintenance:status', 'indexer:status', 'cache:status'], true)) {
            throw new \LogicException('Command is not in the diagnostic allowlist.');
        }
        return $this->execute([PHP_BINARY, $this->root . '/bin/magento', $command, '--no-ansi', '--no-interaction']);
    }

    public function cron(int $lookback, int $stale): array
    {
        return $this->execute([PHP_BINARY, dirname(__DIR__) . '/helpers/cron.php', $this->root, (string) $lookback, (string) $stale]);
    }

    public function execute(array $argv): array
    {
        $environment = ['LC_ALL' => 'C'];
        foreach (array_keys($_ENV + $_SERVER + getenv()) as $key) {
            if (str_starts_with((string) $key, 'M2_SMOKE_')) {
                $environment[$key] = false;
            }
        }
        $process = new Process($argv, $this->root, $environment, null, $this->budget->timeout($this->timeout));
        $stdout = $stderr = '';
        $truncated = false;
        $started = microtime(true);
        try {
            $exit = $process->run(static function (string $type, string $chunk) use (&$stdout, &$stderr, &$truncated, $process): void {
                if ($type === Process::ERR) {
                    $stderr .= $chunk;
                    $truncated = $truncated || strlen($stderr) > 65536;
                    $stderr = substr($stderr, -65536);
                    $process->clearErrorOutput();
                } else {
                    $stdout .= $chunk;
                    $truncated = $truncated || strlen($stdout) > 65536;
                    $stdout = substr($stdout, -65536);
                    $process->clearOutput();
                }
            });
        } catch (ProcessTimedOutException) {
            return ['exit' => 124, 'stdout' => $stdout, 'stderr' => $stderr, 'timeout' => true, 'truncated' => $truncated];
        }
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timeout' => false,
            'truncated' => $truncated, 'elapsed_ms' => (int) ((microtime(true) - $started) * 1000)];
    }

    public static function indexers(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (!str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, " \t|")));
            if (count($cells) >= 3 && preg_match('/^[a-z0-9_]+$/', $cells[0]) && $cells[0] !== 'id') {
                $rows[$cells[0]] = $cells[2];
            }
        }
        if ($rows === []) {
            throw new \RuntimeException('No indexer rows recognized; Magento output may have changed.');
        }
        return $rows;
    }

    public static function caches(string $text): array
    {
        preg_match_all('/^\s*([a-z0-9_]+):\s*([01])\s*$/m', $text, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            throw new \RuntimeException('No cache statuses recognized; Magento output may have changed.');
        }
        return array_column($matches, 2, 1);
    }
}
