<?php

use App\Http\Controllers\ScanController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/my-tickets', [TicketController::class, 'index']);
    Route::post('/scan', [ScanController::class, 'store']);
});
