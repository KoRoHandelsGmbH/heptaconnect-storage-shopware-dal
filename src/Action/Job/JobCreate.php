<?php

declare(strict_types=1);

namespace Heptacom\HeptaConnect\Storage\ShopwareDal\Action\Job;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Heptacom\HeptaConnect\Storage\Base\Action\Job\Create\JobCreatePayload;
use Heptacom\HeptaConnect\Storage\Base\Action\Job\Create\JobCreatePayloads;
use Heptacom\HeptaConnect\Storage\Base\Action\Job\Create\JobCreateResult;
use Heptacom\HeptaConnect\Storage\Base\Action\Job\Create\JobCreateResults;
use Heptacom\HeptaConnect\Storage\Base\Contract\Action\Job\JobCreateActionInterface;
use Heptacom\HeptaConnect\Storage\Base\Contract\JobKeyInterface;
use Heptacom\HeptaConnect\Storage\Base\Contract\StorageKeyGeneratorContract;
use Heptacom\HeptaConnect\Storage\Base\Exception\CreateException;
use Heptacom\HeptaConnect\Storage\Base\Exception\InvalidCreatePayloadException;
use Heptacom\HeptaConnect\Storage\Base\Exception\UnsupportedStorageKeyException;
use Heptacom\HeptaConnect\Storage\ShopwareDal\EntityTypeAccessor;
use Heptacom\HeptaConnect\Storage\ShopwareDal\JobPayload\JobPayloadStorageInterface;
use Heptacom\HeptaConnect\Storage\ShopwareDal\JobTypeAccessor;
use Heptacom\HeptaConnect\Storage\ShopwareDal\StorageKey\JobStorageKey;
use Heptacom\HeptaConnect\Storage\ShopwareDal\StorageKey\PortalNodeStorageKey;
use Heptacom\HeptaConnect\Storage\ShopwareDal\Support\DateTime;
use Heptacom\HeptaConnect\Storage\ShopwareDal\Support\Enum\JobStateEnum;
use Heptacom\HeptaConnect\Storage\ShopwareDal\Support\Id;

final class JobCreate implements JobCreateActionInterface
{
    private Connection $connection;

    private StorageKeyGeneratorContract $storageKeyGenerator;

    private JobTypeAccessor $jobTypes;

    private EntityTypeAccessor $entityTypes;

    private JobPayloadStorageInterface $jobPayloadStorage;

    public function __construct(
        Connection $connection,
        StorageKeyGeneratorContract $storageKeyGenerator,
        JobTypeAccessor $jobTypes,
        EntityTypeAccessor $entityTypes,
        JobPayloadStorageInterface $jobPayloadStorage
    ) {
        $this->connection = $connection;
        $this->storageKeyGenerator = $storageKeyGenerator;
        $this->jobTypes = $jobTypes;
        $this->entityTypes = $entityTypes;
        $this->jobPayloadStorage = $jobPayloadStorage;
    }

    public function create(JobCreatePayloads $payloads): JobCreateResults
    {
        $jobTypes = [];
        $entityTypes = [];

        /** @var JobCreatePayload $payload */
        foreach ($payloads as $payload) {
            $jobTypes[] = $payload->getJobType();
            $entityTypes[] = $payload->getMapping()->getEntityType();
            $portalNodeKey = $payload->getMapping()->getPortalNodeKey()->withoutAlias();

            if (!($portalNodeKey instanceof PortalNodeStorageKey)) {
                throw new InvalidCreatePayloadException($payload, 1639268730, new UnsupportedStorageKeyException(\get_class($portalNodeKey)));
            }
        }

        $jobTypeIds = $this->jobTypes->getIdsForTypes($jobTypes);
        $entityTypeIds = $this->entityTypes->getIdsForTypes($entityTypes);

        foreach ($jobTypes as $jobType) {
            if (!\array_key_exists($jobType, $jobTypeIds)) {
                /** @var \Heptacom\HeptaConnect\Storage\Base\Action\Job\Create\JobCreatePayload $payload */
                foreach ($payloads as $payload) {
                    if ($payload->getJobType() === $jobType) {
                        throw new InvalidCreatePayloadException($payload, 1639268731);
                    }
                }
            }
        }

        foreach ($entityTypes as $entityType) {
            if (!\array_key_exists($entityType, $entityTypeIds)) {
                /** @var \Heptacom\HeptaConnect\Storage\Base\Action\Job\Create\JobCreatePayload $payload */
                foreach ($payloads as $payload) {
                    if ($payload->getMapping()->getEntityType() === $entityType) {
                        throw new InvalidCreatePayloadException($payload, 1639268732);
                    }
                }
            }
        }

        $result = new JobCreateResults();

        $this->connection->transactional(function () use ($payloads, $result, $entityTypeIds, $jobTypeIds): void {
            $keys = new \ArrayIterator(\iterable_to_array($this->storageKeyGenerator->generateKeys(JobKeyInterface::class, $payloads->count())));
            $now = DateTime::nowToStorage();
            $jobInserts = [];
            $payloadUploads = [];

            /** @var JobCreatePayload $payload */
            foreach ($payloads as $payload) {
                $jobTypeId = $jobTypeIds[$payload->getJobType()];
                $entityTypeId = $entityTypeIds[$payload->getMapping()->getEntityType()];
                /** @var PortalNodeStorageKey $portalNodeKey */
                $portalNodeKey = $payload->getMapping()->getPortalNodeKey()->withoutAlias();

                $key = $keys->current();
                $keys->next();

                if (!$key instanceof JobStorageKey) {
                    throw new InvalidCreatePayloadException($payload, 1639268733, new UnsupportedStorageKeyException(\get_class($key)));
                }

                $jobPayloadBinaryId = null;
                $jobPayload = $payload->getJobPayload();

                if ($jobPayload !== null) {
                    $jobPayloadBinaryId = Id::randomBinary();
                    $payloadUploads[] = [
                        'id' => Id::toHex($jobPayloadBinaryId),
                        'data' => \gzcompress(\serialize($jobPayload)),
                    ];
                }

                $jobInserts[] = [
                    'id' => Id::toBinary($key->getUuid()),
                    'external_id' => $payload->getMapping()->getExternalId(),
                    'portal_node_id' => Id::toBinary($portalNodeKey->getUuid()),
                    'entity_type_id' => Id::toBinary($entityTypeId),
                    'job_type_id' => Id::toBinary($jobTypeId),
                    'payload_id' => $jobPayloadBinaryId,
                    'state_id' => JobStateEnum::open(),
                    'created_at' => $now,
                ];

                $result->push([new JobCreateResult($key)]);
            }

            // Upload payloads to object storage before inserting DB rows.
            // If upload fails, throw CreateException. If DB insert fails after upload, uploaded objects may be orphaned.
            try {
                foreach ($payloadUploads as $upload) {
                    $this->jobPayloadStorage->put($upload['id'], $upload['data']);
                }
            } catch (\Throwable $throwable) {
                throw new CreateException(1678000002, $throwable);
            }

            try {
                $this->connection->transactional(function () use ($jobInserts): void {
                    foreach ($jobInserts as $insert) {
                        $this->connection->insert('heptaconnect_job', $insert, [
                            'id' => Types::BINARY,
                            'portal_node_id' => Types::BINARY,
                            'entity_type_id' => Types::BINARY,
                            'job_type_id' => Types::BINARY,
                            'payload_id' => Types::BINARY,
                            'state_id' => Types::BINARY,
                        ]);
                    }
                });
            } catch (\Throwable $throwable) {
                throw new CreateException(1639268734, $throwable);
            }
        });

        return $result;
    }
}
