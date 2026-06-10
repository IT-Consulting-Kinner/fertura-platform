<?php
declare(strict_types=1);

namespace App\Service\Export;

use App\Audit\AuditLogger;
use App\Service\Storage\StorageManager;
use Dompdf\Dompdf;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporting-/Export-Primitiv (Programm Tier-2, P13): erzeugt tabellarische
 * Reports als **CSV/XLSX/PDF** und legt sie optional über den Objekt-Storage
 * (P03, lokal/S3) ab. Modulen als gemeinsame Export-Funktion verfügbar.
 */
class ExportService
{
    public function __construct(private ?AuditLogger $audit = null)
    {
    }

    /**
     * @param list<string> $columns
     * @param list<array<int|string,mixed>> $rows
     */
    public function csv(array $columns, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, array_map([$this, 'antiFormula'], $columns), ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($fh, array_map([$this, 'antiFormula'], array_values($row)), ',', '"', '\\');
        }
        rewind($fh);
        $content = (string)stream_get_contents($fh);
        fclose($fh);

        return $content;
    }

    /**
     * Neutralisiert Formel-Injection (CSV/XLSX): ein Wert, der mit `=`,`+`,`-`,`@`
     * beginnt, wird von Tabellen-Programmen als Formel ausgewertet (Datenexfiltration/
     * DDE). Voranstellen eines `'` entwertet die Formel.
     *
     * WICHTIG: Tabellenprogramme **trimmen führenden Whitespace/Zeilenumbruch**, bevor
     * sie die Zelle parsen — daher zählt ein Auslöser auch NACH führenden Leerzeichen/
     * Tabs/CR/LF (z. B. `" =cmd"`, `"\n=1+1"`). Führender Whitespace selbst kann eine
     * Formel maskieren und wird ebenfalls entwertet.
     */
    public function antiFormula(mixed $value): string
    {
        $s = (string)$value;
        if ($s === '') {
            return $s;
        }
        $first = $s[0];
        // Erstes Nicht-Whitespace-Zeichen (Tabellenprogramme ignorieren führenden
        // Whitespace beim Formel-Parsing).
        $trimmed = ltrim($s, " \t\r\n");
        $lead = $trimmed === '' ? '' : $trimmed[0];
        if (
            in_array($first, [' ', "\t", "\r", "\n"], true)
            || in_array($lead, ['=', '+', '-', '@'], true)
        ) {
            return "'" . $s;
        }

        return $s;
    }

    /**
     * @param list<string> $columns
     * @param list<array<int|string,mixed>> $rows
     */
    public function xlsx(array $columns, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([array_map([$this, 'antiFormula'], $columns)], null, 'A1');
        $sheet->fromArray(
            array_map(fn ($r): array => array_map([$this, 'antiFormula'], array_values($r)), $rows),
            null,
            'A2',
        );
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $tmp = (string)tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($tmp);
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * @param list<string> $columns
     * @param list<array<int|string,mixed>> $rows
     */
    public function pdf(string $title, array $columns, array $rows): string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml($this->html($title, $columns, $rows));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string)$dompdf->output();
    }

    /**
     * @param list<string> $columns
     * @param list<array<int|string,mixed>> $rows
     * @return array{0:string,1:string,2:string} [content, mime, ext]
     */
    public function generate(string $format, string $title, array $columns, array $rows): array
    {
        return match ($format) {
            'csv' => [$this->csv($columns, $rows), 'text/csv', 'csv'],
            'xlsx' => [$this->xlsx($columns, $rows), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
            'pdf' => [$this->pdf($title, $columns, $rows), 'application/pdf', 'pdf'],
            default => throw new InvalidArgumentException("Unbekanntes Export-Format: $format"),
        };
    }

    /**
     * Erzeugt den Report und legt ihn im Objekt-Storage unter `reports/…` ab.
     * Gibt den Storage-Pfad zurück.
     *
     * @param list<string> $columns
     * @param list<array<int|string,mixed>> $rows
     */
    public function store(string $format, string $title, array $columns, array $rows, ?StorageManager $storage = null): string
    {
        [$content, , $ext] = $this->generate($format, $title, $columns, $rows);
        $slug = trim((string)preg_replace('/[^a-z0-9]+/i', '-', $title), '-') ?: 'report';
        $path = 'reports/' . $slug . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
        ($storage ?? new StorageManager())->write($path, $content);

        ($this->audit ??= new AuditLogger())->log('report.export', 'report', $path, [
            'format' => $format,
            'title' => $title,
            'rows' => count($rows),
        ]);

        return $path;
    }

    /**
     * @param list<string> $columns
     * @param list<array<int|string,mixed>> $rows
     */
    private function html(string $title, array $columns, array $rows): string
    {
        $h = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $head = '';
        foreach ($columns as $c) {
            $head .= '<th>' . $h($c) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach (array_values($row) as $cell) {
                $body .= '<td>' . $h($cell) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:10px}'
            . 'h1{font-size:14px}table{width:100%;border-collapse:collapse}'
            . 'th,td{border:1px solid #999;padding:3px 5px;text-align:left}'
            . 'th{background:#eee}</style></head><body>'
            . '<h1>' . $h($title) . '</h1>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>'
            . '</body></html>';
    }
}
