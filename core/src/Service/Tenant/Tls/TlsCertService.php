<?php
declare(strict_types=1);

namespace App\Service\Tenant\Tls;

use App\Service\Settings\SecretCipher;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Operator-managed TLS certificates for tenant custom domains (E158).
 *
 * Responsibility split (multi-tenant: tenants are NOT the operator):
 * - **Tenant** owns having a valid certificate and renewing/uploading it in time.
 * - **Operator** owns deploying it to the edge (separate slice) — tenants have no
 *   edge access.
 * - **This service** is the bounded middle: it validates an uploaded bundle
 *   (host coverage, validity window, key match, chain), stores it with the
 *   private key **AES-256-GCM-encrypted** ({@see SecretCipher}), and exposes
 *   metadata for the deploy and expiry-warning slices. It never terminates TLS
 *   and never auto-renews — so it structurally cannot own handshake correctness.
 */
class TlsCertService
{
    private SecretCipher $cipher;

    public function __construct(?SecretCipher $cipher = null)
    {
        $this->cipher = $cipher ?? new SecretCipher();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /**
     * Validates a certificate bundle against the host without storing anything.
     * Purely advisory: it tells the uploader whether the bundle is sane before it
     * ever reaches the edge.
     *
     * `$nowTs` overrides the reference time for the validity-window check
     * (deterministic in tests; defaults to the wall clock).
     *
     * @return array{ok: bool, errors: list<string>, meta: array{cn: ?string,
     *     sans: list<string>, notBefore: ?string, notAfter: ?string, fingerprint: ?string}}
     */
    public function validateBundle(
        string $host,
        string $certPem,
        ?string $chainPem,
        string $keyPem,
        ?int $nowTs = null,
    ): array {
        $now = $nowTs ?? time();
        $host = strtolower(trim($host));
        $meta = ['cn' => null, 'sans' => [], 'notBefore' => null, 'notAfter' => null, 'fingerprint' => null];

        $x509 = @openssl_x509_read($certPem);
        if ($x509 === false) {
            return ['ok' => false, 'errors' => ['Zertifikat ist kein gültiges PEM/X.509.'], 'meta' => $meta];
        }
        $parsed = openssl_x509_parse($x509);
        if (!is_array($parsed)) {
            return ['ok' => false, 'errors' => ['Zertifikat konnte nicht gelesen werden.'], 'meta' => $meta];
        }

        $errors = [];
        $cn = isset($parsed['subject']['CN']) ? strtolower((string)$parsed['subject']['CN']) : null;
        $sans = $this->extractSans($parsed);
        $notBefore = isset($parsed['validFrom_time_t']) ? (int)$parsed['validFrom_time_t'] : null;
        $notAfter = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : null;
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256') ?: null;

        $meta['cn'] = $cn;
        $meta['sans'] = $sans;
        $meta['notBefore'] = $notBefore !== null ? gmdate('c', $notBefore) : null;
        $meta['notAfter'] = $notAfter !== null ? gmdate('c', $notAfter) : null;
        $meta['fingerprint'] = $fingerprint !== null ? (string)$fingerprint : null;

        // Host coverage: prefer SAN (CN is deprecated for hostname matching, kept
        // as a fallback). Wildcards (*.example.com) cover one label.
        $names = $sans;
        if ($cn !== null) {
            $names[] = $cn;
        }
        if (!$this->hostCovered($host, $names)) {
            $errors[] = "Zertifikat deckt die Domain '$host' nicht ab (CN/SAN).";
        }
        if ($notAfter === null) {
            $errors[] = 'Zertifikat ohne Ablaufdatum (kein notAfter).';
        } elseif ($notAfter <= $now) {
            $errors[] = 'Zertifikat ist bereits abgelaufen.';
        }
        if ($notBefore !== null && $notBefore > $now) {
            $errors[] = 'Zertifikat ist noch nicht gültig (notBefore in der Zukunft).';
        }

        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            $errors[] = 'Privater Schlüssel ist kein gültiges PEM.';
        } elseif (@openssl_x509_check_private_key($x509, $key) !== true) {
            $errors[] = 'Privater Schlüssel passt nicht zum Zertifikat.';
        }

        if ($chainPem !== null && trim($chainPem) !== '') {
            foreach ($this->splitPem($chainPem) as $i => $block) {
                if (@openssl_x509_read($block) === false) {
                    $errors[] = 'Kettenelement #' . ($i + 1) . ' ist kein gültiges Zertifikat.';
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'meta' => $meta];
    }

    /**
     * Validates and stores a bundle as a new `pending_deploy` certificate for the
     * domain. Any prior `pending_deploy` cert of the same domain is superseded.
     * The private key is encrypted at rest. Returns the new certificate id.
     *
     * @throws \App\Service\Tenant\Tls\TlsCertException when the bundle is invalid
     */
    public function store(
        string $domainId,
        string $host,
        string $certPem,
        ?string $chainPem,
        string $keyPem,
        ?string $uploadedBy = null,
    ): string {
        $v = $this->validateBundle($host, $certPem, $chainPem, $keyPem);
        if (!$v['ok']) {
            throw new TlsCertException('Zertifikat abgelehnt: ' . implode(' ', $v['errors']));
        }
        $keyCipher = $this->cipher->encrypt($keyPem);
        $conn = $this->conn();

        return (string)$conn->transactional(
            function () use ($conn, $domainId, $certPem, $chainPem, $keyCipher, $v, $uploadedBy): string {
                $conn->execute(
                    "UPDATE tenant_domain_certs SET status = 'superseded' "
                    . "WHERE domain_id = :d AND status = 'pending_deploy'",
                    ['d' => $domainId],
                );
                $row = $conn->execute(
                    'INSERT INTO tenant_domain_certs '
                    . '(domain_id, cert_pem, chain_pem, key_cipher, subject_cn, sans, not_before, not_after, '
                    . 'fingerprint_sha256, uploaded_by) '
                    . 'VALUES (:d, :cert, :chain, :key, :cn, CAST(:sans AS jsonb), :nb, :na, :fp, :ub) RETURNING id',
                    [
                        'd' => $domainId,
                        'cert' => $certPem,
                        'chain' => $chainPem,
                        'key' => $keyCipher,
                        'cn' => $v['meta']['cn'],
                        'sans' => json_encode($v['meta']['sans']),
                        'nb' => $v['meta']['notBefore'],
                        'na' => $v['meta']['notAfter'],
                        'fp' => $v['meta']['fingerprint'],
                        'ub' => $uploadedBy,
                    ],
                )->fetch('assoc');

                return (string)$row['id'];
            },
        );
    }

    /**
     * Certificate rows of a domain (newest first), without the private key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForDomain(string $domainId): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, subject_cn, sans, not_before, not_after, fingerprint_sha256, status, '
            . 'uploaded_by, uploaded_at, deployed_at '
            . 'FROM tenant_domain_certs WHERE domain_id = :d ORDER BY uploaded_at DESC',
            ['d' => $domainId],
        )->fetchAll('assoc');

        return $rows;
    }

    /** Decrypts and returns the private key PEM of a stored certificate (deploy slice). */
    public function privateKeyPem(string $certId): string
    {
        $row = $this->conn()->execute(
            'SELECT key_cipher FROM tenant_domain_certs WHERE id = :id',
            ['id' => $certId],
        )->fetch('assoc');
        if ($row === false) {
            throw new TlsCertException("Unbekanntes Zertifikat: $certId");
        }

        return $this->cipher->decrypt((string)$row['key_cipher']);
    }

    /**
     * Wildcard-aware host match against a list of certificate names.
     *
     * @param list<string> $names
     */
    private function hostCovered(string $host, array $names): bool
    {
        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if ($name === $host) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1); // ".example.com"
                $hostFirstDot = strpos($host, '.');
                // Wildcard covers exactly one label: foo.example.com, not a.b.example.com.
                if ($hostFirstDot !== false && substr($host, $hostFirstDot) === $suffix) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extracts DNS subjectAltName entries (lowercased) from a parsed certificate.
     *
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    private function extractSans(array $parsed): array
    {
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (!is_string($san) || $san === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $san) as $part) {
            $part = trim($part);
            if (stripos($part, 'DNS:') === 0) {
                $name = strtolower(substr($part, 4));
                if (!in_array($name, $out, true)) {
                    $out[] = $name;
                }
            }
        }

        return $out;
    }

    /**
     * Splits a PEM bundle into individual certificate blocks.
     *
     * @return list<string>
     */
    private function splitPem(string $pem): array
    {
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m)) {
            return [];
        }

        return $m[0];
    }
}
