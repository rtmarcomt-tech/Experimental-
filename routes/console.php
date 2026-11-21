<?php

use App\Services\Finance\HoldReleaseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('marketplace:release-matured-holds', function (HoldReleaseService $service): void {
    $released = $service->releaseMatured();
    $this->info("Released {$released} matured creator fund holds.");
})->purpose('Move matured creator funds from pending to available ledger accounts.');

Schedule::command('marketplace:release-matured-holds')->hourly();
