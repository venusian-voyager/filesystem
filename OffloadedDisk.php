<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\IOPools\Promise;
use Voyager\Filesystem\Offloading\Offloader;

/**
 * A disk with each call run in a pool worker, on the disk the worker builds from this one's
 * config. Every method is the blocking FilesystemAdapter method of the same name: its promise
 * settles with what that method returns, or rejects with the worker's RemoteException for what it
 * threw. Calls on one path complete in the order they were made; calls on unrelated paths run
 * side by side.
 *
 * Contents cross as strings. readStream() and writeStream() hand over an open resource, which
 * can't leave the process that opened it: stream() reads a file in chunks instead, and putFile()
 * has the worker open a file on this machine itself. path(), url() and the temporary URLs are
 * worked out from the config without touching the storage, so there is nothing to offload.
 */
final readonly class OffloadedDisk
{
    /** The chunk size stream() reads in when none is given: 1 MiB. */
    public const int CHUNK_BYTES = 1_048_576;

    /**
     * @param string $name the disk's name, for the FileChunk mail stream() posts
     */
    public function __construct(
        private Offloader $offloader,
        private string $name,
    ) {}

    public function exists(string $path): Promise
    {
        return $this->offloader->call('exists', [$path], [$path]);
    }

    public function missing(string $path): Promise
    {
        return $this->offloader->call('missing', [$path], [$path]);
    }

    public function fileExists(string $path): Promise
    {
        return $this->offloader->call('fileExists', [$path], [$path]);
    }

    public function fileMissing(string $path): Promise
    {
        return $this->offloader->call('fileMissing', [$path], [$path]);
    }

    public function directoryExists(string $path): Promise
    {
        return $this->offloader->call('directoryExists', [$path], [$path]);
    }

    public function directoryMissing(string $path): Promise
    {
        return $this->offloader->call('directoryMissing', [$path], [$path]);
    }

    public function get(string $path): Promise
    {
        return $this->offloader->call('get', [$path], [$path]);
    }

    public function json(string $path, int $flags = 0): Promise
    {
        return $this->offloader->call('json', [$path, $flags], [$path]);
    }

    /**
     * @param array<string, mixed>|string $options a visibility, or Flysystem write options
     */
    public function put(string $path, string $contents, array|string $options = []): Promise
    {
        return $this->offloader->call('put', [$path, $contents, $options], [$path]);
    }

    /**
     * Stores a file on this machine in $directory under a random name, as the blocking putFile()
     * does. The worker opens the file itself, so its contents never pass through this process.
     *
     * @param array<string, mixed>|string $options
     * @return Promise the stored file's path, or false
     */
    public function putFile(string $directory, string $file, array|string $options = []): Promise
    {
        return $this->offloader->call('putFile', [$directory, $file, $options], [$directory]);
    }

    /**
     * @param array<string, mixed>|string $options
     * @return Promise the stored file's path, or false
     */
    public function putFileAs(string $directory, string $file, string $name, array|string $options = []): Promise
    {
        return $this->offloader->call('putFileAs', [$directory, $file, $name, $options], [trim($directory.'/'.$name, '/')]);
    }

    public function getVisibility(string $path): Promise
    {
        return $this->offloader->call('getVisibility', [$path], [$path]);
    }

    public function setVisibility(string $path, string $visibility): Promise
    {
        return $this->offloader->call('setVisibility', [$path, $visibility], [$path]);
    }

    public function prepend(string $path, string $data, string $separator = PHP_EOL): Promise
    {
        return $this->offloader->call('prepend', [$path, $data, $separator], [$path]);
    }

    public function append(string $path, string $data, string $separator = PHP_EOL): Promise
    {
        return $this->offloader->call('append', [$path, $data, $separator], [$path]);
    }

    /**
     * @param array<int, string>|string $paths
     */
    public function delete(array|string $paths): Promise
    {
        return $this->offloader->call('delete', [$paths], array_values((array) $paths));
    }

    public function copy(string $from, string $to): Promise
    {
        return $this->offloader->call('copy', [$from, $to], [$from, $to]);
    }

    public function move(string $from, string $to): Promise
    {
        return $this->offloader->call('move', [$from, $to], [$from, $to]);
    }

    public function size(string $path): Promise
    {
        return $this->offloader->call('size', [$path], [$path]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function checksum(string $path, array $options = []): Promise
    {
        return $this->offloader->call('checksum', [$path, $options], [$path]);
    }

    public function mimeType(string $path): Promise
    {
        return $this->offloader->call('mimeType', [$path], [$path]);
    }

    public function lastModified(string $path): Promise
    {
        return $this->offloader->call('lastModified', [$path], [$path]);
    }

    public function readRange(string $path, int $offset, int $length): Promise
    {
        return $this->offloader->call('readRange', [$path, $offset, $length], [$path]);
    }

    public function files(?string $directory = null, bool $recursive = false): Promise
    {
        return $this->offloader->call('files', [$directory, $recursive], [$directory ?? '']);
    }

    public function allFiles(?string $directory = null): Promise
    {
        return $this->offloader->call('allFiles', [$directory], [$directory ?? '']);
    }

    public function directories(?string $directory = null, bool $recursive = false): Promise
    {
        return $this->offloader->call('directories', [$directory, $recursive], [$directory ?? '']);
    }

    public function allDirectories(?string $directory = null): Promise
    {
        return $this->offloader->call('allDirectories', [$directory], [$directory ?? '']);
    }

    public function makeDirectory(string $path): Promise
    {
        return $this->offloader->call('makeDirectory', [$path], [$path]);
    }

    public function deleteDirectory(string $directory): Promise
    {
        return $this->offloader->call('deleteDirectory', [$directory], [$directory]);
    }

    /**
     * Reads the file $chunk_bytes at a time, each chunk loop mail: a FileChunk dispatched as
     * "disk:{disk}:{path}", oldest first, the last one marked. run() hands the mail to the mail
     * handler.
     *
     * @return Promise the number of bytes read
     */
    public function stream(string $path, int $chunk_bytes = self::CHUNK_BYTES): Promise
    {
        return $this->offloader->stream("disk:{$this->name}", $path, $chunk_bytes);
    }
}
