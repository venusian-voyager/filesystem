<?php

namespace Voyager\Filesystem;

use BadMethodCallException;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WorkTarget;

/**
 * The same disk, every call sent to a work target and answered by a promise.
 * Streams stay behind: a PHP resource cannot cross a worker.
 */
final class OffloadedDisk
{
    private const NOT_OFFLOADABLE = ['readStream', 'writeStream', 'response', 'download', 'serve'];

    public function __construct(private readonly string $disk, private readonly WorkTarget $target) {}

    public function __call(string $method, array $args): Promise
    {
        if (in_array($method, self::NOT_OFFLOADABLE, true)) {
            throw new BadMethodCallException("{$method}() cannot cross a worker: a stream is a resource. Use FileStreamResource for bytes in chunks.");
        }

        return $this->target->run(new DiskGig($this->disk, $method, $args));
    }
}
