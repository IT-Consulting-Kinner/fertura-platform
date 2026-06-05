<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Settings\SecretCipher;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Verwaltung verschlüsselter Settings — insbesondere Schlüsselrotation
 * (Re-Encryption, Entscheidung 164).
 *
 *   bin/cake secret rotate --old <altes-Schlüsselmaterial> [--new <neues>] [--dry-run]
 *
 * Betriebsablauf: Neues Schlüsselmaterial in der Infrastruktur (env
 * SECURITY_ENCRYPTION_KEY) hinterlegen, dann `secret rotate --old <alt>`
 * ausführen. Ohne --new wird gegen das aktuell konfigurierte
 * Security.encryptionKey re-verschlüsselt. Alle is_secret-Settings werden mit
 * dem alten Schlüssel entschlüsselt und mit dem neuen verschlüsselt; danach wird
 * geprüft, dass jedes Geheimnis mit dem neuen Schlüssel entschlüsselbar ist.
 * Alles in einer Transaktion.
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

        // 1. Vorab: alle mit dem ALTEN Schlüssel entschlüsselbar?
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

        // 2. Transaktional schreiben.
        $conn->transactional(function () use ($conn, $reencrypted): void {
            foreach ($reencrypted as $id => $info) {
                $conn->execute(
                    'UPDATE settings SET value_encrypted = :v WHERE id = :id',
                    ['v' => $info['cipher'], 'id' => $id],
                );
            }
        });

        // 3. Verify: alles mit dem NEUEN Schlüssel entschlüsselbar?
        $verifyRows = $conn->execute(
            'SELECT namespace, config_key, value_encrypted FROM settings '
            . 'WHERE is_secret = true AND value_encrypted IS NOT NULL',
        )->fetchAll('assoc');
        foreach ($verifyRows as $r) {
            try {
                $newCipher->decrypt((string)$r['value_encrypted']);
            } catch (Throwable $e) {
                $io->error("VERIFY fehlgeschlagen für {$r['namespace']}.{$r['config_key']}: {$e->getMessage()}");

                return static::CODE_ERROR;
            }
        }

        $io->success(sprintf('%d Geheimnis(se) rotiert und verifiziert.', count($reencrypted)));

        return static::CODE_SUCCESS;
    }
}
