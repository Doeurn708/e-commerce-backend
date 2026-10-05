<?php

namespace App\Providers;

use App\Services\CloudinaryImageStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stateless: one shared instance serves every controller, and tests can
        // swap it for a double with $this->instance() when they need to.
        $this->app->singleton(CloudinaryImageStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
