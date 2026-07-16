<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Admin;

use App\Service\Admin\AdminNavBuilder;
use Cake\TestSuite\TestCase;

/**
 * Realm grouping (operator-tenant design §6, Increment 3): the admin top menu splits
 * the held areas into the OPERATOR realm (Betreiber — operator-scoped Core areas +
 * system pages) and the TENANT realm (Mandant — the tenant-scoped Core area + the
 * module-contributed areas). The internal keys stay `administration` (= Betreiber) /
 * `module` (= Mandant) for route/highlight stability; grouping is by area SCOPE so a
 * single-org viewer (operator tenant that also uses modules) can hold both realms.
 */
class AdminNavBuilderRealmTest extends TestCase
{
    public function testCoreAreasGroupIntoOperatorAndTenantRealms(): void
    {
        $menu = (new AdminNavBuilder())->menu(['user_group_admin', 'system_maintenance', 'core_config']);

        // Operator realm (Betreiber): operator-scoped Core areas + the system group.
        $this->assertArrayHasKey('system_maintenance', $menu['administration']);
        $this->assertArrayHasKey('core_config', $menu['administration']);
        $this->assertArrayHasKey('system', $menu['administration']);
        $this->assertArrayNotHasKey('user_group_admin', $menu['administration']);

        // Tenant realm (Mandant): the tenant-scoped Core area.
        $this->assertArrayHasKey('user_group_admin', $menu['module']);
        $this->assertArrayNotHasKey('system_maintenance', $menu['module']);
    }

    public function testSingleOrgViewerKeepsBothRealms(): void
    {
        // Defaults (single-org / the default tenant that is BOTH operator and module
        // user): both realms are populated, unchanged from the historic behaviour.
        $menu = (new AdminNavBuilder())->menu(['user_group_admin', 'system_maintenance', 'core_config']);
        $this->assertArrayHasKey('core_config', $menu['administration']); // operator realm
        $this->assertArrayHasKey('system_maintenance', $menu['administration']);
        $this->assertArrayHasKey('user_group_admin', $menu['module']); // tenant realm
    }

    public function testCustomerTenantViewerHidesOperatorRealm(): void
    {
        // A customer-tenant viewer ($isOperatorTenant = false): the operator (Betreiber)
        // Core areas are never surfaced (they 403 anyway); only the always-available
        // system group remains in that realm. The tenant realm stays intact.
        $menu = (new AdminNavBuilder())->menu(
            ['user_group_admin', 'system_maintenance', 'core_config'],
            false,
        );
        $this->assertArrayNotHasKey('system_maintenance', $menu['administration']);
        $this->assertArrayNotHasKey('core_config', $menu['administration']);
        $this->assertArrayHasKey('system', $menu['administration']);
        $this->assertArrayHasKey('user_group_admin', $menu['module']);
    }

    public function testAreaTopReflectsScopeNotCoreMembership(): void
    {
        $builder = new AdminNavBuilder();
        $this->assertSame('administration', $builder->areaTop('core_config')); // operator -> Betreiber
        $this->assertSame('administration', $builder->areaTop('system'));
        $this->assertSame('module', $builder->areaTop('user_group_admin')); // tenant -> Mandant
        $this->assertSame('module', $builder->areaTop('zztest_module_area')); // module-contributed -> Mandant
    }
}
