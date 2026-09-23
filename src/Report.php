<?php
declare(strict_types=1);
namespace M2Smoke;

final class Report
{
    private array $checks = [];
    private mixed $log = null;

    public function __construct(private Redactor $redactor, ?string $logPath = null)
    {
        if ($logPath !== null) {
            // Exclusive creation refuses overwrites and existing symlinks.
            $old = umask(0077);
            try {
                $this->log = @fopen($logPath, 'x');
            } finally {
                umask($old);
            }
            if ($this->log === false) {
                throw new \RuntimeException('Cannot create --log file; choose a new path in an existing writable directory.');
            }
        }
    }

    public function add(string $id, string $status, string $message, array $details = []): void
    {
        if (!in_array($status, ['PASS', 'WARN', 'FAIL'], true)) {
            throw new \LogicException('Invalid check status.');
        }
        $check = $this->redactor->clean(compact('id', 'status', 'message', 'details'));
        $this->checks[] = $check;
        if (is_resource($this->log)) {
            $line = json_encode(['time' => gmdate('c')] + $check, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($this->log, $line) !== strlen($line) || !fflush($this->log)) {
                throw new \RuntimeException('Could not write diagnostic log.');
            }
        }
    }

    public function data(bool $verbose = false): array
    {
        $counts = ['PASS' => 0, 'WARN' => 0, 'FAIL' => 0];
        foreach ($this->checks as $check) {
            ++$counts[$check['status']];
        }
        $status = $counts['FAIL'] ? 'FAIL' : ($counts['WARN'] ? 'WARN' : 'PASS');
        $checks = array_map(static function ($check) use ($verbose) {
            if (!$verbose) {
                unset($check['details']);
            }
            return $check;
        }, $this->checks);
        return ['schema_version' => 1, 'status' => $status, 'counts' => $counts, 'checks' => $checks];
    }

    public function exitCode(bool $strict): int
    {
        return match ($this->data()['status']) { 'FAIL' => 2, 'WARN' => $strict ? 1 : 0, default => 0 };
    }

    public function render(string $format, bool $verbose): string
    {
        $data = $this->data($verbose);
        if ($format === 'json') {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        }
        $text = "MAGENTO POST-DEPLOYMENT CHECK\n\n";
        foreach ($data['checks'] as $check) {
            $text .= sprintf("[%s] %s: %s\n", $check['status'], $check['id'], $check['message']);
            if ($verbose && $check['status'] !== 'PASS' && $check['details'] !== []) {
                $text .= '  ' . str_replace("\n", "\n  ", json_encode($check['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
            }
        }
        $text .= sprintf("\nRESULT: %s | PASS %d | WARN %d | FAIL %d\n", $data['status'], ...array_values($data['counts']));
        if (!$verbose && $data['status'] !== 'PASS') {
            $text .= "Use --verbose for failure details; --log=<new-file> saves redacted diagnostics.\n";
        }
        $text .= "\nManual: verify Algolia results, navigation, cart, checkout, fees, shipping, tax, payment and order email.\n";
        return $text;
    }
}
