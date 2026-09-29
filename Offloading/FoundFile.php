<?php

namespace Voyager\Filesystem\Offloading;

use Symfony\Component\Finder\SplFileInfo;

/** A file a worker's files() found, as the paths Finder's SplFileInfo is built from. */
final readonly class FoundFile
{
    public function __construct(
        public string $pathname,
        public string $relative_path,
        public string $relative_pathname,
    ) {}

    public static function of(SplFileInfo $file): self
    {
        return new self($file->getPathname(), $file->getRelativePath(), $file->getRelativePathname());
    }

    public function info(): SplFileInfo
    {
        return new SplFileInfo($this->pathname, $this->relative_path, $this->relative_pathname);
    }
}
