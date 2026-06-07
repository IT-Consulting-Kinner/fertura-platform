<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Security\TrustChain;
use App\Service\Security\TrustStore;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Verwaltung von Vertrauensankern und Sperrliste (Kap. 24.9.2).
 *
 *   bin/cake trust add-anchor <key_id> <public_key_b64> --type root
 *   bin/cake trust add-anchor --cert <publisher-cert.json>   (Kette wird geprüft)
 *   bin/cake trust revoke <key_id> [--reason R]
 *   bin/cake trust list
 *
 * Root-Anker werden direkt (außerhalb des Bandes vertrauenswürdig) installiert.
 * Publisher-Anker werden nur akzeptiert, wenn ihr Zertifikat (aus `mp_tool
 * sign-key`) von einem aktiven Root signiert ist (Kette Root -> Publisher).
 */
class TrustCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['add-anchor', 'revoke', 'list'], 'required' => true])
            ->addArgument('key_id', ['help' => 'Schlüssel-ID (Root-Anker)'])
            ->addArgument('public_key', ['help' => 'Public Key (base64, Root-Anker)'])
            ->addOption('type', ['choices' => ['root', 'publisher'], 'default' => 'root'])
            ->addOption('publisher', ['help' => 'Publisher (für publisher-Keys)'])
            ->addOption('cert', ['help' => 'Publisher-Zertifikat (JSON aus mp_tool sign-key)'])
            ->addOption('valid-from', ['help' => 'Gültig ab (ISO-8601, optional)'])
            ->addOption('valid-to', ['help' => 'Gültig bis (ISO-8601, optional)'])
            ->addOption('reason', ['help' => 'Widerrufsgrund']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $trust = new TrustStore();

        switch ($args->getArgument('operation')) {
            case 'add-anchor':
                return $this->addAnchor($args, $io, $trust);

            case 'revoke':
                $trust->revokeKey((string)$args->getArgument('key_id'), $args->getOption('reason'));
                $io->success('Schlüssel widerrufen: ' . $args->getArgument('key_id'));

                return static::CODE_SUCCESS;

            case 'list':
                $rows = ConnectionManager::get('default')->execute(
                    'SELECT key_id, key_type, publisher, signed_by, valid_from, valid_to, active FROM trust_anchors ORDER BY key_id',
                )->fetchAll('assoc');
                foreach ($rows as $r) {
                    $chain = $r['key_type'] === 'publisher' ? ' <- ' . ($r['signed_by'] ?? '?') : '';
                    $window = ($r['valid_from'] || $r['valid_to'])
                        ? ' [' . ($r['valid_from'] ?? '…') . ' … ' . ($r['valid_to'] ?? '…') . ']'
                        : '';
                    $expired = !TrustStore::validity($r)['ok'] ? ' <warning>UNGUELTIG</warning>' : '';
                    $io->out(sprintf('  %-16s %-10s %s%s%s%s%s', $r['key_id'], $r['key_type'], $r['publisher'] ?? '-', $chain, $window, $expired, $r['active'] ? '' : ' [inaktiv]'));
                }
                $revoked = ConnectionManager::get('default')->execute('SELECT key_id FROM revoked_keys')->fetchAll('assoc');
                foreach ($revoked as $r) {
                    $io->out('  <warning>widerrufen:</warning> ' . $r['key_id']);
                }

                return static::CODE_SUCCESS;
        }

        return static::CODE_ERROR;
    }

    private function addAnchor(Arguments $args, ConsoleIo $io, TrustStore $trust): int
    {
        // Publisher-Anker aus Zertifikat (Kette wird geprüft).
        $certPath = $args->getOption('cert');
        if ($certPath !== null) {
            if (!is_file((string)$certPath)) {
                $io->error('Zertifikatsdatei nicht gefunden: ' . $certPath);

                return static::CODE_ERROR;
            }
            $cert = json_decode((string)file_get_contents((string)$certPath), true);
            if (!is_array($cert)) {
                $io->error('Zertifikat ist kein gültiges JSON-Objekt.');

                return static::CODE_ERROR;
            }
            $check = (new TrustChain())->verifyPublisherCert($cert);
            if (!$check['ok']) {
                $io->error('Kette ungültig: ' . ($check['reason'] ?? 'unbekannt'));

                return static::CODE_ERROR;
            }
            $trust->addAnchor(
                (string)$cert['key_id'],
                (string)$cert['public_key'],
                'publisher',
                $cert['publisher'] ?? null,
                (string)$cert['signed_by'],
                (string)$cert['key_signature'],
                $args->getOption('valid-from') ?: ($cert['valid_from'] ?? null),
                $args->getOption('valid-to') ?: ($cert['valid_to'] ?? null),
            );
            $io->success('Publisher-Anker (Kette geprüft) hinzugefügt: ' . $cert['key_id']);

            return static::CODE_SUCCESS;
        }

        // Root-Anker (direkt vertrauenswürdig).
        $keyId = (string)$args->getArgument('key_id');
        $publicKey = (string)$args->getArgument('public_key');
        if ($keyId === '' || $publicKey === '') {
            $io->error('Root-Anker benötigt <key_id> und <public_key>; Publisher-Anker via --cert.');

            return static::CODE_ERROR;
        }
        if ((string)$args->getOption('type') === 'publisher') {
            $io->error('Publisher-Anker bitte über --cert hinzufügen (Kettenprüfung erforderlich).');

            return static::CODE_ERROR;
        }
        $trust->addAnchor(
            $keyId,
            $publicKey,
            'root',
            null,
            null,
            null,
            $args->getOption('valid-from') ?: null,
            $args->getOption('valid-to') ?: null,
        );
        $io->success('Root-Vertrauensanker hinzugefügt: ' . $keyId);

        return static::CODE_SUCCESS;
    }
}
