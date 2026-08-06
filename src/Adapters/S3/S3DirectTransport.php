<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\S3;

use Aws\S3\S3Client;
use NETipar\Chunky\Exceptions\InvalidConfigurationException;
use NETipar\Chunky\Ports\DirectUploadTransport;
use NETipar\Chunky\Support\Coerce;

/**
 * DirectUploadTransport over the AWS SDK. Works against AWS S3 and
 * S3-compatible targets (MinIO, Cloudflare R2) via the disk's endpoint /
 * use_path_style_endpoint settings. Presigning is a local signing operation —
 * no network round-trip per URL.
 */
final class S3DirectTransport implements DirectUploadTransport
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $root,
        private readonly int $urlTtl,
    ) {}

    /**
     * Build from a Laravel s3 filesystem disk config array.
     *
     * @param  array<string, mixed>  $diskConfig
     */
    public static function fromDiskConfig(array $diskConfig, int $urlTtl): self
    {
        if (! class_exists(S3Client::class)) {
            throw InvalidConfigurationException::forKey(
                'transports.direct_s3',
                'requires aws/aws-sdk-php (composer require aws/aws-sdk-php).',
            );
        }

        $bucket = Coerce::toString($diskConfig['bucket'] ?? '');

        if ($bucket === '') {
            throw InvalidConfigurationException::forKey('transports.direct_s3.disk', 'must point to an s3 disk with a bucket.');
        }

        $options = [
            'version' => '2006-03-01',
            'region' => Coerce::toString($diskConfig['region'] ?? '') !== '' ? Coerce::toString($diskConfig['region']) : 'us-east-1',
        ];

        $key = Coerce::toString($diskConfig['key'] ?? '');
        $secret = Coerce::toString($diskConfig['secret'] ?? '');

        if ($key !== '' && $secret !== '') {
            $options['credentials'] = ['key' => $key, 'secret' => $secret];
        }

        $endpoint = Coerce::toString($diskConfig['endpoint'] ?? '');

        if ($endpoint !== '') {
            $options['endpoint'] = $endpoint;
        }

        if (($diskConfig['use_path_style_endpoint'] ?? false) === true) {
            $options['use_path_style_endpoint'] = true;
        }

        return new self(
            new S3Client($options),
            $bucket,
            trim(Coerce::toString($diskConfig['root'] ?? ''), '/'),
            $urlTtl,
        );
    }

    public function create(string $key, ?string $mimeType): string
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key' => $this->objectKey($key),
        ];

        if ($mimeType !== null && $mimeType !== '') {
            $params['ContentType'] = $mimeType;
        }

        $result = $this->client->createMultipartUpload($params);

        return Coerce::toString($result['UploadId']);
    }

    public function presignParts(string $key, string $remoteUploadId, array $indexes): array
    {
        $urls = [];

        foreach ($indexes as $index) {
            $command = $this->client->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
                'UploadId' => $remoteUploadId,
                'PartNumber' => $index + 1,
            ]);

            $urls[$index] = (string) $this->client->createPresignedRequest($command, "+{$this->urlTtl} seconds")->getUri();
        }

        return $urls;
    }

    public function listParts(string $key, string $remoteUploadId): array
    {
        $parts = [];
        $marker = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
                'UploadId' => $remoteUploadId,
            ];

            if ($marker !== null) {
                $params['PartNumberMarker'] = $marker;
            }

            $result = $this->client->listParts($params);

            foreach (is_array($result['Parts'] ?? null) ? $result['Parts'] : [] as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $number = Coerce::toInt($part['PartNumber'] ?? 0);

                if ($number > 0) {
                    $parts[$number - 1] = Coerce::toString($part['ETag'] ?? '');
                }
            }

            $marker = ($result['IsTruncated'] ?? false) === true
                ? Coerce::toInt($result['NextPartNumberMarker'] ?? 0)
                : null;
        } while ($marker !== null);

        return $parts;
    }

    public function complete(string $key, string $remoteUploadId, array $parts): void
    {
        ksort($parts);

        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $this->objectKey($key),
            'UploadId' => $remoteUploadId,
            'MultipartUpload' => [
                'Parts' => array_values(array_map(
                    static fn (int $index, string $etag): array => ['PartNumber' => $index + 1, 'ETag' => $etag],
                    array_keys($parts),
                    $parts,
                )),
            ],
        ]);
    }

    public function abort(string $key, string $remoteUploadId): void
    {
        $this->client->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $this->objectKey($key),
            'UploadId' => $remoteUploadId,
        ]);
    }

    public function size(string $key): int
    {
        $result = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $this->objectKey($key),
        ]);

        return Coerce::toInt($result['ContentLength'] ?? 0);
    }

    private function objectKey(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->root === '' ? $path : "{$this->root}/{$path}";
    }
}
