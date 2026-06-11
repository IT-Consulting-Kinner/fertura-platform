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
 * Management of trust anchors and the revocation list (ch. 24.9.2).
 *
 *   bin/cake trust add-anchor <key_id> <public_key_b64> --type root
 *   bin/cake trust add-anchor --cert <publisher-cert.json>   (chain is verified)
 *   bin/cake trust revoke <key_id> [--reason R]
 *   bin/cake trust list
 *
 * Root anchors are installed directly (trusted out of band). Publisher anchors
 * are only accepted if their certificate (from `mp_tool sign-key`) is signed by
 * an active Root (chain Root -> Publisher).
 */
class TrustCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['add-anchor', 'revoke', 'rotate', 'list'], 'required' => true])
            ->addArgument('key_id', ['help' => 'Schlüssel-ID (Root-Anker) / alter Schlüssel bei rotate'])
            ->addArgument('public_key', ['help' => 'Public Key (base64, Root-Anker) / neuer Schlüssel bei rotate'])
            ->addOption('type', ['choices' => ['root', 'publisher'], 'default' => 'root'])
            ->addOption('publisher', ['help' => 'Publisher (für publisher-Keys)'])
            ->addOption('cert', ['help' => 'Publisher-Zertifikat (JSON aus mp_tool sign-key)'])
            ->addOption('valid-from', ['help' => 'Gültig ab (ISO-8601, optional)'])
            ->addOption('valid-to', ['help' => 'Gültig bis (ISO-8601, optional)'])
            ->addOption('overlap-days', ['help' => 'Überlappungsfenster bei rotate (Tage, Default 30)', 'default' => '30'])
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

            case 'rotate':
                return $this->rotate($args, $io, $trust);

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

    /**
     * Rolling key rotation (ch. 1.4): the new anchor becomes valid immediately and
     * indefinitely, while the old one expires after an overlap window (E45 enforces
     * the `valid_to`). During the window both are accepted -> no outage. The new
     * anchor must already be installed (`add-anchor`/`--cert`).
     */
    private function rotate(Arguments $args, ConsoleIo $io, TrustStore $trust): int
    {
        $old = (string)$args->getArgument('key_id');
        $new = (string)$args->getArgument('public_key');
        if ($old === '' || $new === '') {
            $io->error('rotate benötigt <alte-key-id> <neue-key-id>.');

            return static::CODE_ERROR;
        }
        if ($trust->getAnchor($new) === null) {
            $io->error("Neuer Anker nicht aktiv/vorhanden: $new (zuerst 'trust add-anchor' bzw. '--cert').");

            return static::CODE_ERROR;
        }
        $days = max(0, (int)$args->getOption('overlap-days'));
        $conn = ConnectionManager::get('default');
        // New anchor: valid from now on, indefinitely.
        $conn->execute(
            'UPDATE trust_anchors SET valid_from = COALESCE(valid_from, now()), valid_to = NULL WHERE key_id = :k',
            ['k' => $new],
        );
        // Old anchor: let it expire after the window.
        $n = $conn->execute(
            "UPDATE trust_anchors SET valid_to = now() + (:d || ' days')::interval WHERE key_id = :k",
            ['d' => (string)$days, 'k' => $old],
        )->rowCount();
        if ($n === 0) {
            $io->warning("Alter Anker '$old' nicht gefunden — nur der neue Anker wurde aktiviert.");
        } else {
            $io->success("Rotation: '$new' gilt ab sofort; '$old' läuft in $days Tagen aus (beide bis dahin akzeptiert).");
        }

        return static::CODE_SUCCESS;
    }

    private function addAnchor(Arguments $args, ConsoleIo $io, TrustStore $trust): int
    {
        // Publisher anchor from a certificate (chain is verified).
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

        // Root anchor (directly trusted).
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
