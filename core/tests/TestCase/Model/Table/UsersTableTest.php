<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\User;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Tests for UsersTable::buildRules() — case-insensitive GLOBAL uniqueness of
 * email and username, mirroring the DB indexes uq_users_email_lower /
 * uq_users_username_lower. The rules turn a DB unique-violation (HTTP 500) into
 * a clean validation error and, crucially, are global (not per tenant) so the
 * pre-auth login/reset resolution (lower(email)/lower(username), no tenant
 * filter) stays unambiguous.
 */
class UsersTableTest extends TestCase
{
    use LocatorAwareTrait;

    /** Test data is uniquely identifiable via this email domain. */
    private const DOMAIN = '@zzutbl.local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM users WHERE email LIKE '%" . self::DOMAIN . "'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzutbl_%'");
    }

    /** Seeds an existing user directly (bypassing the ORM rules under test). */
    private function seedUser(string $username, string $email, ?string $tenantId = null): string
    {
        $conn = ConnectionManager::get('default');
        if ($tenantId === null) {
            return (string)$conn->execute(
                "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
                ['u' => $username, 'e' => $email],
            )->fetch('assoc')['id'];
        }

        return (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => $username, 'e' => $email, 't' => $tenantId],
        )->fetch('assoc')['id'];
    }

    private function newUser(string $username, string $email): User
    {
        $users = $this->fetchTable('Users');
        /** @var \App\Model\Entity\User $user */
        $user = $users->newEntity(['username' => $username, 'email' => $email]);
        $user->set('status', User::STATUS_INVITED);

        return $user;
    }

    private function rand(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(4));
    }

    public function testUniqueUserSaves(): void
    {
        $users = $this->fetchTable('Users');
        $user = $this->newUser($this->rand('uniq'), $this->rand('uniq') . self::DOMAIN);

        $this->assertNotFalse($users->save($user), 'A unique user must save.');
        $this->assertEmpty($user->getErrors());
    }

    public function testDuplicateEmailRejectedCaseInsensitively(): void
    {
        $email = $this->rand('dupe') . self::DOMAIN;
        $this->seedUser($this->rand('seed'), $email);

        $users = $this->fetchTable('Users');
        // Same email, different case -> must collide with the lower(email) index.
        $user = $this->newUser($this->rand('clash'), strtoupper($email));

        $this->assertFalse($users->save($user), 'A case-insensitive duplicate email must be rejected.');
        $this->assertArrayHasKey('uniqueEmail', $user->getError('email'));
        // No second row was written.
        $this->assertSame(
            1,
            $this->countEmail($email),
            'The duplicate must not have been inserted.',
        );
    }

    public function testDuplicateEmailRejectedAcrossTenants(): void
    {
        // The crux of the GLOBAL decision: an email taken in ANOTHER tenant still
        // blocks creation, because the login resolves users globally by email.
        $conn = ConnectionManager::get('default');
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzutbl_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $email = $this->rand('cross') . self::DOMAIN;
        $this->seedUser($this->rand('foreign'), $email, $otherTenant);

        $users = $this->fetchTable('Users');
        $user = $this->newUser($this->rand('here'), $email); // would land in the default tenant
        $this->assertFalse($users->save($user), 'A duplicate email in another tenant must still be rejected (global).');
        $this->assertArrayHasKey('uniqueEmail', $user->getError('email'));
    }

    public function testDuplicateUsernameRejectedCaseInsensitively(): void
    {
        $username = $this->rand('dupeu');
        $this->seedUser($username, $this->rand('seed') . self::DOMAIN);

        $users = $this->fetchTable('Users');
        $user = $this->newUser(strtoupper($username), $this->rand('clash') . self::DOMAIN);

        $this->assertFalse($users->save($user), 'A case-insensitive duplicate username must be rejected.');
        $this->assertArrayHasKey('uniqueUsername', $user->getError('username'));
    }

    public function testUpdatingOwnRecordIsNotSelfFlagged(): void
    {
        $email = $this->rand('own') . self::DOMAIN;
        $id = $this->seedUser($this->rand('own'), $email);
        $users = $this->fetchTable('Users');

        // (a) Editing other fields with the email unchanged must not flag the row
        // against itself.
        $user = $users->get($id);
        $user->set('first_name', 'Changed');
        $this->assertNotFalse($users->save($user), 'Editing own record (email unchanged) must save.');

        // (b) Changing to a brand-new unique email must save.
        $user->set('email', $this->rand('moved') . self::DOMAIN);
        $this->assertNotFalse($users->save($user), 'Changing to a free email must save.');

        // (c) Changing to ANOTHER user's email (any case) must be rejected.
        $takenEmail = $this->rand('taken') . self::DOMAIN;
        $this->seedUser($this->rand('other'), $takenEmail);
        $user->set('email', strtoupper($takenEmail));
        $this->assertFalse($users->save($user), 'Taking another user\'s email must be rejected.');
        $this->assertArrayHasKey('uniqueEmail', $user->getError('email'));
    }

    public function testSalutationLengthIsValidated(): void
    {
        // F22: salutation is mass-assignable + self-service editable and the column
        // is unbounded PG text — a validation cap prevents storing an oversized value.
        // maxLength is a validationDefault rule, applied at marshal time (newEntity).
        $users = $this->fetchTable('Users');
        $bad = $users->newEntity([
            'username' => $this->rand('sal'),
            'email' => $this->rand('sal') . self::DOMAIN,
            'salutation' => str_repeat('x', 101),
        ]);
        $this->assertNotEmpty($bad->getError('salutation'), 'an over-long salutation must fail validation');

        // A normal salutation passes validation and saves.
        $ok = $users->newEntity([
            'username' => $this->rand('sal2'),
            'email' => $this->rand('sal2') . self::DOMAIN,
            'salutation' => 'Frau Dr.',
        ]);
        $ok->set('status', User::STATUS_INVITED);
        $this->assertEmpty($ok->getError('salutation'));
        $this->assertNotFalse($users->save($ok), 'a normal salutation saves');
        $this->assertSame('Frau Dr.', $ok->get('salutation'));
    }

    private function countEmail(string $email): int
    {
        return (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM users WHERE lower(email) = lower(:e)',
            ['e' => $email],
        )->fetch('assoc')['c'];
    }
}
