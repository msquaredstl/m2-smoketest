<?php
declare(strict_types=1);

// Isolated process: load Magento's autoloader, never the utility's vendor tree.
ini_set('display_errors', 'stderr');
$root = $argv[1] ?? '';
$lookback = (int) ($argv[2] ?? 60);
$stale = (int) ($argv[3] ?? 10);
try {
    require $root . '/app/bootstrap.php';
    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
    $resource = $bootstrap->getObjectManager()->get(\Magento\Framework\App\ResourceConnection::class);
    $db = $resource->getConnection();
    $table = $db->quoteIdentifier($resource->getTableName('cron_schedule'));
    // Magento cron timestamps are UTC. Database UTC_TIMESTAMP avoids host clock skew.
    $row = $db->fetchRow(
        "SELECT
            MAX(CASE WHEN status = 'success' THEN finished_at END) AS last_success,
            TIMESTAMPDIFF(SECOND, MAX(CASE WHEN status = 'success' THEN finished_at END), UTC_TIMESTAMP()) AS age_seconds,
            SUM(CASE WHEN status IN ('error','missed') AND scheduled_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) THEN 1 ELSE 0 END) AS failures,
            SUM(CASE WHEN status = 'pending' AND scheduled_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) THEN 1 ELSE 0 END) AS overdue
         FROM $table",
        [$lookback, $stale]
    );
    echo "\n__M2SMOKE__" . json_encode($row, JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $error) {
    // Do not expose SQL, connection strings, env.php values or exception traces.
    fwrite(STDERR, 'Cron read failed (' . get_class($error) . '); check Magento bootstrap and database access.' . "\n");
    exit(2);
}
