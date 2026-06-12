<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Test\TestCase;

use App\Application;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\LogContextMiddleware;
use App\Middleware\TransactionRlsMiddleware;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ApplicationTest class
 */
class ApplicationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Test bootstrap in production.
     *
     * @return void
     */
    public function testBootstrap()
    {
        Configure::write('debug', false);
        $app = new Application(dirname(__DIR__, 2) . '/config');
        $app->bootstrap();
        $plugins = $app->getPlugins();

        $this->assertTrue($plugins->has('Bake'), 'plugins has Bake?');
        $this->assertFalse($plugins->has('DebugKit'), 'plugins has DebugKit?');
        $this->assertTrue($plugins->has('Migrations'), 'plugins has Migrations?');
    }

    /**
     * Test bootstrap add DebugKit plugin in debug mode.
     *
     * @return void
     */
    public function testBootstrapInDebug()
    {
        Configure::write('debug', true);
        $app = new Application(dirname(__DIR__, 2) . '/config');
        $app->bootstrap();
        $plugins = $app->getPlugins();

        $this->assertTrue($plugins->has('DebugKit'), 'plugins has DebugKit?');
    }

    /**
     * testMiddleware
     *
     * @return void
     */
    public function testMiddleware()
    {
        $app = new Application(dirname(__DIR__, 2) . '/config');
        $queue = $app->middleware(new MiddlewareQueue());

        $classes = [];
        for ($i = 0, $n = count($queue); $i < $n; $i++) {
            $queue->seek($i);
            $classes[] = $queue->current()::class;
        }

        // LogContext is outermost so that ErrorHandler logs also carry the context.
        $this->assertSame(LogContextMiddleware::class, $classes[0]);
        $this->assertContains(ErrorHandlerMiddleware::class, $classes);
        $this->assertContains(RoutingMiddleware::class, $classes);

        $idx = static fn(string $c): int|false => array_search($c, $classes, true);
        $auth = $idx(AuthenticationMiddleware::class);
        $api = $idx(ApiAuthMiddleware::class);
        $rls = $idx(TransactionRlsMiddleware::class);
        $this->assertNotFalse($auth);
        // API token auth after session auth (token identity takes precedence).
        $this->assertGreaterThan($auth, $api);
        // RLS transaction (SET LOCAL) after authentication.
        $this->assertGreaterThan($auth, $rls);
    }
}
