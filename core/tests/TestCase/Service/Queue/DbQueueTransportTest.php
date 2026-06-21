<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Queue;

use App\Service\Queue\DbQueueTransport;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of the DB driver for the job-queue transport (#10): push/reserve/ack/
 * release/size using `FOR UPDATE SKIP LOCKED`.
 */
class DbQueueTransportTest extends TestCase
{
    public function testPushReserveAckRelease(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $t = new DbQueueTransport();
            $this->assertSame('db', $t->name());

            $t->push('zzq', ['n' => 1]);
            $t->push('zzq', ['n' => 2]);
            $this->assertSame(2, $t->size('zzq'));

            $res = $t->reserve('zzq', 1);
            $this->assertCount(1, $res);
            $this->assertSame(1, $res[0]['payload']['n']);
            $this->assertSame(1, $t->size('zzq'), 'einer reserviert -> einer bereit');

            $t->ack('zzq', $res[0]['id']); // permanently removed

            $res2 = $t->reserve('zzq', 5);
            $this->assertCount(1, $res2);
            $t->release('zzq', $res2[0]['id']); // back to ready
            $this->assertSame(1, $t->size('zzq'));
        } finally {
            $conn->rollback();
        }
    }

    public function testConcurrentReserveIsDisjoint(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $t = new DbQueueTransport();
            for ($i = 0; $i < 4; $i++) {
                $t->push('zzq2', ['i' => $i]);
            }
            $a = $t->reserve('zzq2', 2);
            $b = $t->reserve('zzq2', 2);
            $idsA = array_column($a, 'id');
            $idsB = array_column($b, 'id');
            $this->assertSame([], array_intersect($idsA, $idsB), 'reservierte Jobs sind disjunkt');
            $this->assertSame(0, $t->size('zzq2'), 'alle reserviert');
        } finally {
            $conn->rollback();
        }
    }

    public function testReclaimStuckResetsStaleReservedJobsOnly(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $t = new DbQueueTransport();
            $t->push('zzq3', ['k' => 'a']);
            $t->push('zzq3', ['k' => 'b']);
            $res = $t->reserve('zzq3', 2); // both reserved (reserved_at = now())
            $this->assertCount(2, $res);
            $this->assertSame(0, $t->size('zzq3'), 'beide reserviert -> keiner bereit');

            // Age ONE reservation past the window (a crashed consumer); the other is fresh.
            $conn->execute(
                "UPDATE job_queue SET reserved_at = now() - interval '600 seconds' WHERE id = :id",
                ['id' => $res[0]['id']],
            );

            // Only the stale reservation is reclaimed back to 'ready'.
            $this->assertSame(1, $t->reclaimStuck(300));
            $this->assertSame(1, $t->size('zzq3'), 'der zurueckgesetzte ist wieder bereit');
            // ...and it is the stale one (the fresh reservation stays reserved).
            $reReserved = $t->reserve('zzq3', 5);
            $this->assertSame([$res[0]['id']], array_column($reReserved, 'id'));
        } finally {
            $conn->rollback();
        }
    }
}
