<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Ephpm\DoctrineDriver\Bridge\BridgeInterface;
use Ephpm\DoctrineDriver\Bridge\SapiBridge;
use Ephpm\DoctrineDriver\Exception\BridgeException;
use SensitiveParameter;

/**
 * Doctrine DBAL driver over ePHPm's in-process database bridge.
 *
 * Register it via DriverManager's `driverClass` parameter:
 *
 *     $conn = DriverManager::getConnection(['driverClass' => Driver::class]);
 *
 * Connection parameters: there is no socket, so `host`, `port`, `user`,
 * `password`, `dbname`, and `charset` are accepted and ignored — the bridge
 * always talks to the embedded database the ePHPm process was configured
 * with (`[db.sqlite]`). The only parameter this driver reads is
 * `driverOptions.bridge`, an optional {@see BridgeInterface} used by tests
 * to substitute a fake for the SAPI natives.
 *
 * Platform selection is inherited from {@see AbstractMySQLDriver} and keys
 * off {@see Connection::getServerVersion()}, which mirrors the MySQL
 * version litewire advertises in its wire handshake (`8.0.36-litewire`),
 * so DBAL selects the MySQL 8.0 platform.
 */
final class Driver extends AbstractMySQLDriver
{
    /** {@inheritDoc} */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $bridge = $params['driverOptions']['bridge'] ?? null;
        if ($bridge !== null && !$bridge instanceof BridgeInterface) {
            throw BridgeException::fromMessage(
                'driverOptions.bridge must implement ' . BridgeInterface::class,
            );
        }

        if ($bridge === null) {
            try {
                $bridge = new SapiBridge();
            } catch (\RuntimeException $e) {
                throw BridgeException::fromBridge($e);
            }
        }

        return new Connection($bridge);
    }

    public function getExceptionConverter(): ExceptionConverterInterface
    {
        return new ExceptionConverter();
    }
}
