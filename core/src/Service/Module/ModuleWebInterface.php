<?php
declare(strict_types=1);

namespace App\Service\Module;

/**
 * Contract for a module's **server-rendered web page** handler (ch. 23.16.3,
 * web-mount). A module declares web pages in its manifest `web_routes` section;
 * each `class` implements this interface. The Core's web dispatcher
 * ({@see \App\Controller\Module\DispatchController}) invokes {@see handle()}
 * **in-process** (HTML rendering cannot cross the out-of-process RPC boundary)
 * inside the normal web middleware stack (session auth, CSRF, RLS, security
 * headers), then renders the module-provided template under a Core-controlled
 * layout.
 *
 * The handler does NOT render HTML itself and does NOT touch the response: it
 * returns view variables (and optionally an HTTP status or a template override).
 * This keeps the Core in control of routing, authentication, layout and output —
 * the module contributes only data + a template file.
 */
interface ModuleWebInterface
{
    /**
     * Handles a web page request and returns the data to render.
     *
     * `$request` carries the request context resolved by the Core dispatcher:
     * - `method`    HTTP method (GET/POST/…)
     * - `path`      module-local path (e.g. `/tickets/42`)
     * - `params`    extracted path parameters (e.g. `['id' => '42']`)
     * - `query`     query-string parameters
     * - `body`      parsed request body (forms/JSON)
     * - `user_id`   the authenticated user UUID, or null for guest pages
     *
     * The return array may contain:
     * - `vars`      array<string,mixed> view variables for the template
     * - `status`    optional HTTP status (default 200)
     * - `template`  optional template name override (defaults to the route's
     *               declared `template`)
     * - `redirect`  optional URL/path to redirect to instead of rendering
     * - `download`  optional file download instead of rendering (E161):
     *               `['filename' => string, 'content_type' => string,
     *               'content' => string]` for in-memory bytes, OR
     *               `['filename' => string, 'content_type' => string,
     *               'storage_path' => string]` to stream a stored report from
     *               object storage (large exports). filename + content_type are
     *               sanitized by the Core.
     *
     * @param array{method:string,path:string,params:array<string,string>,
     *     query:array<string,mixed>,body:mixed,user_id:?string} $request
     * @return array{vars?:array<string,mixed>,status?:int,template?:string,
     *     redirect?:string,download?:array<string,mixed>}
     */
    public function handle(array $request): array;
}
