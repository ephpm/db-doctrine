<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\Exception\InvalidColumnIndex;
use Ephpm\DoctrineDriver\Connection;
use Ephpm\DoctrineDriver\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    private function seeded(): Connection
    {
        $conn = TestConnectionFactory::driverConnection();
        $conn->exec('CREATE TABLE nums (id INTEGER PRIMARY KEY, label VARCHAR(10), ratio DOUBLE)');
        $conn->exec("INSERT INTO nums (label, ratio) VALUES ('one', 0.5)");
        $conn->exec("INSERT INTO nums (label, ratio) VALUES ('two', 1.5)");
        $conn->exec("INSERT INTO nums (label, ratio) VALUES (NULL, NULL)");

        return $conn;
    }

    public function testFetchAssociativeAdvancesCursor(): void
    {
        $result = $this->seeded()->query('SELECT id, label FROM nums ORDER BY id');

        self::assertSame(['id' => 1, 'label' => 'one'], $result->fetchAssociative());
        self::assertSame(['id' => 2, 'label' => 'two'], $result->fetchAssociative());
        self::assertSame(['id' => 3, 'label' => null], $result->fetchAssociative());
        self::assertFalse($result->fetchAssociative());
    }

    public function testFetchNumericPreservesSelectListOrder(): void
    {
        $result = $this->seeded()->query('SELECT label, id, ratio FROM nums ORDER BY id');

        self::assertSame(['one', 1, 0.5], $result->fetchNumeric());
        self::assertSame(['two', 2, 1.5], $result->fetchNumeric());
    }

    public function testFetchOne(): void
    {
        $conn = $this->seeded();
        self::assertSame(3, $conn->query('SELECT COUNT(*) FROM nums')->fetchOne());
        self::assertFalse($conn->query('SELECT id FROM nums WHERE id = 99')->fetchOne());
    }

    public function testFetchAllVariants(): void
    {
        $conn = $this->seeded();

        self::assertSame(
            [['id' => 1, 'label' => 'one'], ['id' => 2, 'label' => 'two']],
            $conn->query('SELECT id, label FROM nums WHERE id < 3 ORDER BY id')->fetchAllAssociative(),
        );
        self::assertSame(
            [[1, 'one'], [2, 'two']],
            $conn->query('SELECT id, label FROM nums WHERE id < 3 ORDER BY id')->fetchAllNumeric(),
        );
        self::assertSame(
            ['one', 'two', null],
            $conn->query('SELECT label FROM nums ORDER BY id')->fetchFirstColumn(),
        );
    }

    public function testNativeScalarTypesComeThrough(): void
    {
        $row = $this->seeded()
            ->query('SELECT id, label, ratio FROM nums WHERE id = 1')
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertIsInt($row['id']);
        self::assertIsString($row['label']);
        self::assertIsFloat($row['ratio']);
    }

    public function testRowCountIsRowCountForSelects(): void
    {
        self::assertSame(3, $this->seeded()->query('SELECT * FROM nums')->rowCount());
    }

    public function testRowCountIsAffectedRowsForWrites(): void
    {
        self::assertSame(2, Result::forWrite(2)->rowCount());
    }

    public function testColumnCountAndNames(): void
    {
        $result = $this->seeded()->query('SELECT label, id FROM nums LIMIT 1');

        self::assertSame(2, $result->columnCount());
        self::assertSame('label', $result->getColumnName(0));
        self::assertSame('id', $result->getColumnName(1));

        $this->expectException(InvalidColumnIndex::class);
        $result->getColumnName(2);
    }

    public function testEmptyResultSetStillCarriesColumnMetadata(): void
    {
        // Column metadata now comes from the executed statement
        // (ephpm_db_run(), issue #262), so a zero-row result set reports its
        // columns — the former "no column metadata without rows" limitation
        // is gone.
        $result = $this->seeded()->query('SELECT id, label FROM nums WHERE id = 99');

        self::assertSame(0, $result->rowCount());
        self::assertFalse($result->fetchAssociative());
        self::assertSame(2, $result->columnCount());
        self::assertSame('id', $result->getColumnName(0));
        self::assertSame('label', $result->getColumnName(1));
    }

    public function testFreeDiscardsRows(): void
    {
        $result = $this->seeded()->query('SELECT * FROM nums');
        $result->free();

        self::assertFalse($result->fetchAssociative());
    }
}
