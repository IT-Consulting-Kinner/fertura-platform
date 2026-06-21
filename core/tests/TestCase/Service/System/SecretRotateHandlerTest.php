<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\Settings\SecretCipher;
use App\Service\System\SecretRotateHandler;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The secret-rotate handler (Phase 6 Increment 3). Only the safe paths are covered:
 * verify() round-trips a secret with the active key, and rollback() is a no-op when
 * nothing was rotated. execute()'s rotation path is exercised through
 * {@see \App\Test\TestCase\Service\Settings\SecretRotationServiceTest} — driving it
 * here would rotate the real secrets if an old key happened to be present.
 */
class SecretRotateHandlerTest extends TestCase
{
    private const NS = 'zztest_sec';

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM settings WHERE namespace = :ns', ['ns' => self::NS]);
        parent::tearDown();
    }

    public function testVerifyOkForActiveKeySecret(): void
    {
        // Seed a secret encrypted with the ACTIVE key so verify finds a decryptable one.
        ConnectionManager::get('default')->execute(
            'INSERT INTO settings (namespace, config_key, is_secret, value_encrypted) VALUES (:ns, :k, true, :v)',
            ['ns' => self::NS, 'k' => 'canary', 'v' => (new SecretCipher())->encrypt('v')],
        );

        $this->assertTrue((new SecretRotateHandler())->verify([])['ok']);
    }

    public function testRollbackIsNoOpWhenNotRotated(): void
    {
        // A fresh handler (execute never ran) has nothing to undo -> must not throw.
        (new SecretRotateHandler())->rollback([]);
        $this->assertTrue(true);
    }
}
