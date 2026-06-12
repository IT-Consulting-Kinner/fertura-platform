<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Event\OutboxAdmin;
use Cake\Http\Response;

/**
 * Dead-letter management (ch. 26.9.2) within the Core configuration area:
 * overview of event statuses plus manual retry/discard of failed events.
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

    public function retry(string $id): ?Response
    {
        $this->request->allowMethod('post');
        $ok = (new OutboxAdmin())->retry($id);
        $ok ? $this->Flash->success(__('flash.outbox.retried')) : $this->Flash->error(__('flash.outbox.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    public function discard(string $id): ?Response
    {
        $this->request->allowMethod('post');
        $ok = (new OutboxAdmin())->discard($id);
        $ok ? $this->Flash->success(__('flash.outbox.discarded')) : $this->Flash->error(__('flash.outbox.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    public function retryAll(): ?Response
    {
        $this->request->allowMethod('post');
        $n = (new OutboxAdmin())->retryAll();
        $this->Flash->success(__('flash.outbox.retried_all', $n));

        return $this->redirect(['action' => 'index']);
    }
}
