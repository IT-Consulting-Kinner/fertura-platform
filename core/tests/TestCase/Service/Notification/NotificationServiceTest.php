<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Notification;

use App\Service\Mail\MailService;
use App\Service\Module\ContributionRuntime;
use App\Service\Notification\NotificationService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test des Benachrichtigungs-Frameworks (P09): In-App-Speicherung/-Lesen,
 * Kanal-Präferenzen (Abschalten), E-Mail-Kanal (Stub) und Modul-Kanal (Stub).
 */
class NotificationServiceTest extends TestCase
{
    private string $userId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->email = 'notif_' . bin2hex(random_bytes(4)) . '@zztest.local';
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_notif_' . bin2hex(random_bytes(4)), 'e' => $this->email],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zztest.local'");
    }

    public function testInAppStoredReadable(): void
    {
        $svc = new NotificationService(null, new FakeMail());
        $id = $svc->notify($this->userId, 'sys.test', 'Hallo', 'Text');
        $this->assertNotSame('', $id);

        $unread = $svc->unread($this->userId);
        $this->assertCount(1, $unread);
        $this->assertSame('Hallo', $unread[0]['title']);
        $this->assertSame(1, $svc->unreadCount($this->userId));

        $svc->markRead($this->userId, $id);
        $this->assertSame(0, $svc->unreadCount($this->userId));
    }

    public function testMarkReadIgnoresMalformedId(): void
    {
        // Fehlgeformte ID aus der API-URL (POST /notifications/{id}/read):
        // UUID-Guard -> no-op statt 22P02 ("invalid input syntax for type uuid").
        $svc = new NotificationService(null, new FakeMail());
        $svc->notify($this->userId, 'sys.test', 'Hallo');

        $svc->markRead($this->userId, 'garbage');
        $this->assertSame(1, $svc->unreadCount($this->userId));
    }

    public function testPrefDisablesInApp(): void
    {
        $svc = new NotificationService(null, new FakeMail());
        $svc->setPref($this->userId, 'sys.test', 'in_app', false);

        $id = $svc->notify($this->userId, 'sys.test', 'Hallo');
        $this->assertSame('', $id, 'in_app deaktiviert -> keine gespeicherte Benachrichtigung');
        $this->assertSame(0, $svc->unreadCount($this->userId));
    }

    public function testEmailChannelInvoked(): void
    {
        $mail = new FakeMail();
        (new NotificationService(null, $mail))->notify($this->userId, 'sys.test', 'Betreff', 'Inhalt');

        $this->assertCount(1, $mail->sent);
        $this->assertSame($this->email, $mail->sent[0]['to']);
        $this->assertSame('Betreff', $mail->sent[0]['subject']);
    }

    public function testEnabledModuleChannelReceivesDelivery(): void
    {
        $runtime = new FakeRuntime();
        $svc = new NotificationService(null, new FakeMail(), $runtime);
        $svc->setPref($this->userId, 'sys.test', 'slack', true);

        $svc->notify($this->userId, 'sys.test', 'Titel', 'Body', ['k' => 'v']);

        $this->assertCount(1, $runtime->delivered);
        $this->assertSame('Titel', $runtime->delivered[0]['title']);
        $this->assertSame($this->email, $runtime->delivered[0]['email']);
    }
}

/** MailService-Stub: zeichnet Versände auf, sendet nichts. */
class FakeMail extends MailService
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $sent = [];

    public function notify(string $to, string $subject, string $body): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return true;
    }
}

/** ContributionRuntime-Stub: ein Modul-Kanal `slack`, der Zustellungen aufzeichnet. */
class FakeRuntime extends ContributionRuntime
{
    /** @var list<array<string,mixed>> */
    public array $delivered = [];

    public function collectors(string $contract): array
    {
        return [['class' => 'Fake\\Slack', 'module_key' => 'fake', 'isolation' => 'in_process']];
    }

    public function call(array $contrib, string $method, array $args, ?array $rls = null): mixed
    {
        if ($method === 'key') {
            return 'slack';
        }
        if ($method === 'deliver') {
            $this->delivered[] = $args[0];
        }

        return null;
    }
}
