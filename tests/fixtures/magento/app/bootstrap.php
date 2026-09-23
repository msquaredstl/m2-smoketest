<?php
declare(strict_types=1);
namespace Magento\Framework\App;
if (!defined('BP')) {
    define('BP', dirname(__DIR__));
}
final class Bootstrap
{
    public static function create($root, $server): self { return new self(); }
    public function getObjectManager(): self { return $this; }
    public function get(string $class): ResourceConnection { return new ResourceConnection(); }
}
final class ResourceConnection
{
    public function getConnection(): self { return $this; }
    public function getTableName(string $table): string { return 'prefix_' . $table; }
    public function quoteIdentifier(string $table): string { return $table; }
    public function fetchRow(string $sql, array $bind): array
    {
        if (!str_starts_with(trim($sql), 'SELECT') || !str_contains($sql, 'prefix_cron_schedule')) {
            throw new \RuntimeException('Only prefixed cron SELECT is expected.');
        }
        return ['last_success' => gmdate('Y-m-d H:i:s'), 'age_seconds' => '30', 'failures' => '0', 'overdue' => '0'];
    }
}
