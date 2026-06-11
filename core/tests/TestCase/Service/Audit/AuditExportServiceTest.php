<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Audit;

use App\Audit\AuditLogger;
use App\Service\Audit\AuditExportService;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;

/**
 * Test des Audit-Export-Streams (Punkt 3b): Keyset-Streaming über den
 * Zeitbereich, korrekte Filter (action/entity_type/from/to) und das Parsen der
 * jsonb-Wertschnappschüsse. Zusätzlich (Punkt 3a): der AuditLogger spiegelt
 * jedes Ereignis auf den `audit`-Log-Kanal mit strukturiertem `audit`-Feld.
 */
class AuditExportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // audit_log ist append-only (Trigger); Test-Bereinigung über den
        // dokumentierten Bypass (nur in einer Transaktion via SET LOCAL).
        $conn = ConnectionManager::get('default');
        $conn->begin();
        $conn->execute("SET LOCAL app.allow_audit_mutation = 'on'");
        $conn->execute("DELETE FROM audit_log WHERE action LIKE 'zztest.%'");
        $conn->commit();
    }

    private function seed(string $action, ?array $newValue = null): void
    {
        (new AuditLogger())->log($action, 'zztest_entity', '019eb000-0000-7000-8000-000000000001', [
            'newValue' => $newValue,
            'component' => 'core',
        ]);
    }

    public function testStreamReturnsMatchingRowsWithFilters(): void
    {
        $this->seed('zztest.alpha', ['k' => 'v1']);
        $this->seed('zztest.beta');
        $this->seed('zztest.alpha', ['k' => 'v2']);

        $svc = new AuditExportService();
        $alpha = iterator_to_array($svc->stream(['action' => 'zztest.alpha']));
        $this->assertCount(2, $alpha);
        // jsonb wird als geparste Struktur geliefert (NDJSON-fähig).
        $this->assertSame(['k' => 'v1'], $alpha[0]['new_value']);

        // entity_type-Filter trifft alle drei Test-Ereignisse.
        $all = iterator_to_array($svc->stream(['entity_type' => 'zztest_entity']));
        $this->assertCount(3, $all);

        // with_values=false lässt die Snapshots weg (SIEM-Pull-Modus).
        $lean = iterator_to_array($svc->stream(['action' => 'zztest.alpha', 'with_values' => false]));
        $this->assertArrayNotHasKey('new_value', $lean[0]);
    }

    public function testStreamIsKeysetOrderedAcrossBatches(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seed('zztest.seq');
        }
        // Batch-Größe via Reflection kurz setzen wäre invasiv; hier prüfen wir die
        // stabile (created_at,id)-Ordnung über den Generator.
        $rows = iterator_to_array((new AuditExportService())->stream(['action' => 'zztest.seq']));
        $this->assertCount(5, $rows);
        $prev = null;
        foreach ($rows as $r) {
            $key = $r['created_at'] . '|' . $r['id'];
            if ($prev !== null) {
                $this->assertGreaterThan($prev, $key, 'streng aufsteigende Keyset-Ordnung');
            }
            $prev = $key;
        }
    }

    public function testFromToWindowFilters(): void
    {
        $this->seed('zztest.window');
        // Zukunfts-„from" -> keine Treffer; Vergangenheits-„to" -> keine Treffer.
        $future = iterator_to_array((new AuditExportService())->stream([
            'action' => 'zztest.window', 'from' => date('c', time() + 86400),
        ]));
        $this->assertCount(0, $future);
        $past = iterator_to_array((new AuditExportService())->stream([
            'action' => 'zztest.window', 'to' => date('c', time() - 86400),
        ]));
        $this->assertCount(0, $past);
    }

    public function testAuditLoggerEmitsStructuredSiemLine(): void
    {
        $captured = [];
        Log::setConfig('zztest_audit_capture', [
            'engine' => 'Array',
            'scopes' => ['audit'],
            'levels' => ['info'],
        ]);
        try {
            $this->seed('zztest.siem', ['x' => 1]);
            /** @var \Cake\Log\Engine\ArrayLog $engine */
            $engine = Log::engine('zztest_audit_capture');
            $captured = $engine->read();
        } finally {
            Log::drop('zztest_audit_capture');
        }

        $this->assertNotEmpty($captured, 'Audit-Ereignis muss auf den audit-Log-Kanal gespiegelt werden');
        $line = implode("\n", $captured);
        $this->assertStringContainsString('audit.zztest.siem', $line);
        // Werte-Snapshots stehen NICHT im Strom (PII-arm, E16).
        $this->assertStringNotContainsString('"x"', $line);
    }
}
