<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\ParameterType;
use Ephpm\DoctrineDriver\Connection;
use Ephpm\DoctrineDriver\Exception\BridgeException;
use PHPUnit\Framework\TestCase;

final class StatementTest extends TestCase
{
    private function connectionWithTable(): Connection
    {
        $conn = TestConnectionFactory::driverConnection();
        $conn->exec('CREATE TABLE vals (id INTEGER PRIMARY KEY, v)');

        return $conn;
    }

    private function roundTrip(Connection $conn, mixed $value, ParameterType $type): mixed
    {
        $insert = $conn->prepare('INSERT INTO vals (v) VALUES (?)');
        $insert->bindValue(1, $value, $type);
        $insert->execute();

        $select = $conn->prepare('SELECT v FROM vals WHERE id = ?');
        $select->bindValue(1, $conn->lastInsertId(), ParameterType::INTEGER);

        return $select->execute()->fetchOne();
    }

    public function testBooleanBindsAsInt(): void
    {
        $conn = $this->connectionWithTable();
        self::assertSame(1, $this->roundTrip($conn, true, ParameterType::BOOLEAN));
        self::assertSame(0, $this->roundTrip($conn, false, ParameterType::BOOLEAN));
    }

    public function testIntegerCoercesNumericString(): void
    {
        $conn = $this->connectionWithTable();
        self::assertSame(42, $this->roundTrip($conn, '42', ParameterType::INTEGER));
    }

    public function testNullBindsAsNullRegardlessOfType(): void
    {
        $conn = $this->connectionWithTable();
        self::assertNull($this->roundTrip($conn, null, ParameterType::STRING));
        self::assertNull($this->roundTrip($conn, null, ParameterType::INTEGER));
    }

    public function testStringRoundTrips(): void
    {
        $conn = $this->connectionWithTable();
        self::assertSame('héllo wörld', $this->roundTrip($conn, 'héllo wörld', ParameterType::STRING));
    }

    public function testBinaryStringPassesThrough(): void
    {
        $conn = $this->connectionWithTable();
        $binary = "\x00\x01\x02\xff";
        self::assertSame($binary, $this->roundTrip($conn, $binary, ParameterType::BINARY));
    }

    public function testLargeObjectAcceptsStreamResource(): void
    {
        $conn = $this->connectionWithTable();
        $stream = \fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        \fwrite($stream, 'blob-contents');
        \rewind($stream);

        self::assertSame('blob-contents', $this->roundTrip($conn, $stream, ParameterType::LARGE_OBJECT));
    }

    public function testWriteStatementResultReportsAffectedRows(): void
    {
        $conn = $this->connectionWithTable();
        $conn->exec("INSERT INTO vals (v) VALUES ('a')");
        $conn->exec("INSERT INTO vals (v) VALUES ('b')");

        $update = $conn->prepare('UPDATE vals SET v = ?');
        $update->bindValue(1, 'z', ParameterType::STRING);
        $result = $update->execute();

        self::assertSame(2, $result->rowCount());
    }

    public function testNamedParametersAreRejectedAtDriverLevel(): void
    {
        $conn = $this->connectionWithTable();
        $stmt = $conn->prepare('SELECT v FROM vals WHERE v = :name');

        $this->expectException(BridgeException::class);
        $this->expectExceptionMessage('positional');
        $stmt->bindValue('name', 'x', ParameterType::STRING);
    }
}
