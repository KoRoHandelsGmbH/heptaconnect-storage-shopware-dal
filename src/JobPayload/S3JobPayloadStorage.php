<?php

declare(strict_types=1);

namespace Heptacom\HeptaConnect\Storage\ShopwareDal\JobPayload;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Heptacom\HeptaConnect\Storage\Base\Exception\CreateException;

final class S3JobPayloadStorage implements JobPayloadStorageInterface
{
    private S3ClientInterface $client;

    private string $bucket;

    private string $prefix;

    public function __construct(S3ClientInterface $client, string $bucket, string $prefix = '')
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->prefix = $prefix;
    }

    public function put(string $payloadId, string $payload): void
    {
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->keyFor($payloadId),
                'Body' => $payload,
            ]);
        } catch (S3Exception $exception) {
            throw new CreateException(1678000001, $exception);
        }
    }

    public function get(string $payloadId): ?JobPayloadStorageItem
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->keyFor($payloadId),
            ]);

            return new JobPayloadStorageItem((string) $result['Body']);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchKey') {
                return null;
            }

            throw $exception;
        }
    }

    public function keyFor(string $payloadId): string
    {
        if (\strlen($payloadId) < 4) {
            throw new \InvalidArgumentException('Payload id must contain at least 4 characters.');
        }

        $path = \sprintf(
            '%s/%s/%s/%s/%s',
            $payloadId[0],
            $payloadId[1],
            $payloadId[2],
            $payloadId[3],
            $payloadId
        );

        return $this->prefix !== '' ? $this->prefix . '/' . $path : $path;
    }
}
