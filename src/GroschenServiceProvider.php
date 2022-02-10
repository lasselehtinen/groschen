<?php
namespace lasselehtinen\Groschen;

use Illuminate\Support\ServiceProvider;

class GroschenServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/Config/groschen.php' => config_path('groschen.php'),
        ], 'groschen-config');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Groschen::class, function () {
            return new Groschen('9789510468036');
        });

        $this->app->alias(Groschen::class, 'groschen');
    }
}
