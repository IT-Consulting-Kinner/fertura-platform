<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Event\OutboxAdmin;

/**
 * Dead-Letter-Verwaltung (Kap. 26.9.2) im Bereich Core-Konfiguration:
 * Übersicht der Event-Stati + manuelles Retry/Verwerfen fehlgeschlagener Events.
 */
class OutboxController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    public function index(): void
    {
        $admin = new OutboxAdmin();
        $this->set('counts', $admin->counts());
        $this->set('deadLetters', $admin->deadLetters());
    }

    public function retry(string $id)
    {
        $this->request->allowMethod('post');
        $ok = (new OutboxAdmin())->retry($id);
        $ok ? $this->Flash->success(__('flash.outbox.retried')) : $this->Flash->error(__('flash.outbox.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    public function discard(string $id)
    {
        $this->request->allowMethod('post');
        $ok = (new OutboxAdmin())->discard($id);
        $ok ? $this->Flash->success(__('flash.outbox.discarded')) : $this->Flash->error(__('flash.outbox.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    public function retryAll()
    {
        $this->request->allowMethod('post');
        $n = (new OutboxAdmin())->retryAll();
        $this->Flash->success(__('flash.outbox.retried_all', $n));

        return $this->redirect(['action' => 'index']);
    }
}
