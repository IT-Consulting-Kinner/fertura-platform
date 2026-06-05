<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Marketplace\MarketplaceClient;
use App\Service\Security\Signer;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Marketplace-Anbindung.
 *
 *   bin/cake marketplace sync
 *   bin/cake marketplace build-fixtures <outdir> --secret <b64> --key-id <id> [--revoke k1,k2]
 *     (Betreiber-/Testhilfe: signierte CRL/Anker/Metadaten erzeugen.)
 */
class MarketplaceCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['sync', 'build-fixtures'], 'required' => true])
            ->addArgument('outdir', ['help' => 'Ausgabeverzeichnis (build-fixtures)'])
            ->addOption('secret', ['help' => 'Secret-Key (base64)'])
            ->addOption('key-id', ['help' => 'Schlüssel-ID'])
            ->addOption('revoke', ['help' => 'Kommagetrennte zu widerrufende Schlüssel']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        if ($args->getArgument('operation') === 'sync') {
            $r = (new MarketplaceClient())->sync();
            $io->success("Sync: {$r['revoked']} widerrufen, {$r['anchors']} Anker.");

            return static::CODE_SUCCESS;
        }

        // build-fixtures
        $dir = rtrim((string)$args->getArgument('outdir'), '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $signer = new Signer();
        $secret = (string)$args->getOption('secret');
        $keyId = (string)$args->getOption('key-id');

        $revoked = [];
        if ($args->getOption('revoke')) {
            foreach (explode(',', (string)$args->getOption('revoke')) as $k) {
                $k = trim($k);
                if ($k !== '') {
                    $revoked[] = ['key_id' => $k, 'reason' => 'compromised'];
                }
            }
        }

        $this->writeSigned($dir . '/crl.json', ['revoked' => $revoked], $signer, $secret, $keyId);
        $this->writeSigned($dir . '/anchors.json', ['anchors' => []], $signer, $secret, $keyId);
        $this->writeSigned(
            $dir . '/metadata.json',
            ['core' => ['latest' => '1.0.1'], 'modules' => []],
            $signer,
            $secret,
            $keyId,
        );

        $io->success("Fixtures signiert in $dir (revoked=" . count($revoked) . ').');

        return static::CODE_SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function writeSigned(string $file, array $payload, Signer $signer, string $secret, string $keyId): void
    {
        $doc = [
            'payload' => $payload,
            'key_id' => $keyId,
            'signature' => $signer->sign(MarketplaceClient::canonical($payload), $secret),
        ];
        file_put_contents($file, (string)json_encode($doc, JSON_PRETTY_PRINT) . "\n");
    }
}
