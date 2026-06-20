<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Api\TokenService;
use Cake\Http\Response;

/**
 * Self-service management of one's own API tokens (ch. 29 / Decision 162).
 * Every administrator manages exclusively their own tokens; the plaintext
 * is shown only once, at creation time.
 */
class TokensController extends AdminController
{
    protected ?string $requiredArea = null;

    public function index(): void
    {
        $this->renderTokenList(false);
    }

    /** Renders the token list plus the inline "create" accordion form. */
    private function renderTokenList(bool $openCreate): void
    {
        $userId = (string)$this->identity()->getIdentifier();
        $this->set('tokens', (new TokenService())->listForUser($userId));
        $this->set('knownScopes', TokenService::KNOWN_SCOPES);
        // Plaintext token (shown once) handed over via the session by create().
        $this->set('newToken', $this->request->getSession()->consume('newApiToken'));
        $this->set('openCreate', $openCreate);
        $this->viewBuilder()->setTemplate('index');
    }

    public function create(): ?Response
    {
        $this->request->allowMethod('post');
        $userId = (string)$this->identity()->getIdentifier();
        $label = trim((string)$this->request->getData('label'));
        $scopes = array_values(array_filter((array)$this->request->getData('scopes'), 'is_string'));
        $expiresAt = trim((string)$this->request->getData('expires_at')) ?: null;

        if ($scopes === []) {
            // Re-render with the create accordion open instead of redirecting.
            $this->Flash->error(__('flash.token.need_scope'));
            $this->renderTokenList(true);

            return null;
        }

        $result = (new TokenService())->create($userId, $label, $scopes, $expiresAt, $userId);
        // Pass the plaintext to the index page once, via the session.
        $this->request->getSession()->write('newApiToken', $result['token']);
        $this->Flash->success(__('flash.token.created'));

        return $this->redirect(['action' => 'index']);
    }

    public function revoke(string $id): ?Response
    {
        $this->request->allowMethod('post');
        $userId = (string)$this->identity()->getIdentifier();
        $ok = (new TokenService())->revoke($id, $userId);
        $ok ? $this->Flash->success(__('flash.token.revoked')) : $this->Flash->error(__('flash.token.not_found'));

        return $this->redirect(['action' => 'index']);
    }
}
