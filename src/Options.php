<?php
declare(strict_types=1);
namespace M2Smoke;

final class Options
{
    public static function parse(array $args): array
    {
        $out = ['format' => 'text', 'verbose' => false, 'fail-on-warning' => false, 'help' => false];
        $flags = ['verbose', 'fail-on-warning', 'help'];
        $values = ['root', 'config', 'env', 'url', 'format', 'log', 'since'];
        for ($i = 0; $i < count($args); ++$i) {
            $arg = match ($args[$i]) { '-v' => '--verbose', '-h' => '--help', default => $args[$i] };
            if (!str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException('Unexpected argument; use --help.');
            }
            [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
            if (in_array($key, $flags, true) && $value === null) {
                $out[$key] = true;
            } elseif (in_array($key, $values, true)) {
                $value ??= $args[++$i] ?? null;
                if ($value === null || $value === '' || str_starts_with($value, '--')) {
                    throw new \InvalidArgumentException("Option --$key requires a value.");
                }
                $out[$key] = $value;
            } else {
                throw new \InvalidArgumentException('Unknown option; use --help.');
            }
        }
        if (!in_array($out['format'], ['text', 'json'], true)) {
            throw new \InvalidArgumentException('--format must be text or json.');
        }
        return $out;
    }
}
