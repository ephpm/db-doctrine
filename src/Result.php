<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver;

use Doctrine\DBAL\Driver\Result as DriverResultInterface;
use Doctrine\DBAL\Exception\InvalidColumnIndex;

/**
 * Driver-level result over rows the bridge already materialized.
 *
 * The bridge returns complete result sets as PHP arrays (there is no
 * cursor to stream from), so this class just walks them. Column order:
 * the bridge's associative rows preserve insertion order, which is the
 * SELECT-list order — fetchNumeric()/getColumnName() rely on that.
 *
 * Known limitation: the bridge carries no column metadata separate from
 * the rows, so a result set with zero rows reports columnCount() = 0 and
 * has no column names.
 */
final class Result implements DriverResultInterface
{
    private int $cursor = 0;

    /** @var list<string> */
    private array $columnNames;

    /**
     * @param list<array<string, float|int|string|null>> $rows
     * @param int $affectedRows rowCount() source: the row count for
     *                          row-returning statements, affected_rows
     *                          for writes
     */
    private function __construct(
        private array $rows,
        private readonly int $affectedRows,
    ) {
        $this->columnNames = $rows === [] ? [] : \array_map(\strval(...), \array_keys($rows[0]));
    }

    /** @param list<array<string, float|int|string|null>> $rows */
    public static function forRows(array $rows): self
    {
        return new self($rows, \count($rows));
    }

    public static function forWrite(int $affectedRows): self
    {
        return new self([], $affectedRows);
    }

    public function fetchNumeric(): array|false
    {
        $row = $this->fetchAssociative();

        return $row === false ? false : \array_values($row);
    }

    public function fetchAssociative(): array|false
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }

        return $this->rows[$this->cursor++];
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        return $row === false ? false : ($row[0] ?? null);
    }

    /** {@inheritDoc} */
    public function fetchAllNumeric(): array
    {
        $result = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $result[] = $row;
        }

        return $result;
    }

    /** {@inheritDoc} */
    public function fetchAllAssociative(): array
    {
        $result = [];
        while (($row = $this->fetchAssociative()) !== false) {
            $result[] = $row;
        }

        return $result;
    }

    /** {@inheritDoc} */
    public function fetchFirstColumn(): array
    {
        $result = [];
        while (($value = $this->fetchOne()) !== false) {
            $result[] = $value;
        }

        return $result;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    public function columnCount(): int
    {
        return \count($this->columnNames);
    }

    public function getColumnName(int $index): string
    {
        return $this->columnNames[$index] ?? throw InvalidColumnIndex::new($index);
    }

    public function free(): void
    {
        $this->rows = [];
        $this->cursor = 0;
    }
}
