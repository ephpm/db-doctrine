<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ephpm\DoctrineDriver\Exception\BridgeException;
use Ephpm\DoctrineDriver\ExceptionConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionConverterTest extends TestCase
{
    /** @return iterable<string, array{int, class-string<DriverException>}> */
    public static function errnoMappings(): iterable
    {
        yield '1062 duplicate entry' => [1062, UniqueConstraintViolationException::class];
        yield '1064 parse error' => [1064, SyntaxErrorException::class];
        yield '1205 lock wait timeout' => [1205, LockWaitTimeoutException::class];
        yield '1290 read only' => [1290, ReadOnlyException::class];
        yield '1452 foreign key' => [1452, ForeignKeyConstraintViolationException::class];
        yield '1105 unknown error fallback' => [1105, DriverException::class];
        yield 'unmapped code fallback' => [9999, DriverException::class];
    }

    /** @param class-string<DriverException> $expectedClass */
    #[DataProvider('errnoMappings')]
    public function testMapsErrnoToDbalException(int $errno, string $expectedClass): void
    {
        $converter = new ExceptionConverter();
        $driverException = BridgeException::fromBridge(
            new \Exception('SQLSTATE[HY000]: something happened', $errno),
        );

        $converted = $converter->convert($driverException, null);

        self::assertSame($expectedClass, $converted::class);
        self::assertSame($errno, $converted->getCode());
        self::assertSame('HY000', $converted->getSQLState());
    }
}
