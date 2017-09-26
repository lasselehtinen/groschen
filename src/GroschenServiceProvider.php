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
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Groschen::class, function () {
            return new Groschen();
        });

        $this->app->alias(Groschen::class, 'groschen');
    }
}
