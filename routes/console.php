<?php

use App\Models\GateDeviceSnapshot;
use App\Models\Hold;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Hold::query()
        ->where('status', 'held')
        ->where('expires_at', '<=', now())
        ->update(['status' => 'expired']);
})->everyMinute()->name('expire-ticket-holds')->withoutOverlapping();

Schedule::call(function (): void {
    GateDeviceSnapshot::query()
        ->where('expires_at', '<=', now()->subDays((int) config('ticketing.snapshot_retention_days', 30)))
        ->delete();
})->daily()->name('prune-expired-gate-snapshots')->withoutOverlapping();
