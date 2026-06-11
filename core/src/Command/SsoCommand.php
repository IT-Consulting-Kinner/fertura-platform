<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Auth\Sso\SsoService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Console management of the SSO providers (Tier-1 programme, P06).
 *
 *   bin/cake sso list
 *   bin/cake sso add-oidc --name X --issuer https://idp/ --client-id … --client-secret … [--scopes "openid email profile"] [--label "Login mit X"]
 *   bin/cake sso add-saml --name X --idp-entity-id … --idp-sso-url … --idp-cert-file /path/cert.pem [--label …]
 *   bin/cake sso on|off|rm <id>
 */
class SsoCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('SSO-Provider (OIDC/SAML) verwalten (P06).');
        $parser->addArgument('action', ['help' => 'list|add-oidc|add-saml|on|off|rm', 'required' => true]);
        $parser->addArgument('id', ['help' => 'Provider-ID (on/off/rm)', 'required' => false]);
        $parser->addOption('name', ['help' => 'Anzeigename']);
        $parser->addOption('label', ['help' => 'Button-Beschriftung', 'default' => 'Single Sign-On']);
        $parser->addOption('issuer', ['help' => 'OIDC Issuer-URL']);
        $parser->addOption('client-id', ['help' => 'OIDC Client-ID']);
        $parser->addOption('client-secret', ['help' => 'OIDC Client-Secret']);
        $parser->addOption('scopes', ['help' => 'OIDC Scopes', 'default' => 'openid email profile']);
        $parser->addOption('idp-entity-id', ['help' => 'SAML IdP EntityID']);
        $parser->addOption('idp-sso-url', ['help' => 'SAML IdP SSO-URL']);
        $parser->addOption('idp-cert-file', ['help' => 'SAML IdP X.509-Zertifikat (Datei)']);
        $parser->addOption('sp-cert-file', ['help' => 'SAML SP-Zertifikat (Datei) — optional, für signierte AuthnRequests']);
        $parser->addOption('sp-key-file', ['help' => 'SAML SP-Privatschlüssel (Datei) — optional, wird verschlüsselt']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $svc = new SsoService();
        $action = (string)$args->getArgument('action');
        $id = (string)$args->getArgument('id');

        switch ($action) {
            case 'list':
                foreach ($svc->listProviders() as $p) {
                    $io->out(sprintf(
                        '%s  [%s]  %-5s  %s  "%s"',
                        $p['id'],
                        $p['active'] ? 'aktiv' : 'inaktiv',
                        $p['type'],
                        $p['name'],
                        $p['button_label'],
                    ));
                }

                return self::CODE_SUCCESS;

            case 'add-oidc':
                $name = (string)$args->getOption('name');
                $issuer = (string)$args->getOption('issuer');
                $clientId = (string)$args->getOption('client-id');
                if ($name === '' || $issuer === '' || $clientId === '') {
                    $io->error('--name, --issuer, --client-id erforderlich.');

                    return self::CODE_ERROR;
                }
                $res = $svc->createProvider('oidc', $name, [
                    'issuer' => $issuer,
                    'client_id' => $clientId,
                    'scopes' => (string)$args->getOption('scopes'),
                ], (string)$args->getOption('client-secret'), (string)$args->getOption('label'));
                $io->success('OIDC-Provider angelegt: ' . $res['id']);

                return self::CODE_SUCCESS;

            case 'add-saml':
                $name = (string)$args->getOption('name');
                $entityId = (string)$args->getOption('idp-entity-id');
                $ssoUrl = (string)$args->getOption('idp-sso-url');
                $certFile = (string)$args->getOption('idp-cert-file');
                if ($name === '' || $entityId === '' || $ssoUrl === '' || $certFile === '') {
                    $io->error('--name, --idp-entity-id, --idp-sso-url, --idp-cert-file erforderlich.');

                    return self::CODE_ERROR;
                }
                if (!is_file($certFile)) {
                    $io->error("Zertifikatsdatei nicht gefunden: $certFile");

                    return self::CODE_ERROR;
                }
                // Optional SP signing: certificate goes into the config, private key
                // as an (encrypted) secret.
                $spCertFile = (string)$args->getOption('sp-cert-file');
                $spKeyFile = (string)$args->getOption('sp-key-file');
                $config = [
                    'idp_entity_id' => $entityId,
                    'idp_sso_url' => $ssoUrl,
                    'idp_x509cert' => trim((string)file_get_contents($certFile)),
                ];
                $spKey = null;
                if ($spCertFile !== '' && $spKeyFile !== '') {
                    if (!is_file($spCertFile) || !is_file($spKeyFile)) {
                        $io->error('SP-Zertifikat/-Schlüssel-Datei nicht gefunden.');

                        return self::CODE_ERROR;
                    }
                    $config['sp_cert'] = trim((string)file_get_contents($spCertFile));
                    $spKey = trim((string)file_get_contents($spKeyFile));
                }
                $res = $svc->createProvider('saml', $name, $config, $spKey, (string)$args->getOption('label'));
                $io->success('SAML-Provider angelegt' . ($spKey !== null ? ' (signierte AuthnRequests)' : '') . ': ' . $res['id']);

                return self::CODE_SUCCESS;

            case 'on':
            case 'off':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->setActive($id, $action === 'on');
                $io->success('OK.');

                return self::CODE_SUCCESS;

            case 'rm':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->deleteProvider($id);
                $io->success('Gelöscht.');

                return self::CODE_SUCCESS;

            default:
                $io->error("Unbekannte Aktion: $action");

                return self::CODE_ERROR;
        }
    }
}
