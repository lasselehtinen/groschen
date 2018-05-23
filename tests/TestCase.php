<?php
namespace lasselehtinen\Groschen\Test;

use Dotenv\Dotenv;
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
        $dotenv = new Dotenv(__DIR__ . '/..');
        $dotenv->load();
        $dotenv->required('SCHILLING_WEB_SERVICES_HOSTNAME');
        $dotenv->required('SCHILLING_WEB_SERVICES_PORT');
        $dotenv->required('SCHILLING_WEB_SERVICES_USERNAME');
        $dotenv->required('SCHILLING_WEB_SERVICES_PASSWORD');
        $dotenv->required('SCHILLING_WEB_SERVICES_COMPANY');
        $dotenv->required('ELVIS_HOSTNAME');
        $dotenv->required('ELVIS_USERNAME');
        $dotenv->required('ELVIS_PASSWORD');
        $dotenv->required('SOUNDCLOUD_CLIENTID');
        $dotenv->required('SOUNDCLOUD_CLIENTSECRET');

        // Setup default database to use sqlite :memory:
        $app['config']->set('groschen.schilling.hostname', getenv('SCHILLING_WEB_SERVICES_HOSTNAME'));
        $app['config']->set('groschen.schilling.port', getenv('SCHILLING_WEB_SERVICES_PORT'));
        $app['config']->set('groschen.schilling.username', getenv('SCHILLING_WEB_SERVICES_USERNAME'));
        $app['config']->set('groschen.schilling.password', getenv('SCHILLING_WEB_SERVICES_PASSWORD'));
        $app['config']->set('groschen.schilling.company', getenv('SCHILLING_WEB_SERVICES_COMPANY'));
        $app['config']->set('groschen.elvis.hostname', getenv('ELVIS_HOSTNAME'));
        $app['config']->set('groschen.elvis.username', getenv('ELVIS_USERNAME'));
        $app['config']->set('groschen.elvis.password', getenv('ELVIS_PASSWORD'));
        $app['config']->set('groschen.soundcloud.clientId', getenv('SOUNDCLOUD_CLIENTID'));
        $app['config']->set('groschen.soundcloud.clientSecret', getenv('SOUNDCLOUD_CLIENTSECRET'));
        $app['config']->set('groschen.opus.hostname', getenv('OPUS_HOSTNAME')); 
        $app['config']->set('groschen.opus.token', getenv('OPUS_OAUTH2_TOKEN'));
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
