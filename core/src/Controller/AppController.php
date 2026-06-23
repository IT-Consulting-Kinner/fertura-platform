<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use App\Service\Security\MfaService;
use App\Service\Tenant\TenantService;
use Cake\Controller\Controller;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Throwable;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/5/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
    }

    /** @return \Cake\Http\Response|null|void */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        // MFA enforcement (security.mfa.required): authenticated users without
        // configured TOTP are redirected to setup — everywhere except the
        // Auth/MFA pages themselves (otherwise a redirect loop).
        $identity = $this->identity();
        if ($identity === null || in_array($this->request->getParam('controller'), ['Auth', 'Mfa', 'Sso'], true)) {
            return;
        }
        // SSO sessions are exempt (the MFA policy is owned by the IdP).
        if ($this->request->getSession()->read('Auth.via_sso') === true) {
            return;
        }
        $identifier = $identity->getIdentifier();
        if (!is_string($identifier)) {
            return;
        }
        try {
            $mfa = new MfaService();
            if ($mfa->required() && !$mfa->enabled($identifier)) {
                $this->Flash->error(__('flash.mfa.setup_required'));
                $event->setResult($this->redirect('/mfa'));
            }
        } catch (Throwable) {
            // Fail-open here is deliberately NOT about the login itself (that
            // has already happened), only about the setup redirect: a settings
            // or DB problem must not lock the entire UI.
        }
    }

    /**
     * Provides the current identity (or null).
     *
     * @return \Authentication\IdentityInterface|null
     */
    protected function identity()
    {
        return $this->request->getAttribute('identity');
    }

    /**
     * Whether the caller is an OPERATOR admin: authenticated, in the operator
     * (default) tenant per the request RLS context, AND holding at least one
     * administration area. The gate for platform-wide/operator-only data exposed
     * outside the AdminController hierarchy (e.g. /health/detail, /metrics) so a
     * mere authenticated tenant user cannot read platform internals. Fail-closed:
     * a NULL/foreign tenant or a user without any admin area yields false.
     */
    protected function isOperatorAdmin(): bool
    {
        $identity = $this->identity();
        $userId = $identity?->getIdentifier();
        if (!is_string($userId)) {
            return false;
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $row = $conn->execute(
            'SELECT (core.current_tenant() = :op) '
            . 'AND EXISTS (SELECT 1 FROM user_admin_areas WHERE user_id = :u) AS ok',
            ['op' => TenantService::DEFAULT_TENANT_ID, 'u' => $userId],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }
}
