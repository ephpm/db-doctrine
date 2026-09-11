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
 * Column metadata comes from the executed statement via `ephpm_db_run()`,
 * so a result set with zero rows still reports its column count and names
 * (ePHPm issue #262) — the former "no column metadata without rows"
 * limitation is gone.
 */
final class Result implements DriverResultInterface
{
    private int $cursor = 0;

    /**
     * @param list<array<string, float|int|string|null>> $rows
     * @param int                                         $affectedRows rowCount()
     *        source: the row count for row-returning statements,
     *        affected_rows for writes
     * @param list<string>                                $columnNames column
     *        names in SELECT-list order, carried even when $rows is empty
     */
    private function __construct(
        private array $rows,
        private readonly int $affectedRows,
        private readonly array $columnNames,
    ) {
    }

    /** @param list<array<string, float|int|string|null>> $rows */
    public static function forRows(array $rows): self
    {
        $names = $rows === [] ? [] : \array_map(\strval(...), \array_keys($rows[0]));

        return new self($rows, \count($rows), $names);
    }

    public static function forWrite(int $affectedRows): self
    {
        return new self([], $affectedRows, []);
    }

    /**
     * Build from the unified `ephpm_db_run()` result: rows and column
     * metadata for a rowset (column names present even with zero rows),
     * affected_rows for a write.
     *
     * @param array{has_rowset: bool, rows?: list<array<string, float|int|string|null>>, columns?: list<array{name: string, type?: ?string}>, affected_rows?: int, last_insert_id?: int} $result
     */
    public static function fromRun(array $result): self
    {
        $names = \array_map(
            static fn (array $c): string => (string) ($c['name'] ?? ''),
            $result['columns'] ?? [],
        );

        if ($result['has_rowset'] ?? false) {
            $rows = $result['rows'] ?? [];

            return new self($rows, \count($rows), $names);
        }

        return new self([], (int) ($result['affected_rows'] ?? 0), $names);
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
