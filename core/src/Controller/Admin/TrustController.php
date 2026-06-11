<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Marketplace\MarketplaceClient;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;

/**
 * Management of **trust anchors & revocation list** (ch. 24.9.2) — GUI counterpart to
 * `bin/cake trust`. Security-critical: an anchor is the root of the module signature
 * chain, so actions require hard confirmation. Provides display (anchors + validity +
 * revocation list + CRL age), **revocation** of a key (flags affected modules), and
 * **manual addition** of an anchor (out-of-band trust).
 *
 * Deliberately left CLI-only: rolling key rotation with an overlap window
 * (`trust rotate`) and importing from certificate files — an operator/file-path concern.
 */
class TrustController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    private function conn(): \Cake\Database\Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    public function index(): void
    {
        $anchors = $this->conn()->execute(
            'SELECT key_id, key_type, publisher, signed_by, valid_from, valid_to, active, created_at '
            . 'FROM trust_anchors ORDER BY key_type, key_id',
        )->fetchAll('assoc');
        $revoked = $this->conn()->execute(
            'SELECT key_id, reason, source, revoked_at FROM revoked_keys ORDER BY revoked_at DESC LIMIT 100',
        )->fetchAll('assoc');

        // Enrich each anchor with its validity/revocation result (E45/24.9.2).
        $trust = new TrustStore();
        foreach ($anchors as &$a) {
            $a['revoked'] = $trust->isRevoked((string)$a['key_id']);
            $a['validity'] = TrustStore::validity($a);
        }
        unset($a);

        $crl = ['stale' => false, 'age_days' => null, 'last_fetch_at' => null, 'max_age_days' => 0];
        try {
            $crl = (new MarketplaceClient())->crlState();
        } catch (\Throwable) {
            // The CRL status is informational; empty without marketplace config.
        }

        $this->set(compact('anchors', 'revoked', 'crl'));
    }

    /** Revokes a key and flags affected modules (deny-side). */
    public function revoke(string $keyId): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        if (trim($keyId) === '' || strlen($keyId) > 256) {
            $this->Flash->error(__('flash.trust.bad_key'));

            return $this->redirect(['action' => 'index']);
        }
        $trust = new TrustStore();
        $trust->revokeKey($keyId, (string)$this->request->getData('reason') ?: null, 'manual');
        // Mark modules that were signed with this key as revoked.
        $affected = $trust->reconcileModuleSignatures();
        $this->Flash->success(__('flash.trust.revoked', $keyId, $affected));

        return $this->redirect(['action' => 'index']);
    }

    /** Adds a trust anchor (manual, out-of-band trust decision). */
    public function addAnchor(): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        $keyId = trim((string)$this->request->getData('key_id'));
        $publicKey = trim((string)$this->request->getData('public_key'));
        $type = (string)$this->request->getData('key_type');
        $publisher = trim((string)$this->request->getData('publisher')) ?: null;
        if ($keyId === '' || $publicKey === '' || !in_array($type, ['root', 'publisher'], true)) {
            $this->Flash->error(__('flash.trust.add_fields'));

            return $this->redirect(['action' => 'index']);
        }
        try {
            (new TrustStore())->addAnchor($keyId, $publicKey, $type, $publisher);
            $this->Flash->success(__('flash.trust.added'));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.trust.add_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }
}
