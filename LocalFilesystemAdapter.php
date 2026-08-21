<?php

namespace Voyager\Filesystem;

use Closure;
use Voyager\NutsAndBolts\Concerns\Conditionable;
use RuntimeException;

class LocalFilesystemAdapter extends FilesystemAdapter
{
    use Conditionable;

    /**
     * The name of the filesystem disk.
     */
    protected ?string $disk = null;

    /**
     * Indicates if signed URLs should serve corresponding files.
     */
    protected bool $shouldServeSignedUrls = false;

    /**
     * The Closure that should be used to resolve the URL generator.
     */
    protected ?Closure $urlGeneratorResolver = null;

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return $this->temporaryUrlCallback || (
            $this->shouldServeSignedUrls && $this->urlGeneratorResolver instanceof Closure
        );
    }

    /**
     * Determine if temporary upload URLs can be generated.
     */
    public function providesTemporaryUploadUrls(): bool
    {
        return $this->temporaryUploadUrlCallback || (
            $this->shouldServeSignedUrls && $this->urlGeneratorResolver instanceof Closure
        );
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @param  string  $path
     * @param  \DateTimeInterface  $expiration
     * @param  array  $options
     * @return string
     *
     * @throws \RuntimeException
     */
    public function temporaryUrl($path, $expiration, array $options = [])
    {
        if ($this->temporaryUrlCallback) {
            return $this->temporaryUrlCallback->bindTo($this, static::class)(
                $path, $expiration, $options
            );
        }

        if (! $this->providesTemporaryUrls()) {
            throw new RuntimeException('This driver does not support creating temporary URLs.');
        }

        $url = call_user_func($this->urlGeneratorResolver);

        return $url->to($url->temporarySignedRoute(
            'storage.'.$this->disk,
            $expiration,
            ['path' => strtr(rawurlencode($path), ['%2F' => '/'])],
            absolute: false
        ));
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     *
     * @param  string  $path
     * @param  \DateTimeInterface  $expiration
     * @param  array  $options
     * @return array
     *
     * @throws \RuntimeException
     */
    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        if ($this->temporaryUploadUrlCallback) {
            return $this->temporaryUploadUrlCallback->bindTo($this, static::class)(
                $path, $expiration, $options
            );
        }

        if (! $this->providesTemporaryUploadUrls()) {
            throw new RuntimeException('This driver does not support creating temporary upload URLs.');
        }

        $url = call_user_func($this->urlGeneratorResolver);

        return [
            'url' => $url->to($url->temporarySignedRoute(
                'storage.'.$this->disk.'.upload',
                $expiration,
                ['path' => strtr(rawurlencode($path), ['%2F' => '/']), 'upload' => true],
                absolute: false
            )),
            'headers' => [],
        ];
    }

    /**
     * Specify the name of the disk the adapter is managing.
     *
     * @return $this
     */
    public function diskName(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Indicate that signed URLs should serve the corresponding files.
     *
     * @return $this
     */
    public function shouldServeSignedUrls(bool $serve = true, ?Closure $urlGeneratorResolver = null): static
    {
        $this->shouldServeSignedUrls = $serve;
        $this->urlGeneratorResolver = $urlGeneratorResolver;

        return $this;
    }
}
