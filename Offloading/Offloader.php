<?php

namespace Voyager\Filesystem\Offloading;

use Throwable;
use InvalidArgumentException;
use Voyager\Vessel\ControlPanel;
use Voyager\Filesystem\FileChunk;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WorkerPools\WorkerPool;

/**
 * Sends filesystem calls to a worker pool through the filesystem's path lanes. Only data crosses:
 * arguments must be strings, numbers, booleans, null or arrays of them, checked when the call is
 * made, so what a call writes is what it was given at that moment.
 */
final readonly class Offloader
{
    /**
     * @param array<string, mixed>|null $disk the disk's config, or null for the local filesystem
     */
    public function __construct(
        public Loop $loop,
        public WorkerPool $pool,
        public PathLanes $lanes,
        public ?array $disk,
    ) {}

    /**
     * The loop, and the pool offloaded calls run on: 'thread' or 'process' by name, or with none
     * named, the thread pool when it is on and the process pool otherwise.
     *
     * @return array{0: Loop, 1: WorkerPool}
     * @throws InvalidArgumentException the pool doesn't exist or isn't on
     */
    public static function pool(?string $name): array
    {
        $app = ControlPanel::getInstance();

        $binding = match ($name) {
            null => $app->isBound('thread-pool') ? 'thread-pool' : 'process-pool',
            'thread' => 'thread-pool',
            'process' => 'process-pool',
            default => throw new InvalidArgumentException(
                "There is no \"{$name}\" pool: offload to 'thread' or 'process', or name none for the thread pool when it is on and the process pool otherwise."
            ),
        };

        if (! $app->isBound($binding)) {
            throw new InvalidArgumentException(match (true) {
                is_null($name) => 'Offloading runs on a worker pool, and none is on: enable io-pools.pool_workers.threads or io-pools.pool_workers.process.',
                $name === 'thread' => 'The thread pool is off: enable io-pools.pool_workers.threads to offload to it.',
                default => 'The process pool is off: enable io-pools.pool_workers.process to offload to it.',
            });
        }

        return [$app->get(Loop::class), $app->get($binding)];
    }

    /**
     * Runs $method in a worker once no earlier call holds any of $paths.
     *
     * @param list<mixed> $arguments
     * @param list<string> $paths every path the call reads or writes
     */
    public function call(string $method, array $arguments, array $paths): Promise
    {
        try {
            self::assertData($arguments);
        } catch (InvalidArgumentException $e) {
            return $this->rejected(new InvalidArgumentException("{$method}() can't run in a worker: {$e->getMessage()}", 0, $e));
        }

        $call = new FilesystemCall($this->disk, $method, $arguments);

        return $this->lanes->run($paths, fn (): Promise => $this->pool->submit($call));
    }

    /**
     * Reads $path one readRange() call per chunk and posts each chunk to the loop as FileChunk
     * mail, oldest first, the last one marked. A chunk is read after the one before it arrived,
     * so no more than one is in flight. The path is held for the whole read, so the file doesn't
     * change under it partway through. The file's size is taken first: bytes added later aren't
     * read, and a file that shrank ends on the short read.
     *
     * @return Promise the number of bytes read
     */
    public function stream(string $source, string $path, int $chunk_bytes): Promise
    {
        if ($chunk_bytes < 1) {
            return $this->rejected(new InvalidArgumentException("A stream reads at least one byte per chunk, {$chunk_bytes} given."));
        }

        return $this->lanes->run([$path], function () use ($source, $path, $chunk_bytes): Promise {
            $done = $this->loop->promise();

            $this->pool->submit(new FilesystemCall($this->disk, 'size', [$path]))->then(
                function (int $size) use ($done, $source, $path, $chunk_bytes): null {
                    $this->chunk($done, $source, $path, 0, $size, $chunk_bytes);

                    return null;
                },
                function (Throwable $e) use ($done): null {
                    $done->reject($e);

                    return null;
                },
            );

            return $done;
        });
    }

    private function chunk(Promise $done, string $source, string $path, int $offset, int $size, int $chunk_bytes): void
    {
        $length = max(0, min($chunk_bytes, $size - $offset));

        $this->pool->submit(new FilesystemCall($this->disk, 'readRange', [$path, $offset, $length]))->then(
            function (string $bytes) use ($done, $source, $path, $offset, $size, $chunk_bytes): null {
                $next = $offset + strlen($bytes);
                $last = $next >= $size || $bytes === '';

                $this->loop->post(new FileChunk($source, $path, $offset, $bytes, $last));

                $last ? $done->resolve($next) : $this->chunk($done, $source, $path, $next, $size, $chunk_bytes);

                return null;
            },
            function (Throwable $e) use ($done): null {
                $done->reject($e);

                return null;
            },
        );
    }

    private function rejected(Throwable $e): Promise
    {
        $promise = $this->loop->promise();
        $promise->reject($e);

        return $promise;
    }

    /**
     * @param array<array-key, mixed> $values
     * @throws InvalidArgumentException a value that isn't data
     */
    private static function assertData(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                self::assertData($value);
            } elseif (! is_null($value) && ! is_scalar($value)) {
                throw new InvalidArgumentException(
                    'its arguments must be strings, numbers, booleans, null or arrays of them, and '.get_debug_type($value).' was given.'
                );
            }
        }
    }
}
