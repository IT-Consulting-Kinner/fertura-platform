<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);

        // Authentifizierung (Step 10).
        $builder->connect('/login', ['controller' => 'Auth', 'action' => 'login']);
        $builder->connect('/logout', ['controller' => 'Auth', 'action' => 'logout']);
        // Passwort vergessen + setzen per Einladungs-/Reset-Token (Kap. 27.2/27.15).
        $builder->connect('/forgot-password', ['controller' => 'Auth', 'action' => 'forgotPassword']);
        $builder->connect('/set-password', ['controller' => 'Auth', 'action' => 'setPassword']);

        // Health-Endpoint (Step 12, Kap. 20.2.1): öffentlicher Liveness + geschützter Detailstatus.
        $builder->connect('/health', ['controller' => 'Health', 'action' => 'index']);
        $builder->connect('/health/detail', ['controller' => 'Health', 'action' => 'detail']);

        // Prometheus-Metriken (P04): geschützt wie der Health-Detailpfad.
        $builder->connect('/metrics', ['controller' => 'Metrics', 'action' => 'index']);

        // Admin-Bereich (scoped admin, Kap. 27.3.1).
        $builder->prefix('Admin', function (RouteBuilder $admin): void {
            $admin->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);
            $admin->fallbacks();
        });

        // Externe API v1 (Kap. 29): Bearer-Token (ApiAuthMiddleware), JSON.
        // Per Deployment abschaltbar (FEATURE_API=false) -> keine /api-Routen.
        if (\App\Service\System\FeatureFlags::enabled('api')) {
            $builder->prefix('Api', function (RouteBuilder $api): void {
                $api->prefix('V1', function (RouteBuilder $v1): void {
                    $v1->connect('/health', ['controller' => 'Health', 'action' => 'index']);
                    $v1->connect('/me', ['controller' => 'Me', 'action' => 'index']);
                    $v1->connect('/modules', ['controller' => 'Modules', 'action' => 'index']);
                });
            });
        }

        $builder->connect('/pages/*', 'Pages::display');

        /*
         * Connect catchall routes for all controllers.
         *
         * The `fallbacks` method is a shortcut for
         *
         * ```
         * $builder->connect('/{controller}', ['action' => 'index']);
         * $builder->connect('/{controller}/{action}/*', []);
         * ```
         *
         * It is NOT recommended to use fallback routes after your initial prototyping phase!
         * See https://book.cakephp.org/5/en/development/routing.html#fallbacks-method for more information
         */
        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
