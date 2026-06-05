<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Authentication\AuthenticationService;

/**
 * Lokaler Default-Provider (Benutzer + Passwort, Kap. 27.2.2). Greift, wenn kein
 * alternativer Provider (SSO/AD) für `core.auth.provider` aktiv ist — damit ist
 * der Core ohne weiteres Modul voll authentifizierungsfähig. Dient bei aktivem
 * SSO als Break-Glass-Pfad (durch Deaktivieren des SSO-Moduls erreichbar).
 *
 * Hashing: Argon2id (E13) mit bcrypt-Fallback (Verifikation alter Hashes).
 * Login-Finder `active` sperrt nicht-aktive Benutzer aus.
 */
class LocalAuthProvider implements AuthProviderInterface
{
    public function label(): string
    {
        return 'lokal (Benutzer/Passwort)';
    }

    public function configure(AuthenticationService $service): void
    {
        $service->loadIdentifier('Authentication.Password', [
            'fields' => [
                'username' => 'username',
                'password' => 'password_hash',
            ],
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'Users',
                'finder' => 'active',
            ],
            'passwordHasher' => [
                'className' => 'Authentication.Fallback',
                'hashers' => [
                    ['className' => 'Authentication.Default', 'hashType' => PASSWORD_ARGON2ID],
                    ['className' => 'Authentication.Default'],
                ],
            ],
        ]);

        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Form', [
            'fields' => [
                'username' => 'username',
                'password' => 'password',
            ],
            'loginUrl' => '/login',
        ]);
    }
}
