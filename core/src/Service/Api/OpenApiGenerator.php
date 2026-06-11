<?php
declare(strict_types=1);

namespace App\Service\Api;

/**
 * Erzeugt die OpenAPI-3.1-Spezifikation der externen API (P07) aus dem
 * **tatsächlichen** API-Bestand: feste Core-Endpunkte + die von aktiven Modulen
 * registrierten Routen ({@see ApiRouteRegistry}). Single Source of Truth statt
 * handgepflegter Spezifikation (Architekturhabitus).
 */
class OpenApiGenerator
{
    public function __construct(private ?ApiRouteRegistry $routes = null)
    {
        $this->routes ??= new ApiRouteRegistry();
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $baseUrl): array
    {
        $paths = [];

        // Feste Core-Endpunkte: [Methode, Pfad, Scope|null, Beschreibung].
        $core = [
            ['GET', '/api/v1/health', null, 'Liveness/Health der Plattform'],
            ['GET', '/api/v1/me', 'me:read', 'Aktueller Benutzer (Token-Inhaber)'],
            ['GET', '/api/v1/modules', 'modules:read', 'Installierte Module'],
            ['GET', '/api/v1/search', 'me:read', 'Volltextsuche (Parameter: q, limit)'],
            ['GET', '/api/v1/notifications', 'me:read', 'Ungelesene Benachrichtigungen'],
            ['POST', '/api/v1/notifications/{id}/read', 'me:read', 'Benachrichtigung als gelesen markieren'],
            ['POST', '/api/v1/notifications/read-all', 'me:read', 'Alle als gelesen markieren'],
            ['GET', '/api/v1/audit', 'audit:read', 'Audit-Log-Export als NDJSON-Strom (Filter: from/to/action/entity_type/entity_id/module_key/actor_user_id; with_values=1 für Wert-Snapshots)'],
            ['GET', '/api/v1/openapi.json', null, 'Diese OpenAPI-Spezifikation'],
        ];
        foreach ($core as [$method, $path, $scope, $summary]) {
            $this->addPath($paths, $method, $path, $summary, $scope, 'core');
        }

        // Von Modulen registrierte Endpunkte.
        foreach ($this->routes->all() as $r) {
            $this->addPath(
                $paths,
                $r['method'],
                '/api/v1/m/' . $r['module_key'] . $r['path'],
                $r['summary'] !== '' ? $r['summary'] : ($r['module_key'] . ' endpoint'),
                $r['scope'],
                $r['module_key'],
            );
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Fertura Platform API',
                'version' => '1.0.0',
                'description' => 'Externe API der Fertura-Plattform (Bearer-Token, Scopes, Rate-Limiting).',
            ],
            'servers' => [['url' => $baseUrl]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
            'security' => [['bearerAuth' => []]],
            'paths' => $paths,
        ];
    }

    /**
     * @param array<string,mixed> $paths
     */
    private function addPath(array &$paths, string $method, string $path, string $summary, ?string $scope, string $tag): void
    {
        $op = [
            'summary' => $summary,
            'tags' => [$tag],
            'responses' => [
                '200' => ['description' => 'OK'],
                '401' => ['description' => 'Nicht authentifiziert'],
                '429' => ['description' => 'Rate-Limit überschritten'],
            ],
        ];
        if ($scope !== null) {
            $op['description'] = 'Erforderlicher Scope: ' . $scope;
        }
        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $path, $mm)) {
            $op['parameters'] = array_map(
                static fn (string $n): array => [
                    'name' => $n,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                $mm[1],
            );
        }
        $paths[$path][strtolower($method)] = $op;
    }
}
