<?php
declare(strict_types=1);

namespace App\Log;

use Cake\Log\Formatter\JsonFormatter;

/**
 * JSON log formatter that automatically merges the process-wide {@see LogContext}
 * as well as operative fields supplied at the call site into every line
 * (ch. 20.2.3). This way log lines reliably contain `correlation_id`,
 * `request_id`, `component` and (if set) `module` — without every caller having
 * to pass them. Call-site context takes precedence over the holder.
 */
class ContextJsonFormatter extends JsonFormatter
{
    private const FIELDS = ['correlation_id', 'request_id', 'component', 'module'];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function format(mixed $level, string $message, array $context = []): string
    {
        $merged = $context + LogContext::all();

        $log = [
            'date' => date($this->_config['dateFormat']),
            'level' => (string)$level,
            'message' => $message,
        ];
        foreach (self::FIELDS as $f) {
            if (isset($merged[$f]) && is_scalar($merged[$f])) {
                $log[$f] = $merged[$f];
            }
        }
        // Audit event (item 3a): pass through as a structured field for SIEM
        // parsing, instead of hiding it in free text.
        if (isset($merged['audit']) && is_array($merged['audit'])) {
            $log['audit'] = $merged['audit'];
        }

        $json = json_encode($log, JSON_THROW_ON_ERROR | $this->_config['flags']);

        return $this->_config['appendNewline'] ? $json . "\n" : $json;
    }
}
