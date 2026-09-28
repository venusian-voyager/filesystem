<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\Filesystem\LockTimeoutException;

/**
 * An open file behind an flock(): read, truncate and write it while the lock is held.
 */
class LockableFile
{
    /**
     * @var resource
     */
    protected $handle;

    protected bool $is_locked = false;

    public function __construct(protected readonly string $path, string $mode)
    {
        $this->ensureDirectoryExists($path);

        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new \RuntimeException("Unable to open [{$path}] in mode [{$mode}].");
        }

        $this->handle = $handle;
    }

    /**
     * Reads $length bytes from the current position, or the whole file when no length is given.
     */
    public function read(?int $length = null): string
    {
        clearstatcache(true, $this->path);

        return (string) fread($this->handle, $length ?? max(1, $this->size()));
    }

    public function size(): int
    {
        return (int) filesize($this->path);
    }

    public function write(string $contents): static
    {
        fwrite($this->handle, $contents);
        fflush($this->handle);

        return $this;
    }

    public function truncate(): static
    {
        rewind($this->handle);
        ftruncate($this->handle, 0);

        return $this;
    }

    /**
     * @throws LockTimeoutException another process holds an exclusive lock and $block is false
     */
    public function getSharedLock(bool $block = false): static
    {
        return $this->lock(LOCK_SH, $block);
    }

    /**
     * @throws LockTimeoutException another process holds a lock and $block is false
     */
    public function getExclusiveLock(bool $block = false): static
    {
        return $this->lock(LOCK_EX, $block);
    }

    public function releaseLock(): static
    {
        flock($this->handle, LOCK_UN);
        $this->is_locked = false;

        return $this;
    }

    public function close(): bool
    {
        if ($this->is_locked) {
            $this->releaseLock();
        }

        return fclose($this->handle);
    }

    private function lock(int $operation, bool $block): static
    {
        if (! flock($this->handle, $operation | ($block ? 0 : LOCK_NB))) {
            throw new LockTimeoutException("Unable to acquire file lock at path [{$this->path}].");
        }

        $this->is_locked = true;

        return $this;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
    }
}
