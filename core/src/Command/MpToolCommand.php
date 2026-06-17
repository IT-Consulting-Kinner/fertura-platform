<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\License\LicenseService;
use App\Service\Marketplace\MarketplaceClient;
use App\Service\Sdk\PackageBuilder;
use App\Service\Security\PackageVerifier;
use App\Service\Security\Signer;
use App\Service\Security\TrustChain;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Operator/marketplace tooling for the key, signature and licensing workflow
 * (ch. 24.9.2 / 28.7). Acts as the signing counterpart to the Core.
 *
 * Key hierarchy (Root -> Publisher):
 *   - Root key: the top-level trust anchor (keep offline / in an HSM).
 *     Signs publisher certificates as well as marketplace documents (anchors/crl).
 *   - Publisher key: operational; signs packages and licenses. Its
 *     certificate is signed by the Root (chain, verified by the Core).
 *
 *   bin/cake mp_tool keygen
 *   bin/cake mp_tool sign-key      --secret <rootSecret> --key-id <rootId> \
 *                                  --pub-key <publisherPub> --pub-id <pubId> --publisher <Name>
 *   bin/cake mp_tool package       <modulverzeichnis> [--out <dir>] [--previous <x.y.z>]
 *   bin/cake mp_tool sign          <paketverzeichnis> --secret <pubSecret> --key-id <pubId>
 *   bin/cake mp_tool license       <module> --secret <pubSecret> --key-id <pubId> --valid-to <ISO> [--grace N] [--online]
 *   bin/cake mp_tool sign-doc      <payload.json> --secret <rootSecret> --key-id <rootId>
 *
 * See SIGNING.md for the complete operational procedure.
 */
class MpToolCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', [
                'choices' => ['keygen', 'sign-key', 'package', 'sign', 'license', 'sign-doc'],
                'required' => true,
            ])
            ->addArgument('target', [
                'help' => 'Modulverzeichnis (package) / Paketverzeichnis (sign) / module_ref (license) / payload.json (sign-doc)',
            ])
            ->addOption('out', ['help' => 'package: Ausgabeverzeichnis (Default: dist)'])
            ->addOption('previous', ['help' => 'package: Vorversion für die Monotonie-Prüfung (x.y.z)'])
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

            case 'package':
                return $this->package($args, $io);

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
     * Release-assembly pre-step (24.13.2): lints, enforces a monotonic version +
     * reversible migrations + a changelog, and emits the signable package dir.
     */
    private function package(Arguments $args, ConsoleIo $io): int
    {
        $dir = (string)$args->getArgument('target');
        if ($dir === '') {
            $io->error('package benötigt ein Modulverzeichnis als Argument.');

            return static::CODE_ERROR;
        }
        $out = $args->getOption('out') !== null ? (string)$args->getOption('out') : 'dist';
        $previous = $args->getOption('previous') !== null ? (string)$args->getOption('previous') : null;

        $res = (new PackageBuilder())->build($dir, $out, $previous);
        if (!$res['ok']) {
            foreach ($res['errors'] as $e) {
                $io->error($e);
            }

            return static::CODE_ERROR;
        }

        /** @var array{path:string, release:array{version:string, migrations:list<array<string,mixed>>}} $res */
        $io->success('Paket erstellt: ' . $res['path']);
        $io->out(sprintf(
            '  Version %s, %d Migration(en) (alle reversibel), Changelog vorhanden.',
            $res['release']['version'],
            count($res['release']['migrations']),
        ));
        $io->out('  Signieren: bin/cake mp_tool sign ' . $res['path'] . ' --secret <pubSecret> --key-id <pubId>');

        return static::CODE_SUCCESS;
    }

    /**
     * The Root signs a publisher certificate (chain Root -> Publisher).
     * Output: an anchor document including the Root signature over (key_id, public_key, publisher).
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
     * Signs a marketplace document (anchors.json/crl.json/metadata.json):
     * reads a JSON payload and emits the envelope {payload, key_id, signature}.
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
