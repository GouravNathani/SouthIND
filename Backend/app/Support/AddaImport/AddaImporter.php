<?php

namespace App\Support\AddaImport;

use App\Support\PublicStorage;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * One-time move of Adda's whole database into SouthIND (adda:import).
 *
 * SouthIND is wiped and refilled from Adda with the SAME primary keys, so every
 * link (user → deposits, admin → branch, conversation → messages) survives
 * untouched. Adda is only ever READ.
 *
 * Every Adda table is in exactly one bucket — copied live, archived as JSON in
 * legacy_adda_rows, merged from the 2026-09-24 restore staging, or skipped (the
 * framework/token tables) — and plan() refuses to go on when Adda has a table
 * this class does not know, so a hand-made table can never be lost silently.
 *
 * Rows are written with the query builder, never Eloquent: model events would
 * push an admin notification per imported deposit and the hashed cast would
 * re-check every bcrypt password.
 */
class AddaImporter
{
    /**
     * Adda tables copied into the SouthIND table of the same name, in an order
     * that satisfies every foreign key. Cycles are broken by DEFERRED columns.
     */
    public const COPY = [
        'branches',
        'admins',
        'global_settings',
        'app_settings',
        'users',
        'tags',
        'tag_user',
        'accounts',
        'account_status_events',
        'bonus_codes',
        'bonus_code_tag',
        'deposits',
        'withdrawals',
        'bonus_code_redemptions',
        'banners',
        'support_conversations',
        'support_messages',
        'whatsapp_accounts',
        'whatsapp_templates',
        'whatsapp_conversations',
        'whatsapp_messages',
    ];

    /**
     * Adda features SouthIND does not have (streaks, login audit, flat referral
     * rewards, the account ledger) and Adda's own billing ledger, which belongs
     * to Adda's Control wallet and must never be billed again from here.
     */
    public const ARCHIVE = [
        'admin_login_events',
        'account_adjustments',
        'streak_settings',
        'streak_prizes',
        'user_streaks',
        'streak_day_credits',
        'streak_prize_claims',
        'referrals',
        'referral_invites',
        'referral_settings',
        'wallet_states',
        'wallet_charges',
        'wallet_daily_payouts',
        'wallet_monthly_payouts',
        'restore_user_streaks',
    ];

    /**
     * Staging copies of the support chats deleted on 2026-09-24, merged in.
     */
    public const RESTORE = [
        'restore_support_conversations' => 'support_conversations',
        'restore_support_messages' => 'support_messages',
    ];

