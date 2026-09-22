<?php

namespace Voyager\Filesystem;

use Voyager\NutsAndBolts\ServiceProvider;

class FilesystemServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the filesystem.
     */
    public function boot(): void
    {
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/filesystems.php', 'filesystems');

        $this->registerFlysystem();

        $this->app->registerSingleton(Storage::class, fn ($app) => new Storage($app['filesystem'], $app['work-targets']));
    }

    /**
     * Register the driver based filesystem.
     */
    protected function registerFlysystem(): void
    {
        $this->registerManager();

        $this->app->registerSingleton('filesystem.disk', function ($app) {
            return $app['filesystem']->disk($this->getDefaultDriver());
        });

        $this->app->registerSingleton('filesystem.cloud', function ($app) {
            return $app['filesystem']->disk($this->getCloudDriver());
        });
    }

    /**
     * Register the filesystem manager.
     */
    protected function registerManager(): void
    {
        $this->app->registerSingleton('filesystem', function ($app) {
            return new FilesystemManager($app);
        });
    }

    /**
     * Get the default file driver.
     */
    protected function getDefaultDriver(): string
    {
        return $this->app['config']['filesystems.default'];
    }

    /**
     * Get the default cloud based file driver.
     */
    protected function getCloudDriver(): string
    {
        return $this->app['config']['filesystems.cloud'];
    }
}
