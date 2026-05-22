<?php

declare(strict_types=1);

namespace Heptacom\HeptaConnect\Storage\ShopwareDal\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1678000000DropJobPayloadForeignKey extends MigrationStep
{
    public const UP = <<<'SQL'
ALTER TABLE `heptaconnect_job` DROP FOREIGN KEY `fk.heptaconnect_job.payload_id`;
SQL;

    public function getCreationTimestamp(): int
    {
        return 1678000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(self::UP);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
