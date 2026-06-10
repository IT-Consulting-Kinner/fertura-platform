<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\Sso\SsoService;
use App\Service\Webhook\WebhookService;
use Cake\Datasource\ConnectionManager;

/**
 * Admin-GUI „Integrationen & Automatisierung" (zurückgestellter GUI-Ausbau) im
 * Bereich Core-Konfiguration: Übersicht + sichere Aktionen für Webhooks, SSO,
 * Automations-Regeln und Workflows. Anlage erfolgt weiterhin über CLI/API
 * (die formularlastige Konfiguration); die GUI deckt Monitoring + aktivieren/
 * deaktivieren/löschen/Zustellung-erneut ab.
 */
class IntegrationsController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    public function index(): void
    {
        $webhooks = new WebhookService();
        $this->set('webhooks', $webhooks->listSubscriptions());
        $this->set('deliveries', $webhooks->listDeliveries(null, 30));
        $this->set('ssoProviders', (new SsoService())->listProviders());
        $this->set('automationRules', $this->conn()->execute(
            'SELECT id, name, event, active FROM automation_rules ORDER BY created_at',
        )->fetchAll('assoc'));
        $this->set('workflows', $this->conn()->execute(
            'SELECT id, name, entity_type, initial_state, active FROM workflow_definitions ORDER BY created_at',
        )->fetchAll('assoc'));
    }

    public function webhookToggle(string $id)
    {
        $this->request->allowMethod('post');
        (new WebhookService())->setActive($id, !$this->isActive('webhook_subscriptions', $id));

        return $this->back();
    }

    public function webhookDelete(string $id)
    {
        $this->request->allowMethod('post');
        (new WebhookService())->deleteSubscription($id);

        return $this->back();
    }

    public function deliveryRetry(string $id)
    {
        $this->request->allowMethod('post');
        (new WebhookService())->retryDelivery($id);

        return $this->back();
    }

    public function ssoToggle(string $id)
    {
        $this->request->allowMethod('post');
        (new SsoService())->setActive($id, !$this->isActive('sso_providers', $id));

        return $this->back();
    }

    public function ssoDelete(string $id)
    {
        $this->request->allowMethod('post');
        (new SsoService())->deleteProvider($id);

        return $this->back();
    }

    public function automationToggle(string $id)
    {
        $this->request->allowMethod('post');
        $this->setActive('automation_rules', $id, !$this->isActive('automation_rules', $id));

        return $this->back();
    }

    public function automationDelete(string $id)
    {
        $this->request->allowMethod('post');
        $this->conn()->execute('DELETE FROM automation_rules WHERE id = :id', ['id' => $id]);

        return $this->back();
    }

    public function workflowToggle(string $id)
    {
        $this->request->allowMethod('post');
        $this->setActive('workflow_definitions', $id, !$this->isActive('workflow_definitions', $id));

        return $this->back();
    }

    public function workflowDelete(string $id)
    {
        $this->request->allowMethod('post');
        $this->conn()->execute('DELETE FROM workflow_definitions WHERE id = :id', ['id' => $id]);

        return $this->back();
    }

    private function conn(): \Cake\Datasource\ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    private function isActive(string $table, string $id): bool
    {
        $row = $this->conn()->execute("SELECT active FROM $table WHERE id = :id", ['id' => $id])->fetch('assoc');

        return $row !== false && (bool)$row['active'];
    }

    private function setActive(string $table, string $id, bool $active): void
    {
        $this->conn()->execute(
            "UPDATE $table SET active = :a WHERE id = :id",
            ['a' => $active ? 'true' : 'false', 'id' => $id],
        );
    }

    private function back()
    {
        $this->Flash->success(__('flash.integrations.done'));

        return $this->redirect(['action' => 'index']);
    }
}
