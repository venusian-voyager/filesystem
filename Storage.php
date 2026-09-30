<?php

namespace Voyager\Filesystem;

use UnitEnum;
use Voyager\Contracts\Core\FrameworkCore;

use function Voyager\NutsAndBolts\Helpers\enum_value;

/**
 * The disks in one place: any disk by name, the default disk's operations called on Storage
 * itself, offloading, and fakes for tests.
 *
 * @mixin \Voyager\Filesystem\FilesystemAdapter
 */
class Storage
{
    public function __construct(protected readonly FrameworkCore $app) {}

    public function disk(UnitEnum|string|null $name = null): FilesystemAdapter
    {
        return $this->manager()->disk($name);
    }

    public function cloud(): FilesystemAdapter
    {
        return $this->manager()->cloud();
    }

    /**
     * @param array<string, mixed>|string $config a disk config, or a local root
     */
    public function build(array|string $config): FilesystemAdapter
    {
        return $this->manager()->build($config);
    }

    /**
     * The default disk with every call run in a worker and answered by a promise.
     *
     * @param 'thread'|'process'|null $pool null: the thread pool when it is on, the process pool otherwise
     */
    public function via(?string $pool = null): OffloadedDisk
    {
        return $this->disk()->via($pool);
    }

    /**
     * Swaps $disk for an empty local disk under storage/framework/testing/disks, and hands it back.
     *
     * @param array<string, mixed> $config merged into the fake's config
     */
    public function fake(UnitEnum|string|null $disk = null, array $config = []): FilesystemAdapter
    {
        $disk = enum_value($disk) ?: $this->manager()->getDefaultDriver();
        $root = $this->fakeRoot($disk);

        new Filesystem()->cleanDirectory($root);

        return $this->swap($disk, $config, $root);
    }

    /**
     * fake() that keeps what earlier runs wrote: the directory is not emptied.
     *
     * @param array<string, mixed> $config merged into the fake's config
     */
    public function persistentFake(UnitEnum|string|null $disk = null, array $config = []): FilesystemAdapter
    {
        $disk = enum_value($disk) ?: $this->manager()->getDefaultDriver();

        return $this->swap($disk, $config, $this->fakeRoot($disk));
    }

    public function manager(): FilesystemManager
    {
        return $this->app['filesystem'];
    }

    /**
     * The default disk's operations, called on Storage.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->disk()->{$method}(...$parameters);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function swap(string $disk, array $config, string $root): FilesystemAdapter
    {
        $original = $this->app['config']["filesystems.disks.{$disk}"] ?? [];

        $fake = $this->manager()->createLocalDriver(
            array_merge(['throw' => $original['throw'] ?? false], $config, ['driver' => 'local', 'root' => $root]),
            $disk,
        );

        $this->manager()->set($disk, $fake);

        return $fake;
    }

    protected function fakeRoot(string $disk): string
    {
        return $this->app->storagePath('framework/testing/disks/'.$disk);
    }
}
