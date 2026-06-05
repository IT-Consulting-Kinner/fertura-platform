<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\License\LicenseService;
use App\Service\Marketplace\MarketplaceClient;
use App\Service\Security\PackageVerifier;
use App\Service\Security\Signer;
use App\Service\Security\TrustChain;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Betreiber-/Marketplace-Werkzeug für das Schlüssel-, Signatur- und
 * Lizenzverfahren (Kap. 24.9.2 / 28.7). Agiert als signierende Gegenstelle.
 *
 * Schlüsselhierarchie (Root -> Publisher):
 *   - Root-Schlüssel: oberster Vertrauensanker (offline/HSM aufbewahren).
 *     Signiert Publisher-Zertifikate sowie Marketplace-Dokumente (anchors/crl).
 *   - Publisher-Schlüssel: operativ; signiert Pakete und Lizenzen. Ihr
 *     Zertifikat wird vom Root signiert (Kette, vom Core geprüft).
 *
 *   bin/cake mp_tool keygen
 *   bin/cake mp_tool sign-key      --secret <rootSecret> --key-id <rootId> \
 *                                  --pub-key <publisherPub> --pub-id <pubId> --publisher <Name>
 *   bin/cake mp_tool sign          <paketverzeichnis> --secret <pubSecret> --key-id <pubId>
 *   bin/cake mp_tool license       <module> --secret <pubSecret> --key-id <pubId> --valid-to <ISO> [--grace N] [--online]
 *   bin/cake mp_tool sign-doc      <payload.json> --secret <rootSecret> --key-id <rootId>
 *
 * Siehe SIGNING.md für das vollständige Betriebsverfahren.
 */
class MpToolCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', [
                'choices' => ['keygen', 'sign-key', 'sign', 'license', 'sign-doc'],
                'required' => true,
            ])
            ->addArgument('target', ['help' => 'Paketverzeichnis (sign) / module_ref (license) / payload.json (sign-doc)'])
            ->addOption('secret', ['help' => 'Signierender Secret-Key (base64)'])
            ->addOption('key-id', ['help' => 'Schlüssel-ID des signierenden Schlüssels'])
            ->addOption('pub-key', ['help' => 'sign-key: zu signierender Publisher-Public-Key (base64)'])
            ->addOption('pub-id', ['help' => 'sign-key: Schlüssel-ID des Publisher-Schlüssels'])
            ->addOption('publisher', ['help' => 'sign-key: Publisher-Name (Bindung an Manifest-Publisher)'])
            ->addOption('valid-to', ['help' => 'Lizenz: Ablauf (ISO-8601)'])
            ->addOption('valid-from', ['help' => 'Lizenz: Beginn (ISO-8601)'])
            ->addOption('grace', ['help' => 'Lizenz: Karenzfenster (Tage)'])
            ->addOption('online', ['boolean' => true, 'help' => 'Lizenz: Online-Enforcement']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $signer = new Signer();

        switch ($args->getArgument('operation')) {
            case 'keygen':
                $kp = Signer::generateKeypair();
                $io->out('public=' . $kp['public']);
                $io->out('secret=' . $kp['secret']);

                return static::CODE_SUCCESS;

            case 'sign-key':
                return $this->signKey($args, $io, $signer);

            case 'sign':
                $dir = rtrim((string)$args->getArgument('target'), '/');
                $sig = $signer->sign((new PackageVerifier())->packageDigest($dir), (string)$args->getOption('secret'));
                $payload = json_encode(['key_id' => (string)$args->getOption('key-id'), 'signature' => $sig], JSON_PRETTY_PRINT);
                file_put_contents($dir . '/' . PackageVerifier::SIGNATURE_FILE, $payload . "\n");
                $io->success("Signiert: $dir/" . PackageVerifier::SIGNATURE_FILE);

                return static::CODE_SUCCESS;

            case 'license':
                $payload = [
                    'module_ref' => (string)$args->getArgument('target'),
                    'valid_from' => $args->getOption('valid-from') ?: date('c', time() - 86400),
                    'valid_to' => (string)$args->getOption('valid-to'),
                    'grace_window_days' => $args->getOption('grace') !== null ? (int)$args->getOption('grace') : null,
                    'online_enforcement' => (bool)$args->getOption('online'),
                    'issuer' => 'Fertura Test Marketplace',
                    'scope' => 'standard',
                ];
                $license = [
                    'payload' => $payload,
                    'key_id' => (string)$args->getOption('key-id'),
                    'signature' => $signer->sign(LicenseService::canonical($payload), (string)$args->getOption('secret')),
                ];
                $io->out((string)json_encode($license));

                return static::CODE_SUCCESS;

            case 'sign-doc':
                return $this->signDoc($args, $io, $signer);
        }

        return static::CODE_ERROR;
    }

    /**
     * Root signiert ein Publisher-Zertifikat (Kette Root -> Publisher).
     * Ausgabe: Anker-Dokument inkl. Root-Signatur über (key_id, public_key, publisher).
     */
    private function signKey(Arguments $args, ConsoleIo $io, Signer $signer): int
    {
        $pubKey = (string)$args->getOption('pub-key');
        $pubId = (string)$args->getOption('pub-id');
        $publisher = $args->getOption('publisher') !== null ? (string)$args->getOption('publisher') : null;
        $rootId = (string)$args->getOption('key-id');
        $rootSecret = (string)$args->getOption('secret');
        if ($pubKey === '' || $pubId === '' || $rootId === '' || $rootSecret === '') {
            $io->error('sign-key benötigt --pub-key, --pub-id, --key-id (Root) und --secret (Root).');

            return static::CODE_ERROR;
        }

        $statement = TrustChain::keyStatement($pubId, $pubKey, $publisher);
        $cert = [
            'key_id' => $pubId,
            'public_key' => $pubKey,
            'key_type' => 'publisher',
            'publisher' => $publisher,
            'signed_by' => $rootId,
            'key_signature' => $signer->sign($statement, $rootSecret),
        ];
        $io->out((string)json_encode($cert, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return static::CODE_SUCCESS;
    }

    /**
     * Signiert ein Marketplace-Dokument (anchors.json/crl.json/metadata.json):
     * liest ein JSON-Payload und gibt die Hülle {payload, key_id, signature} aus.
     */
    private function signDoc(Arguments $args, ConsoleIo $io, Signer $signer): int
    {
        $path = (string)$args->getArgument('target');
        if ($path === '' || !is_file($path)) {
            $io->error('sign-doc benötigt eine existierende payload.json als Argument.');

            return static::CODE_ERROR;
        }
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            $io->error('payload.json ist kein gültiges JSON-Objekt.');

            return static::CODE_ERROR;
        }
        $doc = [
            'payload' => $payload,
            'key_id' => (string)$args->getOption('key-id'),
            'signature' => $signer->sign(MarketplaceClient::canonical($payload), (string)$args->getOption('secret')),
        ];
        $io->out((string)json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return static::CODE_SUCCESS;
    }
}
