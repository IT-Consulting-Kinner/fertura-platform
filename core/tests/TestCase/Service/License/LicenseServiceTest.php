<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\License;

use App\Service\License\LicenseService;
use App\Service\Security\Signer;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Test der Offline-first-Lizenzierung (Kap. 28.7, Entscheidung 158): Datei-
 * Validierung gegen die echte Vertrauenskette (Ed25519), Installation (Upsert)
 * und die evaluate()-Status-Matrix — valid / grace / expired / needs_online /
 * revoked / missing inkl. Online-Enforcement + recordOnlineCheck.
 */
class LicenseServiceTest extends TestCase
{
    private string $keyId = '';
    private string $secret = '';
    private string $moduleKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $this->keyId = 'ztest-lic-root-' . $suffix;
        $this->moduleKey = 'zztest_licmod_' . $suffix;

        $pair = Signer::generateKeypair();
        $this->secret = $pair['secret'];
        (new TrustStore())->addAnchor($this->keyId, $pair['public'], 'root');
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM licenses WHERE module_key = :k', ['k' => $this->moduleKey]);
        $conn->execute('DELETE FROM trust_anchors WHERE key_id = :k', ['k' => $this->keyId]);
        $conn->execute('DELETE FROM revoked_keys WHERE key_id = :k', ['k' => $this->keyId]);
        parent::tearDown();
    }

    /** @param array<string,mixed> $payload */
    private function licenseJson(array $payload): string
    {
        $signature = (new Signer())->sign(LicenseService::canonical($payload), $this->secret);

        return (string)json_encode([
            'payload' => $payload,
            'signature' => $signature,
            'key_id' => $this->keyId,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'module_ref' => $this->moduleKey,
            'issuer' => 'Fertura Test',
            'valid_from' => null,
            'valid_to' => null,
        ];
    }

    public function testValidateFileHappyPathAndRejections(): void
    {
        $service = new LicenseService();

        // Gültig.
        $ok = $service->validateFile($this->licenseJson($this->payload()));
        $this->assertTrue($ok['ok']);
        $this->assertSame($this->keyId, $ok['key_id']);

        // Strukturell kaputt.
        $this->assertFalse($service->validateFile('{"nope":true}')['ok']);

        // Manipulierter Payload -> Signatur ungültig.
        $json = json_decode($this->licenseJson($this->payload()), true);
        $json['payload']['module_ref'] = 'evil_module';
        $bad = $service->validateFile((string)json_encode($json));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsStringIgnoringCase('signatur', (string)$bad['reason']);

        // Unbekannter Anker.
        $json = json_decode($this->licenseJson($this->payload()), true);
        $json['key_id'] = 'ztest-unknown-anchor';
        $this->assertFalse($service->validateFile((string)json_encode($json))['ok']);
    }

    public function testValidateFileRejectsRevokedAndExpiredAnchor(): void
    {
        $service = new LicenseService();
        $json = $this->licenseJson($this->payload());

        // Anker abgelaufen -> abgelehnt (E45-Fenster gilt auch für Lizenzen).
        ConnectionManager::get('default')->execute(
            "UPDATE trust_anchors SET valid_to = now() - interval '1 day' WHERE key_id = :k",
            ['k' => $this->keyId],
        );
        $expired = $service->validateFile($json);
        $this->assertFalse($expired['ok']);

        // Widerrufener Schlüssel -> abgelehnt (greift vor allem anderen).
        ConnectionManager::get('default')->execute(
            'UPDATE trust_anchors SET valid_to = NULL WHERE key_id = :k',
            ['k' => $this->keyId],
        );
        (new TrustStore())->revokeKey($this->keyId, 'Test');
        $revoked = $service->validateFile($json);
        $this->assertFalse($revoked['ok']);
        $this->assertStringContainsString('widerrufen', (string)$revoked['reason']);
    }

    public function testInstallRequiresModuleRefAndUpserts(): void
    {
        $service = new LicenseService();

        // Ohne module_ref -> Abbruch.
        try {
            $service->install($this->licenseJson(['module_ref' => '', 'issuer' => 'x']));
            $this->fail('Erwartete RuntimeException (module_ref fehlt).');
        } catch (RuntimeException) {
            // erwartet
        }

        // Installation -> Status valid (unbegrenzte Gültigkeit).
        $first = $service->install($this->licenseJson($this->payload()));
        $this->assertSame('valid', $first['status']);

        // Upsert: zweite Installation mit Ablauf in der Zukunft ersetzt die erste.
        $second = $service->install($this->licenseJson($this->payload([
            'valid_to' => date('c', time() + 30 * 86400),
        ])));
        $this->assertSame('valid', $second['status']);
        $count = (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM licenses WHERE module_key = :k',
            ['k' => $this->moduleKey],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $count); // ein Datensatz je Modul (Upsert)
    }

    public function testEvaluateStatusMatrix(): void
    {
        $service = new LicenseService();
        $conn = ConnectionManager::get('default');

        // missing: keine Lizenz vorhanden.
        $this->assertSame('missing', $service->evaluate($this->moduleKey)['status']);

        $service->install($this->licenseJson($this->payload()));
        $this->assertSame('valid', $service->evaluate($this->moduleKey)['status']);
        $this->assertTrue($service->isValid($this->moduleKey));

        // Abgelaufen MIT Karenzfenster -> grace (eingeschränkt gültig).
        $conn->execute(
            "UPDATE licenses SET valid_to = now() - interval '2 days', grace_window_days = 10 WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $grace = $service->evaluate($this->moduleKey);
        $this->assertSame('grace', $grace['status']);
        $this->assertTrue($service->isValid($this->moduleKey)); // Aktivierung bleibt erlaubt

        // Abgelaufen JENSEITS des Karenzfensters -> expired.
        $conn->execute(
            "UPDATE licenses SET valid_to = now() - interval '20 days', grace_window_days = 10 WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $this->assertSame('expired', $service->evaluate($this->moduleKey)['status']);
        $this->assertFalse($service->isValid($this->moduleKey));

        // Widerruf des Signaturschlüssels -> revoked (unabhängig vom Zeitfenster).
        (new TrustStore())->revokeKey($this->keyId, 'Test');
        $this->assertSame('revoked', $service->evaluate($this->moduleKey)['status']);
    }

    public function testOnlineEnforcementNeedsFreshCheck(): void
    {
        $service = new LicenseService();
        $conn = ConnectionManager::get('default');
        $service->install($this->licenseJson($this->payload(['online_enforcement' => true])));

        // Nie online bestätigt -> needs_online.
        $this->assertSame('needs_online', $service->evaluate($this->moduleKey)['status']);

        // Frische Bestätigung -> valid.
        $service->recordOnlineCheck($this->moduleKey);
        $this->assertSame('valid', $service->evaluate($this->moduleKey)['status']);

        // Bestätigung veraltet (älter als license.online_max_age_days=7) -> needs_online.
        $conn->execute(
            "UPDATE licenses SET last_online_check = now() - interval '30 days' WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $this->assertSame('needs_online', $service->evaluate($this->moduleKey)['status']);

        // Überfällige Online-Bestätigung INNERHALB des Karenzfensters -> grace.
        $conn->execute(
            "UPDATE licenses SET valid_to = now() - interval '1 day', grace_window_days = 10 WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $graceOnline = $service->evaluate($this->moduleKey);
        $this->assertSame('grace', $graceOnline['status']);
    }
}
