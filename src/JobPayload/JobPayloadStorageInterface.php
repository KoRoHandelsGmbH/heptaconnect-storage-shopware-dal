<?php

declare(strict_types=1);

namespace Heptacom\HeptaConnect\Storage\ShopwareDal\JobPayload;

interface JobPayloadStorageInterface
{
    public function put(string $payloadId, string $payload): void;

    public function get(string $payloadId): ?JobPayloadStorageItem;

    public function keyFor(string $payloadId): string;
}
