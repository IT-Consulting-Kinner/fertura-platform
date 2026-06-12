<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the module lifecycle GUI (admin area `module_lifecycle`):
 * module list with dependency counter, layered dependency SVG (`graph`,
 * ch. 23.13.1), and the error paths of the lifecycle actions (flash instead of
 * 500 for an unknown module).
 */
class ModulesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;
    private string $modA = '';
    private string $modB = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('module_lifecycle', 'Modules', 20) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_madmin_' . bin2hex(random_bytes(3)), 'e' => 'madmin_' . bin2hex(random_bytes(3)) . '@zzmod.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'module_lifecycle'],
        );

        // Two modules with a dependency B -> A (for list + graph layers).
        $this->modA = $this->makeModule('zztestmod_a');
        $this->modB = $this->makeModule('zztestmod_b');
        $conn->execute(
            'INSERT INTO module_dependencies (module_id, required_module_key) VALUES (:m, :r)',
            ['m' => $this->modB, 'r' => 'zztestmod_a'],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // module_dependencies cascades via FK.
        $conn->execute("DELETE FROM modules WHERE module_key LIKE 'zztestmod_%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzmod.local'");
    }

    private function makeModule(string $key): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES (:k, :n, '1.0.0', 'main', '>=1.0.0', 'installed_inactive', '{}'::jsonb) RETURNING id",
            ['k' => $key, 'n' => 'ZZ ' . $key],
        )->fetch('assoc')['id'];
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_madmin', 'email' => 'm@zzmod.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    public function testIndexListsModulesWithDependencyCount(): void
    {
        $this->login();
        $this->get('/admin/modules');

        $this->assertResponseOk();
        $this->assertResponseContains('zztestmod_a');
        $this->assertResponseContains('zztestmod_b');
    }

    public function testGraphRendersLayeredSvg(): void
    {
        $this->login();
        $this->get('/admin/modules/graph');

        $this->assertResponseOk();
        $this->assertResponseContains('<svg');
        $this->assertResponseContains('zztestmod_a');
        $this->assertResponseContains('zztestmod_b');
        // Edge B -> A present (line/path in the SVG, depending on the template).
        $body = (string)$this->_response->getBody();
        $this->assertMatchesRegularExpression('/<(line|path|polyline)\b/', $body);
    }

    public function testLifecycleActionsFailGracefullyForUnknownModule(): void
    {
        $this->login();

        // Error path: ModuleLifecycle throws, GUI catches -> flash + redirect (no 500).
        $this->post('/admin/modules/activate/zztestmod_missing');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/modules/deactivate/zztestmod_missing');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/modules/delete/zztestmod_missing');
        $this->assertRedirect(['action' => 'index']);

        // GET on a POST-only action -> 405.
        $this->get('/admin/modules/activate/zztestmod_a');
        $this->assertResponseCode(405);
    }
}
