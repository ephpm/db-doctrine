<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Ephpm\DoctrineDriver\Exception\BridgeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function quoteCases(): iterable
    {
        yield 'plain' => ['hello', "'hello'"];
        // Single quote is DOUBLED, not backslash-escaped: litewire's tenant
        // parser rejects `\'` as malformed SQL (db-wordpress issue #1).
        yield 'single quote' => ["O'Brien", "'O''Brien'"];
        yield 'double quote' => ['say "hi"', "'say \\\"hi\\\"'"];
        // A quote adjacent to a backslash still round-trips unambiguously:
        // the backslash is doubled and the quote is doubled independently.
        yield 'backslash then quote' => ["a\\'b", "'a\\\\''b'"];
        yield 'backslash' => ['a\\b', "'a\\\\b'"];
        yield 'newline' => ["a\nb", "'a\\nb'"];
        yield 'carriage return' => ["a\rb", "'a\\rb'"];
        yield 'nul byte' => ["a\0b", "'a\\0b'"];
        yield 'ctrl-z' => ["a\x1ab", "'a\\Zb'"];
        yield 'empty' => ['', "''"];
    }

    #[DataProvider('quoteCases')]
    public function testQuoteEscapesMySQLStyle(string $input, string $expected): void
    {
        self::assertSame($expected, TestConnectionFactory::driverConnection()->quote($input));
    }

    public function testExecReturnsAffectedRows(): void
    {
        $conn = TestConnectionFactory::driverConnection();
        $conn->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v VARCHAR(10))');
        $conn->exec("INSERT INTO t (v) VALUES ('a')");
        $conn->exec("INSERT INTO t (v) VALUES ('b')");

        self::assertSame(2, $conn->exec("UPDATE t SET v = 'z'"));
        self::assertSame(2, $conn->exec('DELETE FROM t'));
    }

    public function testLastInsertIdReflectsMostRecentWrite(): void
    {
        $conn = TestConnectionFactory::driverConnection();
        $conn->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v VARCHAR(10))');

        $conn->exec("INSERT INTO t (v) VALUES ('a')");
        self::assertSame(1, $conn->lastInsertId());

        $conn->exec("INSERT INTO t (v) VALUES ('b')");
        self::assertSame(2, $conn->lastInsertId());

        // Row-returning statements do not touch the cache.
        $conn->query('SELECT * FROM t');
        self::assertSame(2, $conn->lastInsertId());
    }

    public function testTransactionCommitAndRollback(): void
    {
        $conn = TestConnectionFactory::driverConnection();
        $conn->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v VARCHAR(10))');

        $conn->beginTransaction();
        $conn->exec("INSERT INTO t (v) VALUES ('kept')");
        $conn->commit();

        $conn->beginTransaction();
        $conn->exec("INSERT INTO t (v) VALUES ('dropped')");
        $conn->rollBack();

        $rows = $conn->query('SELECT v FROM t')->fetchFirstColumn();
        self::assertSame(['kept'], $rows);
    }

    public function testQueryErrorThrowsBridgeExceptionWithSqlState(): void
    {
        $conn = TestConnectionFactory::driverConnection();

        try {
            $conn->query('SELECT nope FROM does_not_exist');
            self::fail('Expected BridgeException');
        } catch (BridgeException $e) {
            self::assertNotNull($e->getSQLState());
            self::assertStringStartsWith('SQLSTATE[', $e->getMessage());
        }
    }
}
