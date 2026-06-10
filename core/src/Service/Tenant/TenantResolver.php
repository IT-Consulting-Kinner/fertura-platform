<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Bestimmt den Mandanten aus dem Request-**Host** — cookie-/session-unabhängig.
 * Reihenfolge: (1) exakte `tenants.domain`-Übereinstimmung, sonst (2) Konvention
 * Subdomain == Mandanten-Schlüssel (`acme.example.com` → Mandant `acme`). Nur
 * **aktive** Mandanten. Liefert null, wenn nichts passt (Single-Org/Default-Host).
 *
 * Einsatz: Pre-Auth-Mandantenkontext (mandantenspezifische Login-/SSO-Oberfläche)
 * sowie als Fallback im RLS-Kontext, solange kein Benutzer angemeldet ist.
 */
class TenantResolver
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    public function resolve(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        $host = explode(':', $host)[0]; // Port abtrennen
        try {
            $row = $this->conn()->execute(
                'SELECT id FROM tenants WHERE active AND lower(domain) = :h',
                ['h' => $host],
            )->fetch('assoc');
            if ($row !== false) {
                return (string)$row['id'];
            }
            $label = explode('.', $host)[0];
            if ($label !== '' && $label !== $host) { // es gibt eine Subdomain
                $row = $this->conn()->execute(
                    'SELECT id FROM tenants WHERE active AND key = :k',
                    ['k' => $label],
                )->fetch('assoc');
                if ($row !== false) {
                    return (string)$row['id'];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
