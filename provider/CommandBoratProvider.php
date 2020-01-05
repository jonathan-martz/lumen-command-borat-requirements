<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Class BoratProvider
 * @package App\Providers
 */
class CommandBoratProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../migration');
    }
}
