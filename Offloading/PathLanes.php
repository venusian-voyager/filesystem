<?php

namespace Voyager\Filesystem\Offloading;

use Closure;
use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;

/**
 * Orders offloaded calls by the paths they touch. A call starts once no earlier call, running or
 * still waiting, holds any of its paths; calls on unrelated paths run side by side. A path holds
 * everything under it, so work on a directory waits for work inside it and the other way round.
 * settle() lets a blocking call on a path wait for the offloaded calls already made on it.
 */
final class PathLanes
{
    /**
     * @var list<array{paths: list<string>, start: Closure(): Promise, promise: Promise}> oldest first
     */
    private array $waiting = [];

    /**
     * @var array<int, list<string>> call id => the paths a running call holds
     */
    private array $running = [];

    private int $next_id = 0;

    /**
     * @param Closure(string): string $normalize a path as this filesystem sees it, the form paths are compared in
     */
    public function __construct(
        private readonly Loop $loop,
        private readonly Closure $normalize,
    ) {}

    /** Paths on this machine: absolute, from the working directory when relative. */
    public static function forLocal(Loop $loop): self
    {
        return new self($loop, static fn (string $path): string => self::lexical(
            str_starts_with($path, '/') ? $path : getcwd().'/'.$path
        ));
    }

    /** Paths on a disk: relative to its root, which is the empty path. */
    public static function forDisk(Loop $loop): self
    {
        return new self($loop, static fn (string $path): string => trim(self::lexical(str_replace('\\', '/', $path)), '/'));
    }

    /**
     * @param list<string> $paths every path the call reads or writes
     * @param Closure(): Promise $start sends the call; its promise settles the one run() returns
     */
    public function run(array $paths, Closure $start): Promise
    {
        $promise = $this->loop->promise();
        $this->waiting[] = ['paths' => array_map($this->normalize, $paths), 'start' => $start, 'promise' => $promise];
        $this->dispatch();

        return $promise;
    }

    /**
     * Blocks until no offloaded call holds any of $paths.
     *
     * @param list<string> $paths
     */
    public function settle(array $paths): void
    {
        $paths = array_map($this->normalize, $paths);

        if ($this->holds($paths)) {
            $this->loop->until(fn (): bool => ! $this->holds($paths));
        }
    }

    /** The directory a glob pattern can match in: everything before its first wildcard, cut back to a whole directory. */
    public static function globRoot(string $pattern): string
    {
        $static = strcspn($pattern, '*?[{');

        return $static === strlen($pattern) ? $pattern : dirname(substr($pattern, 0, $static).'x');
    }

    /**
     * $path with its "." and ".." segments resolved and its separators single, symlinks untouched.
     */
    public static function lexical(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $segments);
    }

    private function dispatch(): void
    {
        $held = array_values($this->running);
        [$ready, $still] = [[], []];

        // Earlier waiting calls hold their paths too, so a later call never overtakes one on the same path.
        foreach ($this->waiting as $call) {
            self::overlaps($call['paths'], $held) ? $still[] = $call : $ready[] = $call;
            $held[] = $call['paths'];
        }

        $this->waiting = $still;
        $released = false;

        foreach ($ready as $call) {
            $id = $this->next_id++;
            $this->running[$id] = $call['paths'];

            try {
                $sent = ($call['start'])();
            } catch (Throwable $e) {
                unset($this->running[$id]);
                $call['promise']->reject($e);
                $released = true;
                continue;
            }

            $sent->then(
                function (mixed $value) use ($id, $call): mixed {
                    $call['promise']->resolve($value);
                    $this->release($id);

                    return $value;
                },
                function (Throwable $e) use ($id, $call): null {
                    $call['promise']->reject($e);
                    $this->release($id);

                    return null;
                },
            );
        }

        // A call that failed to send let its paths go: whatever waited on them may start.
        if ($released) {
            $this->dispatch();
        }
    }

    private function release(int $id): void
    {
        unset($this->running[$id]);
        $this->dispatch();
    }

    /**
     * @param list<string> $paths
     */
    private function holds(array $paths): bool
    {
        return self::overlaps($paths, [...array_column($this->waiting, 'paths'), ...array_values($this->running)]);
    }

    /**
     * @param list<string> $paths
     * @param list<list<string>> $held
     */
    private static function overlaps(array $paths, array $held): bool
    {
        foreach ($held as $taken) {
            foreach ($taken as $other) {
                foreach ($paths as $path) {
                    if (self::conflict($path, $other)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** The same path, or one inside the other. The empty path is the root, inside which is everything. */
    private static function conflict(string $a, string $b): bool
    {
        $a = rtrim($a, '/');
        $b = rtrim($b, '/');

        return $a === $b || $a === '' || $b === ''
            || str_starts_with($b, $a.'/') || str_starts_with($a, $b.'/');
    }
}
