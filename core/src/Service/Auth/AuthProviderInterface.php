<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Authentication\AuthenticationService;

/**
 * Vertrag für einen austauschbaren Authentifizierungs-Provider (Kap. 27.2.2).
 *
 * Der Core definiert den Resolver-Contract `core.auth.provider`; ein Extension-
 * Modul (z. B. OIDC/SAML/AD) registriert eine Implementierung als Provider. Der
 * aktive Provider konfiguriert den `AuthenticationService` (Identifier +
 * Authenticators). Ohne aktiven Provider greift der lokale Default
 * ({@see LocalAuthProvider}).
 *
 * Benutzer/Identitäten bleiben Core-verwaltet (Kap. 27.2.1); ein externer
 * Provider authentifiziert nur und legt die Identität per Just-in-Time-
 * Provisioning an bzw. verknüpft sie. Autorisierung (Bereiche/Gruppen/BREAD)
 * bleibt unabhängig von der Methode.
 */
interface AuthProviderInterface
{
    /**
     * Konfiguriert den Authentifizierungsdienst (Identifier + Authenticators).
     * Der Provider ist für Session-Persistenz selbst verantwortlich.
     */
    public function configure(AuthenticationService $service): void;

    /** Kurzname für Anzeige/Diagnose (z. B. „lokal", „OIDC"). */
    public function label(): string;
}
