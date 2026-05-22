<?php

declare(strict_types=1);

namespace Heptacom\HeptaConnect\Storage\ShopwareDal\JobPayload;

final class InMemoryJobPayloadStorage implements JobPayloadStorageInterface
{
    /**
     * @var array<string, string>
     */
    private array $storage = [];

    public function put(string $payloadId, string $payload): void
    {
        $this->storage[$payloadId] = $payload;
    }

    public function get(string $payloadId): ?JobPayloadStorageItem
    {
        if (!\array_key_exists($payloadId, $this->storage)) {
            return null;
        }

        return new JobPayloadStorageItem($this->storage[$payloadId]);
    }

    public function keyFor(string $payloadId): string
    {
        if (\strlen($payloadId) < 4) {
            throw new \InvalidArgumentException('Payload ID must be a hex string with at least 4 characters for directory sharding.');
        }

        return \sprintf(
            '%s/%s/%s/%s/%s',
            $payloadId[0],
            $payloadId[1],
            $payloadId[2],
            $payloadId[3],
            $payloadId
        );
    }
}
