<?php

namespace Voyager\Filesystem;

use Voyager\Filesystem\Concerns\FileHelpers;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

class File extends SymfonyFile
{
    use FileHelpers;
}
