<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HoldController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\TicketTypeController;
use Illuminate\Support\Facades\Route;

// These are public endpoints for both organizers and clients
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/ticket-types/{ticketType}/holds', [HoldController::class, 'store']);
Route::get('/events', [PublicEventController::class, 'index']);

// These are authenticated organizer endpoints
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/events', [EventController::class, 'publish']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::patch('/events/{event}', [EventController::class, 'update']);
    Route::post('/events/{event}/cancel', [EventController::class, 'cancel']);

    Route::get('/events/{event}/ticket-types', [TicketTypeController::class, 'index']);
    Route::post('/events/{event}/ticket-types', [TicketTypeController::class, 'store']);
    Route::patch('ticket-types/{ticketType}', [TicketTypeController::class, 'update']);
    Route::delete('ticket-types/{ticketType}', [TicketTypeController::class, 'destroy']);
    Route::get('/events/{event}/inventory', [TicketTypeController::class, 'inventory']
    );

    // These are authenticated customer endpoints
    Route::post('/holds/{holdid}/release', [HoldController::class, 'release']);
    Route::post('/holds/{holdid}/confirm', [HoldController::class, 'confirm']);
});
