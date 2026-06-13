<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

use App\Service\Module\ModuleWebInterface;
use App\Service\Storage\StorageManager;

/**
 * Fixture web page handler that returns a file download (E161): in-memory bytes
 * by default, or a report streamed from object storage for `?mode=stream`.
 */
final class DownloadPage implements ModuleWebInterface
{
    public function handle(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        if (($query['mode'] ?? '') === 'stream') {
            (new StorageManager())->write('reports/zztest_dl.txt', 'streamed-content-xyz');

            return [
                'download' => [
                    'filename' => 'report.txt',
                    'content_type' => 'text/plain',
                    'storage_path' => 'reports/zztest_dl.txt',
                ],
            ];
        }

        return [
            'download' => [
                'filename' => 'inline.csv',
                'content_type' => 'text/csv',
                'content' => "a,b\n1,2\n",
            ],
        ];
    }
}
