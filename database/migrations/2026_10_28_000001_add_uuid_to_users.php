<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('uuid')->orWhere('uuid', '');
            })
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'uuid' => (string) Str::uuid(),
                    ]);
                }
            });

        $hasUuidIndex = collect(Schema::getIndexes('users'))
            ->contains(fn (array $index): bool => in_array('uuid', $index['columns'], true));

        if (! $hasUuidIndex) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // User UUIDs are permanent identities and must never be removed.
    }
};
