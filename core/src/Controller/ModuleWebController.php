<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Admin\AdminNavBuilder;
use App\Service\Module\ContributionRuntime;
use App\Service\Module\WebRouteRegistry;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\Log\Log;
use Throwable;

/**
 * Core-controlled dispatcher for module-provided **server-rendered web pages**
 * (ch. 23.16.3, web-mount): `GET|POST /m/<key>[/<path>]`.
 *
 * This is the **web** counterpart to {@see Api\V1\ModuleController} (which serves
 * the JSON API at `/api/v1/m/<key>`). It runs in the normal web middleware stack
 * — **session authentication, CSRF protection, RLS transaction, security
 * headers** — and keeps the Core in control of routing, auth, admin-area gating,
 * layout and rendering. The module contributes only a handler class
 * ({@see \App\Service\Module\ModuleWebInterface}) plus a template file shipped in
 * its own `templates/` directory; it never registers Core routes or touches the
 * response directly.
 *
 * Web pages are invoked **in-process** only — HTML rendering cannot cross the
 * out-of-process RPC boundary (validated at install time).
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 */
class ModuleWebController extends AppController
{
    /** @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        // The single `dispatch` action serves both authenticated and guest
        // module pages. Per-route authentication is enforced in dispatch()
        // itself, so the Authentication component must NOT auto-redirect guest
        // pages to the login screen.
        $this->Authentication->allowUnauthenticated(['dispatch']);
    }

    public function dispatch(string $moduleKey, string $path = ''): ?Response
    {
        $route = (new WebRouteRegistry())->match($moduleKey, '/' . $path);
        if ($route === null) {
            throw new NotFoundException();
        }

        // A page that declares an `area` is an ADMIN page: it renders in the Core
        // admin shell (admin layout + scoped sidebar) and requires the user to
        // hold that area. Otherwise it is a standalone end-user/guest page.
        $isAdmin = $route['area'] !== null;

        // Authentication: guest pages are public; every other page requires a
        // logged-in user (and, for admin pages, the declared area).
        $identity = $this->identity();
        $rawId = $identity?->getIdentifier();
        $userId = is_scalar($rawId) ? (string)$rawId : null;
        $userAreas = [];
        if ($route['auth'] === 'user') {
            if ($userId === null) {
                return $this->redirect('/login');
            }
            if ($isAdmin) {
                $userAreas = $this->loadUserAreas($userId);
                if (!in_array($route['area'], $userAreas, true)) {
                    throw new ForbiddenException();
                }
            }
        }

        $request = [
            'method' => strtoupper($this->request->getMethod()),
            'path' => '/' . $path,
            'params' => (array)$route['params'],
            'query' => $this->request->getQueryParams(),
            'body' => $this->request->getParsedBody() ?? [],
            'user_id' => $userId,
        ];

        try {
            $result = (array)(new ContributionRuntime())->call(
                ['class' => (string)$route['class'], 'module_key' => $moduleKey, 'isolation' => 'in_process'],
                'handle',
                [$request],
            );
        } catch (Throwable $e) {
            // Do not leak internal details (paths/SQL/classes) to the client.
            Log::error('Modul-Web-Fehler: ' . $e->getMessage(), ['module' => $moduleKey, 'path' => '/' . $path]);
            throw new InternalErrorException();
        }

        // The handler result is module-provided (untrusted shape): coerce every
        // field defensively before it touches the response.
        if (isset($result['redirect']) && is_string($result['redirect']) && $result['redirect'] !== '') {
            return $this->redirect($result['redirect']);
        }
        $vars = isset($result['vars']) && is_array($result['vars']) ? $result['vars'] : [];
        $template = isset($result['template']) && is_string($result['template']) && $result['template'] !== ''
            ? $result['template'] : $route['template'];
        $title = isset($result['title']) && is_string($result['title']) ? $result['title'] : $route['title'];
        $status = isset($result['status']) && is_int($result['status']) ? $result['status'] : 200;

        // Render the module-provided template under the Core's module layout.
        // The module ROOT (with a trailing separator, as CakePHP expects for a
        // template path root) is prepended to the search paths, and the template
        // is resolved under its `templates/` subdir — so the module ships
        // `<module>/templates/<tpl>.php`. The Core layouts/elements remain
        // reachable via the existing (lower-priority) paths.
        $tplRoot = $route['source_path'] . DIRECTORY_SEPARATOR;
        $paths = (array)Configure::read('App.paths.templates');
        if (!in_array($tplRoot, $paths, true)) {
            Configure::write('App.paths.templates', array_merge([$tplRoot], $paths));
        }

        $this->set($vars);
        $this->set('moduleKey', $moduleKey);
        $this->set('moduleTitle', $title);
        $this->set('currentUser', $identity);

        // Admin pages render in the admin shell (scoped sidebar incl. this
        // module's nav entries); standalone pages in the Core module layout.
        if ($isAdmin) {
            $this->set('navAreas', (new AdminNavBuilder())->build($userAreas));
            $this->set('userAreas', $userAreas);
            $this->set('activeArea', $route['area']);
        }
        $this->viewBuilder()
            ->setLayout($isAdmin ? 'admin' : 'module')
            ->setTemplatePath('templates')
            ->setTemplate($template);
        $this->response = $this->response->withStatus($status);

        return null;
    }

    /**
     * The admin areas the user holds (ch. 27.3.1).
     *
     * @return list<string>
     */
    private function loadUserAreas(string $userId): array
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $rows = $conn->execute(
            'SELECT admin_area_key FROM user_admin_areas WHERE user_id = :u',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_values(array_map(static fn($r) => (string)$r['admin_area_key'], $rows));
    }
}
