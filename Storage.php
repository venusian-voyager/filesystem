<?php

namespace Voyager\Filesystem;

use UnitEnum;
use Voyager\Contracts\Filesystem\Cloud;
use Voyager\Contracts\Filesystem\Filesystem as Disk;
use Voyager\IOPools\WorkTargetManager;
use function Voyager\NutsAndBolts\Helpers\enum_value;

/**
 * The one door to files: disks, the default disk's calls, offloading, fakes. A facade in the
 * pattern's sense: an object with methods over the subsystem, not a static proxy. Swap it by
 * binding another instance.
 */
final class Storage
{
    public function __construct(
        private readonly FilesystemManager $manager,
        private readonly WorkTargetManager $targets,
    ) {}

    // ---- disks ----------------------------------------------------------------------------

    public function disk(string|UnitEnum|null $name = null): Disk
    {
        return $this->manager->disk(enum_value($name));
    }

    public function cloud(): Cloud
    {
        return $this->manager->cloud();
    }

    /** An on-demand disk from a config array, or a local root path. Not offloadable: a worker can't find it by name. */
    public function build(array|string $config): Disk
    {
        return $this->manager->build($config);
    }

    public function manager(): FilesystemManager
    {
        return $this->manager;
    }

    // ---- offloading -----------------------------------------------------------------------

    /** A disk with every call sent to a work target and answered by a promise. */
    public function via(?string $target = null, string|UnitEnum|null $disk = null): OffloadedDisk
    {
        return $this->disk($disk)->via($target);
    }

    /** The file as FileChunk mail, read by pool workers. Registers itself on the loop; done() settles with the byte count. */
    public function stream(string $path, string|UnitEnum|null $disk = null, int $chunk = 1 << 20): FileStreamResource
    {
        return new FileStreamResource(
            app(\Voyager\Contracts\IOPools\Loop::class),
            app(\Voyager\Contracts\IOPools\WorkerPool::class),
            enum_value($disk) ?: $this->manager->getDefaultDriver(),
            $path,
            $chunk,
        );
    }

    // ---- the default disk, without naming it -----------------------------------------------

    public function path(string $path): string { return $this->disk()->path($path); }
    public function exists(string $path): bool { return $this->disk()->exists($path); }
    public function missing(string $path): bool { return ! $this->exists($path); }
    public function get(string $path): ?string { return $this->disk()->get($path); }
    public function json(string $path, int $flags = 0): ?array { return $this->disk()->json($path, $flags); }
    public function readStream(string $path) { return $this->disk()->readStream($path); }
    public function put(string $path, mixed $contents, mixed $options = []): bool { return $this->disk()->put($path, $contents, $options); }
    public function writeStream(string $path, $resource, array $options = []): bool { return $this->disk()->writeStream($path, $resource, $options); }
    public function getVisibility(string $path): string { return $this->disk()->getVisibility($path); }
    public function setVisibility(string $path, string $visibility): bool { return $this->disk()->setVisibility($path, $visibility); }
    public function prepend(string $path, string $data): bool { return $this->disk()->prepend($path, $data); }
    public function append(string $path, string $data): bool { return $this->disk()->append($path, $data); }
    public function delete(string|array $paths): bool { return $this->disk()->delete($paths); }
    public function copy(string $from, string $to): bool { return $this->disk()->copy($from, $to); }
    public function move(string $from, string $to): bool { return $this->disk()->move($from, $to); }
    public function size(string $path): int { return $this->disk()->size($path); }
    public function lastModified(string $path): int { return $this->disk()->lastModified($path); }
    public function files(?string $directory = null, bool $recursive = false): array { return $this->disk()->files($directory, $recursive); }
    public function allFiles(?string $directory = null): array { return $this->disk()->allFiles($directory); }
    public function directories(?string $directory = null, bool $recursive = false): array { return $this->disk()->directories($directory, $recursive); }
    public function allDirectories(?string $directory = null): array { return $this->disk()->allDirectories($directory); }
    public function makeDirectory(string $path): bool { return $this->disk()->makeDirectory($path); }
    public function deleteDirectory(string $directory): bool { return $this->disk()->deleteDirectory($directory); }

    // ---- testing --------------------------------------------------------------------------

    /** Replace a disk with a local one under storage/framework/testing/disks/<name>, emptied first. */
    public function fake(string|UnitEnum|null $disk = null, array $config = []): Disk
    {
        $disk = enum_value($disk) ?: $this->manager->getDefaultDriver();
        $root = $this->fakeRoot($disk);

        (new Filesystem)->cleanDirectory($root);

        $fake = $this->manager->createLocalDriver($this->fakeConfig($disk, $config, $root), $disk);
        $this->manager->set($disk, $fake);

        $fake->buildTemporaryUrlsUsing(fn ($path, $expiration) => $path.'?expiration='.$expiration->getTimestamp());
        $fake->buildTemporaryUploadUrlsUsing(fn ($path, $expiration) => ['url' => $path.'?expiration='.$expiration->getTimestamp(), 'headers' => []]);

        return $fake;
    }

    /** Same as fake(), but whatever is already under the testing root stays. */
    public function persistentFake(string|UnitEnum|null $disk = null, array $config = []): Disk
    {
        $disk = enum_value($disk) ?: $this->manager->getDefaultDriver();

        $fake = $this->manager->createLocalDriver($this->fakeConfig($disk, $config, $this->fakeRoot($disk)), $disk);
        $this->manager->set($disk, $fake);

        return $fake;
    }

    public function forgetFake(string|UnitEnum|null $disk = null): void
    {
        $this->manager->forgetDisk(enum_value($disk) ?: $this->manager->getDefaultDriver());
    }

    private function fakeRoot(string $disk): string
    {
        return app()->storagePath('framework/testing/disks/'.$disk);
    }

    private function fakeConfig(string $disk, array $config, string $root): array
    {
        $original = app('config')->get("filesystems.disks.{$disk}", []);

        return array_merge(['throw' => $original['throw'] ?? false], $config, ['root' => $root]);
    }
}
