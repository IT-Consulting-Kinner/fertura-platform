<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\I18n;

use App\Service\I18n\PoDocument;
use Cake\TestSuite\TestCase;

class PoDocumentTest extends TestCase
{
    public function testParseExcludesHeaderAndReadsEntries(): void
    {
        $po = "msgid \"\"\nmsgstr \"\"\n\"Language: de_DE\\n\"\n\n"
            . "msgid \"a.b\"\nmsgstr \"Hallo\"\n\nmsgid \"c.d\"\nmsgstr \"Welt\"\n";
        $entries = PoDocument::parse($po)->editableEntries();
        $this->assertCount(2, $entries);
        $this->assertSame('a.b', $entries[0]['id']);
        $this->assertSame('Hallo', $entries[0]['msgstr'][0]);
        $this->assertSame('Welt', $entries[1]['msgstr'][0]);
    }

    public function testParsesEntriesWithoutBlankLines(): void
    {
        // Core-Kataloge trennen Einträge teils nicht durch Leerzeilen.
        $po = "msgid \"x\"\nmsgstr \"y\"\nmsgid \"p\"\nmsgstr \"q\"\n";
        $this->assertCount(2, PoDocument::parse($po)->editableEntries());
    }

    public function testRoundtripIsLossless(): void
    {
        $po = "msgid \"\"\nmsgstr \"\"\n\nmsgid \"k1\"\nmsgstr \"v1\"\nmsgid \"k2\"\nmsgstr \"v2\"\n";
        $doc = PoDocument::parse($po);
        $reparsed = PoDocument::parse($doc->serialize());
        $this->assertSame($this->mapOf($doc), $this->mapOf($reparsed));
    }

    public function testSetMsgstrEscapesAndRoundtrips(): void
    {
        $doc = PoDocument::parse("msgid \"k\"\nmsgstr \"v\"\n");
        $idx = $doc->editableEntries()[0]['index'];
        $doc->setMsgstr($idx, ["Zeile1\nZeile2 \"q\""]);
        $out = $doc->serialize();
        $this->assertStringContainsString('\\n', $out);
        $this->assertStringContainsString('\\"q\\"', $out);
        $value = PoDocument::parse($out)->editableEntries()[0]['msgstr'][0];
        $this->assertSame("Zeile1\nZeile2 \"q\"", $value);
    }

    /** @return array<string,string> */
    private function mapOf(PoDocument $doc): array
    {
        $map = [];
        foreach ($doc->entries as $e) {
            if ($e['msgid'] !== '') {
                $map[$e['msgid']] = $e['msgstr'][0] ?? '';
            }
        }

        return $map;
    }
}
