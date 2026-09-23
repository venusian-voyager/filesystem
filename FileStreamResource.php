<?php

namespace Voyager\Filesystem;

use Throwable;
use Voyager\Contracts\IOPools\Event;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\Pumpable;
use Voyager\Contracts\IOPools\Tickable;
use Voyager\Contracts\IOPools\WorkerPool;

/**
 * A file read in chunks by pool workers, each chunk pumped as a FileChunk the turn it lands.
 * Keeps up to $in_flight chunk gigs out at once and hands them over in offset order. Forgets
 * itself from the loop once the last chunk is delivered, so it never holds a run open.
 */
final class FileStreamResource implements Tickable, Pumpable
{
    private ?int $size = null;
    private int $next_offset = 0;              // next chunk to request
    private int $next_deliver = 0;             // next chunk to hand over
    /** @var array<int, Promise> offset => in-flight gig */
    private array $pending = [];
    /** @var array<int, string> offset => landed bytes not yet delivered */
    private array $landed = [];
    /** @var Event[] */
    private array $mail = [];
    private Promise $done;
    private bool $finished = false;

    public function __construct(
        private readonly Loop $loop,
        private readonly WorkerPool $pool,
        private readonly string $disk,
        private readonly string $path,
        private readonly int $chunk = 1 << 20,
        private readonly int $in_flight = 2,
    ) {
        $this->done = $this->loop->promise();
        $this->loop->resource($this->name(), $this);       // registered for its lifetime, forgotten by finish()

        $this->pool->submit(new DiskGig($disk, 'size', [$path]))
            ->then(function (int $size) { $this->size = $size; $this->request(); })
            ->error(fn (Throwable $e) => $this->fail($e));
    }

    /** Resolves with the total bytes delivered once the last chunk is out; rejects on any failed read. */
    public function done(): Promise
    {
        return $this->done;
    }

    public function tick(): void
    {
        // gigs settle through the promise engine, not here; tick only keeps the pipeline full
        $this->request();
    }

    public function pump(): array
    {
        [$mail, $this->mail] = [$this->mail, []];

        return $mail;
    }

    private function request(): void
    {
        if ($this->finished || is_null($this->size)) {
            return;
        }

        while (count($this->pending) < $this->in_flight && $this->next_offset < max($this->size, 1))
        {
            $offset = $this->next_offset;
            $this->next_offset += $this->chunk;

            $this->pending[$offset] = $this->pool->submit(new DiskGig($this->disk, 'readRange', [$this->path, $offset, $this->chunk]))
                ->then(function (string $bytes) use ($offset) { $this->landed[$offset] = $bytes; unset($this->pending[$offset]); $this->deliver(); $this->request(); })
                ->error(fn (Throwable $e) => $this->fail($e));

            if ($this->size === 0) break;                     // an empty file: one empty chunk, then done
        }
    }

    private function deliver(): void
    {
        while (isset($this->landed[$this->next_deliver]))
        {
            $bytes = $this->landed[$this->next_deliver];
            unset($this->landed[$this->next_deliver]);

            $offset = $this->next_deliver;
            $this->next_deliver += $this->chunk;
            $last = $this->next_deliver >= $this->size;

            $this->mail[] = new FileChunk($this->disk, $this->path, $offset, $bytes, $last);

            if ($last) {
                $this->finish();
                $this->done->resolve($this->size);
                return;
            }
        }
    }

    private function fail(Throwable $e): void
    {
        if (! $this->finished) {
            $this->finish();
            $this->done->reject($e);
        }
    }

    private function finish(): void
    {
        $this->finished = true;
        $this->pending = [];
        $this->loop->forget($this->name());
    }

    public function name(): string
    {
        return 'stream:'.$this->disk.':'.$this->path;
    }
}
