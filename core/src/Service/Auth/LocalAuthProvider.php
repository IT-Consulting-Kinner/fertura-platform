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

        // Remember-me ("Angemeldet bleiben"): a signed long-lived cookie restores
        // the identity after the session expires, but ONLY when the user ticked the
        // `remember_me` box at login — the FormAuthenticator persists the cookie on
        // success only if that field is truthy. Loaded after Form so a fresh GET
        // (no POST data) lets the cookie authenticate. Cookie hardening mirrors the
        // session cookie (config/app.php): HttpOnly + SameSite=Lax always, Secure
        // once TLS is terminated (SESSION_COOKIE_SECURE=1); local HTTP dev stays usable.
        $service->loadAuthenticator('Authentication.Cookie', [
            'rememberMeField' => 'remember_me',
            // The token is built from these *entity* fields (username + stored
            // hash), so `password` must map to the actual column `password_hash`
            // (not the form field) — otherwise _createPlainToken cannot read it.
            // Changing the password rotates the hash and invalidates the cookie.
            'fields' => [
                'username' => 'username',
                'password' => 'password_hash',
            ],
            'loginUrl' => '/login',
            'identifier' => $identifier,
            'cookie' => [
                'name' => 'remember_me',
                'expires' => '+30 days',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => filter_var(env('SESSION_COOKIE_SECURE', false), FILTER_VALIDATE_BOOL),
            ],
        ]);
    }
}
