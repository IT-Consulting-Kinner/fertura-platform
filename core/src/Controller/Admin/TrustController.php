<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Marketplace\MarketplaceClient;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;

/**
 * Verwaltung der **Vertrauensanker & Sperrliste** (Kap. 24.9.2) — GUI-Pendant zu
 * `bin/cake trust`. Sicherheitskritisch: Anker = Wurzel der Modul-Signaturkette;
 * Aktionen daher mit harter Bestätigung. Liefert Anzeige (Anker + Gültigkeit +
 * Sperrliste + CRL-Alter), **Widerruf** eines Schlüssels (markiert betroffene
 * Module) und **manuelles Hinzufügen** eines Ankers (Out-of-band-Vertrauen).
 *
 * Bewusst CLI belassen: gleitende Schlüsselrotation mit Überlappungsfenster
 * (`trust rotate`) und das Einlesen aus Zertifikatsdateien — Operator-/Datei-Pfad.
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

        // Jeden Anker um sein Gültigkeits-/Sperr-Resultat anreichern (E45/24.9.2).
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
            // CRL-Status ist informativ; ohne Marketplace-Konfig leer.
        }

        $this->set(compact('anchors', 'revoked', 'crl'));
    }

    /** Widerruft einen Schlüssel und markiert betroffene Module (deny-side). */
    public function revoke(string $keyId): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        if (trim($keyId) === '' || strlen($keyId) > 256) {
            $this->Flash->error(__('flash.trust.bad_key'));

            return $this->redirect(['action' => 'index']);
        }
        $trust = new TrustStore();
        $trust->revokeKey($keyId, (string)$this->request->getData('reason') ?: null, 'manual');
        // Module, die mit diesem Schlüssel signiert wurden, als revoked kennzeichnen.
        $affected = $trust->reconcileModuleSignatures();
        $this->Flash->success(__('flash.trust.revoked', $keyId, $affected));

        return $this->redirect(['action' => 'index']);
    }

    /** Fügt einen Vertrauensanker hinzu (manuelle, out-of-band Vertrauensentscheidung). */
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
