<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Identity;

use App\Service\Identity\IdentityReader;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Tests the minimal, read-only user/group surface modules use (capability #5b):
 * ids + display name + group ids/names — and explicitly NO email/PII (Decision
 * D4 = minimal).
 */
class IdentityReaderTest extends TestCase
{
    private string $userId = '';
    private string $groupId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $conn = ConnectionManager::get('default');
        $this->userId = (string)$conn->execute(
            'INSERT INTO users (username, email, first_name, last_name, status) '
            . "VALUES (:u, :e, 'Erika', 'Mustermann', 'active') RETURNING id",
            ['u' => 'zztest_idr_' . bin2hex(random_bytes(3)), 'e' => 'idr_' . bin2hex(random_bytes(3)) . '@zztest.local'],
        )->fetch('assoc')['id'];
        $this->groupId = (string)$conn->execute(
            'INSERT INTO "groups" (name) VALUES (:n) RETURNING id',
            ['n' => 'zztest_idr_group_' . bin2hex(random_bytes(3))],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u)',
            ['g' => $this->groupId, 'u' => $this->userId],
        );
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM groups_users WHERE user_id = :u', ['u' => $this->userId]);
        $conn->execute('DELETE FROM "groups" WHERE id = :g', ['g' => $this->groupId]);
        $conn->execute('DELETE FROM users WHERE id = :u', ['u' => $this->userId]);
        parent::tearDown();
    }

    public function testUsersReturnsMinimalShapeWithoutPii(): void
    {
        $users = (new IdentityReader())->users();
        $mine = array_values(array_filter($users, fn(array $u): bool => $u['id'] === $this->userId));

        $this->assertCount(1, $mine);
        $this->assertSame('Erika Mustermann', $mine[0]['display_name']); // first + last name
        // D4: ONLY id + display_name — no email/status/PII leaks through.
        $this->assertSame(['id', 'display_name'], array_keys($mine[0]));
    }

    public function testResolveUserAndInvalidUuid(): void
    {
        $reader = new IdentityReader();
        $this->assertSame('Erika Mustermann', $reader->resolveUser($this->userId)['display_name']);
        $this->assertNull($reader->resolveUser('not-a-uuid'));
    }

    public function testGroupsAndUserGroups(): void
    {
        $reader = new IdentityReader();

        $groupIds = array_map(fn(array $g): string => $g['id'], $reader->groups());
        $this->assertContains($this->groupId, $groupIds);

        $userGroups = $reader->userGroups($this->userId);
        $this->assertCount(1, $userGroups);
        $this->assertSame($this->groupId, $userGroups[0]['id']);
        $this->assertSame(['id', 'name'], array_keys($userGroups[0]));
        $this->assertSame([], $reader->userGroups('not-a-uuid'));
    }
}
