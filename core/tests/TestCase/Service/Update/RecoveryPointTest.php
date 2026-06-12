<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Update;

use App\Service\Update\RecoveryPoint;
use Cake\TestSuite\TestCase;

/**
 * Recovery points (ch. 28.14.2): reside on the persistent backup volume
 * (survive a recreate), not in the ephemeral tmp/.
 */
class RecoveryPointTest extends TestCase
{
    public function testDirIsOnPersistentBackupVolume(): void
    {
        $dir = (new RecoveryPoint())->dir();
        $this->assertStringContainsString('backups' . DIRECTORY_SEPARATOR . 'recovery', $dir);
        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR,
            $dir,
            'Wiederherstellungspunkte dürfen nicht mehr im flüchtigen tmp/ liegen.',
        );
        $this->assertDirectoryExists($dir);
    }
}
