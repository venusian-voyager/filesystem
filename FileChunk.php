<?php

namespace Voyager\Filesystem;

use Ramsey\Uuid\Uuid;
use Voyager\Contracts\IOPools\Event;

/** One slice of a file, delivered as mail. Chunks of one stream arrive in offset order. */
final class FileChunk extends Event
{
    private readonly string $uuid;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly int $offset,
        public readonly string $bytes,
        public readonly bool $last,
    ) {
        $this->uuid = Uuid::uuid4()->toString();
    }

    public function name(): string { return 'file-chunk:'.$this->disk.':'.$this->path; }
    public function uuid(): string { return $this->uuid; }
    public function toData(): array { return ['disk' => $this->disk, 'path' => $this->path, 'offset' => $this->offset, 'bytes' => $this->bytes, 'last' => $this->last]; }
}
