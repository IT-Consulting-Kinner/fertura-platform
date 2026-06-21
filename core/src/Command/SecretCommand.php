<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Settings\SecretCipher;
use App\Service\Settings\SecretRotationService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Management of encrypted settings — in particular key rotation
 * (re-encryption, Decision 164).
 *
 *   bin/cake secret rotate --old <old-key-material> [--new <new>] [--dry-run]
 *
 * Operational flow: deploy the new key material in the infrastructure (env
 * SECURITY_ENCRYPTION_KEY), then run `secret rotate --old <old>`. Without --new
 * it re-encrypts against the currently configured Security.encryptionKey. Every
 * is_secret setting is decrypted with the old key and encrypted with the new
 * one; afterwards it is verified that each secret is decryptable with the new
 * key. All within a single transaction.
 */
class SecretCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Verschlüsselte Settings verwalten (Schlüsselrotation).')
            ->addArgument('operation', ['help' => 'rotate', 'choices' => ['rotate'], 'required' => true])
            ->addOption('old', ['help' => 'Altes Schlüsselmaterial (Pflicht bei rotate)'])
            ->addOption('new', ['help' => 'Neues Schlüsselmaterial (Default: aktuell konfiguriertes Security.encryptionKey)'])
            ->addOption('dry-run', ['boolean' => true, 'help' => 'Nur prüfen, nichts schreiben']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        return $this->rotate($args, $io);
    }

    private function rotate(Arguments $args, ConsoleIo $io): int
    {
        $old = (string)$args->getOption('old');
        if ($old === '') {
            $io->error('--old (altes Schlüsselmaterial) ist erforderlich.');

            return static::CODE_ERROR;
        }
        $newMaterial = $args->getOption('new');
        if ($newMaterial === null || $newMaterial === '') {
            $newMaterial = Configure::read('Security.encryptionKey') ?? Configure::read('Security.salt');
        }
        if (empty($newMaterial)) {
            $io->error('Kein neues Schlüsselmaterial (weder --new noch Security.encryptionKey gesetzt).');

            return static::CODE_ERROR;
        }
        if ((string)$old === (string)$newMaterial) {
            $io->warning('Altes und neues Schlüsselmaterial sind identisch — nichts zu rotieren.');

            return static::CODE_SUCCESS;
        }

        $oldCipher = new SecretCipher((string)$old);
        $newCipher = new SecretCipher((string)$newMaterial);
        $dryRun = (bool)$args->getOption('dry-run');

        $conn = ConnectionManager::get('default');
        $rows = $conn->execute(
            'SELECT id, namespace, config_key, value_encrypted FROM settings '
            . 'WHERE is_secret = true AND value_encrypted IS NOT NULL',
        )->fetchAll('assoc');

        if ($rows === []) {
            $io->out('<info>Keine verschlüsselten Settings vorhanden — nichts zu tun.</info>');

            return static::CODE_SUCCESS;
        }

        $io->out(sprintf('%d verschlüsselte Setting(s) gefunden.%s', count($rows), $dryRun ? ' [DRY-RUN]' : ''));

        // 1. Up front: are all of them decryptable with the OLD key?
        $reencrypted = [];
        foreach ($rows as $r) {
            $label = $r['namespace'] . '.' . $r['config_key'];
            try {
                $plain = $oldCipher->decrypt((string)$r['value_encrypted']);
            } catch (Throwable $e) {
                $io->error("Entschlüsselung mit altem Schlüssel fehlgeschlagen: $label ({$e->getMessage()})");

                return static::CODE_ERROR;
            }
            $reencrypted[(string)$r['id']] = ['label' => $label, 'cipher' => $newCipher->encrypt($plain)];
        }

        if ($dryRun) {
            foreach ($reencrypted as $info) {
                $io->out('  ✓ ' . $info['label'] . ' (re-verschlüsselbar)');
            }
            $io->success('Dry-Run ok: alle Geheimnisse mit altem Schlüssel les- und mit neuem Schlüssel schreibbar.');

            return static::CODE_SUCCESS;
        }

        // Real rotation via the shared, AUDITED service (write + verify in one place,
        // reused by the maintenance critical-action handler).
        try {
            $count = (new SecretRotationService())->rotate((string)$old, (string)$newMaterial);
        } catch (Throwable $e) {
            $io->error('Rotation fehlgeschlagen: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        $io->success(sprintf('%d Geheimnis(se) rotiert und verifiziert.', $count));

        return static::CODE_SUCCESS;
    }
}
