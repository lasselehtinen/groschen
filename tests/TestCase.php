<?php
namespace lasselehtinen\Groschen\Test;

use lasselehtinen\Groschen\GroschenFacade;
use lasselehtinen\Groschen\GroschenServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('groschen.schilling.hostname', 'tuotantoschilling');
        $app['config']->set('groschen.schilling.port', '8888');
        $app['config']->set('groschen.schilling.username', 'dig-dist');
        $app['config']->set('groschen.schilling.password', 'btRnsmLqMsALNt61Vksl');
        $app['config']->set('groschen.schilling.company', '1001');
    }

    /**
     * Load package service provider
     * @param  \Illuminate\Foundation\Application $app
     * @return lasselehtinen\Groschen\GroschenServiceProvider
     */
    protected function getPackageProviders($app)
    {
        return [GroschenServiceProvider::class];
    }
    /**
     * Load package alias
     * @param  \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageAliases($app)
    {
        return [
            'Groschen' => GroschenFacade::class,
        ];
    }
}
