<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\Sso\SsoService;
use App\Service\Webhook\WebhookService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Throwable;

/**
 * Admin GUI "Integrations & Automation" within the core configuration area:
 * overview + create/toggle/delete for **all** core integration types
 * (webhooks, SSO/OIDC+SAML, automation rules, workflows) — full CLI↔GUI
 * parity. Conditions/actions/transitions are accepted (as in the CLI) as
 * validated JSON; a convenient visual rule builder is a later, likewise
 * core-side convenience tier, not a prerequisite for parity.
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

    /** Creates a webhook subscription (name, URL, event filter, optional HMAC secret). */
    public function webhookCreate(): ?Response
    {
        $this->request->allowMethod('post');
        $name = trim((string)$this->request->getData('name'));
        $url = trim((string)$this->request->getData('url'));
        $filter = trim((string)$this->request->getData('event_filter')) ?: '*';
        $secret = trim((string)$this->request->getData('secret')) ?: null;
        if ($name === '' || $url === '') {
            $this->Flash->error(__('flash.integrations.webhook_fields'));

            return $this->redirect(['action' => 'index']);
        }
        try {
            (new WebhookService())->createSubscription($name, $url, $filter, $secret);
            $this->Flash->success(__('flash.integrations.webhook_created'));
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.integrations.webhook_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Creates an SSO provider (OIDC or SAML); the secret is stored encrypted. */
    public function ssoCreate(): ?Response
    {
        $this->request->allowMethod('post');
        $type = (string)$this->request->getData('type');
        $name = trim((string)$this->request->getData('name'));
        $label = trim((string)$this->request->getData('button_label')) ?: $name;
        if (!in_array($type, ['oidc', 'saml'], true) || $name === '') {
            $this->Flash->error(__('flash.integrations.sso_fields'));

            return $this->redirect(['action' => 'index']);
        }

        try {
            if ($type === 'oidc') {
                $issuer = trim((string)$this->request->getData('issuer'));
                $clientId = trim((string)$this->request->getData('client_id'));
                if ($issuer === '' || $clientId === '') {
                    $this->Flash->error(__('flash.integrations.sso_fields'));

                    return $this->redirect(['action' => 'index']);
                }
                $config = [
                    'issuer' => $issuer,
                    'client_id' => $clientId,
                    'scopes' => trim((string)$this->request->getData('scopes')) ?: 'openid email profile',
                ];
                $secret = trim((string)$this->request->getData('client_secret')) ?: null;
            } else {
                $entityId = trim((string)$this->request->getData('idp_entity_id'));
                $ssoUrl = trim((string)$this->request->getData('idp_sso_url'));
                $cert = trim((string)$this->request->getData('idp_x509cert'));
                if ($entityId === '' || $ssoUrl === '' || $cert === '') {
                    $this->Flash->error(__('flash.integrations.sso_fields'));

                    return $this->redirect(['action' => 'index']);
                }
                $config = ['idp_entity_id' => $entityId, 'idp_sso_url' => $ssoUrl, 'idp_x509cert' => $cert];
                $secret = null; // SP signing (private key) stays on the CLI path
            }
            (new SsoService())->createProvider($type, $name, $config, $secret, $label);
            $this->Flash->success(__('flash.integrations.sso_created'));
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.integrations.sso_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Creates an automation rule (name/event + JSON condition/actions, as in the CLI). */
    public function automationCreate(): ?Response
    {
        $this->request->allowMethod('post');
        $name = trim((string)$this->request->getData('name'));
        $event = trim((string)$this->request->getData('event'));
        $condition = trim((string)$this->request->getData('condition')) ?: '{}';
        $actions = trim((string)$this->request->getData('actions')) ?: '[]';
        if ($name === '' || $event === '') {
            $this->Flash->error(__('flash.integrations.automation_fields'));

            return $this->redirect(['action' => 'index']);
        }
        // Same JSON validation as the CLI: condition = object, actions = array.
        if (!is_array(json_decode($condition, true))) {
            $this->Flash->error(__('flash.integrations.bad_json', 'condition'));

            return $this->redirect(['action' => 'index']);
        }
        if (!is_array(json_decode($actions, true)) || !array_is_list((array)json_decode($actions, true))) {
            $this->Flash->error(__('flash.integrations.bad_json', 'actions'));

            return $this->redirect(['action' => 'index']);
        }
        $this->conn()->execute(
            'INSERT INTO automation_rules (name, event, condition, actions) '
            . 'VALUES (:n, :e, CAST(:c AS jsonb), CAST(:a AS jsonb))',
            ['n' => $name, 'e' => $event, 'c' => $condition, 'a' => $actions],
        );
        $this->Flash->success(__('flash.integrations.automation_created'));

        return $this->redirect(['action' => 'index']);
    }

    /** Creates a workflow definition (name/entity/initial state + JSON transitions, as in the CLI). */
    public function workflowCreate(): ?Response
    {
        $this->request->allowMethod('post');
        $name = trim((string)$this->request->getData('name'));
        $entityType = trim((string)$this->request->getData('entity_type'));
        $entityIdField = trim((string)$this->request->getData('entity_id_field')) ?: 'entity_id';
        $initial = trim((string)$this->request->getData('initial_state'));
        $transitions = trim((string)$this->request->getData('transitions')) ?: '[]';
        if ($name === '' || $entityType === '' || $initial === '') {
            $this->Flash->error(__('flash.integrations.workflow_fields'));

            return $this->redirect(['action' => 'index']);
        }
        if (!is_array(json_decode($transitions, true)) || !array_is_list((array)json_decode($transitions, true))) {
            $this->Flash->error(__('flash.integrations.bad_json', 'transitions'));

            return $this->redirect(['action' => 'index']);
        }
        $this->conn()->execute(
            'INSERT INTO workflow_definitions (name, entity_type, entity_id_field, initial_state, transitions) '
            . 'VALUES (:n, :et, :ef, :is, CAST(:tr AS jsonb))',
            ['n' => $name, 'et' => $entityType, 'ef' => $entityIdField, 'is' => $initial, 'tr' => $transitions],
        );
        $this->Flash->success(__('flash.integrations.workflow_created'));

        return $this->redirect(['action' => 'index']);
    }

    public function webhookToggle(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        (new WebhookService())->setActive($id, !$this->isActive('webhook_subscriptions', $id));

        return $this->back();
    }

    public function webhookDelete(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        (new WebhookService())->deleteSubscription($id);

        return $this->back();
    }

    public function deliveryRetry(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        (new WebhookService())->retryDelivery($id);

        return $this->back();
    }

    public function ssoToggle(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        (new SsoService())->setActive($id, !$this->isActive('sso_providers', $id));

        return $this->back();
    }

    public function ssoDelete(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        (new SsoService())->deleteProvider($id);

        return $this->back();
    }

    public function automationToggle(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        $this->setActive('automation_rules', $id, !$this->isActive('automation_rules', $id));

        return $this->back();
    }

    public function automationDelete(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        $this->conn()->execute('DELETE FROM automation_rules WHERE id = :id', ['id' => $id]);

        return $this->back();
    }

    public function workflowToggle(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        $this->setActive('workflow_definitions', $id, !$this->isActive('workflow_definitions', $id));

        return $this->back();
    }

    public function workflowDelete(string $id): ?Response
    {
        if ($denied = $this->guardId($id)) {
            return $denied;
        }
        $this->conn()->execute('DELETE FROM workflow_definitions WHERE id = :id', ['id' => $id]);

        return $this->back();
    }

    /**
     * Shared entry check for all ID actions: POST only, and treat malformed
     * IDs (UUID guard) like unknown entries. Returns the redirect response
     * or null (the action may proceed).
     */
    private function guardId(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if ($this->isUuid($id)) {
            return null;
        }
        $this->Flash->error(__('flash.integrations.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
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

    private function back(): ?Response
    {
        $this->Flash->success(__('flash.integrations.done'));

        return $this->redirect(['action' => 'index']);
    }
}
