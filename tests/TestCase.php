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
        $dotenv->required('MOCKINGBIRD_WORK_API_HOSTNAME');
        $dotenv->required('MOCKINGBIRD_CONTACT_API_HOSTNAME');
        $dotenv->required('MOCKINGBIRD_CLIENT_ID');
        $dotenv->required('MOCKINGBIRD_CLIENT_SECRET');
        $dotenv->required('MOCKINGBIRD_OAUTH_BASE_URL');
        $dotenv->required('MOCKINGBIRD_USERNAME');
        $dotenv->required('MOCKINGBIRD_PASSWORD');

        $app['config']->set('groschen.elvis.hostname', $_ENV['ELVIS_HOSTNAME']);
        $app['config']->set('groschen.elvis.username', $_ENV['ELVIS_USERNAME']);
        $app['config']->set('groschen.elvis.password', $_ENV['ELVIS_PASSWORD']);
        $app['config']->set('groschen.soundcloud.clientId', $_ENV['SOUNDCLOUD_CLIENTID']);
        $app['config']->set('groschen.soundcloud.clientSecret', $_ENV['SOUNDCLOUD_CLIENTSECRET']);
        $app['config']->set('groschen.mockingbird.work_api_hostname', $_ENV['MOCKINGBIRD_WORK_API_HOSTNAME']);
        $app['config']->set('groschen.mockingbird.contact_api_hostname', $_ENV['MOCKINGBIRD_CONTACT_API_HOSTNAME']);
        $app['config']->set('groschen.mockingbird.clientId', $_ENV['MOCKINGBIRD_CLIENT_ID']);
        $app['config']->set('groschen.mockingbird.clientSecret', $_ENV['MOCKINGBIRD_CLIENT_SECRET']);
        $app['config']->set('groschen.mockingbird.urlAuthorize', $_ENV['MOCKINGBIRD_OAUTH_BASE_URL'] . '/core/connect/authorize');
        $app['config']->set('groschen.mockingbird.urlAccessToken', $_ENV['MOCKINGBIRD_OAUTH_BASE_URL'] . '/core/connect/token');
        $app['config']->set('groschen.mockingbird.urlResourceOwnerDetails', $_ENV['MOCKINGBIRD_OAUTH_BASE_URL'] . '/core/connect/resource');
        $app['config']->set('groschen.mockingbird.username', $_ENV['MOCKINGBIRD_USERNAME']);
        $app['config']->set('groschen.mockingbird.password', $_ENV['MOCKINGBIRD_PASSWORD']);
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
