<?php

namespace Voyager\Filesystem;

use Aws\S3\S3Client;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;

/**
 * An S3 disk: public URLs from the bucket, and temporary URLs presigned by the S3 client.
 * Needs aws/aws-sdk-php and league/flysystem-aws-s3-v3.
 */
class AwsS3V3Adapter extends FilesystemAdapter
{
    protected S3Client $client;

    public function __construct(FilesystemOperator $driver, FlysystemAdapter $adapter, array $config, S3Client $client)
    {
        $config['directory_separator'] = '/';

        parent::__construct($driver, $adapter, $config);

        $this->client = $client;
    }

    public function url($path): string
    {
        // An explicit base URL on the disk's config wins over the bucket's own.
        if (isset($this->config['url'])) {
            return $this->concatPathToUrl($this->config['url'], $this->prefixer->prefixPath($path));
        }

        return $this->client->getObjectUrl(
            $this->config['bucket'], $this->prefixer->prefixPath($path)
        );
    }

    public function providesTemporaryUrls(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        $command = $this->client->getCommand('GetObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options));

        $uri = $this->client->createPresignedRequest($command, $expiration, $options)->getUri();

        if (isset($this->config['temporary_url'])) {
            $uri = $this->replaceBaseUrl($uri, $this->config['temporary_url']);
        }

        return (string) $uri;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{url: string, headers: array<string, list<string>>}
     */
    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        $command = $this->client->getCommand('PutObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options));

        $signed = $this->client->createPresignedRequest($command, $expiration, $options);
        $uri = $signed->getUri();

        if (isset($this->config['temporary_url'])) {
            $uri = $this->replaceBaseUrl($uri, $this->config['temporary_url']);
        }

        return [
            'url' => (string) $uri,
            'headers' => $signed->getHeaders(),
        ];
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }
}
