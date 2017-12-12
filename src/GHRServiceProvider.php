<?php

namespace s00d\GuzzleHttpRequest;

use Illuminate\Support\ServiceProvider;

class GHRServiceProvider extends ServiceProvider {

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/ghr.php' => config_path('ghr.php'),
        ]);
    }
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Register providers.
        $this->app->bind('GHR', function($app) {
            return new GHR();
        });
    }
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('GHR');
    }
}