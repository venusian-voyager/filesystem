<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;

class FilesystemServiceProvider extends ServiceProvider
{
    /**
     * The local filesystem, the disk manager, the default and cloud disks, and Storage.
     * The disks resolve on every ask, so a disk Storage::fake() swapped in is the one handed out.
     */
    public function register(): void
    {
        $this->app->registerSingleton('files', fn (): Filesystem => new Filesystem());

        $this->app->registerSingleton('filesystem', fn (FrameworkCore $app): FilesystemManager => new FilesystemManager($app));

        $this->app->bind('filesystem.disk', fn (FrameworkCore $app) => $app['filesystem']->disk());

        $this->app->bind('filesystem.cloud', fn (FrameworkCore $app) => $app['filesystem']->cloud());

        $this->app->registerSingleton(Storage::class, fn (FrameworkCore $app): Storage => new Storage($app));
    }
}
