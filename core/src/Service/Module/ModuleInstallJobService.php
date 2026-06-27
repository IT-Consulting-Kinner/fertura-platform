<?php
declare(strict_types=1);

namespace App\Service\Module;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Queue + status store for async GUI module installation (core.module_install_jobs).
 *
 * The web request {@see enqueue()}s an already-stored upload and returns; the
 * worker ({@see ModuleInstallRunner}) {@see claimNext()}s, installs, and marks the
 * job {@see markSucceeded()}/{@see markFailed()}; the GUI polls {@see get()}.
 */
class ModuleInstallJobService
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /** Enqueues an install job for an already-stored package; returns the job id. */
    public function enqueue(string $packagePath, ?string $originalFilename, ?string $actorId): string
    {
        $row = $this->conn()->execute(
            'INSERT INTO core.module_install_jobs (package_path, original_filename, actor_user_id) '
            . 'VALUES (:p, :f, :a) RETURNING id',
            ['p' => $packagePath, 'f' => $originalFilename, 'a' => $actorId],
        )->fetch('assoc');

        return (string)$row['id'];
    }

    /**
     * Atomically claims the oldest queued job (-> running). `FOR UPDATE SKIP LOCKED`
     * so several workers never double-claim. Returns the claimed row or null.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $row = $this->conn()->execute(
            "UPDATE core.module_install_jobs SET status = 'running', started_at = now() "
            . "WHERE id = (SELECT id FROM core.module_install_jobs WHERE status = 'queued' "
            . 'ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED) RETURNING *',
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    public function markSucceeded(string $id, string $moduleKey): void
    {
        $this->conn()->execute(
            "UPDATE core.module_install_jobs SET status = 'succeeded', module_key = :k, finished_at = now() WHERE id = :id",
            ['k' => $moduleKey, 'id' => $id],
        );
    }

    public function markFailed(string $id, string $message): void
    {
        $this->conn()->execute(
            "UPDATE core.module_install_jobs SET status = 'failed', message = :m, finished_at = now() WHERE id = :id",
            ['m' => mb_substr($message, 0, 2000), 'id' => $id],
        );
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $row = $this->conn()->execute(
            'SELECT * FROM core.module_install_jobs WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * The currently active (queued or running) job, if any — drives the index
     * status banner + polling.
     *
     * @return array<string,mixed>|null
     */
    public function active(): ?array
    {
        $row = $this->conn()->execute(
            "SELECT * FROM core.module_install_jobs WHERE status IN ('queued','running') ORDER BY created_at DESC LIMIT 1",
        )->fetch('assoc');

        return $row === false ? null : $row;
    }
}
