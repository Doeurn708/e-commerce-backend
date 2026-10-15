<?php

namespace App\Providers;

use App\Services\CloudinaryImageStore;
use Illuminate\Support\Facades\URL;
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
        // Proxy detection is handled by trustProxies() in bootstrap/app.php;
        // this only pins generated absolute URLs to HTTPS while the app is in
        // the production environment (the site is HTTPS-only there), which also
        // covers URLs built outside an HTTP request, such as in queued jobs or
        // artisan commands. Local development stays on HTTP.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
