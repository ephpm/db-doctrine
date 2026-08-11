<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\Connection as WrapperConnection;
use Doctrine\DBAL\DriverManager;
use Ephpm\DoctrineDriver\Bridge\PdoSqliteBridge;
use Ephpm\DoctrineDriver\Connection;
use Ephpm\DoctrineDriver\Driver;

/**
 * Builds driver-level and wrapper-level connections routed through the
 * pdo_sqlite fake of the ephpm_db_* natives.
 */
final class TestConnectionFactory
{
    public static function driverConnection(): Connection
    {
        return new Connection(new PdoSqliteBridge());
    }

    public static function wrapperConnection(): WrapperConnection
    {
        return DriverManager::getConnection([
            'driverClass' => Driver::class,
            'driverOptions' => ['bridge' => new PdoSqliteBridge()],
        ]);
    }
}
