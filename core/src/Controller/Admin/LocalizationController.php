<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\I18n\LanguagePackAdmin;

/**
 * Language management (administration area "Language Management", 7., E41).
 *
 * Overview of active/inactive components with their language packs (version
 * status), field-based lossless editor, import (unsigned upload, E42),
 * deletion (rules in E41) and review.
 */
class LocalizationController extends AdminController
{
    protected ?string $requiredArea = 'localization';

    public function index(): void
    {
        $admin = new LanguagePackAdmin();
        $this->set('components', $admin->overview());
        $this->set('installed', $admin->installedComponents());
    }

    public function edit()
    {
        $admin = new LanguagePackAdmin();
        [$component, $version, $locale, $domain] = $this->target();

        try {
            $entries = $admin->entries($component, $version, $locale, $domain);
            $meta = $admin->meta($component, $version, $locale);
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.lang.not_found'));

            return $this->redirect(['action' => 'index']);
        }

        $this->set(compact('entries', 'meta', 'component', 'version', 'locale', 'domain'));

        return null;
    }

    public function save()
    {
        $this->request->allowMethod('post');
        [$component, $version, $locale, $domain] = $this->target();
        /** @var array<int,mixed> $msgstr */
        $msgstr = (array)$this->request->getData('msgstr');
        $byIndex = [];
        foreach ($msgstr as $idx => $vals) {
            $byIndex[(int)$idx] = array_values((array)$vals);
        }

        try {
            $actor = $this->identity() !== null ? (string)$this->identity()->getIdentifier() : null;
            $admin = new LanguagePackAdmin();
            $admin->saveEntries($component, $version, $locale, $domain, $byIndex, $actor);
            $this->Flash->success(__('flash.lang.saved'));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.lang.save_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function delete()
    {
        $this->request->allowMethod('post');
        [$component, $version, $locale, $domain] = $this->target();
        try {
            (new LanguagePackAdmin())->deletePack($component, $version, $locale, $domain);
            $this->Flash->success(__('flash.lang.deleted'));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.lang.delete_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function review()
    {
        $this->request->allowMethod('post');
        [$component, $version, $locale, $domain] = $this->target();
        try {
            (new LanguagePackAdmin())->review($component, $version, $locale, $domain);
            $this->Flash->success(__('flash.lang.reviewed'));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.lang.save_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Import: GET shows the form; POST(step=preview) uploads + shows the
     * review preview (unsigned!, E42); POST(step=commit) applies it.
     */
    public function import()
    {
        $admin = new LanguagePackAdmin();
        $this->set('installed', $admin->installedComponents());

        if ($this->request->is('get')) {
            return null;
        }
        $step = (string)$this->request->getData('step');
        $component = (string)$this->request->getData('component');
        $version = (string)$this->request->getData('version');
        $locale = (string)$this->request->getData('locale');
        $domain = (string)$this->request->getData('domain');
        $type = (string)$this->request->getData('type');

        if ($locale === '' || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            $this->Flash->error(__('flash.lang.bad_locale'));

            return $this->redirect(['action' => 'import']);
        }

        $tmpDir = TMP . 'langimport';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        if ($step === 'commit') {
            $token = preg_replace('/[^a-f0-9]/', '', (string)$this->request->getData('token'));
            $path = $tmpDir . DIRECTORY_SEPARATOR . $token . '.po';
            try {
                $actor = $this->identity() !== null ? (string)$this->identity()->getIdentifier() : null;
                $admin->importCommit($path, $type ?: 'module', $component, $version, $locale, $domain ?: $component, $actor);
                @unlink($path);
                $this->Flash->success(__('flash.lang.imported'));
            } catch (\Throwable $e) {
                $this->Flash->error(__('flash.lang.import_failed', $e->getMessage()));
            }

            return $this->redirect(['action' => 'index']);
        }

        // step = preview
        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error(__('flash.lang.no_file'));

            return $this->redirect(['action' => 'import']);
        }
        $token = bin2hex(random_bytes(16));
        $path = $tmpDir . DIRECTORY_SEPARATOR . $token . '.po';
        $file->moveTo($path);

        $preview = $admin->importPreview($path, $component, $version, $locale);
        if (!$preview['ok']) {
            @unlink($path);
            $this->Flash->error(__('flash.lang.invalid_po', (string)$preview['error']));

            return $this->redirect(['action' => 'import']);
        }
        $this->set(compact('preview', 'token', 'component', 'version', 'locale', 'domain', 'type'));
        $this->viewBuilder()->setTemplate('import_confirm');

        return null;
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function target(): array
    {
        $get = fn (string $k): string => (string)($this->request->getData($k) ?? $this->request->getQuery($k) ?? '');

        return [$get('component'), $get('version'), $get('locale'), $get('domain')];
    }
}
