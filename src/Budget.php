<?php
declare(strict_types=1);
namespace M2Smoke;

final class Budget
{
    private float $end;
    public function __construct(int $seconds) { $this->end = microtime(true) + $seconds; }
    public function remaining(): float { return max(0.0, $this->end - microtime(true)); }
    public function timeout(int $limit): float
    {
        if ($this->remaining() < 0.1) {
            throw new \RuntimeException('Run time budget exhausted; remaining checks could not complete.');
        }
        return min((float) $limit, $this->remaining());
    }
}
