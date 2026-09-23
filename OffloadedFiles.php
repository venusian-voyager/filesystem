<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WorkTarget;

/** The native Filesystem, every call sent to a work target and answered by a promise. */
final class OffloadedFiles
{
    public function __construct(private readonly WorkTarget $target) {}

    public function __call(string $method, array $args): Promise
    {
        return $this->target->run(new FileGig($method, $args));
    }
}
