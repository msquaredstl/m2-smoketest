<?php
declare(strict_types=1);
namespace M2Smoke;

final class Http
{
    public function __construct(private array $config, private Budget $budget, private string $baseUrl) {}

    public static function origin(string $url): string
    {
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host']) ||
            !in_array(strtolower($p['scheme']), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) {
            throw new \RuntimeException('Invalid HTTP URL.');
        }
        $scheme = strtolower($p['scheme']);
        return $scheme . '://' . strtolower($p['host']) . ':' . ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    public static function resolve(string $base, string $reference): string
    {
        $reference = trim($reference);
        if (preg_match('/[\x00-\x20\\\\]/', $reference)) {
            throw new \RuntimeException('Invalid URL reference.');
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) {
            self::origin($reference);
            return explode('#', $reference, 2)[0];
        }
        $p = parse_url($base);
        if (str_starts_with($reference, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . explode('#', $reference, 2)[0];
        }
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $reference = explode('#', $reference, 2)[0];
        if ($reference === '') {
            return explode('#', $base, 2)[0];
        }
        if (str_starts_with($reference, '?')) {
            return $origin . ($p['path'] ?? '/') . $reference;
        }
        [$path, $query] = array_pad(explode('?', $reference, 2), 2, null);
        if (!str_starts_with($path, '/')) {
            $path = substr($p['path'] ?? '/', 0, (int) strrpos($p['path'] ?? '/', '/') + 1) . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $path = '/' . implode('/', $parts) . (str_ends_with($path, '/') && $parts !== [] ? '/' : '');
        return $origin . $path . ($query === null ? '' : '?' . $query);
    }

    public function request(string $url, bool $head = false): array
    {
        $redirects = [];
        $start = microtime(true);
        for ($i = 0; $i <= 3; ++$i) {
            if (self::origin($url) !== self::origin($this->baseUrl)) {
                throw new \RuntimeException('Cross-origin request/redirect blocked; use the canonical base_url.');
            }
            $timeout = $this->budget->timeout($this->config['timeout']);
            $headers = [];
            $body = '';
            $overflow = false;
            $ch = curl_init($url);
            $limit = $this->config['max_body_bytes'];
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, max(1, (int) ($timeout * 1000))),
                CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'm2-smoketest/0.1',
                CURLOPT_NOBODY => $head,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $headers = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $overflow = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $user = Environment::value($this->config['username_env']);
            $password = Environment::value($this->config['password_env']);
            if ($user !== null && $user !== '') {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . ($password ?: ''));
            }
            curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($overflow) {
                throw new \RuntimeException('HTTP response exceeded max_body_bytes; content was not fully checked.');
            }
            if ($error !== '') {
                throw new \RuntimeException('HTTP transport failed: ' . $error);
            }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (!isset($headers['location']) || $i === 3) {
                    throw new \RuntimeException('Invalid or excessive HTTP redirects.');
                }
                $redirects[] = $status . ' ' . $url;
                $url = self::resolve($url, $headers['location']);
                continue;
            }
            // Keep only non-sensitive headers.
            return ['url' => $url, 'status' => $status, 'body' => $body,
                'content_type' => $headers['content-type'] ?? '',
                'redirects' => $redirects, 'elapsed_ms' => (int) ((microtime(true) - $start) * 1000)];
        }
        throw new \RuntimeException('HTTP redirect limit exceeded.');
    }

    public static function pageErrors(array $response, array $page, string $requested): array
    {
        $errors = [];
        if ($response['status'] !== ($page['expect_status'] ?? 200)) {
            $errors[] = 'Unexpected HTTP status ' . $response['status'];
        }
        $expected = parse_url($requested);
        $actual = parse_url($response['url']);
        if (rtrim($expected['path'] ?? '/', '/') !== rtrim($actual['path'] ?? '/', '/') ||
            ($expected['query'] ?? '') !== ($actual['query'] ?? '')) {
            $errors[] = 'Redirect changed the requested page or query (possibly login, cart or homepage).';
        }
        if (!preg_match('~(?:text/html|application/xhtml\+xml)~i', $response['content_type'])) {
            $errors[] = 'Response is not HTML.';
        }
        foreach ($page['contains'] ?? [] as $needle) {
            if (stripos($response['body'], $needle) === false) {
                $errors[] = 'Missing required content assertion.';
            }
        }
        $forbidden = array_merge([
            'There has been an error processing your request',
            'Exception printing is disabled',
            'Service Temporarily Unavailable',
        ], $page['not_contains'] ?? []);
        foreach ($forbidden as $needle) {
            if (stripos($response['body'], $needle) !== false) {
                $errors[] = 'Application error or forbidden content detected.';
            }
        }
        return array_values(array_unique($errors));
    }

    public static function assets(string $html, string $pageUrl): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($doc);
            $base = $pageUrl;
            $baseTag = $xpath->query('//base[@href]')->item(0);
            if ($baseTag !== null) {
                $base = self::resolve($pageUrl, $baseTag->getAttribute('href'));
            }
            $assets = [];
            foreach ($xpath->query('//script[@src] | //link[@href]') as $node) {
                if ($node->nodeName === 'link' &&
                    !in_array('stylesheet', preg_split('/\s+/', strtolower($node->getAttribute('rel'))), true)) {
                    continue;
                }
                $reference = $node->getAttribute($node->nodeName === 'script' ? 'src' : 'href');
                if ($reference === '' || str_starts_with($reference, 'data:')) {
                    continue;
                }
                $url = self::resolve($base, $reference);
                $assets[$url] = $node->nodeName === 'script' ? 'js' : 'css';
            }
            return $assets;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
