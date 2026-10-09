<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\TransactionStatusService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('laundry:sync-statuses', function () {
    app(TransactionStatusService::class)->synchronize();
    $this->info('Status transaksi laundry telah disinkronkan.');
})->purpose('Synchronize completed and late laundry statuses from server time');

// The scheduler is the source of truth when no cashier has the application
// open. `withoutOverlapping` prevents a slow database run from overlapping
// with the following minute's run.
Schedule::command('laundry:sync-statuses')
    ->everyMinute()
    ->withoutOverlapping();
