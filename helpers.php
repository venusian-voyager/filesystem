<?php

use Voyager\Filesystem\Storage;

if (! function_exists('storage')) {
    /** The Storage facade: disks, the default disk, offloading, fakes. */
    function storage(): Storage
    {
        return app(Storage::class);
    }
}
