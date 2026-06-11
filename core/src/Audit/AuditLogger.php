<?php
declare(strict_types=1);

namespace App\Audit;

use App\Model\ActorContext;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Symfony\Component\Uid\Uuid;

/**
 * Central write service for the audit log (ch. 1.6 / 24.16 / 27.18).
 *
 * Writes via the default connection and therefore within the running
 * transaction of the business change (transactional binding, ch. 1.8).
 *
 * Design rule E16: no personal plaintext data in the log. People are
 * referenced by a resolvable UUID (actor_user_id, and entity_id where
 * applicable); textual snapshots (entity_label, module_*) are kept only for
 * non-personal entities (modules/config) for reference robustness.
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
     * Mirrors the event (item 3a) to the dedicated `audit` log channel — which
     * the operator forwards via a log shipper to any SIEM (no vendor-specific
     * connector in the core). **Low-PII** (E16): actor/entity by UUID/identifier,
     * **no** old/new value snapshots in the stream — those stay in the DB and are
     * only retrievable via the authorized export.
     * Failure-isolated: a logging problem must never cause the business action to fail.
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
            // The stream is a mirror for detection; the DB remains the source of truth.
        }
    }
}
