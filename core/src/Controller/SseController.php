<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Realtime\RealtimeService;
use Cake\Event\EventInterface;
use Cake\Http\CallbackStream;
use Cake\Http\Response;
use PDO;

/**
 * Server-Sent-Events-Stream (Programm Tier-2, P08): liefert dem **angemeldeten
 * Benutzer** Echtzeit-Ereignisse (z. B. In-App-Benachrichtigungen) über seinen
 * `LISTEN/NOTIFY`-Kanal.
 *
 * Bewusst **zeitlich begrenzt** (≈30 s/Verbindung) mit Heartbeats: der Browser
 * (`EventSource`) verbindet automatisch neu. So hält die Verbindung keinen
 * FPM-Worker dauerhaft. Der Stream-Body läuft als `CallbackStream` bei der
 * Antwort-Ausgabe — also **außerhalb** der Request-Transaktion (TransactionRls).
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

        $channel = RealtimeService::channel($userId);
        $maxSeconds = self::MAX_SECONDS;

        $body = new CallbackStream(static function () use ($channel, $maxSeconds): void {
            @set_time_limit($maxSeconds + 5);
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
                    $emit(": ping\n\n"); // Heartbeat (hält Verbindung + erkennt Abbruch)
                }
            }
        });

        return $this->response
            ->withType('text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no') // nginx: nicht puffern
            ->withBody($body);
    }
}
