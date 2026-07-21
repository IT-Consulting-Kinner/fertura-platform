<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Admin;

use App\Service\Admin\AreaLabel;
use Cake\I18n\I18n;
use Cake\I18n\Package;
use Cake\TestSuite\TestCase;

/**
 * Localizes core.admin_areas.label at the raw render sites (the user-detail "areas"
 * card): a module's nav_group i18n KEY is resolved via __d in its domain (the key's
 * first segment); plaintext labels pass through unchanged (backward-compat).
 */
class AreaLabelTest extends TestCase
{
    public function testPlaintextLabelPassesThroughUnchanged(): void
    {
        // KnowledgeBase's plaintext label + the Core areas' plain labels have no
        // dotted domain -> returned verbatim.
        $this->assertSame('Wissensdatenbank', AreaLabel::localize('Wissensdatenbank'));
        $this->assertSame(
            'Benutzer- und Gruppenverwaltung',
            AreaLabel::localize('Benutzer- und Gruppenverwaltung'),
        );
    }

    public function testUnknownDomainKeyPassesThroughUnchanged(): void
    {
        // A dotted i18n key whose domain has no catalog resolves to itself (__d
        // returns the key unchanged) -> never a broken blank.
        $this->assertSame('zzunknownmod.nav.group', AreaLabel::localize('zzunknownmod.nav.group'));
    }

    public function testModuleI18nKeyResolvesInItsDomain(): void
    {
        // The real case (Ticketing): the area label IS the nav_group i18n key; the
        // domain is the key's first segment (the module key), resolved via __d —
        // exactly as the nav and dashboard heading already do.
        I18n::setTranslator('zzareamod', function () {
            return new Package('default', null, ['zzareamod.nav.group' => 'Aufgeloestes Label']);
        }, I18n::getLocale());

        $this->assertSame('Aufgeloestes Label', AreaLabel::localize('zzareamod.nav.group'));
    }
}
