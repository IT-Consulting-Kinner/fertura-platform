<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Admin\AdminNavBuilder;
use App\Service\Module\ContributionRuntime;
use App\Service\Module\TenantModuleService;
use App\Service\Module\WebRouteRegistry;
use App\Service\Storage\StorageManager;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\Log\Log;
use Laminas\Diactoros\Stream;
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
        // logged-in user (and, for admin pages, the declared area). An ADMIN page
        // (declares an `area`) is treated as authenticated REGARDLESS of its
        // declared `auth` — defense in depth against a malformed manifest that
        // pairs `area` with `auth='guest'`, which would otherwise skip login, the
        // per-tenant enablement gate AND the area check yet still render in the
        // admin shell. (The ManifestLinter also rejects that combination at install
        // time, so this is the belt to the linter's braces.)
        $identity = $this->identity();
        $rawId = $identity?->getIdentifier();
        $userId = is_scalar($rawId) ? (string)$rawId : null;
        $userAreas = [];
        if ($route['auth'] === 'user' || $isAdmin) {
            if ($userId === null) {
                return $this->redirect('/login');
            }
            // Fail-closed per-tenant module enablement (operator/tenant authz §5):
            // an authenticated module page is only reachable when the module is
            // enabled for the user's tenant. Guest pages (auth !== 'user') are
            // public and intentionally NOT gated here. A 404 (not 403) is returned
            // so a module not provisioned to this tenant is indistinguishable from
            // one that does not exist on the platform.
            if (!(new TenantModuleService())->isEnabled($moduleKey)) {
                throw new NotFoundException();
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
            // Client IP for module-side rate-limiting of public/guest pages (e.g.
            // the Ticketing guest portal). Uses clientIp() = REMOTE_ADDR unless
            // trusted proxies are configured, so it is not X-Forwarded-For-spoofable
            // (same source the Core LoginThrottle keys on).
            'client_ip' => $this->request->clientIp() ?: null,
        ];
        // Per-tenant module config (Increment 5 Phase 3): the handler reads its
        // tenant's settings via $request['module_config'] without calling the Core.
        $request['module_config'] = (new TenantModuleService())->config($moduleKey);

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
        // File download (E161): a handler may return a `download` instead of a
        // template — either in-memory bytes (`content`) or a streamed object-
        // storage report (`storage_path`, for large exports without memory load).
        if (isset($result['download']) && is_array($result['download'])) {
            return $this->moduleDownload($result['download']);
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
            $navBuilder = new AdminNavBuilder();
            $this->set('navAreas', $navBuilder->build($userAreas));
            $this->set('topMenu', $navBuilder->menu($userAreas));
            $this->set('userAreas', $userAreas);
            $this->set('activeArea', $route['area']);
            $this->set('activeTop', $navBuilder->areaTop((string)$route['area']));
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

    /**
     * Builds a file-download response from a handler's `download` result
     * (E161). The shape is module-provided (untrusted) and coerced defensively:
     * the filename and content type are sanitized to prevent header injection.
     *
     * Two variants: in-memory `content` (string bytes) for normal exports, or
     * `storage_path` streamed from object storage (StorageManager) for large
     * reports without holding the whole file in memory.
     *
     * @param array<string, mixed> $dl
     */
    private function moduleDownload(array $dl): Response
    {
        $filename = $this->safeFilename(is_string($dl['filename'] ?? null) ? $dl['filename'] : 'download');
        $contentType = $this->safeContentType(is_string($dl['content_type'] ?? null) ? $dl['content_type'] : '');

        // Variant (a): in-memory bytes.
        if (is_string($dl['content'] ?? null)) {
            return $this->response
                ->withType($contentType)
                ->withStringBody($dl['content'])
                ->withDownload($filename);
        }

        // Variant (b): streamed from object storage.
        if (is_string($dl['storage_path'] ?? null) && $dl['storage_path'] !== '') {
            $path = $dl['storage_path'];
            // The module addresses its own report path; reject traversal defensively.
            if (str_contains($path, '..')) {
                throw new ForbiddenException();
            }
            $storage = new StorageManager();
            if (!$storage->exists($path)) {
                throw new NotFoundException();
            }
            $resource = $storage->readStream($path);
            if (!is_resource($resource)) {
                throw new InternalErrorException();
            }
            $response = $this->response
                ->withType($contentType)
                ->withBody(new Stream($resource))
                ->withDownload($filename);
            try {
                return $response->withHeader('Content-Length', (string)$storage->fileSize($path));
            } catch (Throwable) {
                return $response; // size unknown -> let the transport handle it
            }
        }

        // Neither variant supplied: a download with no payload is a module bug.
        throw new InternalErrorException();
    }

    /** Sanitizes a download filename (basename + safe charset) against header injection. */
    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name)) ?? '';

        return $name !== '' ? $name : 'download';
    }

    /** Validates a Content-Type (type/subtype, no CR/LF) or falls back to octet-stream. */
    private function safeContentType(string $contentType): string
    {
        $contentType = trim(str_replace(["\r", "\n"], '', $contentType));

        return $contentType !== '' && str_contains($contentType, '/') ? $contentType : 'application/octet-stream';
    }
}
