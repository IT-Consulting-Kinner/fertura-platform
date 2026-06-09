<?php
declare(strict_types=1);

namespace App\Service\Metrics;

/**
 * Rendert Metrik-Samples in das Prometheus-Textformat (Exposition 0.0.4).
 *
 * @phpstan-type Sample array{name:string, type?:string, help?:string, labels?:array<string,scalar>, value:int|float}
 */
final class PrometheusRenderer
{
    /**
     * @param list<array<string,mixed>> $samples
     */
    public function render(array $samples): string
    {
        $byName = [];
        foreach ($samples as $s) {
            $byName[(string)$s['name']][] = $s;
        }

        $lines = [];
        foreach ($byName as $name => $group) {
            $first = $group[0];
            if (!empty($first['help'])) {
                $lines[] = "# HELP $name " . $this->escapeHelp((string)$first['help']);
            }
            $lines[] = "# TYPE $name " . (string)($first['type'] ?? 'gauge');
            foreach ($group as $s) {
                $lines[] = $name . $this->labels((array)($s['labels'] ?? [])) . ' ' . $this->num($s['value']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, scalar> $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k . '="' . $this->escapeLabel((string)$v) . '"';
        }

        return '{' . implode(',', $parts) . '}';
    }

    private function escapeLabel(string $v): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v);
    }

    private function escapeHelp(string $v): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $v);
    }

    private function num(int|float $v): string
    {
        if (is_int($v)) {
            return (string)$v;
        }

        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
