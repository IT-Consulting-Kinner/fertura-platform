<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Authentication\AuthenticationService;

/**
 * Local default provider (username + password, ch. 27.2.2). Applies when no
 * alternative provider (SSO/AD) is active for `core.auth.provider` — making the
 * core fully capable of authentication without any additional module. With SSO
 * active it serves as the break-glass path (reachable by disabling the SSO
 * module).
 *
 * Hashing: Argon2id (E13) with a bcrypt fallback (verification of old hashes).
 * The `active` login finder locks out non-active users.
 */
class LocalAuthProvider implements AuthProviderInterface
{
    public function label(): string
    {
        return 'lokal (Benutzer/Passwort)';
    }

    public function configure(AuthenticationService $service): void
    {
        // Identifier configured directly on the authenticator (instead of
        // AuthenticationService::loadIdentifier(), deprecated since authentication 3.3.0).
        // Only the form authenticator verifies username/password; the session
        // restores the identity without another DB lookup.
        $identifier = [
            'Authentication.Password' => [
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
            ],
        ];

        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Form', [
            'fields' => [
                'username' => 'username',
                'password' => 'password',
            ],
            'loginUrl' => '/login',
            'identifier' => $identifier,
        ]);
    }
}
