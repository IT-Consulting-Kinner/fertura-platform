<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Settings\SettingsCatalog;
use App\Service\Settings\SettingsManager;

/**
 * Core configuration (administration area "Core Configuration").
 *
 * Edits the keys known to the SettingsCatalog. Secrets are never
 * displayed in plaintext (Decision 159); an empty secret field
 * leaves the existing value unchanged.
 */
class ConfigController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    public function index(): void
    {
        $catalog = new SettingsCatalog();
        $manager = new SettingsManager();
        $settings = [];
        foreach ($catalog->all() as $namespace => $keys) {
            foreach ($keys as $key => $def) {
                $isSecret = !empty($def['secret']);
                $current = $manager->get($namespace, $key);
                $settings[] = [
                    'namespace' => $namespace,
                    'key' => $key,
                    'type' => $def['type'],
                    'secret' => $isSecret,
                    'min' => $def['min'] ?? null,
                    'max' => $def['max'] ?? null,
                    'default' => $def['default'],
                    'value' => $isSecret ? ($current === null || $current === '' ? null : '********') : $current,
                ];
            }
        }
        $this->set(compact('settings'));
    }

    public function save()
    {
        $this->request->allowMethod('post');
        $namespace = (string)$this->request->getData('namespace');
        $key = (string)$this->request->getData('key');
        $catalog = new SettingsCatalog();
        $def = $catalog->definition($namespace, $key);
        if ($def === null) {
            $this->Flash->error(__('flash.config.unknown_setting'));

            return $this->redirect(['action' => 'index']);
        }

        $raw = $this->request->getData('value');
        if (!empty($def['secret']) && ($raw === null || $raw === '')) {
            $this->Flash->success(__('flash.config.secret_unchanged'));

            return $this->redirect(['action' => 'index']);
        }

        $value = $this->coerce((string)$def['type'], $raw);
        $errors = $catalog->validate($namespace, $key, $value);
        if ($errors !== []) {
            $this->Flash->error(implode(' ', $errors));

            return $this->redirect(['action' => 'index']);
        }

        (new SettingsManager())->set($namespace, $key, $value);
        $this->Flash->success(__('flash.config.saved', $namespace, $key));

        return $this->redirect(['action' => 'index']);
    }

    private function coerce(string $type, mixed $raw): mixed
    {
        return match ($type) {
            'int' => $raw === null || $raw === '' ? null : (int)$raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($raw) ? json_decode($raw, true) : $raw,
            default => $raw === '' ? null : (string)$raw,
        };
    }
}
