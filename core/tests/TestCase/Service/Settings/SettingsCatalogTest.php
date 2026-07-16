<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Settings;

use App\Service\Settings\SettingsCatalog;
use Cake\TestSuite\TestCase;

/**
 * Catalog contract for the dedicated AI egress timeout (`core.ai.timeout_seconds`):
 * registered, default 60s, bounded [1, 600]. AiGateway reads this to give LLM calls
 * a longer timeout than the shared 10s egress default.
 */
class SettingsCatalogTest extends TestCase
{
    public function testAiTimeoutSettingRegisteredWithBounds(): void
    {
        $cat = new SettingsCatalog();

        $this->assertTrue($cat->isKnown('core', 'ai.timeout_seconds'));
        $this->assertSame(60, $cat->default('core', 'ai.timeout_seconds'));

        // A sensible value validates; out-of-range values are rejected.
        $this->assertSame([], $cat->validate('core', 'ai.timeout_seconds', 60));
        $this->assertNotEmpty($cat->validate('core', 'ai.timeout_seconds', 0));
        $this->assertNotEmpty($cat->validate('core', 'ai.timeout_seconds', 700));
    }

    public function testTenantModeSettingRegisteredWithAllowedValues(): void
    {
        $cat = new SettingsCatalog();

        // The TenantMode setting (operator-tenant design §5b): registered, default
        // single_org (backward-compatible), constrained to the two allowed values.
        $this->assertTrue($cat->isKnown('core', 'tenancy.mode'));
        $this->assertSame('single_org', $cat->default('core', 'tenancy.mode'));

        // Allowed values validate; anything else is rejected (the generic `allowed`
        // allow-list check on the string type).
        $this->assertSame([], $cat->validate('core', 'tenancy.mode', 'single_org'));
        $this->assertSame([], $cat->validate('core', 'tenancy.mode', 'multi_org'));
        $this->assertNotEmpty($cat->validate('core', 'tenancy.mode', 'bogus'));
        $this->assertNotEmpty($cat->validate('core', 'tenancy.mode', 'MultiOrg'));
    }
}
