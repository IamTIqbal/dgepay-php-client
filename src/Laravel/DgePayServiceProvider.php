<?php

/**
 * DGePay PHP SDK — Laravel Service Provider
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 * @license   MIT
 */

namespace DgePay\Laravel;

use DgePay\DgePay;
use Illuminate\Support\ServiceProvider;

class DgePayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/dgepay.php', 'dgepay');

        $this->app->singleton(DgePay::class, function ($app) {
            $dgepay = new DgePay(config('dgepay'));

            // Wire up Laravel's logger
            $dgepay->setLogger(function (string $level, string $message, array $context) {
                \Illuminate\Support\Facades\Log::log($level, $message, $context);
            });

            return $dgepay;
        });

        $this->app->alias(DgePay::class, 'dgepay');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/dgepay.php' => config_path('dgepay.php'),
        ], 'dgepay-config');
    }
}