    /**
     * Never carried: framework state, and tokens/subscriptions bound to Adda's
     * APP_KEY, VAPID key or origin. Everyone logs in again on SouthIND.
     */
    public const SKIP = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
        'refresh_tokens',
        'push_subscriptions',
        'user_push_subscriptions',
    ];

    /**
     * SouthIND tables that survive the wipe. SouthIND's own billing ledger stays
     * so its sind-day/sind-month references are never re-billed as new.
     */
    public const KEEP = [
        'migrations',
        'wallet_states',
        'wallet_charges',
        'wallet_daily_payouts',
        'wallet_monthly_payouts',
    ];

    /** Source column => target column. */
    public const RENAME = [
        'users' => ['referred_by_user_id' => 'referred_by'],
    ];

    /** Filled after every table is in (circular or self references). */
    public const DEFERRED = [
        'branches' => ['created_by'],
        'admins' => ['parent_id'],
        'users' => ['referred_by'],
    ];

    /** Stored media paths; absolute Adda URLs are reduced to "storage/<file>". */
    public const MEDIA = [
        'deposits' => ['receipt_image_path'],
        'accounts' => ['logo_path'],
        'banners' => ['image_path'],
        'support_messages' => ['image_path', 'audio_path'],
        'app_settings' => ['logo_path'],
    ];

    /** Adda columns that are secrets bound to Adda's APP_KEY: never copied or archived. */
    public const SECRET = [
        'admins' => ['two_factor_secret', 'two_factor_recovery_codes'],
    ];

    /** Dropped-column values equal to these are defaults, not data, and are not archived. */
    public const DROPPED_DEFAULTS = [
        'admins.withdraw_account_mode' => 'off',
    ];

    /** Adda status => SouthIND status. SouthIND calls "in progress" on_process, but its queues only list pending. */
    public const STATUS_MAP = ['processing' => 'pending'];

    public const STATUSES = [
        'deposits' => ['pending', 'on_process', 'approved', 'rejected', 'failed'],
        'withdrawals' => ['pending', 'on_process', 'approved', 'rejected', 'failed'],
    ];

    private const CHUNK = 500;

    /** @var list<string>|null */
    private ?array $sourceTables = null;

    /** @var array<string, list<string>> */
    private array $sourceColumns = [];

    /** @var array<string, array<string, array<string, mixed>>> target table => column => info */
    private array $targetColumns = [];

    /** @var array<string, array<int|string, true>> */
    private array $idSets = [];

    /** @var array<string, array<string, string>> table => target column => referenced table (nullable FKs only) */
    private array $nullableForeign = [];

    /** @var array<string, int> "table.column" => rows whose dangling reference was set to NULL */
    private array $nulledOrphans = [];

    /** @var array<int, int> restored-conversation id => the user's live conversation id */
    private array $conversationRemap = [];

    private int $mediaRewritten = 0;

    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(
        private readonly ConnectionInterface $source,
        private readonly ConnectionInterface $target,
        private readonly ?Encrypter $sourceEncrypter = null,
        ?\Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
    }

    // ── Plan (read-only) ─────────────────────────────────────────────────────

    /**
     * Everything the import would do, plus every reason it must not. Reads both
     * databases, writes nothing.
     *
     * @return array{errors: list<string>, warnings: list<string>, tables: list<array<string, mixed>>, wipe: array<string, int>}
     */
    public function plan(bool $withRestore = true): array
    {
        $errors = [];
        $warnings = [];
        $rows = [];

        $sourceTables = $this->tableNames($this->source);
        $targetTables = $this->tableNames($this->target);

        $known = array_merge(self::COPY, self::ARCHIVE, array_keys(self::RESTORE), self::SKIP);
        foreach (array_diff($sourceTables, $known) as $unknown) {
            $errors[] = "Adda table `{$unknown}` is not mapped (copy/archive/skip). Add it to AddaImporter before importing.";
        }

        foreach (['legacy_adda_rows', 'sso_tickets'] as $required) {
            if (!in_array($required, $targetTables, true)) {
                $errors[] = "SouthIND table `{$required}` is missing — run the 2026_10_08 migration first.";
            }
        }

        foreach (self::COPY as $table) {
            if (!in_array($table, $sourceTables, true)) {
                $warnings[] = "Adda has no `{$table}` table — nothing to copy.";
                continue;
            }
            if (!in_array($table, $targetTables, true)) {
                $errors[] = "SouthIND has no `{$table}` table.";
                continue;
            }

            $count = $this->source->table($table)->count();
            $dropped = $this->droppedColumns($table);
            $rows[] = [
                'table' => $table,
                'action' => 'copy',
                'rows' => $count,
                'dropped' => implode(', ', $dropped),
            ];

            array_push($errors, ...$this->notNullProblems($table, $table));
            array_push($errors, ...$this->uniqueProblems($table));
            [$orphanErrors, $orphanWarnings] = $this->orphanProblems($table, $table);
            array_push($errors, ...$orphanErrors);
            array_push($warnings, ...$orphanWarnings);
        }

        foreach (self::STATUSES as $table => $allowed) {
            if (!in_array($table, $sourceTables, true)) {
                continue;
            }
            $statuses = $this->source->table($table)->distinct()->pluck('status')->all();
            foreach ($statuses as $status) {
                $mapped = self::STATUS_MAP[$status] ?? $status;
                if (!in_array($mapped, $allowed, true)) {
                    $errors[] = "`{$table}` has status '{$status}' that SouthIND does not know.";
                } elseif ($mapped !== $status) {
                    $n = $this->source->table($table)->where('status', $status)->count();
                    $warnings[] = "`{$table}`: {$n} row(s) with status '{$status}' become '{$mapped}'.";
                }
            }
        }

        if (in_array('whatsapp_accounts', $sourceTables, true)
            && $this->source->table('whatsapp_accounts')->whereNotNull('access_token')->exists()
            && $this->sourceEncrypter === null) {
            $errors[] = 'Adda has WhatsApp access tokens but no Adda APP_KEY was given to re-encrypt them.';
        }

        if (in_array('global_settings', $sourceTables, true)
            && $this->source->table('global_settings')->where('user_panel_maintenance_enabled', true)->exists()) {
            $warnings[] = 'Adda has the user-panel maintenance switch ON — SouthIND will start with it ON too.';
        }

        if (in_array('banners', $sourceTables, true) && in_array('is_flash', $this->sourceColumns('banners'), true)) {
            $flash = $this->source->table('banners')->where('is_flash', true)->count();
            if ($flash > 0) {
                $warnings[] = "{$flash} flash banner(s) imported INACTIVE (SouthIND has no flash popup; the flag is archived).";
            }
        }

        array_push($warnings, ...$this->addaOnlyNotes());
        array_push($warnings, ...$this->southIndLossNotes());

        foreach (self::ARCHIVE as $table) {
            if (in_array($table, $sourceTables, true)) {
                $rows[] = ['table' => $table, 'action' => 'archive', 'rows' => $this->source->table($table)->count(), 'dropped' => ''];
            }
        }

        if ($withRestore) {
            foreach (self::RESTORE as $staging => $live) {
                if (!in_array($staging, $sourceTables, true)) {
                    continue;
                }
                $missing = $this->source->table($staging)
                    ->whereNotIn('id', $this->source->table($live)->select('id'))
                    ->count();
                $rows[] = ['table' => "{$staging} → {$live}", 'action' => 'restore', 'rows' => $missing, 'dropped' => ''];
            }
        }

        foreach (self::SKIP as $table) {
            if (in_array($table, $sourceTables, true)) {
                $rows[] = ['table' => $table, 'action' => 'skip', 'rows' => $this->source->table($table)->count(), 'dropped' => ''];
            }
        }

        $wipe = [];
        foreach ($this->wipeTables() as $table) {
            $wipe[$table] = $this->target->table($table)->count();
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'tables' => $rows, 'wipe' => $wipe];
    }

    // ── Run (wipes SouthIND) ─────────────────────────────────────────────────

    /**
     * Wipe SouthIND and refill it from Adda. Re-runnable: every run starts from
     * the wipe, so a failed run is fixed by running again.
     *
     * @return array{counts: list<array<string, mixed>>, problems: list<string>, orphans: array<string, int>, media_rewritten: int, kept_super_admins: int}
     */
    public function run(bool $withRestore = true): array
    {
        $plan = $this->plan($withRestore);
        if ($plan['errors'] !== []) {
            throw new RuntimeException("Import blocked:\n- " . implode("\n- ", $plan['errors']));
        }

        $keptSupers = $this->target->table('admins')->where('role', 'super_admin')->get()
            ->map(fn ($row) => (array) $row)->all();

        $this->wipe();

        foreach (self::COPY as $table) {
            if ($this->sourceHas($table)) {
                $this->copyTable($table, $table);
            }
        }

        if ($withRestore) {
            $this->mergeRestore();
        }

        $this->fillDeferred();

        foreach (self::ARCHIVE as $table) {
            if ($this->sourceHas($table)) {
                $this->archiveTable($table);
            }
        }

        $readded = $this->readdSuperAdmins($keptSupers);

        return [
            'counts' => $this->verifyCounts($withRestore, $readded),
            'problems' => $this->verifyForeignKeys(),
            'orphans' => $this->nulledOrphans,
            'media_rewritten' => $this->mediaRewritten,
            'kept_super_admins' => $readded,
        ];
    }

    // ── Steps ────────────────────────────────────────────────────────────────

    /**
     * Children before parents (tables that are not copied first, then the copy
     * order reversed), so the wipe also holds where FK checks cannot be paused.
     *
     * @return list<string>
     */
    private function wipeTables(): array
    {
        $tables = array_diff($this->tableNames($this->target), self::KEEP);
        $copied = array_values(array_intersect(array_reverse(self::COPY), $tables));

        return array_values(array_merge(array_diff($tables, $copied), $copied));
    }

    private function wipe(): void
    {
        $schema = $this->target->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        try {
            foreach ($this->wipeTables() as $table) {
                $this->target->table($table)->truncate();
            }
        } finally {
            $schema->enableForeignKeyConstraints();
        }
        ($this->log)('SouthIND wiped (' . count($this->wipeTables()) . ' tables; kept: ' . implode(', ', self::KEEP) . ').');
    }

    private function copyTable(string $sourceTable, string $targetTable, ?\Closure $filter = null): int
    {
        $columns = $this->sourceColumns($sourceTable);
        $hasId = in_array('id', $columns, true);
        $copied = 0;
        $batch = [];
        $archive = [];

        $flush = function () use (&$batch, &$archive, $targetTable): void {
            if ($batch !== []) {
                $this->target->table($targetTable)->insert($batch);
                $batch = [];
            }
            if ($archive !== []) {
                $this->target->table('legacy_adda_rows')->insert($archive);
                $archive = [];
            }
        };

        $handle = function ($row) use (&$batch, &$archive, &$copied, $sourceTable, $targetTable, $filter, $flush): void {
            $row = (array) $row;
            if ($filter !== null) {
                $row = $filter($row);
                if ($row === null) {
                    return;
                }
            }
            [$mapped, $dropped] = $this->transformRow($sourceTable, $targetTable, $row);
            $batch[] = $mapped;
            if ($dropped !== []) {
                $archive[] = $this->legacyRow("{$targetTable}.dropped", $row, $dropped);
            }
            $copied++;
            if (count($batch) >= self::CHUNK) {
                $flush();
            }
        };

        $query = $this->source->table($sourceTable);
        if ($hasId) {
            foreach ($query->lazyById(self::CHUNK) as $row) {
                $handle($row);
            }
        } else {
            foreach ($query->orderBy($columns[0])->cursor() as $row) {
                $handle($row);
            }
        }
        $flush();

        ($this->log)("copied {$sourceTable} → {$targetTable}: {$copied}");

        return $copied;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformRow(string $sourceTable, string $targetTable, array $row): array
    {
        $targetColumns = $this->targetColumns($targetTable);
        $rename = self::RENAME[$targetTable] ?? [];
        $secrets = self::SECRET[$targetTable] ?? [];
        $mapped = [];
        $dropped = [];

        foreach ($row as $column => $value) {
            $to = $rename[$column] ?? $column;
            if (isset($targetColumns[$to])) {
                $mapped[$to] = $value;
            } elseif (!in_array($column, $secrets, true) && !$this->isDefaultValue("{$targetTable}.{$column}", $value)) {
                $dropped[$column] = $value;
            }
        }

        // Dangling references (Adda has few FKs, SouthIND has many) become NULL.
        foreach ($this->nullableForeignKeys($targetTable) as $column => $referenced) {
            $value = $mapped[$column] ?? null;
            if ($value !== null && !isset($this->idSet($referenced)[$value])) {
                $mapped[$column] = null;
                $key = "{$targetTable}.{$column}";
                $this->nulledOrphans[$key] = ($this->nulledOrphans[$key] ?? 0) + 1;
            }
        }

        // Circular/self references are filled in after every table is in.
        foreach (self::DEFERRED[$targetTable] ?? [] as $column) {
            if (array_key_exists($column, $mapped)) {
                $mapped[$column] = null;
            }
        }

        if (isset(self::STATUSES[$targetTable], $mapped['status'])) {
            $mapped['status'] = self::STATUS_MAP[$mapped['status']] ?? $mapped['status'];
        }

        foreach (self::MEDIA[$targetTable] ?? [] as $column) {
            if (isset($mapped[$column]) && is_string($mapped[$column]) && PublicStorage::isAbsolute($mapped[$column])) {
                $relative = PublicStorage::relative($mapped[$column]);
                if ($relative !== '') {
                    $mapped[$column] = 'storage/' . $relative;
                    $this->mediaRewritten++;
                }
            }
        }

        switch ($targetTable) {
            case 'users':
                // SouthIND: a referral code means "agent", and any code can be
                // applied at signup. Adda gave every user one, so they are parked.
                if (!empty($mapped['referral_code'])) {
                    $dropped['referral_code'] = $mapped['referral_code'];
                }
                $mapped['referral_code'] = null;
                break;

            case 'banners':
                if (!empty($row['is_flash'])) {
                    $mapped['is_active'] = 0;
                }
                break;

            case 'whatsapp_accounts':
                if (!empty($mapped['access_token'])) {
                    $plain = $this->sourceEncrypter?->decryptString($mapped['access_token']);
                    $mapped['access_token'] = $plain === null ? null : Crypt::encryptString($plain);
                }
                break;
        }

        return [$mapped, $dropped];
    }

    /**
     * Restore staging rows the live Adda tables do not have. A user who started
     * a new chat after the purge keeps it; the restored messages join it.
     */
    private function mergeRestore(): void
    {
        if ($this->sourceHas('restore_support_conversations')) {
            $liveByUser = $this->target->table('support_conversations')->pluck('id', 'user_id')->all();
            $users = $this->idSet('users');

            $restored = $this->copyTable('restore_support_conversations', 'support_conversations', function (array $row) use ($liveByUser, $users) {
                if (isset($this->idSet('support_conversations')[$row['id']])) {
                    return null;
                }
                if (!isset($users[$row['user_id']])) {
                    return null;
                }
                if (isset($liveByUser[$row['user_id']])) {
                    $this->conversationRemap[(int) $row['id']] = (int) $liveByUser[$row['user_id']];

                    return null;
                }

                return $row;
            });
            ($this->log)("restored conversations: {$restored}, merged into a newer chat: " . count($this->conversationRemap));
        }

        if ($this->sourceHas('restore_support_messages')) {
            $conversations = array_flip($this->target->table('support_conversations')->pluck('id')->all());
            $liveMessages = $this->idSet('support_messages');

            $this->copyTable('restore_support_messages', 'support_messages', function (array $row) use ($conversations, $liveMessages) {
                if (isset($liveMessages[$row['id']])) {
                    return null;
                }
                $conversation = $this->conversationRemap[(int) $row['conversation_id']] ?? (int) $row['conversation_id'];
                if (!isset($conversations[$conversation])) {
                    return null;
                }
                $row['conversation_id'] = $conversation;

                return $row;
            });
        }
    }

    private function fillDeferred(): void
    {
        foreach (self::DEFERRED as $table => $columns) {
            if (!$this->sourceHas($table)) {
                continue;
            }
            $rename = array_flip(self::RENAME[$table] ?? []);
            foreach ($columns as $column) {
                $sourceColumn = $rename[$column] ?? $column;
                if (!in_array($sourceColumn, $this->sourceColumns($table), true) || !isset($this->targetColumns($table)[$column])) {
                    continue;
                }
                $referenced = $this->nullableForeignKeys($table)[$column] ?? $table;
                $valid = $this->idSet($referenced);
                $updated = 0;
                foreach ($this->source->table($table)->whereNotNull($sourceColumn)->lazyById(self::CHUNK) as $row) {
                    $value = ((array) $row)[$sourceColumn];
                    if (!isset($valid[$value]) || ($referenced === $table && (string) $value === (string) $row->id)) {
                        continue;
                    }
                    $this->target->table($table)->where('id', $row->id)->update([$column => $value]);
                    $updated++;
                }
                ($this->log)("linked {$table}.{$column}: {$updated}");
            }
        }
    }

    private function archiveTable(string $table): int
    {
        $columns = $this->sourceColumns($table);
        $batch = [];
        $count = 0;
        $query = $this->source->table($table);
        $rows = in_array('id', $columns, true) ? $query->lazyById(self::CHUNK) : $query->orderBy($columns[0])->cursor();

        foreach ($rows as $row) {
            $row = (array) $row;
            foreach (self::SECRET[$table] ?? [] as $secret) {
                unset($row[$secret]);
            }
            $batch[] = $this->legacyRow($table, $row, $row);
            $count++;
            if (count($batch) >= self::CHUNK) {
                $this->target->table('legacy_adda_rows')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->target->table('legacy_adda_rows')->insert($batch);
        }
        ($this->log)("archived {$table}: {$count}");

        return $count;
    }

    /**
     * SouthIND's own super admins come back after the wipe (new ids, after
     * Adda's) unless Adda already has an admin with that phone.
     *
     * @param  list<array<string, mixed>>  $supers
     */
    private function readdSuperAdmins(array $supers): int
    {
        $added = 0;
        foreach ($supers as $super) {
            if ($this->target->table('admins')->where('phone', $super['phone'])->exists()) {
                continue;
            }
            if (!empty($super['email']) && $this->target->table('admins')->where('email', $super['email'])->exists()) {
                $super['email'] = null;
            }
            if (!empty($super['unique_number']) && $this->target->table('admins')->where('unique_number', $super['unique_number'])->exists()) {
                $super['unique_number'] = null;
            }
            unset($super['id']);
            $super['branch_id'] = null;
            $super['parent_id'] = null;
            $this->target->table('admins')->insert($super);
            $added++;
        }
        if ($added > 0) {
            ($this->log)("re-added SouthIND super admin(s): {$added}");
        }

        return $added;
    }

    // ── Verification ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function verifyCounts(bool $withRestore, int $readded): array
    {
        $result = [];
        foreach (self::COPY as $table) {
            if (!$this->sourceHas($table)) {
                continue;
            }
            $expected = $this->source->table($table)->count();
            if ($table === 'admins') {
                $expected += $readded;
            }
            if ($withRestore && $table === 'support_conversations' && $this->sourceHas('restore_support_conversations')) {
                $expected = null;
            }
            if ($withRestore && $table === 'support_messages' && $this->sourceHas('restore_support_messages')) {
                $expected = null;
            }
            $actual = $this->target->table($table)->count();
            $result[] = [
                'table' => $table,
                'adda' => $this->source->table($table)->count(),
                'southind' => $actual,
                'ok' => $expected === null ? $actual >= $this->source->table($table)->count() : $actual === $expected,
            ];
        }

        foreach (self::ARCHIVE as $table) {
            if (!$this->sourceHas($table)) {
                continue;
            }
            $adda = $this->source->table($table)->count();
            $actual = $this->target->table('legacy_adda_rows')->where('source_table', $table)->count();
            $result[] = ['table' => "{$table} (archive)", 'adda' => $adda, 'southind' => $actual, 'ok' => $adda === $actual];
        }

        foreach (array_keys(self::STATUSES) as $table) {
            if (!$this->sourceHas($table)) {
                continue;
            }
            $adda = round((float) $this->source->table($table)->sum('amount'), 2);
            $southind = round((float) $this->target->table($table)->sum('amount'), 2);
            $result[] = ['table' => "{$table} sum(amount)", 'adda' => $adda, 'southind' => $southind, 'ok' => abs($adda - $southind) < 0.01];

            $approvedAdda = round((float) $this->source->table($table)->where('status', 'approved')->sum('amount'), 2);
            $approvedSind = round((float) $this->target->table($table)->where('status', 'approved')->sum('amount'), 2);
            $result[] = ['table' => "{$table} approved sum", 'adda' => $approvedAdda, 'southind' => $approvedSind, 'ok' => abs($approvedAdda - $approvedSind) < 0.01];
        }

        return $result;
    }

    /**
     * Rows whose foreign key points nowhere. Must be empty: FK checks were on
     * for every insert, so anything here means a check was bypassed.
     *
     * @return list<string>
     */
    private function verifyForeignKeys(): array
    {
        $problems = [];
        $schema = $this->target->getSchemaBuilder();
        foreach (self::COPY as $table) {
            foreach ($schema->getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1) {
                    continue;
                }
                $column = $fk['columns'][0];
                $referenced = $fk['foreign_table'];
                $foreignColumn = $fk['foreign_columns'][0];
                $orphans = $this->target->table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, $this->target->table($referenced)->select($foreignColumn))
                    ->count();
                if ($orphans > 0) {
                    $problems[] = "{$table}.{$column}: {$orphans} row(s) point at a missing {$referenced}.{$foreignColumn}";
                }
            }
        }

        return $problems;
    }

    // ── Plan checks ──────────────────────────────────────────────────────────

    /** @return list<string> */
    private function droppedColumns(string $table): array
    {
        $rename = self::RENAME[$table] ?? [];
        $target = $this->targetColumns($table);

        return array_values(array_filter(
            $this->sourceColumns($table),
            fn (string $column) => !isset($target[$rename[$column] ?? $column]),
        ));
    }

    /**
     * Target NOT NULL columns without a default that Adda cannot fill.
     *
     * @return list<string>
     */
    private function notNullProblems(string $sourceTable, string $targetTable): array
    {
        $problems = [];
        $sourceColumns = $this->sourceColumns($sourceTable);
        $rename = array_flip(self::RENAME[$targetTable] ?? []);
        $deferred = self::DEFERRED[$targetTable] ?? [];

        foreach ($this->targetColumns($targetTable) as $column => $info) {
            if ($info['nullable'] || $info['auto_increment'] || in_array($column, $deferred, true)) {
                continue;
            }
            $sourceColumn = $rename[$column] ?? $column;
            if (!in_array($sourceColumn, $sourceColumns, true)) {
                if ($info['default'] === null) {
                    $problems[] = "{$targetTable}.{$column} is NOT NULL without a default and Adda has no such column.";
                }
                continue;
            }
            if ($info['default'] === null) {
                $nulls = $this->source->table($sourceTable)->whereNull($sourceColumn)->count();
                if ($nulls > 0) {
                    $problems[] = "{$targetTable}.{$column} is NOT NULL but {$nulls} Adda row(s) have NULL.";
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function uniqueProblems(string $table): array
    {
        $problems = [];
        $rename = array_flip(self::RENAME[$table] ?? []);
        $sourceColumns = $this->sourceColumns($table);

        foreach ($this->target->getSchemaBuilder()->getIndexes($table) as $index) {
            if (!$index['unique'] || $index['primary']) {
                continue;
            }
            $columns = array_map(fn ($c) => $rename[$c] ?? $c, $index['columns']);
            // referral_code is cleared on import; columns Adda lacks start empty.
            if ($table === 'users' && $columns === ['referral_code']) {
                continue;
            }
            if (array_diff($columns, $sourceColumns) !== []) {
                continue;
            }
            $query = $this->source->table($table)->select($columns);
            foreach ($columns as $column) {
                $query->whereNotNull($column);
            }
            $duplicates = $this->source->query()
                ->fromSub($query->groupBy($columns)->havingRaw('COUNT(*) > 1'), 'd')
                ->count();
            if ($duplicates > 0) {
                $problems[] = "{$table}(" . implode(',', $columns) . ") is UNIQUE in SouthIND but Adda has {$duplicates} duplicate value(s).";
            }
        }

        return $problems;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function orphanProblems(string $sourceTable, string $targetTable): array
    {
        $errors = [];
        $warnings = [];
        $rename = array_flip(self::RENAME[$targetTable] ?? []);
        $columns = $this->targetColumns($targetTable);

        foreach ($this->target->getSchemaBuilder()->getForeignKeys($targetTable) as $fk) {
            if (count($fk['columns']) !== 1) {
                continue;
            }
            $column = $fk['columns'][0];
            $sourceColumn = $rename[$column] ?? $column;
            $referenced = $fk['foreign_table'];
            if (!in_array($sourceColumn, $this->sourceColumns($sourceTable), true)) {
                continue;
            }
            if (!$this->sourceHas($referenced)) {
                $errors[] = "{$targetTable}.{$column} references `{$referenced}`, which Adda does not have.";
                continue;
            }
            $orphans = $this->source->table($sourceTable)
                ->whereNotNull($sourceColumn)
                ->whereNotIn($sourceColumn, $this->source->table($referenced)->select($fk['foreign_columns'][0]))
                ->count();
            if ($orphans === 0) {
                continue;
            }
            if ($columns[$column]['nullable']) {
                $warnings[] = "{$targetTable}.{$column}: {$orphans} Adda row(s) point at a missing {$referenced} — set to NULL.";
            } else {
                $errors[] = "{$targetTable}.{$column}: {$orphans} Adda row(s) point at a missing {$referenced} and the column is NOT NULL.";
            }
        }

        return [$errors, $warnings];
    }

    /**
     * Adda features SouthIND does not have. Their rows are archived, never
     * lost, but nobody can act on them in SouthIND — the owner settles or
     * accepts these before the move.
     *
     * @return list<string>
     */
    private function addaOnlyNotes(): array
    {
        $notes = [];
        $count = function (string $table, \Closure $where): ?int {
            if (!$this->sourceHas($table)) {
                return null;
            }
            $query = $this->source->table($table);
            $where($query);

            return $query->count();
        };
        $has = fn (string $table, string $column) => $this->sourceHas($table) && in_array($column, $this->sourceColumns($table), true);

        if ($has('streak_prize_claims', 'status') && ($n = $count('streak_prize_claims', fn ($q) => $q->where('status', 'pending')))) {
            $notes[] = "Adda-only: {$n} streak prize claim(s) still PENDING — SouthIND has no streak, settle them in Adda first (archived).";
        }
        if ($has('user_streaks', 'coins_balance') && ($n = $count('user_streaks', fn ($q) => $q->where('coins_balance', '>', 0)))) {
            $coins = (int) $this->source->table('user_streaks')->sum('coins_balance');
            $notes[] = "Adda-only: {$n} user(s) hold {$coins} streak coins — archived, not usable on SouthIND.";
        }
        if ($has('referrals', 'status') && ($n = $count('referrals', fn ($q) => $q->whereIn('status', ['pending', 'eligible'])))) {
            $owed = $has('referrals', 'reward_amount')
                ? ' (rewards ' . round((float) $this->source->table('referrals')->whereIn('status', ['pending', 'eligible'])->sum('reward_amount'), 2) . ')'
                : '';
            $notes[] = "Adda-only: {$n} referral reward(s) pending/eligible{$owed} — SouthIND has no flat rewards, settle in Adda first (archived).";
        }
        if ($has('admins', 'two_factor_confirmed_at')) {
            $n = $count('admins', function ($q) {
                $q->whereNotNull('two_factor_confirmed_at');
                if (in_array('two_factor_required', $this->sourceColumns('admins'), true)) {
                    $q->orWhere('two_factor_required', true);
                }
            });
            if ($n) {
                $notes[] = "Adda-only: {$n} admin(s) use 2FA — SouthIND has no 2FA; they log in with password only.";
            }
        }
        if ($has('admins', 'withdraw_account_mode') && ($n = $count('admins', fn ($q) => $q->whereNotNull('withdraw_account_mode')->where('withdraw_account_mode', '!=', 'off')))) {
            $notes[] = "Adda-only: {$n} admin(s) have the withdrawal account picker on — SouthIND has none (setting archived).";
        }
        if ($has('accounts', 'opening_balance') && ($n = $count('accounts', fn ($q) => $q->whereNotNull('opening_balance')->where('opening_balance', '!=', 0)))) {
            $notes[] = "Adda-only: {$n} account(s) have an opening balance — SouthIND has no account balance view (archived).";
        }
        if ($n = $count('account_adjustments', fn ($q) => $q)) {
            $notes[] = "Adda-only: {$n} account balance adjustment(s) archived (no SouthIND screen).";
        }
        if ($has('withdrawals', 'account_id') && ($n = $count('withdrawals', fn ($q) => $q->whereNotNull('account_id')))) {
            $notes[] = "Adda-only: {$n} withdrawal(s) record which admin account paid them — archived (SouthIND has no such column).";
        }

        return $notes;
    }

    /**
     * What SouthIND's own live data loses in the wipe, in money terms.
     *
     * @return list<string>
     */
    private function southIndLossNotes(): array
    {
        $tables = $this->tableNames($this->target);
        $notes = [];
        foreach (['deposits', 'withdrawals'] as $table) {
            if (!in_array($table, $tables, true)) {
                continue;
            }
            $rows = $this->target->table($table)->count();
            if ($rows === 0) {
                continue;
            }
            $approved = $this->target->table($table)->where('status', 'approved');
            $open = $this->target->table($table)->whereIn('status', ['pending', 'on_process'])->count();
            $notes[] = "SouthIND today: {$rows} {$table} ({$approved->count()} approved, sum "
                . round((float) $approved->sum('amount'), 2) . "; {$open} open) are WIPED — only the backup keeps them"
                . ($table === 'deposits' ? ', and the monthly payout never bills them.' : '.');
        }

        return $notes;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function tableNames(ConnectionInterface $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $tables = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? $schema->getTables($connection->getDatabaseName())
            : $schema->getTables();

        return array_values(array_filter(
            array_column($tables, 'name'),
            fn (string $name) => !str_starts_with($name, 'sqlite_'),
        ));
    }

    private function sourceHas(string $table): bool
    {
        $this->sourceTables ??= $this->tableNames($this->source);

        return in_array($table, $this->sourceTables, true);
    }

    /** @return list<string> */
    private function sourceColumns(string $table): array
    {
        return $this->sourceColumns[$table] ??= $this->source->getSchemaBuilder()->getColumnListing($table);
    }

    /** @return array<string, array<string, mixed>> */
    private function targetColumns(string $table): array
    {
        if (!isset($this->targetColumns[$table])) {
            $this->targetColumns[$table] = [];
            foreach ($this->target->getSchemaBuilder()->getColumns($table) as $column) {
                $this->targetColumns[$table][$column['name']] = $column;
            }
        }

        return $this->targetColumns[$table];
    }

    /** @return array<string, string> */
    private function nullableForeignKeys(string $table): array
    {
        if (!isset($this->nullableForeign[$table])) {
            $this->nullableForeign[$table] = [];
            $columns = $this->targetColumns($table);
            foreach ($this->target->getSchemaBuilder()->getForeignKeys($table) as $fk) {
                if (count($fk['columns']) === 1 && ($columns[$fk['columns'][0]]['nullable'] ?? false)) {
                    $this->nullableForeign[$table][$fk['columns'][0]] = $fk['foreign_table'];
                }
            }
        }

        return $this->nullableForeign[$table];
    }

    /**
     * Ids present in Adda for a table (the ids SouthIND will have after copy).
     *
     * @return array<int|string, true>
     */
    private function idSet(string $table): array
    {
        if (!isset($this->idSets[$table])) {
            $ids = $this->sourceHas($table) ? $this->source->table($table)->pluck('id')->all() : [];
            $this->idSets[$table] = array_fill_keys($ids, true);
        }

        return $this->idSets[$table];
    }

    private function isDefaultValue(string $key, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (array_key_exists($key, self::DROPPED_DEFAULTS)) {
            return (string) $value === self::DROPPED_DEFAULTS[$key];
        }

        return is_numeric($value) && (float) $value === 0.0;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function legacyRow(string $sourceTable, array $row, array $payload): array
    {
        return [
            'source_table' => $sourceTable,
            'source_id' => isset($row['id']) ? (string) $row['id'] : null,
            'user_id' => isset($row['user_id']) && is_numeric($row['user_id']) ? (int) $row['user_id'] : null,
            'branch_id' => isset($row['branch_id']) && is_numeric($row['branch_id']) ? (int) $row['branch_id'] : null,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'source_created_at' => $row['created_at'] ?? null,
            'imported_at' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
