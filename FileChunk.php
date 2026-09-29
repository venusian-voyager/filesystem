<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\Signals\NamedSignal;

/**
 * One chunk of a file a via()->stream() call is reading, delivered as loop mail. Dispatched as
 * "file:{path}" for the local filesystem, "disk:{disk}:{path}" for a disk.
 */
final readonly class FileChunk implements NamedSignal
{
    /**
     * @param string $source "file", or "disk:{disk}"
     * @param int $offset where in the file the chunk starts
     * @param bool $last no chunk follows this one
     */
    public function __construct(
        public string $source,
        public string $path,
        public int $offset,
        public string $bytes,
        public bool $last,
    ) {}

    public function name(): string
    {
        return "{$this->source}:{$this->path}";
    }
}
