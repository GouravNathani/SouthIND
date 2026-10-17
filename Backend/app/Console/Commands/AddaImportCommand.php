<?php

namespace App\Console\Commands;

use App\Support\AddaImport\AddaImporter;
use App\Support\AddaImport\MediaCopier;
use App\Support\Wallet\PayoutCutoff;
use App\Support\Wallet\WalletIdentity;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-time Adda → SouthIND move. Without --execute it only reports (dry run).
 *
 *   php84 artisan adda:import --adda-env=/home/u710497605/domains/wd.bettingadda9.com/public_html/.env
 *   php84 artisan adda:import --adda-env=... --execute --confirm=WIPE-SOUTHIND
 *
 * Media: the files under Adda's storage/app/public (next to its .env, or
 * --adda-storage) are copied onto SouthIND's public disk after the rows —
 * add-only, never overwriting, never deleting.
 *
 * Adda's credentials are read from Adda's own .env at run time, so they are
 * never copied into SouthIND's. The Adda session is READ ONLY.
 */
class AddaImportCommand extends Command
{
    public const CONFIRM = 'WIPE-SOUTHIND';

    protected $signature = 'adda:import
        {--adda-env= : Path to Adda\'s .env (DB credentials + APP_KEY are read from it)}
        {--adda-connection= : Use this configured connection as the source instead (tests)}
        {--execute : Actually wipe SouthIND and import (default: dry run)}
        {--confirm= : Must be ' . self::CONFIRM . ' together with --execute}
        {--no-restore : Do not merge the 2026-09-24 support-chat restore staging}
        {--adda-storage= : Adda storage/app/public (default: next to --adda-env)}
        {--no-media : Do not copy media files}';

    protected $description = 'Wipe SouthIND and import all of Adda (same ids). Dry run unless --execute.';

    public function handle(WalletIdentity $wallet): int
    {
        [$source, $encrypter] = $this->sourceConnection();
        if ($source === null) {
            return self::FAILURE;
        }

        $target = DB::connection();
        if ($this->sameDatabase($source, $target)) {
            $this->error('Source and target are the same database. Refusing.');

            return self::FAILURE;
        }

        $readOnly = in_array($source->getDriverName(), ['mysql', 'mariadb'], true);
        if ($readOnly) {
            // One consistent, read-only snapshot of Adda for the whole run.
            // Hostinger's MariaDB defaults to READ COMMITTED, which refuses it.
            $source->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }

        try {
            $importer = new AddaImporter($source, $target, $encrypter, fn (string $line) => $this->line("  {$line}"));
            $withRestore = !$this->option('no-restore');
            $plan = $importer->plan($withRestore);

            $this->info("Adda: {$source->getDatabaseName()}  →  SouthIND: {$target->getDatabaseName()}");
            $this->table(['Adda table', 'Action', 'Rows', 'Columns parked (no SouthIND column)'], $plan['tables']);
            $this->line('SouthIND rows that the wipe removes: ' . json_encode(array_filter($plan['wipe'])));
            foreach ($plan['warnings'] as $warning) {
                $this->warn("! {$warning}");
            }
            foreach ($plan['errors'] as $error) {
                $this->error("✗ {$error}");
            }

            [$copier, $mediaPlan, $mediaError] = $this->mediaPlan($source);
            if ($mediaError !== null) {
                $this->error("✗ {$mediaError}");
                $plan['errors'][] = $mediaError;
            }

            $guards = $this->executeGuards($wallet);
            if (!$this->option('execute')) {
                foreach ($guards as $guard) {
                    $this->warn("(before --execute) {$guard}");
                }
                $this->info($plan['errors'] === [] ? 'Dry run OK — nothing was written.' : 'Dry run found blocking problems — nothing was written.');

                return $plan['errors'] === [] ? self::SUCCESS : self::FAILURE;
            }

            if ($plan['errors'] !== [] || $guards !== []) {
                foreach ($guards as $guard) {
                    $this->error("✗ {$guard}");
                }
                $this->error('Not importing.');

                return self::FAILURE;
            }

            $started = microtime(true);
            $result = $importer->run($withRestore);
            $seconds = round(microtime(true) - $started, 1);
        } finally {
            if ($readOnly) {
                $source->statement('COMMIT');
            }
        }

        $this->table(['Check', 'Adda', 'SouthIND', 'OK'], array_map(
            fn (array $row) => [$row['table'], $row['adda'], $row['southind'], $row['ok'] ? 'yes' : 'NO'],
            $result['counts'],
        ));
        if ($result['orphans'] !== []) {
            $this->warn('Dangling Adda references set to NULL: ' . json_encode($result['orphans']));
        }
        foreach ($result['problems'] as $problem) {
            $this->error("✗ {$problem}");
        }

        $mediaOk = true;
        if ($copier !== null) {
            $copy = $copier->copy();
            $after = MediaCopier::missingReferences($target, $this->publicRoot(), AddaImporter::MEDIA);
            $missingBefore = array_sum($mediaPlan['adda_missing']['missing']);
            $missingAfter = array_sum($after['missing']);
            // Files Adda itself had already lost cannot appear; nothing else may go missing.
            $mediaOk = $copy['failed'] === 0 && $missingAfter <= $missingBefore;
            $this->table(['Media', 'Value'], [
                ['copied', $copy['copied'] . ' file(s), ' . $this->megabytes($copy['bytes'])],
                ['already on SouthIND', $copy['already_there']],
                ['name taken by a different SouthIND file (kept)', $copy['conflicts']],
                ['failed', $copy['failed']],
                ['references checked on SouthIND', $after['checked']],
                ['missing on SouthIND / already missing in Adda', "{$missingAfter} / {$missingBefore}"],
            ]);
            $mediaPlan['copy'] = $copy;
            $mediaPlan['southind_missing'] = $after;
            $result['media'] = $mediaPlan;
        }

        Artisan::call('cache:clear');

        $ok = $mediaOk && $result['problems'] === [] && !in_array(false, array_column($result['counts'], 'ok'), true);
        $this->writeReport($plan, $result, $seconds, $ok);
        $ok ? $this->info("Import complete in {$seconds}s.") : $this->error("Import finished in {$seconds}s WITH MISMATCHES — see above.");

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: ?ConnectionInterface, 1: ?Encrypter}
     */
    private function sourceConnection(): array
    {
        if ($name = $this->option('adda-connection')) {
            return [DB::connection($name), null];
        }

        $path = (string) $this->option('adda-env');
        if ($path === '' || !is_readable($path)) {
            $this->error('Give --adda-env=<path to Adda .env> (readable).');

            return [null, null];
        }

        $env = Dotenv::parse((string) file_get_contents($path));
        foreach (['DB_DATABASE', 'DB_USERNAME'] as $key) {
            if (!isset($env[$key]) || $env[$key] === '') {
                $this->error("{$key} missing in {$path}.");

                return [null, null];
            }
        }
        // The key must be present, but an empty value is a valid password (local root).
        if (!array_key_exists('DB_PASSWORD', $env)) {
            $this->error("DB_PASSWORD missing in {$path}.");

            return [null, null];
        }

        $base = config('database.connections.' . config('database.default'));
        config(['database.connections.adda_source' => array_merge($base, [
            'driver' => in_array($base['driver'] ?? '', ['mysql', 'mariadb'], true) ? $base['driver'] : 'mysql',
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? '3306',
            'database' => $env['DB_DATABASE'],
            'username' => $env['DB_USERNAME'],
            'password' => (string) $env['DB_PASSWORD'],
            'url' => null,
            'unix_socket' => '',
        ])]);

        $encrypter = null;
        $key = (string) ($env['APP_KEY'] ?? '');
        if ($key !== '') {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
            $encrypter = new Encrypter($raw, (string) config('app.cipher', 'AES-256-CBC'));
        }

        return [DB::connection('adda_source'), $encrypter];
    }

    /**
     * Read-only look at Adda's media: what would be copied, and how many stored
     * references already point at a file Adda no longer has.
     *
     * @return array{0: ?MediaCopier, 1: ?array<string, mixed>, 2: ?string}
     */
    private function mediaPlan(ConnectionInterface $source): array
    {
        if ($this->option('no-media')) {
            $this->warn('! Media files are NOT copied (--no-media).');

            return [null, null, null];
        }

        $root = (string) $this->option('adda-storage');
        if ($root === '' && ($env = (string) $this->option('adda-env')) !== '') {
            $root = dirname($env) . '/storage/app/public';
        }
        if ($root === '') {
            return [null, null, null];
        }
        $root = rtrim($root, '/');
        if (!is_dir($root)) {
            return [null, null, "Adda media folder {$root} not found (give --adda-storage, or --no-media)."];
        }
        if (realpath($root) === realpath($this->publicRoot())) {
            return [null, null, 'Adda media folder is SouthIND\'s own public disk. Refusing.'];
        }

        $copier = new MediaCopier($root, $this->publicRoot(), fn (string $line) => $this->line("  {$line}"));
        $scan = $copier->scan();
        $missing = MediaCopier::missingReferences($source, $root, AddaImporter::MEDIA + [
            'restore_support_messages' => ['image_path', 'audio_path'],
        ]);

        $rows = [];
        foreach ($scan['folders'] as $folder => $info) {
            $rows[] = [$folder, $info['files'], $this->megabytes($info['bytes'])];
        }
        $rows[] = ['TOTAL', $scan['files'], $this->megabytes($scan['bytes'])];
        $this->table(['Adda media folder', 'Files', 'Size'], $rows);
        $this->line("Media references in Adda rows: {$missing['checked']}; already missing in Adda: "
            . (array_sum($missing['missing']) ?: 0) . ($missing['missing'] !== [] ? ' ' . json_encode($missing['missing']) : ''));

        return [$copier, ['source' => $root, 'scan' => $scan, 'adda_missing' => $missing], null];
    }

    private function publicRoot(): string
    {
        return rtrim(Storage::disk('public')->path(''), '/');
    }

    private function megabytes(int $bytes): string
    {
        return round($bytes / 1048576, 1) . ' MB';
    }

    /** @return list<string> */
    private function executeGuards(WalletIdentity $wallet): array
    {
        $guards = [];
        if ($this->option('confirm') !== self::CONFIRM) {
            $guards[] = 'Pass --confirm=' . self::CONFIRM . ' to wipe SouthIND.';
        }
        if (PayoutCutoff::startsAt() === null) {
            $guards[] = 'PAYOUT_START_AT is not set — imported Adda deposits would be billed again to the SouthIND wallet.';
        }
        if ($wallet->configuredId() === null) {
            $guards[] = 'WALLET_ID is not set — the wallet would silently switch to Adda\'s super admin phone.';
        }

        return $guards;
    }

    private function sameDatabase(ConnectionInterface $a, ConnectionInterface $b): bool
    {
        return $a->getDatabaseName() === $b->getDatabaseName()
            && $a->getConfig('host') === $b->getConfig('host')
            && $a->getDriverName() === $b->getDriverName()
            && $a->getDatabaseName() !== ':memory:';
    }

    /**
     * Counts only (no row data) — the audit trail of the move.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     */
    private function writeReport(array $plan, array $result, float $seconds, bool $ok): void
    {
        $dir = storage_path('app/adda-import');
        @mkdir($dir, 0700, true);
        $file = $dir . '/report-' . now()->format('Ymd-His') . '.json';
        file_put_contents($file, json_encode([
            'finished_at' => now()->toIso8601String(),
            'ok' => $ok,
            'seconds' => $seconds,
            'payout_start_at' => config('payout.start_at'),
            'plan' => ['tables' => $plan['tables'], 'warnings' => $plan['warnings'], 'wiped' => $plan['wipe']],
            'result' => $result,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("Report: {$file}");
    }
}
