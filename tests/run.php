<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use M2Smoke\{Budget, Commands, Config, Environment, Http, Logs, Options, Redactor, Report};
use Symfony\Component\Process\Process;

$tests = $failed = 0;
function check(bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function throws(callable $fn, string $needle = ''): void {
    try { $fn(); } catch (Throwable $e) {
        check($needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected exception.');
}
function test(string $name, callable $fn): void {
    global $tests, $failed;
    ++$tests;
    try { $fn(); echo "PASS $name\n"; }
    catch (Throwable $e) { ++$failed; echo "FAIL $name: {$e->getMessage()}\n"; }
}

$temp = sys_get_temp_dir() . '/m2-smoke-tests-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
$fixture = __DIR__ . '/fixtures/magento';

test('default configuration validates', function () {
    Config::validate(Config::load(null));
});
test('options accept flags and both value syntaxes', function () {
    $o = Options::parse(['--url=https://example.test', '--since', '15 minutes', '-v', '--fail-on-warning', '--env', '.env.stage']);
    check($o['verbose'] && $o['fail-on-warning'] && $o['since'] === '15 minutes' && $o['env'] === '.env.stage');
});
test('unknown/missing options fail', function () {
    throws(fn () => Options::parse(['--repair']));
    throws(fn () => Options::parse(['--url']));
    throws(fn () => Options::parse(['--format=xml']));
});
test('root discovery walks parent directories', function () use ($fixture) {
    check(Config::root(null, $fixture . '/app') === realpath($fixture));
    throws(fn () => Config::root(__DIR__, __DIR__), 'root not found');
});
test('same-origin URLs normalize default ports', function () {
    check(Http::origin('https://EXAMPLE.test/a') === Http::origin('https://example.test:443/b'));
    check(Http::origin('https://example.test') !== Http::origin('http://example.test'));
    throws(fn () => Http::origin('file:///etc/passwd'));
    throws(fn () => Http::origin('https://user:pass@example.test'));
});
test('URL resolution handles paths queries and fragments', function () {
    check(Http::resolve('https://example.test/store/page', '../app.js?x=1') === 'https://example.test/app.js?x=1');
    check(Http::resolve('https://example.test/store/page', '?q=test') === 'https://example.test/store/page?q=test');
    check(Http::resolve('https://example.test/a', '//example.test/b#fragment') === 'https://example.test/b');
    throws(fn () => Http::resolve('https://example.test/', "https://bad.test\\evil"));
});
test('dotenv config supports unlimited categorized URLs and OS overrides', function () use ($temp) {
    $content = "M2_SMOKE_BASE_URL=https://file.example.test\nM2_SMOKE_PAGE_HOMEPAGE_URL=/\n";
    for ($i = 0; $i < 25; ++$i) {
        $content .= "M2_SMOKE_PAGE_ITEM_$i" . "_URL=/product-$i.html\nM2_SMOKE_PAGE_ITEM_$i" . "_TYPE=product\n";
    }
    file_put_contents("$temp/test.env", $content);
    putenv('M2_SMOKE_BASE_URL=https://os.example.test');
    Environment::load("$temp/test.env", true);
    $c = Environment::apply(Config::load(null));
    Config::validate($c);
    check($c['base_url'] === 'https://os.example.test');
    check($c['pages']['item_24']['type'] === 'product' && count($c['pages']) === 29);
    putenv('M2_SMOKE_BASE_URL');
    foreach (array_keys($_ENV + $_SERVER) as $key) {
        if (str_starts_with((string) $key, 'M2_SMOKE_')) { unset($_ENV[$key], $_SERVER[$key]); }
    }
});
test('invalid category type is rejected', function () {
    $c = Config::load(null); $c['pages']['homepage']['type'] = 'typo';
    throws(fn () => Config::validate($c), 'page type');
});
test('absolute page URL cannot silently cross environments', function () {
    $c = Config::load(null); $c['base_url'] = 'https://stage.example.test';
    $c['pages']['homepage']['path'] = 'https://production.example.test/';
    throws(fn () => Config::validate($c), 'origin');
});
test('configuration rejects unknown keys and invalid thresholds', function () use ($temp) {
    file_put_contents("$temp/invalid.yml", "cron:\n  stale_after_minute: 5\n");
    throws(fn () => Config::load("$temp/invalid.yml"), 'Unknown keys');
    $c = Config::load(null); $c['http']['timeout'] = 0;
    throws(fn () => Config::validate($c), 'positive integer');
});
test('YAML lists replace defaults rather than merge by index', function () use ($temp) {
    file_put_contents("$temp/lists.yml", "logs:\n  files: [var/log/custom.log]\n");
    $c = Config::load("$temp/lists.yml");
    check($c['logs']['files'] === ['var/log/custom.log']);
});
test('since handles relative and explicit dates', function () {
    check(abs(time() - Config::since('15 minutes', 1)->getTimestamp() - 900) < 2);
    check(Config::since('2020-01-01T00:00:00Z', 1)->format('Y') === '2020');
    throws(fn () => Config::since('tomorrow', 1));
    throws(fn () => Config::since('2020-02-31T00:00:00Z', 1));
});
test('indexer parser handles table including suspended and unknown states', function () {
    $rows = Commands::indexers("| ID | Title | Status | Update On |\n| catalogsearch_fulltext | Catalog Search | Ready | Schedule |\n| customer_grid | Customer Grid | Suspended | Save |\n");
    check($rows === ['catalogsearch_fulltext' => 'Ready', 'customer_grid' => 'Suspended']);
    throws(fn () => Commands::indexers('unrecognized output'));
});
test('cache parser rejects unrecognized format', function () {
    check(Commands::caches("Current status:\n config: 1\n full_page: 0\n") === ['config' => '1', 'full_page' => '0']);
    throws(fn () => Commands::caches('Nothing to see'));
});
test('Magento command allowlist rejects writes', function () use ($fixture) {
    $commands = new Commands($fixture, new Budget(10), 5);
    foreach (['cache:flush', 'setup:upgrade', 'indexer:reindex', 'cron:run'] as $command) {
        throws(fn () => $commands->magento($command), 'allowlist');
    }
});
test('subprocess errors and timeouts preserve diagnostic status', function () use ($fixture) {
    $commands = new Commands($fixture, new Budget(10), 1);
    $r = $commands->execute([PHP_BINARY, '-r', 'fwrite(STDERR, "broken"); exit(9);']);
    check($r['exit'] === 9 && $r['stderr'] === 'broken');
    $r = $commands->execute([PHP_BINARY, '-r', 'sleep(3);']);
    check($r['timeout'] && $r['exit'] === 124);
});
test('Magento subprocesses do not inherit utility credentials or autoloaded classes', function () use ($fixture) {
    $_ENV['M2_SMOKE_HTTP_PASSWORD'] = 'must-not-leak';
    $_SERVER['M2_SMOKE_HTTP_PASSWORD'] = 'must-not-leak';
    try {
        $commands = new Commands($fixture, new Budget(10), 5);
        $r = $commands->execute([PHP_BINARY, '-r',
            'echo json_encode([getenv("M2_SMOKE_HTTP_PASSWORD"), class_exists("M2Smoke\\\\Runner", false)]);']);
        check($r['exit'] === 0 && json_decode($r['stdout'], true) === [false, false]);
    } finally {
        unset($_ENV['M2_SMOKE_HTTP_PASSWORD'], $_SERVER['M2_SMOKE_HTTP_PASSWORD']);
    }
});
test('command output is bounded and truncation detected', function () use ($fixture) {
    $commands = new Commands($fixture, new Budget(10), 5);
    $r = $commands->execute([PHP_BINARY, '-r', 'echo str_repeat("x", 100000);']);
    check($r['truncated'] && strlen($r['stdout']) <= 65536);
});
test('redactor removes known credentials header values URL queries and terminal escapes', function () {
    $r = new Redactor(['actual-secret']);
    $value = $r->clean("actual-secret password=hidden\nAuthorization: Bearer abc\nhttps://user:pass@host.test/a?random=private\n\x1b[31mtext");
    foreach (['actual-secret', 'hidden', 'Bearer abc', 'user:pass', 'private', "\x1b"] as $secret) {
        check(!str_contains($value, $secret), $value);
    }
    $array = $r->clean(['https://host.test/a?secret=x' => 'failed', 'password' => 'anything']);
    check(!str_contains(json_encode($array), 'secret=x') && $array['password'] === '[REDACTED]');
});
test('warnings exit zero unless strict and failures exit two', function () {
    $r = new Report(new Redactor());
    $r->add('check', 'WARN', 'Warning', ['extra' => 'details']);
    check($r->exitCode(false) === 0 && $r->exitCode(true) === 1);
    check(!isset($r->data()['checks'][0]['details']));
    check(isset($r->data(true)['checks'][0]['details']));
    $r->add('second', 'FAIL', 'Failure');
    check($r->exitCode(false) === 2);
    check(json_decode($r->render('json', true), true)['status'] === 'FAIL');
});
test('diagnostic log is exclusive private and redacted', function () use ($temp) {
    $r = new Report(new Redactor(['secret-value']), "$temp/report.jsonl");
    $r->add('test', 'FAIL', 'Oops', ['error' => 'secret-value']);
    $line = file_get_contents("$temp/report.jsonl");
    check(!str_contains($line, 'secret-value') && str_contains($line, '[REDACTED]'));
    check((fileperms("$temp/report.jsonl") & 0077) === 0);
    throws(fn () => new Report(new Redactor(), "$temp/report.jsonl"), 'Cannot create');
});
test('recent logs exclude old errors and preserve multiline failure samples', function () {
    $text = "[2020-01-01T00:00:00+00:00] main.ERROR: old\n" .
        "[" . gmdate('c') . "] main.CRITICAL: new\nstack line\n";
    $r = Logs::scan($text, new DateTimeImmutable('-15 minutes'));
    check($r['status'] === 'FAIL' && $r['details']['errors'] === 1);
    check(str_contains($r['details']['excerpts'][0], 'stack line'));
});
test('bounded or unrecognized logs warn instead of claiming clean', function () {
    $r = Logs::scan("[" . gmdate('c') . "] main.INFO: recent\n", new DateTimeImmutable('-15 minutes'), true);
    check($r['status'] === 'WARN' && $r['details']['incomplete']);
    check(Logs::scan('unknown timestamp content', new DateTimeImmutable('-15 minutes'))['status'] === 'WARN');
    check(Logs::inspect('/no/such/log', new DateTimeImmutable(), 100)['status'] === 'WARN');
});
test('empty existing log passes', function () use ($temp) {
    file_put_contents("$temp/empty.log", '');
    check(Logs::inspect("$temp/empty.log", new DateTimeImmutable(), 100)['status'] === 'PASS');
});
test('HTML error pages and redirects cannot masquerade as healthy product pages', function () {
    $r = ['status' => 200, 'body' => '<html>There has been an error processing your request</html>',
        'content_type' => 'text/html', 'url' => 'https://example.test/'];
    check(count(Http::pageErrors($r, [], 'https://example.test/product')) === 2);
    $r['body'] = 'Hello';
    check(Http::pageErrors($r, ['contains' => ['product']], 'https://example.test/') !== []);
});
test('asset discovery deduplicates URLs honors base tags and ignores non-stylesheet links', function () {
    $assets = Http::assets('<html><base href="/static/"><script src="app.js"></script><script src="app.js"></script><link rel="stylesheet preload" href="styles.css"><link rel="icon" href="icon.png"></html>', 'https://example.test/');
    check($assets === ['https://example.test/static/app.js' => 'js', 'https://example.test/static/styles.css' => 'css']);
});

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = new Process([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/router.php']);
$server->start();
try {
    $ready = false;
    for ($i = 0; $i < 40; ++$i) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    check($ready, 'Fixture HTTP server did not start.');
    $base = 'http://' . $address;
    test('real HTTP client checks healthy pages and blocks cross-origin redirects', function () use ($base) {
        $h = new Http(Config::load(null)['http'], new Budget(10), $base);
        $r = $h->request($base . '/');
        check($r['status'] === 200 && str_contains($r['body'], 'Known product'));
        throws(fn () => $h->request($base . '/external'), 'Cross-origin');
        $r = $h->request($base . '/redirect');
        check(Http::pageErrors($r, [], $base . '/redirect') !== []);
    });
    test('HTTP responses are byte bounded', function () use ($base) {
        $c = Config::load(null)['http']; $c['max_body_bytes'] = 100;
        throws(fn () => (new Http($c, new Budget(10), $base))->request($base . '/large'), 'max_body_bytes');
    });
    test('CLI integration executes diagnostics including cron assets and JSON', function () use ($fixture, $base, $temp) {
        $env = "$temp/integration.env";
        file_put_contents($env, "M2_SMOKE_BASE_URL=$base\nM2_SMOKE_HTTP_PASSWORD=fixture-secret\n");
        $cmd = [PHP_BINARY, dirname(__DIR__) . '/bin/m2-smoketest', '--root=' . $fixture, '--env=' . $env, '--format=json', '--verbose'];
        $p = new Process($cmd); $p->mustRun();
        $json = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        check($json['status'] === 'WARN'); // Deliberately unconfigured pages/missing logs.
        $byId = array_column($json['checks'], null, 'id');
        check($byId['magento.cron']['status'] === 'PASS');
        check($byId['assets.homepage.homepage']['status'] === 'PASS');
        check($byId['http.category.category']['status'] === 'WARN');
        $p = new Process([...$cmd, '--fail-on-warning']); $p->run();
        check($p->getExitCode() === 1);
        $p = new Process([...$cmd, '--log=' . $temp . '/integration.jsonl'], null, ['SMOKE_TEST_SCENARIO' => 'broken']);
        $p->run();
        check($p->getExitCode() === 2 && !str_contains($p->getOutput(), 'fixture-secret'));
        $json = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $byId = array_column($json['checks'], null, 'id');
        check($byId['magento.cache']['details']['exit'] === 1);
        check(!str_contains(file_get_contents($temp . '/integration.jsonl'), 'fixture-secret'));
    });
    test('broken assets fail and verbose diagnostics name failed requests', function () use ($fixture, $base, $temp) {
        file_put_contents("$temp/broken.env", "M2_SMOKE_BASE_URL=$base\nM2_SMOKE_PAGE_HOMEPAGE_URL=/assets-broken\n");
        $p = new Process([PHP_BINARY, dirname(__DIR__) . '/bin/m2-smoketest', '--root=' . $fixture, '--env=' . $temp . '/broken.env', '--format=json', '-v']);
        $p->run();
        check($p->getExitCode() === 2);
        $json = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $byId = array_column($json['checks'], null, 'id');
        check($byId['assets.homepage.homepage']['status'] === 'FAIL');
        check(count($byId['assets.homepage.homepage']['details']['failures']) === 2);
    });
    test('configuration errors return three without echoing secret dotenv contents', function () use ($fixture, $temp) {
        file_put_contents("$temp/bad.env", "M2_SMOKE_HTTP_PASSWORD=\"super-private\n");
        $p = new Process([PHP_BINARY, dirname(__DIR__) . '/bin/m2-smoketest', '--root=' . $fixture, '--env=' . $temp . '/bad.env', '--format=json']);
        $p->run();
        check($p->getExitCode() === 3 && !str_contains($p->getOutput(), 'super-private'));
        check(json_decode($p->getOutput(), true)['status'] === 'ERROR');
    });
    test('HTTP timeout is enforced', function () use ($base) {
        $c = Config::load(null)['http']; $c['timeout'] = 1;
        throws(fn () => (new Http($c, new Budget(10), $base))->request($base . '/slow'), 'transport failed');
    });
} finally {
    $server->stop(0.1);
    // Only remove the random temporary directory created by this test process.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temp);
}
echo "\n$tests tests, $failed failures\n";
exit($failed ? 1 : 0);
