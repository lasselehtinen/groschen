<?php
namespace lasselehtinen\Groschen\Test;

use Dotenv\Dotenv;
use lasselehtinen\Groschen\GroschenFacade;
use lasselehtinen\Groschen\GroschenServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Illuminate\Foundation\Application;

class TestCase extends OrchestraTestCase
{
    /**
     * Define environment setup.
     *
     * @param  Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        $dotenv->required('ELVIS_HOSTNAME');
        $dotenv->required('ELVIS_USERNAME');
        $dotenv->required('ELVIS_PASSWORD');
        $dotenv->required('SOUNDCLOUD_CLIENTID');
        $dotenv->required('SOUNDCLOUD_CLIENTSECRET');
        $dotenv->required('OPUS_HOSTNAME');
        $dotenv->required('OPUS_SEARCH_HOSTNAME');
        $dotenv->required('OPUS_CLIENT_ID');
        $dotenv->required('OPUS_CLIENT_SECRET');
        $dotenv->required('OPUS_OAUTH_BASE_URL');
        $dotenv->required('OPUS_USERNAME');
        $dotenv->required('OPUS_PASSWORD');

        $app['config']->set('groschen.elvis.hostname', $_ENV['ELVIS_HOSTNAME']);
        $app['config']->set('groschen.elvis.username', $_ENV['ELVIS_USERNAME']);
        $app['config']->set('groschen.elvis.password', $_ENV['ELVIS_PASSWORD']);
        $app['config']->set('groschen.soundcloud.clientId', $_ENV['SOUNDCLOUD_CLIENTID']);
        $app['config']->set('groschen.soundcloud.clientSecret', $_ENV['SOUNDCLOUD_CLIENTSECRET']);
        $app['config']->set('groschen.opus.hostname', $_ENV['OPUS_HOSTNAME']);
        $app['config']->set('groschen.opus.search_hostname', $_ENV['OPUS_SEARCH_HOSTNAME']);
        $app['config']->set('groschen.opus.clientId', $_ENV['OPUS_CLIENT_ID']);
        $app['config']->set('groschen.opus.clientSecret', $_ENV['OPUS_CLIENT_SECRET']);
        $app['config']->set('groschen.opus.urlAuthorize', $_ENV['OPUS_OAUTH_BASE_URL'] . '/core/connect/authorize');
        $app['config']->set('groschen.opus.urlAccessToken', $_ENV['OPUS_OAUTH_BASE_URL'] . '/core/connect/token');
        $app['config']->set('groschen.opus.urlResourceOwnerDetails', $_ENV['OPUS_OAUTH_BASE_URL'] . '/core/connect/resource');
        $app['config']->set('groschen.opus.username', $_ENV['OPUS_USERNAME']);
        $app['config']->set('groschen.opus.password', $_ENV['OPUS_PASSWORD']);
    }

    /**
     * Load package service provider
     * @param  Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [GroschenServiceProvider::class];
    }

    /**
     * Load package alias
     * @param  Application $app
     * @return array
     */
    protected function getPackageAliases($app)
    {
        return [
            'Groschen' => GroschenFacade::class,
        ];
    }
}
