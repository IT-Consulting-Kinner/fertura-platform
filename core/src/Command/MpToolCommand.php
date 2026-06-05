<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\License\LicenseService;
use App\Service\Security\PackageVerifier;
use App\Service\Security\Signer;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Marketplace-/Betreiber-Werkzeug (Schlüssel erzeugen, Pakete signieren,
 * Lizenzen ausstellen). Agiert als signierende Gegenstelle (Marketplace-/
 * Lizenzserver) — hier für Tests/Betreiber-Selbstsignatur.
 *
 *   bin/cake mp_tool keygen
 *   bin/cake mp_tool sign <paketverzeichnis> --secret <b64> --key-id <id>
 *   bin/cake mp_tool license <module> --secret <b64> --key-id <id> --valid-to <ISO> [--grace N] [--online]
 */
class MpToolCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['keygen', 'sign', 'license'], 'required' => true])
            ->addArgument('target', ['help' => 'Paketverzeichnis (sign) oder module_ref (license)'])
            ->addOption('secret', ['help' => 'Secret-Key (base64)'])
            ->addOption('key-id', ['help' => 'Schlüssel-ID'])
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
        }

        return static::CODE_ERROR;
    }
}
