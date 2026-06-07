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
 * @since     3.3.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App;

use App\Middleware\FootprintMiddleware;
use App\Middleware\HostHeaderMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\TransactionRlsMiddleware;
use App\Service\Module\ModuleAutoloader;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application setup class.
 *
 * This defines the bootstrapping logic and middleware layers you
 * want to use in your application.
 *
 * @extends \Cake\Http\BaseApplication<\App\Application>
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /** Aktuelle Core-Version (SemVer) für Modul-Kompatibilitätsprüfungen. */
    public const CORE_VERSION = '1.0.0';

    /**
     * Load all the application configuration and bootstrap logic.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        // Call parent to load bootstrap from files.
        parent::bootstrap();

        // By default, does not allow fallback classes.
        FactoryLocator::add('Table', (new TableLocator())->allowFallbackClass(false));

        // Lokale Authentifizierung (Resolver-Default, Entscheidung 171).
        $this->addPlugin('Authentication');

        // Aktive Module zur Laufzeit autoloaden (Step 7, fehlertolerant).
        ModuleAutoloader::registerActiveModules();

        // i18n (E37/E39): fehlende Schlüssel fallen auf Englisch zurück. CakePHPs
        // eingebauter Fallback ist nur Domain-, kein Locale-Fallback -> eigener
        // Merge-Loader (Englisch als Basis) für die Core-Domain `default`.
        // Modul-Domains werden in i18n-4 analog registriert.
        \Cake\I18n\I18n::useFallback(true);
        \App\I18n\EnglishFallbackLoader::register('default');
        // Modul-/Extension-Domains aus dem Managed Locale Store (i18n-4),
        // fehlertolerant.
        \App\I18n\StoreLocaleLoader::registerActiveModules();

        // Session-Timeout aus der DB-Konfiguration anwenden (Kap. 27.16 /
        // setting core.session.timeout_minutes). Fehlertolerant: greift erst,
        // wenn die DB verfügbar ist (sonst CakePHP-Default).
        try {
            $minutes = (int)(new \App\Service\Settings\SettingsManager())
                ->get('core', 'session.timeout_minutes', 120);
            if ($minutes > 0) {
                \Cake\Core\Configure::write('Session.timeout', $minutes);
            }
        } catch (\Throwable) {
            // DB (noch) nicht verfügbar -> Framework-Default belassen.
        }
    }

    /**
     * Setup the middleware queue your application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Validate Host header to prevent Host Header Injection attacks.
            // In production, ensures App.fullBaseUrl is configured and validates
            // the incoming Host header against it.
            ->add(new HostHeaderMiddleware())

            // Wartungsmodus (Step 8): 503, wenn core.maintenance_mode aktiv.
            ->add(new MaintenanceMiddleware())

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))

            // Add routing middleware.
            // If you have a large number of routes connected, turning on routes
            // caching in production could improve performance.
            // See https://github.com/CakeDC/cakephp-cached-routing
            ->add(new RoutingMiddleware($this))

            // Parse various types of encoded request bodies so that they are
            // available as array through $request->getData()
            // https://book.cakephp.org/5/en/controllers/middleware.html#body-parser-middleware
            ->add(new BodyParserMiddleware())

            // Cross Site Request Forgery (CSRF) Protection Middleware
            // https://book.cakephp.org/5/en/security/csrf.html#cross-site-request-forgery-csrf-middleware
            ->add((new CsrfProtectionMiddleware([
                'httponly' => true,
            ]))->skipCheckCallback(static function (ServerRequestInterface $request): bool {
                // Externe API nutzt Bearer-Token statt CSRF (Kap. 29).
                return str_starts_with($request->getUri()->getPath(), '/api/');
            }))

            // Authentifizierung: stellt die Identitaet pro Request bereit.
            // Erzwingt selbst keinen Login; Controller/Adminbereich entscheiden.
            ->add(new AuthenticationMiddleware($this))

            // Externe API: Bearer-Token-Authentifizierung (nur /api/-Pfade);
            // setzt Identitaet + Scopes, sonst JSON 401. Nach der Session-Auth,
            // damit die Token-Identitaet fuer API-Requests Vorrang hat.
            ->add(new \App\Middleware\ApiAuthMiddleware())

            // Anzeigesprache pro Request setzen (i18n, E37) – nach der
            // AuthenticationMiddleware, damit user.locale verfuegbar ist.
            ->add(new \App\Middleware\LocaleMiddleware())

            // Footprint: uebernimmt die Identitaet in den ActorContext fuer
            // created_by/updated_by (muss NACH der AuthenticationMiddleware laufen).
            ->add(new FootprintMiddleware())

            // RLS: Request in Transaktion huellen + Zugriffskontext via SET LOCAL
            // (Step 9, Entscheidung 175). Nach der AuthenticationMiddleware.
            ->add(new TransactionRlsMiddleware());

        return $middlewareQueue;
    }

    /**
     * Authentifizierungsdienst über den austauschbaren Provider-Resolver-Slot
     * (Kap. 27.2.2). Der aktive Provider für `core.auth.provider` konfiguriert
     * den Dienst; ohne aktiven (oder bei defektem) Provider greift der lokale
     * Default — die Plattform bleibt immer anmeldbar (Break-Glass).
     *
     * Identitäten bleiben Core-verwaltet; ein externer Provider (SSO/AD)
     * authentifiziert nur (JIT-Provisioning). Autorisierung bleibt unabhängig.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request.
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService([
            'unauthenticatedRedirect' => '/login',
            'queryParam' => 'redirect',
        ]);

        (new \App\Service\Auth\AuthProviderResolver())->provider()->configure($service);

        return $service;
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
        // Allow your Tables to be dependency injected
        //$container->delegate(new \Cake\ORM\Locator\TableContainer());
    }

    /**
     * Register custom event listeners here
     *
     * @param \Cake\Event\EventManagerInterface $eventManager
     * @return \Cake\Event\EventManagerInterface
     * @link https://book.cakephp.org/5/en/core-libraries/events.html#registering-listeners
     */
    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        // $eventManager->on(new SomeCustomListenerClass());

        return $eventManager;
    }
}
