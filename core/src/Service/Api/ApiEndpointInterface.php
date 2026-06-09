<?php
declare(strict_types=1);

namespace App\Service\Api;

/**
 * Vertrag für einen von einem Modul bereitgestellten API-Endpunkt
 * (Programm Tier-1, P07; Core-Contract `core.api.route`).
 *
 * Module deklarieren Endpunkte im Manifest (`api_routes`: method/path/class)
 * und implementieren dieses Interface. Der Core routet `/api/v1/m/<key>/…` auf
 * die passende Klasse — in-process oder (out_of_process) über RPC, wie alle
 * Erweiterungspunkte (Kap. 23.16.2/29). Autorisierung bleibt Core-Sache
 * (Bearer-Token + optionaler Scope + RLS des gebundenen Benutzers).
 */
interface ApiEndpointInterface
{
    /**
     * @param array{
     *   method:string, path:string, params:array<string,string>,
     *   query:array<string,mixed>, body:mixed, user_id:string, scopes:list<string>
     * } $request
     * @return array{status?:int, body?:mixed} Antwort (status default 200; body = Nutzlast)
     */
    public function handle(array $request): array;
}
