<?php
declare(strict_types=1);
namespace M2Smoke;

final class Logs
{
    public static function inspect(string $file, \DateTimeImmutable $since, int $limit): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return ['status' => 'WARN', 'message' => 'Log missing or unreadable; not verified.', 'details' => []];
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
                throw new \RuntimeException('Log must be a regular file.');
            }
            $size = $stat['size'];
            $truncated = $size > $limit;
            if ($truncated) {
                fseek($handle, -$limit, SEEK_END);
                fgets($handle); // Ignore the first partial record.
            }
            $text = stream_get_contents($handle, $limit);
            if ($text === false) {
                throw new \RuntimeException('Cannot read log.');
            }
        } finally {
            fclose($handle);
        }
        return self::scan($text, $since, $truncated);
    }

    public static function scan(string $text, \DateTimeImmutable $since, bool $truncated = false): array
    {
        $errors = $warnings = $unparsed = 0;
        $earliest = null;
        $samples = [];
        // Magento/Monolog records start with [ISO-8601 timestamp].
        foreach (preg_split('/(?=^\[)/m', $text, -1, PREG_SPLIT_NO_EMPTY) as $record) {
            if (!preg_match('/^\[([^\]]+)\]/', $record, $match)) {
                if (trim($record) !== '') {
                    ++$unparsed;
                }
                continue;
            }
            try {
                $date = new \DateTimeImmutable($match[1], new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                ++$unparsed;
                continue;
            }
            $earliest = $earliest === null || $date < $earliest ? $date : $earliest;
            if ($date < $since) {
                continue;
            }
            if (preg_match('/^\[[^\]]+\]\s+\S+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/i', $record)) {
                ++$errors;
            } elseif (preg_match('/^\[[^\]]+\]\s+\S+\.WARNING:/i', $record)) {
                ++$warnings;
            } else {
                continue;
            }
            if (count($samples) < 5) {
                $samples[] = substr($record, 0, 1500);
            }
        }
        $incomplete = $truncated && ($earliest === null || $earliest > $since);
        $status = $errors ? 'FAIL' : (($warnings || $unparsed || $incomplete) ? 'WARN' : 'PASS');
        return [
            'status' => $status,
            'message' => "$errors error(s), $warnings warning(s) since " . $since->format('c') .
                ($incomplete ? '; byte limit prevents full lookback coverage.' : '') .
                ($unparsed ? "; $unparsed unrecognized record(s)." : ''),
            'details' => ['errors' => $errors, 'warnings' => $warnings, 'unparsed' => $unparsed,
                'incomplete' => $incomplete, 'excerpts' => $samples],
        ];
    }
}
