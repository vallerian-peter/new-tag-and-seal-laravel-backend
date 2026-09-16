<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FarmUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * BackfillFarmUserAccounts
 *
 * Finds all farm_users that do NOT have a corresponding users table entry
 * and creates the missing User account so that farm users can login.
 *
 * Usage:
 *   php artisan farmuser:backfill-accounts [--dry-run]
 */
class BackfillFarmUserAccounts extends Command
{
    protected $signature = 'farmuser:backfill-accounts {--dry-run : Show what would be created without actually creating}';

    protected $description = 'Create missing users table entries for existing farm_users so they can login via the app';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE — No changes will be made.');
        }

        $this->info('🔄 Scanning farm_users for missing user accounts...');
        $this->newLine();

        $farmUsers = FarmUser::all();
        $this->info("Total farm_users found: {$farmUsers->count()}");

        $created  = 0;
        $skipped  = 0;
        $errors   = 0;

        /** @var \App\Http\Controllers\FarmUser\FarmUserController $farmUserController */
        $farmUserController = app(\App\Http\Controllers\FarmUser\FarmUserController::class);

        foreach ($farmUsers as $farmUser) {
            try {
                // Check if a linked User already exists
                $linkedUser = User::where('role', UserRole::FARM_INVITED_USER)
                    ->where('roleId', $farmUser->id)
                    ->first();

                if ($linkedUser) {
                    $this->line("  ⏭️  FarmUser ID={$farmUser->id} ({$farmUser->email}) → already linked to User ID={$linkedUser->id} (username={$linkedUser->username})");
                    $skipped++;
                    continue;
                }

                if ($isDryRun) {
                    $this->info("  ✅ [DRY-RUN] Would create/link User for FarmUser ID={$farmUser->id} ({$farmUser->email})");
                    $created++;
                    continue;
                }

                $user = $farmUserController->ensureUserAccount($farmUser, null, null, false);
                $this->info("  ✅ Linked/Created User ID={$user->id} for FarmUser ID={$farmUser->id} ({$farmUser->email}) username={$user->username}");
                Log::info("✅ [BackfillFarmUserAccounts] Created/Linked User ID={$user->id} for FarmUser ID={$farmUser->id} ({$farmUser->email})");
                $created++;

            } catch (\Exception $e) {
                $this->error("  ❌ Error processing FarmUser ID={$farmUser->id} ({$farmUser->email}): {$e->getMessage()}");
                Log::error("❌ [BackfillFarmUserAccounts] Error for FarmUser ID={$farmUser->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info('========== Backfill Summary ==========');
        $this->info("  ✅ Created : {$created}");
        $this->info("  ⏭️  Skipped : {$skipped}");
        $this->info("  ❌ Errors  : {$errors}");
        $this->newLine();

        if ($isDryRun) {
            $this->warn('DRY RUN complete — run without --dry-run to apply changes.');
        } else {
            $this->info('Backfill complete.');
        }

        return Command::SUCCESS;
    }
}
