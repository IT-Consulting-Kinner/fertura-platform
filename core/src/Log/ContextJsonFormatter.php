<?php
declare(strict_types=1);

namespace App\Log;

use Cake\Log\Formatter\JsonFormatter;

/**
 * JSON-Log-Formatter, der den prozessweiten {@see LogContext} sowie am
 * Aufrufort mitgegebene operative Felder automatisch in jede Zeile einmischt
 * (Kap. 20.2.3). So enthalten Logzeilen verlässlich `correlation_id`,
 * `request_id`, `component` und (falls gesetzt) `module` — ohne dass jeder
 * Aufrufer sie übergeben muss. Aufruf-Kontext hat Vorrang vor dem Holder.
 */
class ContextJsonFormatter extends JsonFormatter
{
    private const FIELDS = ['correlation_id', 'request_id', 'component', 'module'];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function format($level, string $message, array $context = []): string
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

        $json = json_encode($log, JSON_THROW_ON_ERROR | $this->_config['flags']);

        return $this->_config['appendNewline'] ? $json . "\n" : $json;
    }
}
