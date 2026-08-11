<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\ServerVersionProvider;
use Ephpm\DoctrineDriver\Bridge\BridgeInterface;
use Ephpm\DoctrineDriver\Bridge\PdoSqliteBridge;
use Ephpm\DoctrineDriver\Connection;
use Ephpm\DoctrineDriver\Driver;
use Ephpm\DoctrineDriver\Exception\BridgeException;
use Ephpm\DoctrineDriver\ExceptionConverter;
use PHPUnit\Framework\TestCase;

final class DriverTest extends TestCase
{
    public function testSelectsMySQL80PlatformForAdvertisedHandshakeVersion(): void
    {
        $driver = new Driver();
        $provider = new class implements ServerVersionProvider {
            public function getServerVersion(): string
            {
                return Connection::SERVER_VERSION;
            }
        };

        self::assertInstanceOf(MySQL80Platform::class, $driver->getDatabasePlatform($provider));
    }

    public function testWrapperConnectionSelectsMySQLPlatform(): void
    {
        $connection = TestConnectionFactory::wrapperConnection();

        self::assertInstanceOf(MySQL80Platform::class, $connection->getDatabasePlatform());
    }

    public function testServerVersionMirrorsLitewireHandshake(): void
    {
        $connection = (new Driver())->connect([
            'driverOptions' => ['bridge' => new PdoSqliteBridge()],
        ]);

        self::assertSame('8.0.36-litewire', $connection->getServerVersion());
    }

    public function testExceptionConverterIsOurs(): void
    {
        self::assertInstanceOf(ExceptionConverter::class, (new Driver())->getExceptionConverter());
    }

    public function testHostAndPortParamsAreAcceptedAndIgnored(): void
    {
        $bridge = new PdoSqliteBridge();
        $connection = (new Driver())->connect([
            'host' => 'db.example.com',
            'port' => 3306,
            'user' => 'ignored',
            'password' => 'ignored',
            'dbname' => 'ignored',
            'driverOptions' => ['bridge' => $bridge],
        ]);

        self::assertSame($bridge, $connection->getNativeConnection());
        self::assertSame([['answer' => 1]], $bridge->query('SELECT 1 AS answer'));
    }

    public function testRejectsForeignBridgeObject(): void
    {
        $this->expectException(BridgeException::class);
        $this->expectExceptionMessage(BridgeInterface::class);

        (new Driver())->connect(['driverOptions' => ['bridge' => new \stdClass()]]);
    }
}
