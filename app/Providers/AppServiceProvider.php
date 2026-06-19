<?php

namespace App\Providers;

use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Observers\OutboundOrderObserver;
use App\Observers\SalesOrderObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        OutboundOrder::observe(OutboundOrderObserver::class);
        SalesOrder::observe(SalesOrderObserver::class);
    }
}
