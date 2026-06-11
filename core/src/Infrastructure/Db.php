<?php
declare(strict_types=1);

namespace App\Infrastructure;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

use function Cake\Core\env;

/**
 * Connection selection for privileged paths (RLS effectiveness, Decision E26).
 *
 * In production the default connection runs as a **NOBYPASSRLS role** (fertura_app),
 * so that row-level security takes effect at runtime. DDL/maintenance/bypass
 * paths (module lifecycle, module migrations, update manager, recovery, worker)
 * use the **privileged** connection (superuser), which bypasses RLS.
 *
 * Falls back to 'default' when no privileged connection is configured
 * (e.g. dev without separate roles) -> backward-compatible.
 */
class Db
{
    public static function privileged(): Connection
    {
        // The privileged connection is usable exactly when a superuser DSN is
        // configured (DATABASE_URL). Note: CakePHP rewrites 'url' into individual
        // keys on registration, hence env() here instead of getConfig()['url'].
        if (env('DATABASE_URL') && ConnectionManager::getConfig('privileged') !== null) {
            try {
                /** @var Connection */
                return ConnectionManager::get('privileged');
            } catch (Throwable) {
                // falls through to default below
            }
        }

        /** @var Connection */
        return ConnectionManager::get('default');
    }

    /**
     * Name of the privileged connection (for APIs that expect a connection
     * name, e.g. cakephp/migrations). Falls back to 'default'.
     */
    public static function privilegedName(): string
    {
        if (env('DATABASE_URL') && ConnectionManager::getConfig('privileged') !== null) {
            return 'privileged';
        }

        return 'default';
    }
}
