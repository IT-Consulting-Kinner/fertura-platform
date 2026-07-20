<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Admin;

use App\Service\Admin\AdminNavBuilder;
use Cake\TestSuite\TestCase;

/**
 * Realm grouping (operator-tenant design §6, Increment 3): the admin top menu splits
 * the held areas into the OPERATOR realm (Administration — operator-scoped Core areas
 * + system pages + user/group mgmt) and the TENANT realm (Module — the module-
 * contributed setting areas). The internal keys stay `administration` / `module` for
 * route/highlight stability. User/group management is TENANT-scoped for ACCESS (a
 * tenant admin reaches it) but DISPLAYED under Administration, so it is never narrowed
 * away for a customer-tenant viewer.
 */
class AdminNavBuilderRealmTest extends TestCase
{
    public function testCoreAreasGroupIntoOperatorAndTenantRealms(): void
    {
        $menu = (new AdminNavBuilder())->menu(['user_group_admin', 'system_maintenance', 'core_config']);

        // Operator (Administration) realm: operator-scoped Core areas + the system
        // group + user/group mgmt (tenant-scoped but displayed here).
        $this->assertArrayHasKey('system_maintenance', $menu['administration']);
        $this->assertArrayHasKey('core_config', $menu['administration']);
        $this->assertArrayHasKey('system', $menu['administration']);
        $this->assertArrayHasKey('user_group_admin', $menu['administration']);

        // Tenant (Module) realm: no Core areas land here (only module-contributed).
        $this->assertArrayNotHasKey('user_group_admin', $menu['module']);
        $this->assertArrayNotHasKey('system_maintenance', $menu['module']);
    }

    public function testDefaultViewerKeepsFullOperatorRealm(): void
    {
        // Defaults (single-org / the default tenant that is BOTH operator and module
        // user): the operator realm is fully populated, incl. user/group mgmt.
        $menu = (new AdminNavBuilder())->menu(['user_group_admin', 'system_maintenance', 'core_config']);
        $this->assertArrayHasKey('core_config', $menu['administration']);
        $this->assertArrayHasKey('system_maintenance', $menu['administration']);
        $this->assertArrayHasKey('user_group_admin', $menu['administration']);
    }

    public function testCustomerTenantKeepsUserGroupsButHidesOperatorAreas(): void
    {
        // A customer-tenant viewer ($isOperatorTenant = false): the operator-SCOPED
        // Core areas are dropped (they 403 anyway), but user/group mgmt (tenant-scoped,
        // displayed under Administration) MUST remain — a tenant admin still manages
        // their own users/groups — plus the always-available system group.
        $menu = (new AdminNavBuilder())->menu(
            ['user_group_admin', 'system_maintenance', 'core_config'],
            false,
        );
        $this->assertArrayNotHasKey('system_maintenance', $menu['administration']);
        $this->assertArrayNotHasKey('core_config', $menu['administration']);
        $this->assertArrayHasKey('system', $menu['administration']);
        // The authz-preservation guarantee: user/group mgmt stays for the tenant admin.
        $this->assertArrayHasKey('user_group_admin', $menu['administration']);
        $this->assertArrayNotHasKey('user_group_admin', $menu['module']);
    }

    public function testAreaTopReflectsDisplayRealm(): void
    {
        $builder = new AdminNavBuilder();
        $this->assertSame('administration', $builder->areaTop('core_config')); // operator
        $this->assertSame('administration', $builder->areaTop('system'));
        // Tenant-scoped for access, but DISPLAYED under Administration.
        $this->assertSame('administration', $builder->areaTop('user_group_admin'));
        $this->assertSame('module', $builder->areaTop('zztest_module_area')); // module-contributed
    }
}
