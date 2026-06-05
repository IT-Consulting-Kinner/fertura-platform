<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\License\LicenseService;
use App\Service\Marketplace\MarketplaceClient;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;

/**
 * Marketplace-Anbindung und Lizenzverwaltung
 * (Administrationsbereich „Marketplace / Lizenz").
 */
class MarketplaceController extends AdminController
{
    protected ?string $requiredArea = 'marketplace_license';

    public function index(): void
    {
        $settings = new SettingsManager();
        $baseUrl = (string)$settings->get('core', 'marketplace.base_url', '');
        $metadata = null;
        $error = null;
        if ($baseUrl !== '') {
            try {
                $metadata = (new MarketplaceClient())->metadata();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $this->set(compact('baseUrl', 'metadata', 'error'));
    }

    public function sync()
    {
        $this->request->allowMethod('post');
        try {
            $result = (new MarketplaceClient())->sync();
            $this->Flash->success(sprintf(
                'Synchronisierung abgeschlossen (Trust-Anchors: %d, Sperrliste: %d).',
                $result['anchors'] ?? 0,
                $result['revoked'] ?? 0,
            ));
        } catch (\Throwable $e) {
            $this->Flash->error('Synchronisierung fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    public function licenses(): void
    {
        $rows = ConnectionManager::get('default')->execute(
            'SELECT module_key, signed_key_id, valid_from, valid_to, grace_window_days, '
            . 'online_enforcement, status, installed_at FROM licenses ORDER BY module_key',
        )->fetchAll('assoc');
        $service = new LicenseService();
        $licenses = [];
        foreach ($rows as $row) {
            $row['evaluated'] = $service->evaluate((string)$row['module_key'])['status'];
            $licenses[] = $row;
        }
        $this->set(compact('licenses'));
    }

    public function uploadLicense()
    {
        $this->request->allowMethod('post');
        $file = $this->request->getData('license_file');
        $json = '';
        if ($file !== null && $file->getError() === UPLOAD_ERR_OK) {
            $json = (string)$file->getStream()->getContents();
        } else {
            $json = trim((string)$this->request->getData('license_json'));
        }
        if ($json === '') {
            $this->Flash->error('Keine Lizenzdatei übergeben.');

            return $this->redirect(['action' => 'licenses']);
        }
        try {
            $result = (new LicenseService())->install($json);
            $this->Flash->success('Lizenz installiert (Status: ' . ($result['status'] ?? '–') . ').');
        } catch (\Throwable $e) {
            $this->Flash->error('Lizenzinstallation fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirect(['action' => 'licenses']);
    }
}
