<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\Security\TrustStore;
use App\Service\System\TrustRotateHandler;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The trust-rotate handler's verify + rollback (Phase 6 Increment 4). The full
 * execute → rollback round-trip on the anchors is covered by
 * {@see \App\Test\TestCase\Service\Security\TrustRotationServiceTest}.
 */
class TrustRotateHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM trust_anchors WHERE key_id LIKE 'zztest-trh-%'");
        parent::tearDown();
    }

    private function seed(string $keyId, ?string $validTo = null): void
    {
        (new TrustStore())->addAnchor($keyId, base64_encode(random_bytes(32)), 'root', null, null, null, null, $validTo);
    }

    public function testVerifyOkForOpenEndedActiveAnchor(): void
    {
        $new = 'zztest-trh-ok-' . bin2hex(random_bytes(2));
        $this->seed($new); // active, valid_to NULL
        $this->assertTrue((new TrustRotateHandler())->verify(['new_key_id' => $new])['ok']);
    }

    public function testVerifyFailsWhenNewAnchorStillHasAnExpiry(): void
    {
        $new = 'zztest-trh-exp-' . bin2hex(random_bytes(2));
        $this->seed($new, '2099-01-01T00:00:00Z'); // valid_to set -> not open-ended
        $this->assertFalse((new TrustRotateHandler())->verify(['new_key_id' => $new])['ok']);
    }

    public function testVerifyFailsForMissingAnchor(): void
    {
        $this->assertFalse((new TrustRotateHandler())->verify(['new_key_id' => 'zztest-trh-absent'])['ok']);
    }

    public function testRollbackIsNoOpWithEmptySnapshot(): void
    {
        (new TrustRotateHandler())->rollback([]); // execute never ran -> nothing to restore
        $this->assertTrue(true);
    }
}
