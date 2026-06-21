<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\MaintenanceService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Maintenance leading-state store (core.maintenance_session) — Phase 1.
 *
 * Proves the three guarantees the gate (later phases) relies on: the reader is
 * uncached and sees writes immediately, {@see MaintenanceService::engage()} admits
 * exactly one open session (single-operator lockout), and {@see release()} reopens
 * the slot. The table is platform-global (no RLS), so the default connection reads
 * and writes it directly; we wipe it around each test for isolation.
 */
class MaintenanceServiceTest extends TestCase
{
    private MaintenanceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new MaintenanceService();
        ConnectionManager::get('default')->execute('DELETE FROM core.maintenance_session');
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM core.maintenance_session');
        parent::tearDown();
    }

    public function testEngageThenActiveSession(): void
    {
        $this->assertFalse($this->svc->isActive());
        $this->assertNull($this->svc->activeSession());

        $session = $this->svc->engage('11111111-1111-7111-8111-111111111111', 'hash-a', 'patching');
        $this->assertNotNull($session);
        $this->assertSame('active', $session['status']);
        $this->assertSame('patching', $session['reason']);

        $active = $this->svc->activeSession();
        $this->assertNotNull($active);
        $this->assertSame($session['id'], $active['id']);
        $this->assertSame('hash-a', $active['allow_token_hash']);
        $this->assertTrue($this->svc->isActive());
    }

    public function testEngageIsSingleOperator(): void
    {
        $first = $this->svc->engage(null, 'hash-1');
        $this->assertNotNull($first);

        // A second activator loses the ON CONFLICT race and gets null.
        $second = $this->svc->engage(null, 'hash-2');
        $this->assertNull($second);

        $count = ConnectionManager::get('default')
            ->execute("SELECT count(*) AS n FROM core.maintenance_session WHERE status <> 'closed'")
            ->fetch('assoc');
        $this->assertSame(1, (int)$count['n']);
    }

    public function testReleaseReopensTheSlot(): void
    {
        $first = $this->svc->engage(null, 'hash-1');
        $this->assertNotNull($first);

        $this->svc->release($first['id']);
        $this->assertFalse($this->svc->isActive());

        // With the slot free a fresh operator can engage again.
        $second = $this->svc->engage(null, 'hash-2');
        $this->assertNotNull($second);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function testHeartbeatAdvancesTimestamp(): void
    {
        $session = $this->svc->engage(null, 'hash-1');
        $this->assertNotNull($session);

        ConnectionManager::get('default')->execute(
            "UPDATE core.maintenance_session SET heartbeat_at = now() - interval '1 hour' WHERE id = :id",
            ['id' => $session['id']],
        );
        $this->svc->heartbeat($session['id']);

        $row = ConnectionManager::get('default')->execute(
            'SELECT heartbeat_at > opened_at - interval \'1 minute\' AS fresh FROM core.maintenance_session WHERE id = :id',
            ['id' => $session['id']],
        )->fetch('assoc');
        $this->assertTrue((bool)$row['fresh']);
    }
}
