<?php

namespace App\Providers;

use App\Services\StripeGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        RateLimiter::for('gate-scan', function (Request $request): Limit {
            $user = $request->user();
            $accessToken = $user?->currentAccessToken();
            $tokenId = is_object($accessToken) && method_exists($accessToken, 'getKey')
                ? (string) $accessToken->getKey()
                : (string) $user?->getKey();

            return Limit::perMinute(60)->by('gate-scan:'.$tokenId);
        });

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            return URL::temporarySignedRoute(
                'email.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

            return $frontendUrl.'/password-reset/'.urlencode($token)
                .'?email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
