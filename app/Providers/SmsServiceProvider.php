<?php

namespace App\Providers;

use App\Services\Sms\SmsClientInterface;
use App\Services\Sms\TwilioSmsClient;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsClientInterface::class, function ($app) {
            return new TwilioSmsClient;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
