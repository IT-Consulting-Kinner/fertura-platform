<?php
declare(strict_types=1);

namespace App\Service\Automation;

use App\Service\Event\OutboxPublisher;
use App\Service\Notification\NotificationService;
use Throwable;

/**
 * Executes declarative actions (program tier-2, P12/state machines): `notify`
 * (notification) and `event` (a further outbox event). Shared by the
 * {@see AutomationEngine} (ECA rules) and the workflow state machine. Action
 * failures are isolated.
 */
class ActionExecutor
{
    public function __construct(
        private ?NotificationService $notifications = null,
        private ?OutboxPublisher $outbox = null,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @param array<string,mixed> $payload
     */
    public function run(array $actions, array $payload): void
    {
        foreach ($actions as $action) {
            try {
                match ((string)($action['type'] ?? '')) {
                    'notify' => $this->notify($action, $payload),
                    'event' => $this->event($action, $payload),
                    default => null,
                };
            } catch (Throwable) {
                // Isolate action failures.
            }
        }
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $payload
     */
    private function notify(array $action, array $payload): void
    {
        $userId = (string)($action['user_id'] ?? $this->fromPayload((string)($action['user_field'] ?? 'user_id'), $payload));
        if ($userId === '') {
            return;
        }
        ($this->notifications ??= new NotificationService())->notify(
            $userId,
            (string)($action['notify_type'] ?? 'automation'),
            $this->interpolate((string)($action['title'] ?? 'Automatisierung'), $payload),
            $this->interpolate((string)($action['body'] ?? ''), $payload),
        );
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $payload
     */
    private function event(array $action, array $payload): void
    {
        $contract = (string)($action['contract'] ?? '');
        if ($contract === '') {
            return;
        }
        ($this->outbox ??= new OutboxPublisher())->publish($contract, (array)($action['payload'] ?? $payload));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function fromPayload(string $path, array $payload): string
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return '';
            }
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function interpolate(string $text, array $payload): string
    {
        return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($payload): string {
            return $this->fromPayload($m[1], $payload);
        }, $text);
    }
}
