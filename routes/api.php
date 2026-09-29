<?php

use App\Http\Controllers\Api\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\NewPasswordController;
use App\Http\Controllers\Api\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\VerifyEmailController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\GateDeviceController;
use App\Http\Controllers\Api\GateInvitationAcceptanceController;
use App\Http\Controllers\Api\GateScanController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderManagementController;
use App\Http\Controllers\Api\Organizer\GateController;
use App\Http\Controllers\Api\Organizer\GateStaffInvitationController;
use App\Http\Controllers\Api\PublicEventController;
use App\Http\Controllers\Api\SalesSummaryController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketTypeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public registration, login, password recovery, event discovery, and Stripe return/webhook routes.
Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1');
Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1');
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::get('/events', [PublicEventController::class, 'index']);
Route::get('/events/{event}', [PublicEventController::class, 'show']);
Route::post('/stripe/webhook', [OrderManagementController::class, 'webhook'])->name('stripe.webhook');
Route::get('/checkout/success', [OrderManagementController::class, 'success'])->name('checkout.success');
Route::get('/checkout/cancel', [OrderManagementController::class, 'cancel'])->name('checkout.cancel');
Route::post('/gate-invitations/accept', [GateInvitationAcceptanceController::class, 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', fn (Request $request) => $request->user());
    Route::post('/logout', [LoginController::class, 'destroy']);
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['role:organizer', 'throttle:6,1']);

    // Organizer operations require a verified organizer account and enforce event ownership.
    Route::middleware(['role:organizer', 'verified'])->prefix('organizer')->group(function (): void {
        Route::get('/events', [EventController::class, 'index']);
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/events/{event}', [EventController::class, 'showOrganizer']);
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::post('/events/{event}/cancel', [EventController::class, 'cancel']);
        Route::get('/events/{event}/ticket-types', [TicketTypeController::class, 'index']);
        Route::post('/events/{event}/ticket-types', [TicketTypeController::class, 'store']);
        Route::get('/events/{event}/inventory', [TicketTypeController::class, 'inventory']);
        Route::get('/sales_summary', SalesSummaryController::class);
        Route::patch('/ticket-types/{ticketType}', [TicketTypeController::class, 'update']);
        Route::delete('/ticket-types/{ticketType}', [TicketTypeController::class, 'destroy']);
        Route::get('/events/{event}/gates', [GateController::class, 'index']);
        Route::post('/events/{event}/gates', [GateController::class, 'store']);
        Route::patch('/gates/{gate}', [GateController::class, 'update']);
        Route::delete('/gates/{gate}', [GateController::class, 'destroy']);
        Route::get('/events/{event}/entry-counts', [GateController::class, 'entryCounts']);
        Route::get('/events/{event}/gate-staff/invitations', [GateStaffInvitationController::class, 'index']);
        Route::post('/events/{event}/gate-staff/invitations', [GateStaffInvitationController::class, 'store']);
        Route::delete('/events/{event}/gate-staff/invitations/{invitation}', [GateStaffInvitationController::class, 'destroy']);
        Route::delete('/events/{event}/gate-staff/{staff}', [GateStaffInvitationController::class, 'revokeAssignment']);
        Route::post('/events/{event}/gates/{gate}/devices', [GateDeviceController::class, 'store']);
        Route::get('/events/{event}/gates/{gate}/devices', [GateDeviceController::class, 'index']);
        Route::delete('/events/{event}/gates/{gate}/devices/{device}', [GateDeviceController::class, 'destroy']);
    });

    Route::get('/my-tickets', [TicketController::class, 'index'])->middleware('role:attendee');

    Route::prefix('gate')->group(function (): void {
        Route::get('/devices/{device}/snapshot', [GateDeviceController::class, 'snapshot']);
        Route::post('/devices/{device}/scan-sync', [GateScanController::class, 'sync'])->middleware('throttle:gate-scan');
        Route::post('/events/{event}/gates/{gate}/scans', [GateScanController::class, 'store'])
            ->middleware('throttle:gate-scan');
    });

    // Attendees can reserve and pay only for their own holds and orders.
    Route::middleware('role:attendee')->group(function (): void {
        Route::post('/ticket-types/{ticketType}/holds', [HoldController::class, 'store']);
        Route::post('/holds/{hold}/release', [HoldController::class, 'release']);
        Route::post('/checkout', [OrderManagementController::class, 'checkout'])->middleware('idempotency');
        Route::get('/orders', [OrderManagementController::class, 'index']);
        Route::get('/order_items', [OrderManagementController::class, 'orderItems']);
        Route::post('/refund', [OrderManagementController::class, 'refund']);
    });
});
