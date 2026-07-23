<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Peer-review F5: a privileged token scope must not exceed the minting user's own
 * permissions. `scim:manage` (directory provisioning) requires the user-management
 * area (`user_group_admin`); any admin may still mint the self-service read scopes.
 */
class TokensControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

    private string $userAdminId = '';
    private string $plainAdminId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        // Ensure the areas referenced by the FK exist (idempotent).
        foreach (['user_group_admin' => 'Users', 'tenant_backup' => 'Backup'] as $key => $label) {
            $conn->execute(
                'INSERT INTO admin_areas (area_key, label, sort_order) VALUES (:k, :l, 50) ON CONFLICT (area_key) DO NOTHING',
                ['k' => $key, 'l' => $label],
            );
        }
        // A user-management admin (may mint scim:manage) and a plain admin holding a
        // different area only (may not). Both pass the area-less TokensController gate.
        $this->userAdminId = $this->makeAdmin('user_group_admin');
        $this->plainAdminId = $this->makeAdmin('tenant_backup');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function makeAdmin(string $area): string
    {
        $conn = ConnectionManager::get('default');
        $id = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztok_' . bin2hex(random_bytes(3)), 'e' => 'tok_' . bin2hex(random_bytes(3)) . '@zztok.local'],
        )->fetch('assoc')['id'];
        $this->grantAdminAreas($id, $area);

        return $id;
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM api_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@zztok.local')");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztok.local'");
    }

    private function login(string $userId): void
    {
        $this->session(['Auth' => ['id' => $userId, 'username' => 'zztok', 'email' => 't@zztok.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function tokenCount(string $userId): int
    {
        return (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) c FROM api_tokens WHERE user_id = :u',
            ['u' => $userId],
        )->fetch('assoc')['c'];
    }

    public function testPlainAdminCannotMintScimScope(): void
    {
        $this->login($this->plainAdminId);
        $this->post('/admin/tokens/create', ['label' => 'x', 'scopes' => ['scim:manage']]);
        // Re-rendered with an error (not a redirect) and NO token persisted.
        $this->assertResponseOk();
        $this->assertSame(0, $this->tokenCount($this->plainAdminId), 'a non-user-admin must not mint scim:manage');
    }

    public function testPlainAdminCanMintReadScope(): void
    {
        $this->login($this->plainAdminId);
        $this->post('/admin/tokens/create', ['label' => 'x', 'scopes' => ['me:read']]);
        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(1, $this->tokenCount($this->plainAdminId), 'self-service read scopes stay mintable');
    }

    public function testUserGroupAdminCanMintScimScope(): void
    {
        $this->login($this->userAdminId);
        $this->post('/admin/tokens/create', ['label' => 'x', 'scopes' => ['scim:manage']]);
        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(1, $this->tokenCount($this->userAdminId), 'a user-management admin may mint scim:manage');
    }
}
