<?php
declare(strict_types=1);

namespace App\Test\TestCase\Session;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Session\DatabaseSession;
use Cake\TestSuite\TestCase;

/**
 * Instanzübergreifender Session-Speicher (Kap. 20.8/30.7, HA-Voraussetzung):
 * Sessions liegen in core.sessions, sodass mehrere Web-Instanzen denselben
 * Zustand sehen. Prüft Schreiben/Lesen, Aktualisierung, Löschen und —
 * entscheidend für HA — die Sichtbarkeit über eine zweite Handler-Instanz
 * (simuliert einen zweiten Knoten).
 */
class DatabaseSessionTest extends TestCase
{
    private string $sid = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = 'ztest_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM sessions WHERE id = :id', ['id' => $this->sid]);
        parent::tearDown();
    }

    private function handler(): DatabaseSession
    {
        return (new DatabaseSession(['model' => 'Sessions']))->setTimeout(3600);
    }

    public function testWriteReadUpdateDestroy(): void
    {
        $h = $this->handler();
        $this->assertTrue($h->write($this->sid, 'erste-daten'));
        $this->assertSame('erste-daten', $h->read($this->sid));

        // Aktualisierung derselben Session.
        $this->assertTrue($h->write($this->sid, 'zweite-daten'));
        $this->assertSame('zweite-daten', $h->read($this->sid));

        $this->assertTrue($h->destroy($this->sid));
        $this->assertSame('', $h->read($this->sid));
    }

    public function testVisibleAcrossInstances(): void
    {
        // Knoten A schreibt ...
        $this->handler()->write($this->sid, 'gemeinsam');
        // ... Knoten B (frische Handler-/Tabelleninstanz) liest denselben Zustand.
        $nodeB = $this->handler();
        $this->assertSame('gemeinsam', $nodeB->read($this->sid));
    }

    public function testStoresBinaryDataWithNulBytes(): void
    {
        // PHP-Session-Serialisierung kann NUL-Bytes enthalten (z. B. private
        // Objekt-Properties). Eine text-Spalte würde daran scheitern -> bytea.
        $payload = "user|O:8:\"stdClass\":1:{s:7:\"\0*\0name\";s:3:\"abc\";}";
        $h = $this->handler();
        $this->assertTrue($h->write($this->sid, $payload));
        $this->assertSame($payload, $h->read($this->sid));
    }

    public function testGcRemovesExpired(): void
    {
        $h = (new DatabaseSession(['model' => 'Sessions']))->setTimeout(-10); // sofort abgelaufen
        $h->write($this->sid, 'alt');
        $h->gc(0);
        $this->assertSame('', $h->read($this->sid));
    }
}
