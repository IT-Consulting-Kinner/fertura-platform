<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Cache\CacheStore;
use App\Service\Realtime\RealtimeService;
use App\Service\Settings\SettingsManager;
use Cake\Event\EventInterface;
use Cake\Http\CallbackStream;
use Cake\Http\Response;
use PDO;

/**
 * Server-Sent-Events stream (program tier-2, P08): delivers real-time events
 * (e.g. in-app notifications) to the **authenticated user** over their
 * `LISTEN/NOTIFY` channel.
 *
 * Deliberately **time-limited** (≈30 s/connection) with heartbeats: the browser
 * (`EventSource`) reconnects automatically. This way the connection does not hold
 * an FPM worker indefinitely. The stream body runs as a `CallbackStream` during
 * response output — i.e. **outside** the request transaction (TransactionRls).
 */
class SseController extends AppController
{
    private const MAX_SECONDS = 30;

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['stream']);
    }

    public function stream(): Response
    {
        $identity = $this->request->getAttribute('identity');
        $userId = $identity !== null ? (string)$identity->getIdentifier() : '';
        if ($userId === '') {
            return $this->response->withStatus(401)->withType('text/plain')->withStringBody("unauthorized\n");
        }

        // Cap on concurrent streams per user (against FPM/DB slot exhaustion).
        // Counter in the cache; on a crash it expires via the TTL (self-healing),
        // otherwise it is decremented at the end of the stream.
        $cap = (int)(new SettingsManager())->get('core', 'sse.max_streams_per_user', 3);
        $cache = new CacheStore('_app_ratelimit_');
        $counterKey = 'sse:' . $userId;
        if ($cache->increment($counterKey) > $cap) {
            $cache->decrement($counterKey);

            return $this->response->withStatus(429)->withType('text/plain')
                ->withHeader('Retry-After', (string)self::MAX_SECONDS)
                ->withStringBody("too many concurrent streams\n");
        }

        $channel = RealtimeService::channel($userId);
        $maxSeconds = self::MAX_SECONDS;

        $body = new CallbackStream(static function () use ($channel, $maxSeconds, $cache, $counterKey): void {
            @set_time_limit($maxSeconds + 5);
            try {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                $emit = static function (string $text): void {
                    echo $text;
                    @flush();
                };

                $pdo = RealtimeService::listenPdo();
                if ($pdo === null) {
                    $emit(": no-listen\n\n");

                    return;
                }
                $pdo->exec('LISTEN ' . $channel);
                $emit(": connected\n\n");

                $start = time();
                while (time() - $start < $maxSeconds) {
                    if (connection_aborted()) {
                        break;
                    }
                    /** @var array{message:string,payload:string}|false $note */
                    $note = $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 5000);
                    if (is_array($note) && ($note['message'] ?? '') === $channel) {
                        $emit('data: ' . $note['payload'] . "\n\n");
                    } else {
                        $emit(": ping\n\n"); // Heartbeat (keeps the connection + detects abort)
                    }
                }
            } finally {
                $cache->decrement($counterKey);
            }
        });

        return $this->response
            ->withType('text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no') // nginx: do not buffer
            ->withBody($body);
    }
}
