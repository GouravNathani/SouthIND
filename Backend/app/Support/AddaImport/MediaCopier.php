<?php

namespace App\Support\AddaImport;

use App\Support\PublicStorage;
use Illuminate\Database\ConnectionInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Adda's uploaded files (receipts, QR images, banners, chat images/voice) for
 * adda:import. Adda stores them as "/storage/<folder>/<file>"; SouthIND reads
 * the same values through PublicStorage, so the rows need no rewrite — the
 * files just have to be on SouthIND's public disk.
 *
 * Only adds: a file SouthIND already has is never overwritten, nothing is
 * deleted, and the original modification time is kept so SouthIND's 7-day
 * receipt cleanup treats every copy exactly as Adda's would have.
 */
final class MediaCopier
{
    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(
        private readonly string $source,
        private readonly string $target,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
    }

    /**
     * Files and bytes per top-level folder of the source.
     *
     * @return array{folders: array<string, array{files: int, bytes: int}>, files: int, bytes: int}
     */
    public function scan(): array
    {
        $folders = [];
        $files = 0;
        $bytes = 0;
        foreach ($this->files() as $relative => $info) {
            $folder = str_contains($relative, '/') ? strstr($relative, '/', true) : '.';
            $folders[$folder] ??= ['files' => 0, 'bytes' => 0];
            $folders[$folder]['files']++;
            $folders[$folder]['bytes'] += $info->getSize();
            $files++;
            $bytes += $info->getSize();
        }
        ksort($folders);

        return ['folders' => $folders, 'files' => $files, 'bytes' => $bytes];
    }

    /**
     * @return array{copied: int, already_there: int, conflicts: int, failed: int, bytes: int}
     */
    public function copy(): array
    {
        $result = ['copied' => 0, 'already_there' => 0, 'conflicts' => 0, 'failed' => 0, 'bytes' => 0];

        foreach ($this->files() as $relative => $info) {
            $to = $this->target . '/' . $relative;
            if (is_file($to)) {
                // Same name and size = already copied (re-run); a different
                // file with the same name is SouthIND's own and stays.
                filesize($to) === $info->getSize() ? $result['already_there']++ : $result['conflicts']++;
                continue;
            }

            $dir = dirname($to);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $result['failed']++;
                continue;
            }
            if (!@copy($info->getPathname(), $to)) {
                $result['failed']++;
                continue;
            }
            @touch($to, $info->getMTime());
            $result['copied']++;
            $result['bytes'] += $info->getSize();
        }

        ($this->log)("media copied: {$result['copied']} file(s), already there {$result['already_there']}, conflicts {$result['conflicts']}, failed {$result['failed']}");

        return $result;
    }

    /**
     * Stored media values whose file is not under $root.
     *
     * @param  array<string, list<string>>  $media  table => columns
     * @return array{checked: int, missing: array<string, int>}
     */
    public static function missingReferences(ConnectionInterface $db, string $root, array $media): array
    {
        $schema = $db->getSchemaBuilder();
        $checked = 0;
        $missing = [];

        foreach ($media as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    continue;
                }
                $query = $db->table($table)->select(['id', $column])->whereNotNull($column)->where($column, '!=', '');
                foreach ($query->lazyById(1000) as $row) {
                    $value = (string) ((array) $row)[$column];
                    // Links to other sites are not ours to copy.
                    if (PublicStorage::isAbsolute($value) && !str_contains((string) parse_url($value, PHP_URL_PATH), '/storage/')) {
                        continue;
                    }
                    $relative = PublicStorage::relative($value);
                    if ($relative === '') {
                        continue;
                    }
                    $checked++;
                    if (!is_file($root . '/' . $relative)) {
                        $key = "{$table}.{$column}";
                        $missing[$key] = ($missing[$key] ?? 0) + 1;
                    }
                }
            }
        }

        return ['checked' => $checked, 'missing' => $missing];
    }

    /**
     * Regular files under the source, keyed by path relative to it. Symlinks
     * and dot-files are left out.
     *
     * @return \Generator<string, \SplFileInfo>
     */
    private function files(): \Generator
    {
        if (!is_dir($this->source)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->source, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $prefix = strlen(rtrim($this->source, '/')) + 1;

        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (!$info->isFile() || $info->isLink() || str_starts_with($info->getFilename(), '.')) {
                continue;
            }
            yield substr($info->getPathname(), $prefix) => $info;
        }
    }
}
