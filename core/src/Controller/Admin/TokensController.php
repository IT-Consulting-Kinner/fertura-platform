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

    /**
     * Admin areas a user must hold to MINT a privileged scope (the token's
     * capability must not exceed the minting user's own permissions). A token
     * authenticates as its user, but the API endpoints check only the scope, so
     * an ungated scope would let any admin grant themselves a capability they do
     * not otherwise have. `scim:manage` performs directory provisioning, which is
     * the user-management area; the cross-tenant risk is already closed by the
     * per-tenant scoping in ScimUsersController, this bounds the WITHIN-tenant
     * privilege. Scopes absent from this map are self-service (read-only).
     *
     * @var array<string,string>
     */
    private const SCOPE_AREA_REQUIREMENTS = ['scim:manage' => 'user_group_admin'];

    public function index(): void
    {
        $this->renderTokenList(false);
    }

    /** Renders the token list plus the inline "create" accordion form. */
    private function renderTokenList(bool $openCreate): void
    {
        $userId = (string)$this->identity()->getIdentifier();
        $this->set('tokens', (new TokenService())->listForUser($userId));
        // Only offer scopes the user may actually mint (see SCOPE_AREA_REQUIREMENTS).
        $this->set('knownScopes', $this->mintableScopes());
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

        // Server-side guard: reject any requested scope the user is not allowed to
        // mint (the form already hides them, but never trust the submitted list).
        if (array_diff($scopes, $this->mintableScopes()) !== []) {
            $this->Flash->error(__('flash.token.scope_forbidden'));
            $this->renderTokenList(true);

            return null;
        }

        $result = (new TokenService())->create($userId, $label, $scopes, $expiresAt, $userId);
        // Pass the plaintext to the index page once, via the session.
        $this->request->getSession()->write('newApiToken', $result['token']);
        $this->Flash->success(__('flash.token.created'));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * The scopes the current admin may mint: every known scope without an area
     * requirement, plus those whose required area the user holds.
     *
     * @return list<string>
     */
    private function mintableScopes(): array
    {
        return array_values(array_filter(
            TokenService::KNOWN_SCOPES,
            fn(string $scope): bool => !isset(self::SCOPE_AREA_REQUIREMENTS[$scope])
                || in_array(self::SCOPE_AREA_REQUIREMENTS[$scope], $this->userAreaKeys, true),
        ));
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
