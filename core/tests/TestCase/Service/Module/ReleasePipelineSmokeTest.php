<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\ModuleLifecycle;
use App\Service\Sdk\PackageBuilder;
use App\Service\Security\PackageVerifier;
use App\Service\Security\Signer;
use App\Service\Security\TrustStore;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * End-to-end smoke for the module release/distribution pipeline (E176 context):
 * the never-before-E2E-tested chain **PackageBuilder → sign → trust anchor →
 * SIGNED `module install` → activate**, plus the negative case that tampering a
 * packaged file makes the signed install fail (so the signature is genuinely
 * enforced, not decorative).
 *
 * Uses the lint-clean `sample_module` fixture (a copy + a CHANGELOG, so the
 * PackageBuilder release gates pass) and signs with a freshly generated ROOT
 * anchor — which sidesteps the publisher-certificate chain while exercising the
 * real PackageVerifier path the Core install uses.
 */
class ReleasePipelineSmokeTest extends TestCase
{
    private const KEY = 'sample_module';

    private string $work = '';
    private string $rootId = '';
    private bool $prevRequireSig = true;

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        $this->prevRequireSig = (bool)$sm->get('core', 'require_module_signature', true);
        $sm->set('core', 'require_module_signature', true); // the path under test

        $this->cleanupModule();
        $this->work = sys_get_temp_dir() . '/zzrel_' . bin2hex(random_bytes(5));
        mkdir($this->work, 0o775, true);
        $this->rootId = 'zztest_root_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->cleanupModule();
        ConnectionManager::get('default')->execute('DELETE FROM trust_anchors WHERE key_id = :k', ['k' => $this->rootId]);
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevRequireSig);
        $this->rrmdir($this->work);
        parent::tearDown();
    }

    public function testSignedPackageInstallsActivatesAndMigrates(): void
    {
        $staging = $this->buildSignedPackage();

        // SIGNED install (verifies signature.json against the trusted root anchor).
        (new ModuleLifecycle())->install($staging);
        (new ModuleLifecycle())->activate(self::KEY);

        $conn = ConnectionManager::get('default');
        $status = $conn->execute('SELECT status FROM modules WHERE module_key = :k', ['k' => self::KEY])->fetch('assoc');
        $this->assertNotFalse($status, 'module row must exist after install');
        $this->assertSame('active', $status['status']);

        // The module migration ran -> its schema exists.
        $schema = $conn->execute(
            "SELECT 1 FROM information_schema.schemata WHERE schema_name = 'mod_sample_module'",
        )->fetch('assoc');
        $this->assertNotFalse($schema, 'module migration must have created mod_sample_module');
    }

    public function testTamperedPackageFailsSignedInstall(): void
    {
        $staging = $this->buildSignedPackage();

        // Tamper AFTER signing: append a byte to a packaged file -> the digest no
        // longer matches the signature -> the signed install must reject it.
        file_put_contents($staging . '/manifest.json', ' ', FILE_APPEND);

        try {
            (new ModuleLifecycle())->install($staging);
            $this->fail('Tampered package must not install.');
        } catch (Throwable $e) {
            $this->assertMatchesRegularExpression('/Signatur|signature/i', $e->getMessage());
        }
        // Proof it did not get installed.
        $row = ConnectionManager::get('default')
            ->execute('SELECT 1 FROM modules WHERE module_key = :k', ['k' => self::KEY])->fetch('assoc');
        $this->assertFalse($row, 'tampered package must not create a module row');
    }

    /** Builds + signs a package from the sample_module fixture; returns the staging dir. */
    private function buildSignedPackage(): string
    {
        // 1) A buildable source: the fixture + a CHANGELOG (PackageBuilder gate).
        $src = $this->work . '/src';
        $this->copyTree(ROOT . '/tests/Fixture/sample_module', $src);
        file_put_contents($src . '/CHANGELOG.md', "# Changelog\n\n## 1.0.0\n- Smoke release.\n");

        // 2) Assemble (lint + version + reversible migrations + changelog gates).
        $res = (new PackageBuilder())->build($src, $this->work . '/out');
        $this->assertTrue($res['ok'], 'package build: ' . implode('; ', $res['errors']));
        $staging = $res['path'];

        // 3) Trust a freshly generated ROOT key, then sign the package with it.
        $kp = Signer::generateKeypair();
        (new TrustStore())->addAnchor($this->rootId, $kp['public'], 'root');
        $signer = new Signer();
        $signature = $signer->sign((new PackageVerifier())->packageDigest($staging), $kp['secret']);
        file_put_contents(
            $staging . '/' . PackageVerifier::SIGNATURE_FILE,
            (string)json_encode(['key_id' => $this->rootId, 'signature' => $signature]) . "\n",
        );

        return $staging;
    }

    private function cleanupModule(): void
    {
        try {
            (new ModuleLifecycle())->delete(self::KEY);
        } catch (Throwable) {
            // not installed
        }
        try {
            ConnectionManager::get('default')->execute('DELETE FROM modules WHERE module_key = :k', ['k' => self::KEY]);
        } catch (Throwable) {
        }
        $this->rrmdir(ROOT . '/modules/' . self::KEY);
    }

    private function copyTree(string $s, string $d): void
    {
        mkdir($d, 0o775, true);
        foreach (scandir($s) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            is_dir("$s/$i") ? $this->copyTree("$s/$i", "$d/$i") : copy("$s/$i", "$d/$i");
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            is_dir("$dir/$i") ? $this->rrmdir("$dir/$i") : @unlink("$dir/$i");
        }
        @rmdir($dir);
    }
}
