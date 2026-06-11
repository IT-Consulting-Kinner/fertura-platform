<?php
declare(strict_types=1);

namespace App\Service\Api;

/**
 * Contract for an API endpoint provided by a module
 * (program Tier-1, P07; core contract `core.api.route`).
 *
 * Modules declare endpoints in the manifest (`api_routes`: method/path/class)
 * and implement this interface. The core routes `/api/v1/m/<key>/…` to the
 * matching class — in-process or (out_of_process) via RPC, like all extension
 * points (ch. 23.16.2/29). Authorization stays a core responsibility
 * (bearer token + optional scope + RLS of the bound user).
 */
interface ApiEndpointInterface
{
    /**
     * @param array{
     *   method:string, path:string, params:array<string,string>,
     *   query:array<string,mixed>, body:mixed, user_id:string, scopes:list<string>
     * } $request
     * @return array{status?:int, body?:mixed} Response (status defaults to 200; body = payload)
     */
    public function handle(array $request): array;
}
