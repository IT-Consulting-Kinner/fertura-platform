<?php
declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Localizes an admin-area label (`core.admin_areas.label`) for the RAW render sites
 * — the user-detail "areas" card ({@see \App\Controller\Admin\UsersController::view()}
 * / templates/Admin/Users/view.php) and any grant/assignment list — where the label
 * would otherwise be printed verbatim.
 *
 * Module-contributed areas store their nav_group i18n KEY (e.g. Ticketing's
 * `ticketing.nav.group`), the SAME value the top menu and the dashboard heading
 * already resolve via __d in the module's i18n domain
 * ({@see \App\Service\Dashboard\DashboardTileCollector::moduleLabels()}). This mirrors
 * that resolution so all three agree: the domain is the key's FIRST dotted segment
 * (a module's i18n domain equals its module key, and its keys are `<module_key>.…`),
 * so `ticketing.nav.group` -> __d('ticketing', …) -> "Ticketsystem"/"Ticketing" in the
 * viewer's locale.
 *
 * Backward-compatible: __d returns an unknown key/domain UNCHANGED, so a plaintext
 * label (KnowledgeBase's "Wissensdatenbank", the Core areas' plain labels) passes
 * through untouched — only a real `<module>.<…>` i18n key is resolved. A module
 * therefore keeps ONE bilingual label channel (its i18n domain) instead of shipping a
 * second, single-language static label just for these lists.
 */
final class AreaLabel
{
    public static function localize(string $label): string
    {
        // Domain = the segment before the first dot (the module key). A label with
        // no dot is plaintext and is returned as-is.
        $domain = strstr($label, '.', true);
        if ($domain === false || $domain === '') {
            return $label;
        }

        return (string)__d($domain, $label);
    }
}
