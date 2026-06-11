<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Authentication\AuthenticationService;

/**
 * Contract for a pluggable authentication provider (ch. 27.2.2).
 *
 * The core defines the resolver contract `core.auth.provider`; an extension
 * module (e.g. OIDC/SAML/AD) registers an implementation as a provider. The
 * active provider configures the `AuthenticationService` (identifier +
 * authenticators). With no active provider, the local default applies
 * ({@see LocalAuthProvider}).
 *
 * Users/identities stay core-managed (ch. 27.2.1); an external provider only
 * authenticates and creates or links the identity via just-in-time
 * provisioning. Authorization (scopes/groups/BREAD) remains independent of the
 * method.
 */
interface AuthProviderInterface
{
    /**
     * Configures the authentication service (identifier + authenticators).
     * The provider is itself responsible for session persistence.
     */
    public function configure(AuthenticationService $service): void;

    /** Short name for display/diagnostics (e.g. "local", "OIDC"). */
    public function label(): string;
}
