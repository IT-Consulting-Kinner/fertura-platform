<?php
declare(strict_types=1);

namespace App\Test\TestCase\Session;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Session\DatabaseSession;
use Cake\TestSuite\TestCase;

/**
 * Cross-instance session store (ch. 20.8/30.7, HA prerequisite): sessions live in
 * core.sessions so that multiple web instances see the same state. Verifies
 * write/read, update, delete and — crucial for HA — visibility across a second
 * handler instance (simulating a second node).
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

        // Update of the same session.
        $this->assertTrue($h->write($this->sid, 'zweite-daten'));
        $this->assertSame('zweite-daten', $h->read($this->sid));

        $this->assertTrue($h->destroy($this->sid));
        $this->assertSame('', $h->read($this->sid));
    }

    public function testVisibleAcrossInstances(): void
    {
        // Node A writes ...
        $this->handler()->write($this->sid, 'gemeinsam');
        // ... node B (fresh handler/table instance) reads the same state.
        $nodeB = $this->handler();
        $this->assertSame('gemeinsam', $nodeB->read($this->sid));
    }

    public function testStoresBinaryDataWithNulBytes(): void
    {
        // PHP session serialization can contain NUL bytes (e.g. private object
        // properties). A text column would fail on those -> bytea.
        $payload = "user|O:8:\"stdClass\":1:{s:7:\"\0*\0name\";s:3:\"abc\";}";
        $h = $this->handler();
        $this->assertTrue($h->write($this->sid, $payload));
        $this->assertSame($payload, $h->read($this->sid));
    }

    public function testGcRemovesExpired(): void
    {
        $h = (new DatabaseSession(['model' => 'Sessions']))->setTimeout(-10); // expired immediately
        $h->write($this->sid, 'alt');
        $h->gc(0);
        $this->assertSame('', $h->read($this->sid));
    }
}
