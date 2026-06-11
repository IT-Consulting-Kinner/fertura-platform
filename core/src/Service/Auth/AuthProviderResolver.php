<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Throwable;

/**
 * Resolves the active authentication provider from the capability registry
 * (resolver slot `core.auth.provider`, ch. 27.2.2 / 26.7).
 *
 * Security policy (break-glass): if the registered provider cannot be
 * loaded/instantiated or does not implement {@see AuthProviderInterface}, the
 * code falls back to the local default and logs a warning — keeping the
 * platform always able to authenticate.
 */
class AuthProviderResolver
{
    public const CONTRACT = 'core.auth.provider';

    /** Class name of the registered active provider, or null (= local). */
    public function resolveClass(): ?string
    {
        try {
            $row = ConnectionManager::get('default')->execute(
                'SELECT r.implementation_class FROM contract_registrations r '
                . 'JOIN contracts c ON c.id = r.contract_id '
                . "WHERE c.name = :n AND c.active AND r.registration_type = 'provider' AND r.active "
                . 'ORDER BY r.priority DESC, r.created_at ASC LIMIT 1',
                ['n' => self::CONTRACT],
            )->fetch('assoc');
        } catch (Throwable) {
            return null; // DB not (yet) available -> local default
        }

        return $row !== false && !empty($row['implementation_class']) ? (string)$row['implementation_class'] : null;
    }

    /** The provider to use (with a defensive fallback to local). */
    public function provider(): AuthProviderInterface
    {
        $class = $this->resolveClass();
        if ($class !== null) {
            try {
                if (class_exists($class)) {
                    $instance = new $class();
                    if ($instance instanceof AuthProviderInterface) {
                        return $instance;
                    }
                    Log::warning("Auth-Provider '$class' implementiert kein AuthProviderInterface -> lokaler Fallback.");
                } else {
                    Log::warning("Auth-Provider '$class' nicht ladbar -> lokaler Fallback.");
                }
            } catch (Throwable $e) {
                Log::warning("Auth-Provider '$class' fehlgeschlagen ({$e->getMessage()}) -> lokaler Fallback.");
            }
        }

        return new LocalAuthProvider();
    }
}
