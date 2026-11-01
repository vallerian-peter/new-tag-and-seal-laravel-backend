<?php

namespace App\Providers;

use App\Services\Ledger\FinanceLinker;
use App\Services\Ledger\LegacyBridge;
use App\Services\Ledger\LegacyCatalog;
use App\Services\Ledger\LegacyRetiredException;
use App\Services\Ledger\LegacySubjectMirror;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // The bridge listens by table rather than by model class, so any model on a
        // catalogued legacy table is covered.
        Event::listen(['eloquent.saving: *', 'eloquent.deleting: *'], function (string $name, array $data): void {
            $model = $data[0] ?? null;
            $spec = $model instanceof Model ? LegacyCatalog::table($model->getTable()) : null;
            if ($spec && LegacyBridge::authority($spec['family']) === 'retired') {
                throw new LegacyRetiredException($model->getTable(), $spec['family']);
            }
        });
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *'], function (string $name, array $data): void {
            $model = $data[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }
            $table = $model->getTable();
            $id = $model->getKey();
            if (str_starts_with($name, 'eloquent.saved') && in_array($table, ['bills', 'finance_expenses', 'finance_incomes'], true)) {
                app(LegacyBridge::class)->safely('finance_link', $table, $id, fn () => app(FinanceLinker::class)->linkRow($table, $id));

                return;
            }
            if ($table !== 'livestocks' && ! LegacyCatalog::table($table)) {
                return;
            }
            $bridge = app(LegacyBridge::class);
            if ($table === 'livestocks') {
                $bridge->safely('livestock_mirror', $table, $id, fn () => app(LegacySubjectMirror::class)->mirrorUuid($model->getAttribute('uuid')));

                return;
            }
            $row = DB::table($table)->where('id', $id)->first();
            $bridge->safely('legacy_to_ledger', $table, $id, fn () => $row
                ? $bridge->fromLegacy($table, (array) $row)
                : $bridge->legacyDeleted($table, ['id' => $id]));
        });
    }
}
