<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Privacy;

use App\Service\Privacy\AnonymizationService;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest des Anonymisierungs-Hooks (Kap. 27.15.3): Module bereinigen
 * über den Core-Collector `core.collector.anonymize` ihre eigenen
 * personenbezogenen Daten zu einem Benutzer — in derselben Transaktion wie die
 * Core-Anonymisierung. Prüft Direktaufruf und den End-to-End-Pfad über
 * UsersTable::anonymize.
 */
class AnonymizationServiceTest extends TestCase
{
    use LocatorAwareTrait;

    private const CONTRIB = 'App\\Test\\Fixture\\Anonymize\\TestAnonymizeContributor';
    private const TARGET = '11111111-1111-7111-8111-aaaaaaaaaaaa';
    private const OTHER = '22222222-2222-7222-8222-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $conn = ConnectionManager::get('default');
        // Modul-Testtabelle mit PII zweier Benutzer.
        $conn->execute('DROP TABLE IF EXISTS public.ztest_anon_data');
        $conn->execute('CREATE TABLE public.ztest_anon_data (id serial PRIMARY KEY, owner_id uuid, secret text)');
        $conn->execute("INSERT INTO public.ztest_anon_data (owner_id, secret) VALUES (:a, 'Klarname A'), (:a, 'Notiz A'), (:b, 'Klarname B')", ['a' => self::TARGET, 'b' => self::OTHER]);
        // Der Core-Contract wird per Migration geseedet, aber der Test-Migrator
        // truncatet Seed-Daten -> hier sicherstellen.
        $conn->execute(
            "INSERT INTO contracts (owner_module_key, name, contract_type, version, multi_use, active) "
            . "VALUES ('core', 'core.collector.anonymize', 'collector', '1.0.0', true, true) ON CONFLICT (name) DO NOTHING",
        );
        // Beitrag für core.collector.anonymize registrieren (module_key ist nur Text).
        $cid = $conn->execute("SELECT id FROM contracts WHERE name = 'core.collector.anonymize'")->fetch('assoc');
        $conn->execute(
            'INSERT INTO contract_registrations (contract_id, module_key, registration_type, implementation_class, active) '
            . "VALUES (:c, 'ztest_anon', 'collector_contribution', :cls, true)",
            ['c' => $cid['id'], 'cls' => self::CONTRIB],
        );
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM contract_registrations WHERE module_key = 'ztest_anon'");
        $conn->execute('DROP TABLE IF EXISTS public.ztest_anon_data');
        parent::tearDown();
    }

    public function testRunInvokesContributorsScopedToUser(): void
    {
        $conn = ConnectionManager::get('default');
        $scrubbed = $conn->transactional(fn () => (new AnonymizationService())->run(self::TARGET, $conn));

        $this->assertSame(2, $scrubbed, 'Beide PII-Zeilen des Zielnutzers müssen bereinigt sein.');
        // Zielnutzer bereinigt ...
        $target = $conn->execute("SELECT count(*) c FROM public.ztest_anon_data WHERE owner_id = :u AND secret = '[anonymisiert]'", ['u' => self::TARGET])->fetch('assoc');
        $this->assertSame(2, (int)$target['c']);
        // ... anderer Benutzer unberührt.
        $other = $conn->execute("SELECT secret FROM public.ztest_anon_data WHERE owner_id = :u", ['u' => self::OTHER])->fetch('assoc');
        $this->assertSame('Klarname B', $other['secret']);
    }

    public function testBypassContextRestoredAfterRun(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->transactional(function () use ($conn): void {
            $conn->execute("SELECT set_config('app.bypass_rls', 'false', true)");
            (new AnonymizationService())->run(self::TARGET, $conn);
            $after = (string)$conn->execute("SELECT current_setting('app.bypass_rls', true) AS v")->fetch('assoc')['v'];
            $this->assertSame('false', $after, 'bypass_rls muss nach den Beiträgen wiederhergestellt sein.');
        });
    }

    public function testEndToEndViaUsersTableAnonymize(): void
    {
        $conn = ConnectionManager::get('default');
        // Echten Benutzer anlegen, dessen UUID auf die PII-Zeilen zeigt.
        $row = $conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'anon_e2e_' . bin2hex(random_bytes(4)), 'e' => 'anon_e2e@invalid.local'],
        )->fetch('assoc');
        $userId = (string)$row['id'];
        $conn->execute("INSERT INTO public.ztest_anon_data (owner_id, secret) VALUES (:u, 'E2E Klarname')", ['u' => $userId]);

        try {
            $users = $this->fetchTable('Users');
            $user = $users->get($userId);
            $this->assertTrue($users->anonymize($user));

            // Core-Benutzer anonymisiert ...
            $u = $conn->execute('SELECT status, email FROM users WHERE id = :id', ['id' => $userId])->fetch('assoc');
            $this->assertSame('anonymized', $u['status']);
            // ... und die Modul-PII des Benutzers bereinigt.
            $m = $conn->execute("SELECT secret FROM public.ztest_anon_data WHERE owner_id = :u", ['u' => $userId])->fetch('assoc');
            $this->assertSame('[anonymisiert]', $m['secret']);
        } finally {
            $conn->execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);
        }
    }
}
