<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\I18n;

use App\Service\I18n\LanguagePackStore;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Peer-review F8: LanguagePackStore must reject path components that could escape
 * the store (traversal, separators, drive markers, leading dot, NUL) so the admin
 * localization import cannot write or delete an arbitrary .po outside the store.
 * dir()/filePath() are the authoritative chokepoint for every read/write/delete.
 */
class LanguagePackStoreTest extends TestCase
{
    public function testRejectsTraversalInComponentKey(): void
    {
        $this->expectException(RuntimeException::class);
        (new LanguagePackStore())->filePath('../../../webroot/x', '1.0.0', 'de_DE', 'default');
    }

    public function testRejectsTraversalInVersion(): void
    {
        $this->expectException(RuntimeException::class);
        (new LanguagePackStore())->filePath('core', '../evil', 'de_DE', 'default');
    }

    public function testRejectsSeparatorInDomain(): void
    {
        $this->expectException(RuntimeException::class);
        (new LanguagePackStore())->filePath('core', '1.0.0', 'de_DE', 'a/b');
    }

    public function testRejectsVariousUnsafeComponents(): void
    {
        $store = new LanguagePackStore();
        foreach (['..\\x', 'C:evil', '.hidden', ''] as $bad) {
            try {
                $store->filePath('core', '1.0.0', 'de_DE', $bad);
                $this->fail("expected rejection for unsafe component '$bad'");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAcceptsLegitimateComponents(): void
    {
        $store = new LanguagePackStore();
        $path = $store->filePath('ticketing', '1.0.0', 'de_DE', 'default');
        $this->assertStringEndsWith('/ticketing/1.0.0/de_DE/default.po', $path);
        $this->assertStringStartsWith($store->base(), $path);
    }
}
