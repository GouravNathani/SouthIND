<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin/super list endpoints all order by created_at, and most of them first
 * scope to a branch. The existing indexes stop at (branch_id, status), so those
 * queries still had to filesort the whole table — 60k+ deposits in production,
 * which is a large part of why the list endpoints took seconds.
 *
 * Every index is created and dropped behind a guard, so this is safe to re-run
 * and safe on a database where one of them was already added by hand.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: list<string>}>
     */
    private array $indexes = [
        ['deposits', ['branch_id', 'created_at']],
        ['deposits', ['created_at']],
        ['withdrawals', ['branch_id', 'created_at']],
        ['withdrawals', ['created_at']],
        ['support_conversations', ['last_message_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns]) {
            $name = $this->indexName($table, $columns);

            if (!$this->isApplicable($table, $columns) || Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes) as [$table, $columns]) {
            $name = $this->indexName($table, $columns);

            if (!$this->isApplicable($table, $columns) || !Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function isApplicable(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && Schema::hasColumns($table, $columns);
    }

    /**
     * Name the indexes explicitly rather than relying on the generated name, so
     * the guard above checks for exactly what this migration creates.
     *
     * @param  list<string>  $columns
     */
    private function indexName(string $table, array $columns): string
    {
        return $table . '_' . implode('_', $columns) . '_index';
    }
};
