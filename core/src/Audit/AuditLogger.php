<?php
declare(strict_types=1);

namespace App\Audit;

use App\Model\ActorContext;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
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

        $this->stream($action, $entityType, $entityId, $correlationId, $actor, $options);
    }

    /**
     * Spiegelt das Ereignis (Punkt 3a) auf den dedizierten `audit`-Log-Kanal —
     * den der Betreiber per Log-Shipper an ein beliebiges SIEM ausleitet (kein
     * vendorspezifischer Konnektor im Core). **PII-arm** (E16): Akteur/Entität
     * per UUID/Bezeichner, **keine** old/new-Wert-Snapshots im Strom — die
     * bleiben in der DB und sind nur über den autorisierten Export abrufbar.
     * Fehlerisoliert: ein Log-Problem darf die fachliche Aktion nie scheitern lassen.
     *
     * @param array<string,mixed> $options
     */
    private function stream(
        string $action,
        string $entityType,
        ?string $entityId,
        string $correlationId,
        ?string $actor,
        array $options,
    ): void {
        try {
            Log::write('info', 'audit.' . $action, [
                'scope' => ['audit'],
                'component' => (string)($options['component'] ?? 'core'),
                'correlation_id' => $correlationId,
                'audit' => [
                    'action' => $action,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'entity_label' => $options['entityLabel'] ?? null,
                    'module_key' => $options['moduleKey'] ?? null,
                    'actor_user_id' => $actor,
                ],
            ]);
        } catch (\Throwable) {
            // Strom ist Spiegel zur Detektion; die DB bleibt die Quelle der Wahrheit.
        }
    }
}
