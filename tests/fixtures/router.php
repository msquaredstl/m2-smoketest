<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/slow') {
    sleep(2);
}
if ($path === '/redirect') {
    header('Location: /');
    exit;
}
if ($path === '/external') {
    header('Location: https://example.invalid/');
    exit;
}
if ($path === '/style.css') {
    header('Content-Type: text/css');
    echo 'body { color: black; }';
    exit;
}
if ($path === '/app.js') {
    header('Content-Type: application/javascript');
    echo 'console.log("fixture");';
    exit;
}
if ($path === '/head-fallback.js') {
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        http_response_code(405);
    }
    header('Content-Type: application/javascript');
    echo 'void 0;';
    exit;
}
if ($path === '/missing.css') {
    http_response_code(404);
    exit;
}
if ($path === '/large') {
    echo str_repeat('x', 10000);
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
if ($path === '/error') {
    echo '<html>There has been an error processing your request</html>';
} elseif ($path === '/assets-broken') {
    echo '<html><link rel="stylesheet" href="/missing.css"><script src="/fake.js"></script></html>';
} else {
    echo '<html><link rel="stylesheet" href="/style.css"><script src="/app.js"></script><script src="/head-fallback.js"></script>Known product</html>';
}
