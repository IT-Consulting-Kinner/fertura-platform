<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\TrustRotationService;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Rolling trust-anchor rotation (Phase 6 Increment 4): the new anchor goes
 * open-ended, the old one gets an expiry, and the returned snapshot restores both
 * validity windows on rollback. Transactional + audited.
 */
class TrustRotationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM trust_anchors WHERE key_id LIKE 'zztest-tr-%'");
    }

    private function seed(string $keyId): void
    {
        (new TrustStore())->addAnchor($keyId, base64_encode(random_bytes(32)), 'root');
    }

    private function validTo(string $keyId): ?string
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT valid_to FROM trust_anchors WHERE key_id = :k',
            ['k' => $keyId],
        )->fetch('assoc');

        return $row !== false && $row['valid_to'] !== null ? (string)$row['valid_to'] : null;
    }

    public function testRotateExpiresOldAndRestoreReverts(): void
    {
        $old = 'zztest-tr-old-' . bin2hex(random_bytes(2));
        $new = 'zztest-tr-new-' . bin2hex(random_bytes(2));
        $this->seed($old);
        $this->seed($new);
        $this->assertNull($this->validTo($old)); // fresh anchors are open-ended

        $snapshot = (new TrustRotationService())->rotate($old, $new, 30);
        $this->assertNull($this->validTo($new)); // new stays open-ended
        $this->assertNotNull($this->validTo($old)); // old now expires after the overlap

        // Action-own rollback restores both windows.
        (new TrustRotationService())->restore($snapshot);
        $this->assertNull($this->validTo($old));
    }

    public function testRotateThrowsWhenNewAnchorMissing(): void
    {
        $old = 'zztest-tr-old-' . bin2hex(random_bytes(2));
        $this->seed($old);

        $this->expectException(RuntimeException::class);
        (new TrustRotationService())->rotate($old, 'zztest-tr-absent', 30);
    }

    public function testRotateThrowsWhenOldAnchorAbsent(): void
    {
        // New anchor present, old key typo'd/absent -> the old-anchor UPDATE matches
        // nothing -> the rotation must abort (not silently half-apply).
        $new = 'zztest-tr-new-' . bin2hex(random_bytes(2));
        $this->seed($new);

        $this->expectException(RuntimeException::class);
        (new TrustRotationService())->rotate('zztest-tr-no-such-old', $new, 30);
    }

    public function testRotateThrowsWithEmptyIds(): void
    {
        $this->expectException(RuntimeException::class);
        (new TrustRotationService())->rotate('', 'x', 30);
    }
}
