<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clubs: activity tiers per branch (Diamond 21 days, Gold 14, …). The
     * system puts every user in the highest club whose day count they reach,
     * from approved deposits/withdrawals in the last `club_window_days` days.
     *
     * Branch tickers: the running strip on the user home page, per branch.
     *
     * Only creates what is missing; never drops or changes existing data. Each
     * branch that has no club yet gets the four default clubs.
     */
    public function up(): void
    {
        if (! Schema::hasTable('clubs')) {
            Schema::create('clubs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('name', 60);
                $table->string('color', 32)->default('#6366f1');
                $table->unsignedSmallInteger('min_active_days');
                $table->timestamps();
                $table->unique(['branch_id', 'name']);
            });
        }

        if (! Schema::hasTable('club_user')) {
            Schema::create('club_user', function (Blueprint $table) {
                $table->id();
                // One club per user; the row goes when the user drops below every club.
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedSmallInteger('active_days')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('global_settings') && ! Schema::hasColumn('global_settings', 'club_window_days')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('club_window_days')->default(30);
            });
        }

        if (! Schema::hasTable('branch_tickers')) {
            Schema::create('branch_tickers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('text', 300)->default('');
                $table->string('updated_by', 120)->nullable();
                $table->timestamps();
            });
        }

        $this->seedDefaultClubs();
    }

    private function seedDefaultClubs(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        $defaults = [
            ['Diamond Club', 21, '#0ea5e9'],
            ['Gold Club', 14, '#f59e0b'],
            ['Silver Club', 7, '#64748b'],
            ['Comeback Club', 3, '#10b981'],
        ];
        $now = Carbon::now();

        foreach (DB::table('branches')->pluck('id') as $branchId) {
            if (DB::table('clubs')->where('branch_id', $branchId)->exists()) {
                continue;
            }
            foreach ($defaults as [$name, $days, $color]) {
                DB::table('clubs')->insert([
                    'branch_id' => $branchId,
                    'name' => $name,
                    'color' => $color,
                    'min_active_days' => $days,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('club_user');
        Schema::dropIfExists('clubs');
        Schema::dropIfExists('branch_tickers');

        if (Schema::hasTable('global_settings') && Schema::hasColumn('global_settings', 'club_window_days')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('club_window_days');
            });
        }
    }
};
