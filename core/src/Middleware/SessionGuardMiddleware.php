<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use App\Service\Notification\NotificationService;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session-Anomalie-Erkennung (Kür zu E129) für **Session-basierte** Identitäten:
 *
 * - **UA-Bindung** (`security.session.bind_ua`, Default an): die Session wird an
 *   den User-Agent-Hash des Logins gebunden — wechselt er (gestohlenes Cookie in
 *   anderem Browser), wird die Session **zerstört** (fail-closed) und auditiert.
 * - **IP-Wechsel**: wird auditiert (`session.ip_change`); bei
 *   `security.session.ip_strict` (Default aus — mobile Netze/NAT wechseln IPs
 *   legitim) wird die Session ebenfalls beendet.
 * - **Neues Gerät**: erster Login mit unbekanntem User-Agent-Fingerprint je
 *   Benutzer → In-App-Benachrichtigung (`security.session.notify_new_device`)
 *   + Audit (`session.new_device`).
 *
 * API-Pfade (Bearer-Token, keine Session) sind ausgenommen. Alle Neben-
 * wirkungen (Audit/Notify) sind fehlerisoliert — sie dürfen den Request
 * nie brechen.
 */
class SessionGuardMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $identity = $request->getAttribute('identity');
        $session = $request->getAttribute('session');
        if ($identity === null || !$session instanceof Session || str_starts_with($path, '/api/')) {
            return $handler->handle($request);
        }
        $userId = $identity->getIdentifier();
        if (!is_string($userId) || !Uuid::isValid($userId)) {
            return $handler->handle($request);
        }

        [$bindUa, $ipStrict, $notify] = $this->config();
        $uaHash = hash('sha256', $request->getHeaderLine('User-Agent'));
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');

        /** @var array{ua:string,ip:string}|null $guard */
        $guard = $session->read('Guard');
        if (!is_array($guard)) {
            // Erster authentifizierter Request dieser Session: binden + Gerät prüfen.
            $session->write('Guard', ['ua' => $uaHash, 'ip' => $ip]);
            $this->checkKnownDevice($userId, $uaHash, $notify);

            return $handler->handle($request);
        }

        if ($bindUa && !hash_equals((string)$guard['ua'], $uaHash)) {
            $this->audit('session.anomaly', $userId, ['reason' => 'ua_mismatch']);
            $session->destroy();

            return (new Response())->withStatus(302)->withLocation('/login');
        }

        if ($ip !== '' && $guard['ip'] !== '' && $guard['ip'] !== $ip) {
            $this->audit('session.ip_change', $userId, ['from' => $guard['ip'], 'to' => $ip]);
            if ($ipStrict) {
                $session->destroy();

                return (new Response())->withStatus(302)->withLocation('/login');
            }
            $session->write('Guard.ip', $ip); // Wechsel registriert, weiter beobachten
        }

        return $handler->handle($request);
    }

    /** Unbekannter UA-Fingerprint für diesen Benutzer? -> merken + benachrichtigen. */
    private function checkKnownDevice(string $userId, string $uaHash, bool $notify): void
    {
        try {
            /** @var \Cake\Database\Connection $conn */
            $conn = ConnectionManager::get('default');
            $inserted = $conn->execute(
                'INSERT INTO user_known_devices (user_id, fingerprint_hash) VALUES (:u, :f) '
                . 'ON CONFLICT (user_id, fingerprint_hash) DO UPDATE SET last_seen_at = now() '
                . 'RETURNING (xmax = 0) AS is_new',
                ['u' => $userId, 'f' => $uaHash],
            )->fetch('assoc');
            $isNew = $inserted !== false && ($inserted['is_new'] === true || $inserted['is_new'] === 't');
            if (!$isNew) {
                return;
            }
            $this->audit('session.new_device', $userId, ['fingerprint' => substr($uaHash, 0, 12)]);
            // Erstes Gerät überhaupt = die Einrichtung selbst -> kein Alarm nötig.
            $count = (int)$conn->execute(
                'SELECT count(*) AS c FROM user_known_devices WHERE user_id = :u',
                ['u' => $userId],
            )->fetch('assoc')['c'];
            if ($notify && $count > 1) {
                (new NotificationService())->notify($userId, 'security.new_device', __('security.new_device_notice'));
            }
        } catch (\Throwable) {
            // Geräte-Heuristik darf den Request nie brechen.
        }
    }

    /** @param array<string,mixed> $detail */
    private function audit(string $action, string $userId, array $detail): void
    {
        try {
            (new AuditLogger())->log($action, 'user', $userId, ['newValue' => $detail, 'component' => 'core']);
        } catch (\Throwable) {
            // fehlerisoliert
        }
    }

    /** @return array{0:bool,1:bool,2:bool} [bindUa, ipStrict, notifyNewDevice] */
    private function config(): array
    {
        try {
            $s = new SettingsManager();

            return [
                (bool)$s->get('core', 'security.session.bind_ua', true),
                (bool)$s->get('core', 'security.session.ip_strict', false),
                (bool)$s->get('core', 'security.session.notify_new_device', true),
            ];
        } catch (\Throwable) {
            return [true, false, true];
        }
    }
}
