<?php

use App\Http\Controllers\Api\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');
Route::view('/password-reset/{token}', 'app')->name('password.reset');
Route::view('/checkout/success', 'app')->name('checkout.success');
Route::view('/checkout/cancel', 'app')->name('checkout.cancel');
Route::view('/gate-invitation/accept', 'app')->name('gate-invitation.accept');
Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'web'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('email.verify');
