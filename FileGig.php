<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\IOPools\ShouldPool;

/** One native Filesystem call, wherever it runs. */
final class FileGig implements ShouldPool
{
    public function __construct(
        public readonly string $method,
        public readonly array $args = [],
    ) {}

    public function handle(): mixed
    {
        return app('files')->{$this->method}(...$this->args);
    }
}
