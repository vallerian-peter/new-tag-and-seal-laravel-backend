<?php

use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Dynamic\SyncChangeRecorder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tag-and-seal:publish-configuration', function (ConfigurationSyncService $configuration) {
    $revision = $configuration->publishPending();
    $changes = SyncChangeRecorder::recordRequested();
    $this->info($revision ? "Published configuration revision {$revision->revision}." : 'No configuration changes waiting.');
    $this->info("Recorded {$changes} Portal change(s) in the sync feed.");
})->purpose('Compile the manifest and record sync-feed entries for changes queued by the Portal');

Schedule::command('tag-and-seal:publish-configuration')->everyMinute()->withoutOverlapping();
