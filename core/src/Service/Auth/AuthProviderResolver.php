<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Throwable;

/**
 * Löst den aktiven Authentifizierungs-Provider aus der Capability-Registry auf
 * (Resolver-Slot `core.auth.provider`, Kap. 27.2.2 / 26.7).
 *
 * Sicherheitsleitlinie (Break-Glass): Lässt sich der registrierte Provider nicht
 * laden/instanziieren oder implementiert er nicht {@see AuthProviderInterface},
 * wird auf den lokalen Default zurückgefallen und gewarnt — die Plattform bleibt
 * so immer anmeldbar.
 */
class AuthProviderResolver
{
    public const CONTRACT = 'core.auth.provider';

    /** Klassenname des registrierten aktiven Providers oder null (= lokal). */
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
            return null; // DB (noch) nicht verfügbar -> lokaler Default
        }

        return $row !== false && !empty($row['implementation_class']) ? (string)$row['implementation_class'] : null;
    }

    /** Der zu verwendende Provider (mit defensivem Fallback auf lokal). */
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
