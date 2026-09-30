<?php

use Voyager\Filesystem\Storage;

if (! function_exists('storage')) {
    /** The disks: any disk by name, the default disk, offloading, fakes. */
    function storage(): Storage
    {
        return app(Storage::class);
    }
}
