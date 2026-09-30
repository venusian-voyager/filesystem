<?php

namespace Voyager\Filesystem;

use Voyager\Contracts\IOPools\Promise;
use Voyager\Filesystem\Offloading\FoundFile;
use Voyager\Filesystem\Offloading\Offloader;
use Voyager\Filesystem\Offloading\PathLanes;

/**
 * The local filesystem with each call run in a pool worker. Every method is the blocking
 * Filesystem method of the same name: its promise settles with what that method returns, or
 * rejects with the worker's RemoteException for what it threw. Calls on one path complete in the
 * order they were made; calls on unrelated paths run side by side.
 *
 * Methods with nothing to offload aren't here: name(), basename(), dirname() and extension() only
 * take a path apart, lines() reads through a handle that can't leave the worker, and
 * getRequire()/requireOnce() run a file's code, which has to run in the process that wants it.
 */
final readonly class OffloadedFiles
{
    /** The chunk size stream() reads in when none is given: 1 MiB. */
    public const int CHUNK_BYTES = 1_048_576;

    public function __construct(private Offloader $offloader) {}

    public function exists(string $path): Promise
    {
        return $this->offloader->call('exists', [$path], [$path]);
    }

    public function missing(string $path): Promise
    {
        return $this->offloader->call('missing', [$path], [$path]);
    }

    public function get(string $path, bool $lock = false): Promise
    {
        return $this->offloader->call('get', [$path, $lock], [$path]);
    }

    public function json(string $path, int $flags = 0, bool $lock = false): Promise
    {
        return $this->offloader->call('json', [$path, $flags, $lock], [$path]);
    }

    public function sharedGet(string $path): Promise
    {
        return $this->offloader->call('sharedGet', [$path], [$path]);
    }

    public function hash(string $path, string $algorithm = 'md5'): Promise
    {
        return $this->offloader->call('hash', [$path, $algorithm], [$path]);
    }

    public function put(string $path, string $contents, bool $lock = false): Promise
    {
        return $this->offloader->call('put', [$path, $contents, $lock], [$path]);
    }

    public function replace(string $path, string $content, ?int $mode = null): Promise
    {
        return $this->offloader->call('replace', [$path, $content, $mode], [$path]);
    }

    /**
     * @param array<int, string>|string $search
     * @param array<int, string>|string $replace
     */
    public function replaceInFile(array|string $search, array|string $replace, string $path): Promise
    {
        return $this->offloader->call('replaceInFile', [$search, $replace, $path], [$path]);
    }

    public function prepend(string $path, string $data): Promise
    {
        return $this->offloader->call('prepend', [$path, $data], [$path]);
    }

    public function append(string $path, string $data, bool $lock = false): Promise
    {
        return $this->offloader->call('append', [$path, $data, $lock], [$path]);
    }

    public function chmod(string $path, ?int $mode = null): Promise
    {
        return $this->offloader->call('chmod', [$path, $mode], [$path]);
    }

    /**
     * @param array<int, string>|string $paths
     */
    public function delete(array|string $paths): Promise
    {
        return $this->offloader->call('delete', [$paths], array_values((array) $paths));
    }

    public function move(string $path, string $target): Promise
    {
        return $this->offloader->call('move', [$path, $target], [$path, $target]);
    }

    public function copy(string $path, string $target): Promise
    {
        return $this->offloader->call('copy', [$path, $target], [$path, $target]);
    }

    public function link(string $target, string $link): Promise
    {
        return $this->offloader->call('link', [$target, $link], [$link]);
    }

    public function relativeLink(string $target, string $link): Promise
    {
        return $this->offloader->call('relativeLink', [$target, $link], [$link]);
    }

    public function guessExtension(string $path): Promise
    {
        return $this->offloader->call('guessExtension', [$path], [$path]);
    }

    public function type(string $path): Promise
    {
        return $this->offloader->call('type', [$path], [$path]);
    }

    public function mimeType(string $path): Promise
    {
        return $this->offloader->call('mimeType', [$path], [$path]);
    }

    public function size(string $path): Promise
    {
        return $this->offloader->call('size', [$path], [$path]);
    }

    public function lastModified(string $path): Promise
    {
        return $this->offloader->call('lastModified', [$path], [$path]);
    }

    public function isDirectory(string $directory): Promise
    {
        return $this->offloader->call('isDirectory', [$directory], [$directory]);
    }

    public function isEmptyDirectory(string $directory, bool $ignoreDotFiles = false): Promise
    {
        return $this->offloader->call('isEmptyDirectory', [$directory, $ignoreDotFiles], [$directory]);
    }

    public function isReadable(string $path): Promise
    {
        return $this->offloader->call('isReadable', [$path], [$path]);
    }

    public function isWritable(string $path): Promise
    {
        return $this->offloader->call('isWritable', [$path], [$path]);
    }

    public function hasSameHash(string $firstFile, string $secondFile): Promise
    {
        return $this->offloader->call('hasSameHash', [$firstFile, $secondFile], [$firstFile, $secondFile]);
    }

    public function isFile(string $file): Promise
    {
        return $this->offloader->call('isFile', [$file], [$file]);
    }

    public function glob(string $pattern, int $flags = 0): Promise
    {
        return $this->offloader->call('glob', [$pattern, $flags], [PathLanes::globRoot($pattern)]);
    }

    /**
     * @param array<int, string>|string $directory
     * @param array<int, int|string>|string|int $depth
     * @return Promise the files, as Finder's SplFileInfo like the blocking call's
     */
    public function files(array|string $directory, bool $hidden = false, array|string|int $depth = 0): Promise
    {
        return $this->offloader->call('files', [$directory, $hidden, $depth], array_values((array) $directory))
            ->then(self::found(...));
    }

    /** @return Promise the files, as Finder's SplFileInfo like the blocking call's */
    public function allFiles(string $directory, bool $hidden = false): Promise
    {
        return $this->offloader->call('allFiles', [$directory, $hidden], [$directory])
            ->then(self::found(...));
    }

    /**
     * @param array<int, int|string>|string|int $depth
     */
    public function directories(string $directory, array|string|int $depth = 0): Promise
    {
        return $this->offloader->call('directories', [$directory, $depth], [$directory]);
    }

    public function allDirectories(string $directory): Promise
    {
        return $this->offloader->call('allDirectories', [$directory], [$directory]);
    }

    public function ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): Promise
    {
        return $this->offloader->call('ensureDirectoryExists', [$path, $mode, $recursive], [$path]);
    }

    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): Promise
    {
        return $this->offloader->call('makeDirectory', [$path, $mode, $recursive, $force], [$path]);
    }

    public function moveDirectory(string $from, string $to, bool $overwrite = false): Promise
    {
        return $this->offloader->call('moveDirectory', [$from, $to, $overwrite], [$from, $to]);
    }

    public function copyDirectory(string $directory, string $destination, ?int $options = null): Promise
    {
        return $this->offloader->call('copyDirectory', [$directory, $destination, $options], [$directory, $destination]);
    }

    public function deleteDirectory(string $directory, bool $preserve = false): Promise
    {
        return $this->offloader->call('deleteDirectory', [$directory, $preserve], [$directory]);
    }

    public function deleteDirectories(string $directory): Promise
    {
        return $this->offloader->call('deleteDirectories', [$directory], [$directory]);
    }

    public function cleanDirectory(string $directory): Promise
    {
        return $this->offloader->call('cleanDirectory', [$directory], [$directory]);
    }

    public function readRange(string $path, int $offset, int $length): Promise
    {
        return $this->offloader->call('readRange', [$path, $offset, $length], [$path]);
    }

    /**
     * Reads the file $chunk_bytes at a time, each chunk loop mail: a FileChunk dispatched as
     * "file:{path}", oldest first, the last one marked. run() hands the mail to the mail handler.
     *
     * @return Promise the number of bytes read
     */
    public function stream(string $path, int $chunk_bytes = self::CHUNK_BYTES): Promise
    {
        return $this->offloader->stream('file', $path, $chunk_bytes);
    }

    /**
     * @param list<mixed> $files
     * @return list<mixed>
     */
    private static function found(array $files): array
    {
        return array_map(fn (mixed $file): mixed => $file instanceof FoundFile ? $file->info() : $file, $files);
    }
}
