<?php

namespace Voyager\Filesystem\Offloading;

use Symfony\Component\Finder\SplFileInfo;
use Voyager\Filesystem\Filesystem;
use Voyager\Filesystem\FilesystemManager;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;

/**
 * One filesystem call, run in a pool worker: the blocking method of the same name, on the local
 * filesystem or on a disk built from the caller's disk config.
 */
final readonly class FilesystemCall implements ShouldPool
{
    /**
     * @param array<string, mixed>|null $disk the disk's config, or null for the local filesystem
     * @param list<mixed> $arguments
     */
    public function __construct(
        public ?array $disk,
        public string $method,
        public array $arguments,
    ) {}

    public function handle(): mixed
    {
        $result = $this->target()->{$this->method}(...$this->arguments);

        // Finder's SplFileInfo won't serialize: it crosses as the three paths it is built from.
        return is_array($result)
            ? array_map(fn (mixed $item): mixed => $item instanceof SplFileInfo
                ? FoundFile::of($item)
                : $item, $result)
            : $result;
    }

    private function target(): object
    {
        if (is_null($this->disk)) {
            return new Filesystem();
        }

        $app = ControlPanel::getInstance();
        $manager = $app->isBound('filesystem') ? $app->get('filesystem') : new FilesystemManager($app);

        return $manager->build($this->disk);
    }
}
