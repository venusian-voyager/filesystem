<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\IOPools\ShouldPool;

/** One disk call, wherever it runs. The worker looks the disk up by name; only the arguments travel. */
final class DiskGig implements ShouldPool
{
    public function __construct(
        public readonly string $disk,
        public readonly string $method,
        public readonly array $args = [],
    ) {}

    public function handle(): mixed
    {
        return app('filesystem')->disk($this->disk)->{$this->method}(...$this->args);
    }
}
