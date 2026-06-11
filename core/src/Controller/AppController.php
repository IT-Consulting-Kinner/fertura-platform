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

use Cake\Controller\Controller;
use Cake\Event\EventInterface;

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

        // MFA-Pflicht (security.mfa.required): angemeldete Benutzer ohne
        // eingerichtetes TOTP werden auf die Einrichtung gelenkt — überall
        // außer auf den Auth-/MFA-Seiten selbst (sonst Redirect-Schleife).
        $identity = $this->identity();
        if ($identity === null || in_array($this->request->getParam('controller'), ['Auth', 'Mfa', 'Sso'], true)) {
            return;
        }
        // SSO-Sitzungen sind ausgenommen (MFA-Policy liegt beim IdP).
        if ($this->request->getSession()->read('Auth.via_sso') === true) {
            return;
        }
        $identifier = $identity->getIdentifier();
        if (!is_string($identifier)) {
            return;
        }
        try {
            $mfa = new \App\Service\Security\MfaService();
            if ($mfa->required() && !$mfa->enabled($identifier)) {
                $this->Flash->error(__('flash.mfa.setup_required'));
                $event->setResult($this->redirect('/mfa'));
            }
        } catch (\Throwable) {
            // Fail-open hier bewusst NICHT für die Anmeldung selbst (die ist
            // bereits passiert), sondern nur für die Setup-Umleitung: ein
            // Settings-/DB-Problem darf die ganze Oberfläche nicht sperren.
        }
    }

    /**
     * Stellt die aktuelle Identität (oder null) bereit.
     *
     * @return \Authentication\IdentityInterface|null
     */
    protected function identity()
    {
        return $this->request->getAttribute('identity');
    }
}
