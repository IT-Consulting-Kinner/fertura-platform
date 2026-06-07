<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

/**
 * GET /api/v1/me — Identität + Scopes des aktuellen Tokens (Scope `me:read`).
 */
class MeController extends ApiController
{
    public function index()
    {
        if ($denied = $this->requireScope('me:read')) {
            return $denied;
        }
        $identity = $this->request->getAttribute('identity');
        $data = $identity?->getOriginalData();

        return $this->json([
            'user_id' => $this->userId(),
            'username' => $data?->get('username'),
            'email' => $data?->get('email'),
            'locale' => $data?->get('locale'),
            'scopes' => $this->scopes(),
            'token_id' => $this->request->getAttribute('apiTokenId'),
        ]);
    }
}
