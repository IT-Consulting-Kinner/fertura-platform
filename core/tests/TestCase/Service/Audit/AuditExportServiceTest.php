<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Audit;

use App\Audit\AuditLogger;
use App\Service\Audit\AuditExportService;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;

/**
 * Tests the audit export stream (item 3b): keyset streaming across the time
 * range, correct filters (action/entity_type/from/to) and parsing of the jsonb
 * value snapshots. Additionally (item 3a): the AuditLogger mirrors every event
 * to the `audit` log channel with a structured `audit` field.
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
        // audit_log is append-only (trigger); test cleanup goes through the
        // documented bypass (only within a transaction via SET LOCAL).
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
        // jsonb is delivered as a parsed structure (NDJSON-capable).
        $this->assertSame(['k' => 'v1'], $alpha[0]['new_value']);

        // The entity_type filter matches all three test events.
        $all = iterator_to_array($svc->stream(['entity_type' => 'zztest_entity']));
        $this->assertCount(3, $all);

        // with_values=false omits the snapshots (SIEM pull mode).
        $lean = iterator_to_array($svc->stream(['action' => 'zztest.alpha', 'with_values' => false]));
        $this->assertArrayNotHasKey('new_value', $lean[0]);
    }

    public function testStreamIsKeysetOrderedAcrossBatches(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seed('zztest.seq');
        }
        // Setting the batch size via reflection would be invasive; here we check
        // the stable (created_at,id) ordering across the generator.
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
        // Future "from" -> no matches; past "to" -> no matches.
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
        // Value snapshots are NOT in the stream (PII-light, E16).
        $this->assertStringNotContainsString('"x"', $line);
    }
}
