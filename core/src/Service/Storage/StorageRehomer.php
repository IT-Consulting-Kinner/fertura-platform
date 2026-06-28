<?php
declare(strict_types=1);

namespace App\Service\Storage;

/**
 * One-time, operator-run re-homing of a module's LEGACY files into the per-tenant
 * convention `tenant/<id>/<module-key>/…` (Inc 8).
 *
 * A module that previously wrote via a bare {@see StorageManager} (outside the
 * convention) cannot migrate its own legacy files: the Inc 10 capability gate forbids
 * raw file access in module `src/`, and {@see ModuleStorage} only reaches the
 * convention subtree. So the Core performs the privileged byte move here; the MODULE
 * supplies the mapping (from its own DB) and updates its own path columns from the
 * returned results. The Core never touches the module's database — pure storage.
 *
 * The byte move runs outside any {@see ModuleStorageScope}, so the StorageManager
 * convention guard does not apply (this IS the migration into the convention). The
 * target path is built EXPLICITLY per entry via {@see TenantStorage::prefixForModule()},
 * so no ambient tenant/RLS context is needed.
 *
 * Safe by construction: verify-before-delete (the source is removed only after the
 * target is written and its size matches), idempotent (a re-run of an already-moved
 * entry is a no-op), and dry-run capable.
 */
class StorageRehomer
{
    /** A status that means the entry needs operator attention (non-success). */
    private const ERROR_STATUSES = ['error', 'missing_source', 'conflict', 'verify_failed'];

    public function __construct(private StorageManager $storage = new StorageManager())
    {
    }

    /** True for a result status that indicates a failed/attention-needed entry. */
    public static function isError(string $status): bool
    {
        return in_array($status, self::ERROR_STATUSES, true);
    }

    /**
     * Re-homes every plan entry. Each entry: `tenant_id` (path segment), `source`
     * (current storage-root-relative path the module wrote), `target` (the relpath
     * UNDER `tenant/<id>/<module-key>/` the file should live at).
     *
     * @param list<array<string,mixed>> $plan
     * @param array{dryRun?:bool, deleteSource?:bool, overwrite?:bool} $opts
     * @return list<array{tenant_id:string, source:string, target:string, status:string, bytes:int, error:string}>
     */
    public function rehome(string $moduleKey, array $plan, array $opts = []): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $moduleKey) !== 1) {
            throw new StorageException("Ungueltiger Modul-Key fuer Re-Homing: '$moduleKey'.");
        }
        $dry = (bool)($opts['dryRun'] ?? false);
        $del = (bool)($opts['deleteSource'] ?? false);
        $overwrite = (bool)($opts['overwrite'] ?? false);

        $results = [];
        foreach ($plan as $i => $entry) {
            $results[] = $this->rehomeOne($moduleKey, (array)$entry, $dry, $del, $overwrite, (int)$i);
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{tenant_id:string, source:string, target:string, status:string, bytes:int, error:string}
     */
    private function rehomeOne(string $moduleKey, array $entry, bool $dry, bool $del, bool $overwrite, int $i): array
    {
        $tenantId = trim((string)($entry['tenant_id'] ?? ''));
        $source = (string)($entry['source'] ?? '');
        $target = ltrim((string)($entry['target'] ?? ''), '/');

        // A tenant_id becomes a path segment: allow only hex/dashes (UUID-shaped), no
        // slashes/dots, so a malformed plan cannot escape the tenant subtree.
        if (preg_match('/^[0-9a-fA-F-]{8,64}$/', $tenantId) !== 1) {
            return $this->result('', $source, '', 'error', 0, "Eintrag #$i: ungueltige tenant_id.");
        }
        if ($source === '' || $target === '' || str_contains($target, '..')) {
            return $this->result($tenantId, $source, '', 'error', 0, "Eintrag #$i: ungueltige source/target.");
        }
        $targetPath = TenantStorage::prefixForModule($tenantId, $moduleKey) . $target;

        if ($source === $targetPath) {
            return $this->result($tenantId, $source, $targetPath, 'noop', 0, '');
        }

        try {
            $srcExists = $this->storage->exists($source);
            $tgtExists = $this->storage->exists($targetPath);

            // Target already present -> idempotent path (unless an overwrite is forced).
            if ($tgtExists && !$overwrite) {
                $tgtSize = $this->storage->fileSize($targetPath);
                if (!$srcExists) {
                    // A prior run already moved it (source gone, target present).
                    return $this->result($tenantId, $source, $targetPath, 'already', $tgtSize, '');
                }
                $srcSize = $this->storage->fileSize($source);
                if ($srcSize !== $tgtSize) {
                    return $this->result(
                        $tenantId, $source, $targetPath, 'conflict', $tgtSize,
                        "Eintrag #$i: Ziel existiert mit abweichender Groesse ($tgtSize != $srcSize) — --overwrite noetig.",
                    );
                }
                if ($del && !$dry) {
                    $this->storage->delete($source);
                }

                return $this->result($tenantId, $source, $targetPath, $dry ? 'would_already' : 'already', $tgtSize, '');
            }

            if (!$srcExists) {
                return $this->result(
                    $tenantId, $source, $targetPath, 'missing_source', 0,
                    "Eintrag #$i: Quelle '$source' fehlt und kein Ziel vorhanden.",
                );
            }
            $srcSize = $this->storage->fileSize($source);
            if ($dry) {
                return $this->result($tenantId, $source, $targetPath, 'would_move', $srcSize, '');
            }

            // Stream the bytes (memory-safe for large attachments).
            $in = $this->storage->readStream($source);
            $this->storage->writeStream($targetPath, $in);
            if (is_resource($in)) {
                fclose($in);
            }
            // Verify BEFORE removing the source.
            $tgtSize = $this->storage->fileSize($targetPath);
            if ($tgtSize !== $srcSize) {
                return $this->result(
                    $tenantId, $source, $targetPath, 'verify_failed', $tgtSize,
                    "Eintrag #$i: Verifikation fehlgeschlagen (Ziel $tgtSize != Quelle $srcSize); Quelle bleibt.",
                );
            }
            if ($del) {
                $this->storage->delete($source);
            }

            return $this->result($tenantId, $source, $targetPath, 'moved', $srcSize, '');
        } catch (StorageException $e) {
            return $this->result($tenantId, $source, $targetPath, 'error', 0, "Eintrag #$i: " . $e->getMessage());
        }
    }

    /**
     * @return array{tenant_id:string, source:string, target:string, status:string, bytes:int, error:string}
     */
    private function result(string $tenantId, string $source, string $target, string $status, int $bytes, string $error): array
    {
        return [
            'tenant_id' => $tenantId,
            'source' => $source,
            'target' => $target,
            'status' => $status,
            'bytes' => $bytes,
            'error' => $error,
        ];
    }
}
