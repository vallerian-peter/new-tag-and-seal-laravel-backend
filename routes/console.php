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

Artisan::command('hiring:dispatch-notifications', function () {
    // Recover a worker killed during delivery, without retrying a live send.
    \Illuminate\Support\Facades\DB::table('hiring_notifications')->whereIn('sms_status', ['sending', 'queued'])->where('updated_at', '<', now()->subMinutes(10))->update(['sms_status' => 'failed', 'sms_next_attempt_at' => now()]);
    $ids = \Illuminate\Support\Facades\DB::table('hiring_notifications')->whereIn('sms_status', ['pending', 'failed'])->where('sms_attempts', '<', 5)->where(fn ($query) => $query->whereNull('sms_next_attempt_at')->orWhere('sms_next_attempt_at', '<=', now()))->limit(100)->pluck('id');
    foreach ($ids as $id) {
        $claimed = \Illuminate\Support\Facades\DB::table('hiring_notifications')->where('id', $id)->whereIn('sms_status', ['pending', 'failed'])->update(['sms_status' => 'queued', 'updated_at' => now()]);
        if ($claimed) \App\Jobs\SendHiringSms::dispatch($id)->onConnection('database');
    }
})->purpose('Dispatch durable hiring notification SMS outbox entries');
Schedule::command('hiring:dispatch-notifications')->everyMinute()->withoutOverlapping();

Artisan::command('cooperatives:dispatch-membership-notifications', function (\App\Services\Dairy\CooperativeMembershipNotifications $notifications) {
    $this->info('Provider accepted '.$notifications->dispatchPending().' membership notification(s).');
})->purpose('Send pending cooperative membership notifications');
Schedule::command('cooperatives:dispatch-membership-notifications')->everyMinute()->withoutOverlapping();
