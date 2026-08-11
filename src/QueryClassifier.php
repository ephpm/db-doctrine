<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver;

/**
 * Decides which bridge native a statement must go through.
 *
 * The ephpm_db_* surface is split: `ephpm_db_query()` stages a result set
 * (and returns `[]` for statements without one, losing the OK metadata),
 * while `ephpm_db_execute()` returns affected_rows / last_insert_id (and
 * zeros for statements that produced rows). A prepared Statement therefore
 * has to route by statement kind, which we do by first keyword.
 *
 * Known limitation: a top-level writable CTE (`WITH ... DELETE/UPDATE`)
 * classifies as row-returning; the write still executes, but its affected
 * row count and last-insert id are not observable through the bridge.
 *
 * @internal
 */
final class QueryClassifier
{
    private const ROW_RETURNING = [
        'SELECT' => true,
        'SHOW' => true,
        'DESCRIBE' => true,
        'DESC' => true,
        'EXPLAIN' => true,
        'WITH' => true,
        'VALUES' => true,
        'TABLE' => true,
        'PRAGMA' => true,
    ];

    /** Whether the statement should be routed through ephpm_db_query(). */
    public static function returnsRows(string $sql): bool
    {
        $keyword = self::firstKeyword($sql);

        return $keyword !== null && isset(self::ROW_RETURNING[$keyword]);
    }

    private static function firstKeyword(string $sql): ?string
    {
        $length = \strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === '(') {
                $offset++;
                continue;
            }

            if ($char === '#' || ($char === '-' && \substr($sql, $offset, 2) === '--')) {
                $newline = \strpos($sql, "\n", $offset);
                if ($newline === false) {
                    return null;
                }
                $offset = $newline + 1;
                continue;
            }

            if ($char === '/' && \substr($sql, $offset, 2) === '/*') {
                $end = \strpos($sql, '*/', $offset + 2);
                if ($end === false) {
                    return null;
                }
                $offset = $end + 2;
                continue;
            }

            break;
        }

        if (\preg_match('/\G([A-Za-z]+)/', $sql, $matches, 0, $offset) !== 1) {
            return null;
        }

        return \strtoupper($matches[1]);
    }
}
