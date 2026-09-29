<?php

namespace Voyager\Filesystem\Offloading;

use DateTimeInterface;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;

/**
 * A disk's Flysystem operator that waits, before each operation, for the offloaded calls already
 * made on the paths it touches. A disk gets one the first time it is offloaded; every blocking
 * disk method goes through its operator, so each waits its turn behind the async ones.
 */
final class SettlingOperator implements FilesystemOperator
{
    public function __construct(
        private readonly FilesystemOperator $inner,
        private readonly PathLanes $lanes,
    ) {}

    public function fileExists(string $location): bool
    {
        $this->lanes->settle([$location]);

        return $this->inner->fileExists($location);
    }

    public function directoryExists(string $location): bool
    {
        $this->lanes->settle([$location]);

        return $this->inner->directoryExists($location);
    }

    public function has(string $location): bool
    {
        $this->lanes->settle([$location]);

        return $this->inner->has($location);
    }

    public function read(string $location): string
    {
        $this->lanes->settle([$location]);

        return $this->inner->read($location);
    }

    public function readStream(string $location)
    {
        $this->lanes->settle([$location]);

        return $this->inner->readStream($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $this->lanes->settle([$location]);

        return $this->inner->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        $this->lanes->settle([$path]);

        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        $this->lanes->settle([$path]);

        return $this->inner->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        $this->lanes->settle([$path]);

        return $this->inner->mimeType($path);
    }

    public function visibility(string $path): string
    {
        $this->lanes->settle([$path]);

        return $this->inner->visibility($path);
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->lanes->settle([$location]);

        $this->inner->write($location, $contents, $config);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->lanes->settle([$location]);

        $this->inner->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->lanes->settle([$path]);

        $this->inner->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->lanes->settle([$location]);

        $this->inner->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->lanes->settle([$location]);

        $this->inner->deleteDirectory($location);
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->lanes->settle([$location]);

        $this->inner->createDirectory($location, $config);
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->lanes->settle([$source, $destination]);

        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->lanes->settle([$source, $destination]);

        $this->inner->copy($source, $destination, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function checksum(string $path, array $config = []): string
    {
        $this->lanes->settle([$path]);

        return $this->inner->checksum($path, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function publicUrl(string $path, array $config = []): string
    {
        return $this->inner->publicUrl($path, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        return $this->inner->temporaryUrl($path, $expiresAt, $config);
    }

    /** The operator this one wraps. */
    public function inner(): FilesystemOperator
    {
        return $this->inner;
    }

    /**
     * Whatever else the wrapped operator offers.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->inner->{$method}(...$parameters);
    }
}
