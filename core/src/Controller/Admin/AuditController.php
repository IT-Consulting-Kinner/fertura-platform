<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Cake\Datasource\ConnectionManager;

/**
 * Audit-Log-Einsicht (für jeden Administrator, kein spezifischer Bereich).
 *
 * Lesend und gefiltert. Personenbezug bleibt per UUID referenziert (E16);
 * der Benutzername wird nur zur Anzeige aufgelöst, nicht aus dem Log gelesen.
 */
class AuditController extends AdminController
{
    protected ?string $requiredArea = null;

    public function index(): void
    {
        $action = trim((string)$this->request->getQuery('action'));
        $entityType = trim((string)$this->request->getQuery('entity_type'));
        $moduleKey = trim((string)$this->request->getQuery('module_key'));

        $where = [];
        $params = [];
        if ($action !== '') {
            $where[] = 'a.action ILIKE :action';
            $params['action'] = '%' . $action . '%';
        }
        if ($entityType !== '') {
            $where[] = 'a.entity_type = :etype';
            $params['etype'] = $entityType;
        }
        if ($moduleKey !== '') {
            $where[] = 'a.module_key = :mkey';
            $params['mkey'] = $moduleKey;
        }
        $sql = 'SELECT a.created_at, a.actor_user_id, u.username AS actor_username, a.action, '
            . 'a.entity_type, a.entity_id, a.entity_label, a.module_key, a.component '
            . 'FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.created_at DESC LIMIT 100';

        $conn = ConnectionManager::get('default');
        $entries = $conn->execute($sql, $params)->fetchAll('assoc');

        $actions = $conn->execute('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll('assoc');
        $entityTypes = $conn->execute('SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type')->fetchAll('assoc');

        $this->set(compact('entries', 'actions', 'entityTypes', 'action', 'entityType', 'moduleKey'));
    }
}
