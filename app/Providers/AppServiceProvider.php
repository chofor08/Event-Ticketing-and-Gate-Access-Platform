<?php

namespace App\Providers;

use App\Services\StripeGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn (): StripeClient => new StripeClient(
            (string) config('services.stripe.secret'),
        ));

        $this->app->singleton(StripeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

            return $frontendUrl.'/password-reset/'.urlencode($token)
                .'?email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
