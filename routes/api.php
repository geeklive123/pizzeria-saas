<?php

use App\Http\Controllers\BancoEconomicoWebhookController;
use App\Http\Controllers\PrintAgentJobController;
use Illuminate\Support\Facades\Route;

Route::post('baneco/notify-payment', BancoEconomicoWebhookController::class)->middleware('throttle:30,1')->name('baneco.webhook');

Route::middleware(['print.agent', 'throttle:120,1'])->prefix('print-agent')->group(function (): void {
    Route::post('/jobs/claim', [PrintAgentJobController::class, 'claim'])->name('api.print-agent.jobs.claim');
    Route::post('/jobs/{job}/printed', [PrintAgentJobController::class, 'printed'])->name('api.print-agent.jobs.printed');
    Route::post('/jobs/{job}/failed', [PrintAgentJobController::class, 'failed'])->name('api.print-agent.jobs.failed');
});
