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

use App\I18n\EnglishFallbackLoader;
use App\I18n\StoreLocaleLoader;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\ApiRateLimitMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\FootprintMiddleware;
use App\Middleware\HostHeaderMiddleware;
use App\Middleware\LocaleMiddleware;
use App\Middleware\LogContextMiddleware;
use App\Middleware\LoginThrottleMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\SelectiveMaintenanceMiddleware;
use App\Middleware\SessionGuardMiddleware;
use App\Middleware\TransactionRlsMiddleware;
use App\Middleware\UniqueViolationMiddleware;
use App\Service\Auth\AuthProviderResolver;
use App\Service\Module\ModuleAutoloader;
use App\Service\Security\CookieSecurity;
use App\Service\Settings\SettingsManager;
use App\Service\System\FeatureFlags;
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
use Cake\I18n\I18n;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

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
    /** Current core version (SemVer), used for module compatibility checks. */
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

        // Local authentication (the resolver default, Decision 171).
        $this->addPlugin('Authentication');

        // Autoload active modules at runtime (Step 7, fault-tolerant).
        ModuleAutoloader::registerActiveModules();

        // i18n (E37/E39): missing keys fall back to English. CakePHP's built-in
        // fallback is domain-only, not locale-fallback -> our own merge loader
        // (English as the base) for the core `default` domain.
        // Module domains are registered analogously in i18n-4.
        I18n::useFallback(true);
        EnglishFallbackLoader::register('default');
        // Module/extension domains from the Managed Locale Store (i18n-4),
        // fault-tolerant.
        StoreLocaleLoader::registerActiveModules();

        // Apply the session timeout from the DB configuration (ch. 27.16 /
        // setting core.session.timeout_minutes). Fault-tolerant: only takes
        // effect once the DB is available (otherwise the CakePHP default).
        try {
            $minutes = (int)(new SettingsManager())
                ->get('core', 'session.timeout_minutes', 120);
            if ($minutes > 0) {
                Configure::write('Session.timeout', $minutes);
            }
        } catch (Throwable) {
            // DB not (yet) available -> keep the framework default.
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
            // Log context (ch. 20.2.3): correlation_id/request_id/component for
            // every log line. Outermost, so ErrorHandler logs carry it too.
            ->add(new LogContextMiddleware())

            // Security headers (CSP/nosniff/Frame/Referrer/HSTS) on EVERY
            // response — ABOVE the ErrorHandler: an exception travels up through
            // this middleware, and only the returning (error) response gets the
            // headers. If it sat below, error pages would be left bare.
            ->add(new SecurityHeadersMiddleware())

            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Validate Host header to prevent Host Header Injection attacks.
            // In production, ensures App.fullBaseUrl is configured and validates
            // the incoming Host header against it.
            ->add(new HostHeaderMiddleware())

            // Maintenance mode (Step 8): 503 when core.maintenance_mode is active.
            ->add(new MaintenanceMiddleware())

            // CORS for the headless content API (E160): adds CORS headers to
            // PUBLIC module API routes and answers preflight OPTIONS directly.
            // BEFORE routing so a preflight (which matches no route) is not 404'd.
            ->add(new CorsMiddleware())

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
                // Defense-in-depth for the CSRF cookie: SameSite=Lax always and
                // the Secure flag fail-safe ON outside local debug/dev (see
                // CookieSecurity). Local HTTP dev usage stays functional.
                'samesite' => 'Lax',
                'secure' => CookieSecurity::enabled(),
            ]))->skipCheckCallback(static function (ServerRequestInterface $request): bool {
                $path = $request->getUri()->getPath();

                // The external API uses Bearer tokens instead of CSRF (ch. 29).
                // SAML ACS is a form posted by the IdP (no CSRF token); its
                // authenticity is guaranteed by the signed SAML assertion (P06).
                return str_starts_with($path, '/api/') || $path === '/sso/saml/acs';
            }))

            // Per-IP login protection BEFORE authentication (P-Review #2): an
            // IP with too many failed attempts (password spraying) is rejected
            // with 429 before any password is hashed. After BodyParser/CSRF,
            // before the AuthenticationMiddleware.
            ->add(new LoginThrottleMiddleware())

            // Authentication: provides the identity per request.
            // Does not enforce a login itself; controllers/admin area decide.
            ->add(new AuthenticationMiddleware($this))

            // Session anomaly detection (UA binding, IP change, new device) —
            // immediately after authentication (needs the identity).
            ->add(new SessionGuardMiddleware())

            // Selective maintenance gate (Phase 3): when a maintenance session is
            // engaged, 503 every request EXCEPT the operator who engaged it (identity
            // == actor or a valid allow-token cookie). After the AuthenticationMiddleware
            // so the identity is available; also fail-closes POST /login + SSO + MFA.
            ->add(new SelectiveMaintenanceMiddleware())

            // External API: Bearer-token authentication (only /api/ paths);
            // sets identity + scopes, otherwise JSON 401. After the session auth,
            // so the token identity takes precedence for API requests. Only
            // loaded when the external API is active (FEATURE_API); otherwise a
            // no-op (an empty array is not enqueued).
            ->add(FeatureFlags::enabled('api')
                ? new ApiAuthMiddleware()
                : [])

            // API rate limiting (P07): after the token auth, so limiting can be
            // per token (otherwise per IP). Only when the API is active.
            ->add(FeatureFlags::enabled('api')
                ? new ApiRateLimitMiddleware()
                : [])

            // Set the display language per request (i18n, E37) – after the
            // AuthenticationMiddleware, so user.locale is available.
            ->add(new LocaleMiddleware())

            // Footprint: carries the identity into the ActorContext for
            // created_by/updated_by (must run AFTER the AuthenticationMiddleware).
            ->add(new FootprintMiddleware())

            // Global safety net: a unique-constraint violation (23505) from any
            // create/rename becomes a Flash warning + redirect (or 409 for JSON)
            // instead of a 500. Sits directly OUTSIDE the tx layer so the request
            // transaction is already rolled back when we emit the response.
            ->add(new UniqueViolationMiddleware())

            // RLS: wrap the request in a transaction + set the access context via
            // SET LOCAL (Step 9, Decision 175). After the AuthenticationMiddleware.
            ->add(new TransactionRlsMiddleware());

        return $middlewareQueue;
    }

    /**
     * Authentication service via the pluggable provider-resolver slot
     * (ch. 27.2.2). The active provider for `core.auth.provider` configures the
     * service; with no active (or a broken) provider the local default applies —
     * the platform always stays loginable (break-glass).
     *
     * Identities remain core-managed; an external provider (SSO/AD) only
     * authenticates (JIT provisioning). Authorization stays independent.
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

        (new AuthProviderResolver())->provider()->configure($service);

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
