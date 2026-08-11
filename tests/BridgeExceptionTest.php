<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Ephpm\DoctrineDriver\Exception\BridgeException;
use PHPUnit\Framework\TestCase;

final class BridgeExceptionTest extends TestCase
{
    public function testParsesSqlStateFromMessagePrefix(): void
    {
        $native = new \Exception('SQLSTATE[23000]: UNIQUE constraint failed: t.a', 1062);
        $exception = BridgeException::fromBridge($native);

        self::assertInstanceOf(DriverExceptionInterface::class, $exception);
        self::assertSame('23000', $exception->getSQLState());
        self::assertSame(1062, $exception->getCode());
        self::assertSame($native->getMessage(), $exception->getMessage());
        self::assertSame($native, $exception->getPrevious());
    }

    public function testNoSqlStateWhenMessageHasNoPrefix(): void
    {
        $native = new \Exception('ephpm_db: no embedded database is active (requires [db.sqlite])');
        $exception = BridgeException::fromBridge($native);

        self::assertNull($exception->getSQLState());
    }
}
