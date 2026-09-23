<?php
declare(strict_types=1);
namespace M2Smoke;

final class Redactor
{
    public function __construct(private array $secrets = []) {}

    public function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? $this->clean($key) : $key;
                $out[$safeKey] = is_string($key) && preg_match('/password|secret|token|authorization|cookie|api.?key/i', $key)
                    ? '[REDACTED]' : $this->clean($item);
            }
            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }
        foreach ($this->secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $value = str_replace([$secret, rawurlencode($secret)], '[REDACTED]', $value);
            }
        }
        $value = preg_replace('~(https?://)[^/\s@]+@~i', '$1[REDACTED]@', $value);
        // Never print URL queries/fragments: tokens may have arbitrary parameter names.
        $value = preg_replace('~(https?://[^\s?#"<>]+)[?#][^\s"<>]*~i', '$1?[REDACTED]', $value);
        $value = preg_replace('/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*:[^\r\n]*/i', '$1: [REDACTED]', $value);
        $value = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key)\b[\'"]?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[REDACTED]', $value);
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[EMAIL REDACTED]', $value);
        // Remove terminal escape sequences and control characters from external output.
        $value = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $value);
        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $value);
    }
}
