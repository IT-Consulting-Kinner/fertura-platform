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
 * Session anomaly detection (stretch goal of E129) for **session-based** identities:
 *
 * - **UA binding** (`security.session.bind_ua`, default on): the session is bound to
 *   the User-Agent hash of the login — if it changes (stolen cookie used in a
 *   different browser), the session is **destroyed** (fail-closed) and audited.
 * - **IP change**: is audited (`session.ip_change`); with
 *   `security.session.ip_strict` (default off — mobile networks/NAT change IPs
 *   legitimately) the session is also terminated.
 * - **New device**: first login with an unknown User-Agent fingerprint per
 *   user → in-app notification (`security.session.notify_new_device`)
 *   + audit (`session.new_device`).
 *
 * API paths (bearer token, no session) are exempt. All side effects
 * (audit/notify) are error-isolated — they must never break the request.
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
            // First authenticated request of this session: bind + check device.
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
            $session->write('Guard.ip', $ip); // change recorded, keep observing
        }

        return $handler->handle($request);
    }

    /** Unknown UA fingerprint for this user? -> remember + notify. */
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
            // The very first device = the setup itself -> no alert needed.
            $count = (int)$conn->execute(
                'SELECT count(*) AS c FROM user_known_devices WHERE user_id = :u',
                ['u' => $userId],
            )->fetch('assoc')['c'];
            if ($notify && $count > 1) {
                (new NotificationService())->notify($userId, 'security.new_device', __('security.new_device_notice'));
            }
        } catch (\Throwable) {
            // The device heuristic must never break the request.
        }
    }

    /** @param array<string,mixed> $detail */
    private function audit(string $action, string $userId, array $detail): void
    {
        try {
            (new AuditLogger())->log($action, 'user', $userId, ['newValue' => $detail, 'component' => 'core']);
        } catch (\Throwable) {
            // error-isolated
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
