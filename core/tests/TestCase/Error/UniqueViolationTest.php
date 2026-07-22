<?php
declare(strict_types=1);

namespace App\Test\TestCase\Error;

use App\Error\UniqueViolation;
use Cake\Database\Exception\QueryException;
use Cake\I18n\I18n;
use Cake\TestSuite\TestCase;
use PDOException;
use RuntimeException;

/**
 * Unit test for the shared 23505 classifier + message mapper used by both global
 * safety nets (web middleware + CLI runner).
 */
class UniqueViolationTest extends TestCase
{
    private string $locale = 'en_US';

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = I18n::getLocale();
        I18n::setLocale('de_DE');
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->locale);
        parent::tearDown();
    }

    private function pdo23505(string $constraint = 'uq_groups_tenant_name_lower'): PDOException
    {
        $msg = 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "'
            . $constraint . '"';
        $e = new PDOException($msg);
        $e->errorInfo = ['23505', 7, $msg];

        return $e;
    }

    public function testConstraintNameFromWrappedQueryException(): void
    {
        // The real shape: Cake QueryException wrapping the PDOException.
        $e = new QueryException('INSERT INTO "groups" ...', $this->pdo23505());
        $this->assertSame('uq_groups_tenant_name_lower', UniqueViolation::constraintOf($e));
        $this->assertTrue(UniqueViolation::isUniqueViolation($e));
    }

    public function testConstraintNameFromBarePdoException(): void
    {
        $e = $this->pdo23505('uq_tenants_key');
        $this->assertSame('uq_tenants_key', UniqueViolation::constraintOf($e));
    }

    public function testNonUniqueErrorIsNotAViolation(): void
    {
        $this->assertNull(UniqueViolation::constraintOf(new RuntimeException('boom')));
        $this->assertFalse(UniqueViolation::isUniqueViolation(new RuntimeException('boom')));
        // A different SQLSTATE (e.g. not-null violation) must NOT be caught.
        $other = new PDOException('SQLSTATE[23502]: Not null violation: ...');
        $this->assertNull(UniqueViolation::constraintOf($other));
    }

    public function testUnnamedViolationReturnsEmptyString(): void
    {
        // 23505 whose message carries no parseable constraint name.
        $e = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value');
        $this->assertSame('', UniqueViolation::constraintOf($e));
        $this->assertTrue(UniqueViolation::isUniqueViolation($e));
    }

    public function testMappedConstraintGetsSpecificMessage(): void
    {
        $this->assertSame('Eine Gruppe mit diesem Namen existiert bereits.', UniqueViolation::messageFor('uq_groups_tenant_name_lower'));
        $this->assertSame('Dieser Mandanten-Schlüssel ist bereits vergeben.', UniqueViolation::messageFor('uq_tenants_key'));
    }

    public function testUnknownOrEmptyConstraintGetsGenericMessage(): void
    {
        $generic = 'Dieser Wert existiert bereits. Bitte einen anderen wählen.';
        $this->assertSame($generic, UniqueViolation::messageFor('uq_some_module_thing'));
        $this->assertSame($generic, UniqueViolation::messageFor(''));
        $this->assertSame($generic, UniqueViolation::messageFor(null));
    }
}
