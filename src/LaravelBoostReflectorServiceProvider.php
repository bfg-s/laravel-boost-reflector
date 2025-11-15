<?php

namespace Bfg\LaravelBoostReflector;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;


class LaravelBoostReflectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //Register Config file
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-boost-reflector.php', 'laravel-boost-reflector');

        //Publish Config
        $this->publishes([
           __DIR__.'/../config/laravel-boost-reflector.php' => config_path('laravel-boost-reflector.php'),
        ], 'laravel-boost-reflector-config');
    }

    public function boot(): void
    {
        if (is_array($connections = config('laravel-boost-reflector.tools.include', []))) {
            $config = Arr::dot($connections, 'boost.mcp.tools.include.');
            /** @var array<string, mixed> $config */
            config($config);
        }
    }
}
