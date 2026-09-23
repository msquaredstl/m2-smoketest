<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$failed = false;
foreach (['src', 'helpers', 'tests', 'bin'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' && $file->getFilename() !== 'm2-smoketest') {
            continue;
        }
        $proc = proc_open([PHP_BINARY, '-l', $file->getPathname()], [1 => STDOUT, 2 => STDERR], $pipes);
        $failed = proc_close($proc) !== 0 || $failed;
    }
}
exit($failed ? 1 : 0);
