<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Export;

use App\Service\Export\ExportService;
use App\Service\Storage\StorageManager;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Test of the export primitive (P13): CSV/XLSX/PDF generation + storage via the
 * object storage (P03).
 */
class ExportServiceTest extends TestCase
{
    private const COLUMNS = ['Name', 'Betrag'];
    private const ROWS = [['Alpha', '100'], ['Beta', '200']];

    public function testCsv(): void
    {
        $csv = (new ExportService())->csv(self::COLUMNS, self::ROWS);
        $this->assertStringContainsString('Name,Betrag', $csv);
        $this->assertStringContainsString('Alpha,100', $csv);
        $this->assertStringContainsString('Beta,200', $csv);
    }

    public function testCsvNeutralizesFormulaInjection(): void
    {
        $e = new ExportService();
        $csv = $e->csv(['x'], [['=HYPERLINK("http://evil")'], ['+1'], ['@cmd'], ['safe']]);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
        $this->assertSame('safe', $e->antiFormula('safe'));
        $this->assertSame("'=1+1", $e->antiFormula('=1+1'));
    }

    public function testAntiFormulaCatchesLeadingWhitespaceAndNewline(): void
    {
        $e = new ExportService();
        // Spreadsheet programs trim leading whitespace/newlines before parsing —
        // a trigger character behind it (or the whitespace itself) must be neutralized.
        $this->assertSame("' =HYPERLINK(\"x\")", $e->antiFormula(' =HYPERLINK("x")'));
        $this->assertSame("'\n=1+1", $e->antiFormula("\n=1+1"));
        $this->assertSame("'\t@cmd", $e->antiFormula("\t@cmd"));
        $this->assertSame("'  -2+3", $e->antiFormula('  -2+3'));
        // Harmless values remain untouched.
        $this->assertSame('Müller', $e->antiFormula('Müller'));
        $this->assertSame('100', $e->antiFormula('100'));
    }

    public function testXlsxIsZipContainer(): void
    {
        $xlsx = (new ExportService())->xlsx(self::COLUMNS, self::ROWS);
        $this->assertSame('PK', substr($xlsx, 0, 2), 'XLSX ist ein ZIP-Container.');
        $this->assertGreaterThan(500, strlen($xlsx));
    }

    public function testPdfHasHeader(): void
    {
        $pdf = (new ExportService())->pdf('Bericht', self::COLUMNS, self::ROWS);
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }

    public function testGenerateUnknownFormatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ExportService())->generate('docx', 'X', self::COLUMNS, self::ROWS);
    }

    public function testStorePersistsToStorage(): void
    {
        $root = sys_get_temp_dir() . '/fertura_export_' . bin2hex(random_bytes(5));
        $storage = new StorageManager(new Filesystem(new LocalFilesystemAdapter($root)));

        $path = (new ExportService())->store('csv', 'Mein Report', self::COLUMNS, self::ROWS, $storage);

        $this->assertStringStartsWith('reports/', $path);
        $this->assertStringEndsWith('.csv', $path);
        $this->assertStringContainsString('Alpha,100', $storage->read($path));

        $this->rrmdir($root);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $full = $path . '/' . $i;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
