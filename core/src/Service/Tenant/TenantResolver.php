<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Determines the tenant from the request **host** — cookie/session-independent.
 * Order: (1) exact `tenants.domain` match, otherwise (2) the convention that the
 * subdomain == tenant key (`acme.example.com` → tenant `acme`). Only **active**
 * tenants. Returns null if nothing matches (single-org/default host).
 *
 * Used for: the pre-auth tenant context (tenant-specific login/SSO surface) as
 * well as a fallback in the RLS context while no user is logged in.
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
        $host = explode(':', $host)[0]; // strip the port
        try {
            $row = $this->conn()->execute(
                'SELECT id FROM tenants WHERE active AND lower(domain) = :h',
                ['h' => $host],
            )->fetch('assoc');
            if ($row !== false) {
                return (string)$row['id'];
            }
            $label = explode('.', $host)[0];
            if ($label !== '' && $label !== $host) { // there is a subdomain
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
