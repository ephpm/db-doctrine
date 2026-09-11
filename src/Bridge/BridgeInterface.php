<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Bridge;

/**
 * Abstraction over ePHPm's in-process database bridge.
 *
 * The production implementation ({@see SapiBridge}) calls the global
 * `ephpm_db_query()` / `ephpm_db_execute()` functions registered by the
 * ePHPm SAPI. Tests and ephpm-less development use {@see PdoSqliteBridge}.
 *
 * Method semantics intentionally mirror the ephpm_db_* SAPI surface:
 *
 * - SQL is MySQL dialect; `?` positional placeholders only.
 * - Parameters may be null, bool, int, float, or string (what the MySQL
 *   binary protocol can carry). Bools bind as 1/0.
 * - Errors are thrown as \Exception with `getCode()` = the MySQL error
 *   number (1062, 1064, 1105, 1205, 1290, 1452) and a message of the
 *   form `SQLSTATE[xxxxx]: <backend message>`.
 */
interface BridgeInterface
{
    /**
     * Execute SQL and return the rows as a list of associative arrays
     * keyed by column name, in SELECT-list order. Integer/float columns
     * come back as PHP int/float, NULL as null, text/blob as string.
     * A statement with no result set returns an empty array.
     *
     * @param list<bool|float|int|string|null> $params
     *
     * @return list<array<string, float|int|string|null>>
     *
     * @throws \Exception on any database error (see interface docs)
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute SQL and return the OK metadata. A SELECT routed through
     * execute returns zeros rather than throwing.
     *
     * @param list<bool|float|int|string|null> $params
     *
     * @return array{affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception on any database error (see interface docs)
     */
    public function execute(string $sql, array $params = []): array;

    /**
     * Execute SQL once and report what it actually did — the unified entry
     * point mirroring the native `ephpm_db_run()` (ePHPm issue #263).
     *
     * `has_rowset` is read from the executed statement, so a statement is
     * never mis-classified by its first keyword (a writable CTE reports its
     * affected-row count and last-insert id correctly). `columns` carries
     * the column metadata even for a zero-row result set (ePHPm issue #262),
     * so `Result::columnCount()`/`getColumnName()` work with no rows. `rows`
     * is empty for an OK outcome; `affected_rows`/`last_insert_id` are zero
     * for a result set.
     *
     * @param list<bool|float|int|string|null> $params
     *
     * @return array{has_rowset: bool, rows: list<array<string, float|int|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception on any database error (see interface docs)
     */
    public function run(string $sql, array $params = []): array;
}
