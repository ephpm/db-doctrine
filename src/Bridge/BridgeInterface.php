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
}
