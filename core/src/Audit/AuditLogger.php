<?php
declare(strict_types=1);

namespace App\Audit;

use App\Model\ActorContext;
use Cake\Datasource\ConnectionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Zentraler Schreib-Service für das Audit-Log (Kap. 1.6 / 24.16 / 27.18).
 *
 * Schreibt über die Default-Connection und damit innerhalb der laufenden
 * Transaktion der fachlichen Änderung (transaktionaler Bezug, Kap. 1.8).
 *
 * Designregel E16: keine personenbezogenen Klartextdaten ins Log. Personen
 * werden per auflösbarer UUID (actor_user_id, ggf. entity_id) referenziert;
 * textuelle Schnappschüsse (entity_label, module_*) nur für nicht-
 * personenbezogene Entitäten (Module/Config) zwecks Referenzrobustheit.
 *
 * @param array{
 *     actorUserId?: ?string, entityLabel?: ?string, oldValue?: mixed,
 *     newValue?: mixed, moduleKey?: ?string, moduleName?: ?string,
 *     moduleVersion?: ?string, component?: string, correlationId?: ?string
 * } $options
 */
class AuditLogger
{
    public function log(string $action, string $entityType, ?string $entityId = null, array $options = []): void
    {
        $connection = ConnectionManager::get('default');

        $actor = array_key_exists('actorUserId', $options)
            ? $options['actorUserId']
            : ActorContext::get();
        $correlationId = $options['correlationId'] ?? Uuid::v7()->toRfc4122();

        $oldValue = array_key_exists('oldValue', $options) && $options['oldValue'] !== null
            ? json_encode($options['oldValue'])
            : null;
        $newValue = array_key_exists('newValue', $options) && $options['newValue'] !== null
            ? json_encode($options['newValue'])
            : null;

        $connection->execute(
            'INSERT INTO audit_log '
            . '(actor_user_id, action, entity_type, entity_id, entity_label, '
            . 'module_key, module_name, module_version, component, correlation_id, old_value, new_value) '
            . 'VALUES (:actor, :action, :etype, :eid, :elabel, :mkey, :mname, :mver, :component, :corr, '
            . 'CAST(:old AS jsonb), CAST(:new AS jsonb))',
            [
                'actor' => $actor,
                'action' => $action,
                'etype' => $entityType,
                'eid' => $entityId,
                'elabel' => $options['entityLabel'] ?? null,
                'mkey' => $options['moduleKey'] ?? null,
                'mname' => $options['moduleName'] ?? null,
                'mver' => $options['moduleVersion'] ?? null,
                'component' => $options['component'] ?? 'core',
                'corr' => $correlationId,
                'old' => $oldValue,
                'new' => $newValue,
            ],
        );
    }
}
