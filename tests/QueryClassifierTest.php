<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Ephpm\DoctrineDriver\QueryClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT 1', true];
        yield 'select lowercase' => ['select * from t', true];
        yield 'select leading whitespace' => ["  \n\tSELECT 1", true];
        yield 'select leading paren' => ['(SELECT 1) UNION (SELECT 2)', true];
        yield 'select line comment' => ["-- hello\nSELECT 1", true];
        yield 'select hash comment' => ["# hello\nSELECT 1", true];
        yield 'select block comment' => ['/* hi */ SELECT 1', true];
        yield 'show' => ['SHOW TABLES', true];
        yield 'describe' => ['DESCRIBE users', true];
        yield 'explain' => ['EXPLAIN SELECT 1', true];
        yield 'with (cte)' => ['WITH x AS (SELECT 1) SELECT * FROM x', true];
        yield 'insert' => ['INSERT INTO t VALUES (1)', false];
        yield 'update' => ['UPDATE t SET a = 1', false];
        yield 'delete' => ['DELETE FROM t', false];
        yield 'create' => ['CREATE TABLE t (a INT)', false];
        yield 'drop' => ['DROP TABLE t', false];
        yield 'begin' => ['BEGIN', false];
        yield 'commit' => ['COMMIT', false];
        yield 'savepoint' => ['SAVEPOINT DOCTRINE_2', false];
        yield 'set' => ["SET NAMES 'utf8mb4'", false];
        yield 'empty' => ['', false];
        yield 'only comment' => ['-- nothing', false];
    }

    #[DataProvider('statements')]
    public function testClassification(string $sql, bool $expected): void
    {
        self::assertSame($expected, QueryClassifier::returnsRows($sql));
    }
}
