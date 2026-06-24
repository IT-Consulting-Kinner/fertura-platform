<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Backup;

use App\Service\Backup\TenantBackupPlanService;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;

/**
 * Inc 7b: next-run computation for backup plans (pure, deterministic). 2026-06-24
 * is a Wednesday (PG/PHP dow = 3).
 */
class TenantBackupPlanServiceTest extends TestCase
{
    private TenantBackupPlanService $svc;
    private DateTimeImmutable $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenantBackupPlanService();
        $this->from = new DateTimeImmutable('2026-06-24 10:00:00'); // Wednesday
    }

    private function next(string $cadence, int $hour, ?int $wd, ?int $dom): string
    {
        return $this->svc->computeNextRun($cadence, $hour, $wd, $dom, $this->from)->format('Y-m-d H:i:s');
    }

    public function testDaily(): void
    {
        // 02:00 already passed today -> tomorrow; 14:00 still ahead -> today.
        $this->assertSame('2026-06-25 02:00:00', $this->next('daily', 2, null, null));
        $this->assertSame('2026-06-24 14:00:00', $this->next('daily', 14, null, null));
    }

    public function testWeekly(): void
    {
        // Target Monday (1) from Wednesday -> the coming Monday.
        $this->assertSame('2026-06-29 03:00:00', $this->next('weekly', 3, 1, null));
        // Target Wednesday (3) at a still-future hour -> today.
        $this->assertSame('2026-06-24 14:00:00', $this->next('weekly', 14, 3, null));
    }

    public function testMonthly(): void
    {
        // Day 1 already passed -> next month; day 28 still ahead -> this month.
        $this->assertSame('2026-07-01 02:00:00', $this->next('monthly', 2, null, 1));
        $this->assertSame('2026-06-28 02:00:00', $this->next('monthly', 2, null, 28));
    }
}
