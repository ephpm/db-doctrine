<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Bridge;

/**
 * Test / development fake for the ephpm_db_* natives, backed by pdo_sqlite.
 *
 * Approximates what ePHPm's embedded litewire session does at runtime:
 * MySQL-errno error mapping (mirroring litewire's `error_map.rs`), plus a
 * handful of MySQL-ism rewrites that litewire genuinely implements
 * (`SELECT VERSION()`, `SELECT DATABASE()`, `information_schema.TABLES`
 * listing, CREATE TABLE option stripping).
 *
 * It is NOT a full MySQL-to-SQLite translator. SQL that leans on MySQL
 * syntax SQLite doesn't share (backslash string escapes, ENGINE clauses
 * beyond CREATE TABLE, ON DUPLICATE KEY UPDATE, ...) works against the
 * real runtime but not against this fake. Do not use it in production.
 */
final class PdoSqliteBridge implements BridgeInterface
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function query(string $sql, array $params = []): array
    {
        $shimmed = $this->shimRowQuery($sql);
        if ($shimmed !== null) {
            return $shimmed;
        }

        $stmt = $this->prepareAndExecute($this->rewrite($sql), $params);
        /** @var list<array<string, float|int|string|null>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $rows;
    }

    public function execute(string $sql, array $params = []): array
    {
        $stmt = $this->prepareAndExecute($this->rewrite($sql), $params);
        $affected = $stmt->rowCount();
        $stmt->closeCursor();

        return [
            'affected_rows' => $affected,
            'last_insert_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    public function run(string $sql, array $params = []): array
    {
        // A shimmed row query (VERSION()/DATABASE()/information_schema) is
        // always a rowset; carry its column names from the synthetic keys.
        $shimmed = $this->shimRowQuery($sql);
        if ($shimmed !== null) {
            return [
                'has_rowset' => true,
                'rows' => $shimmed,
                'columns' => self::columnsFromRows($shimmed),
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        $stmt = $this->prepareAndExecute($this->rewrite($sql), $params);
        $ncols = $stmt->columnCount();
        $hasRowset = $ncols > 0;

        // Column metadata read from the statement, so it is present even for
        // a zero-row result set (issue #262).
        $columns = [];
        for ($i = 0; $i < $ncols; $i++) {
            /** @var array<string, mixed>|false $meta */
            $meta = $stmt->getColumnMeta($i);
            $decl = \is_array($meta) ? ($meta['sqlite:decl_type'] ?? null) : null;
            $columns[] = [
                'name' => \is_array($meta) ? (string) ($meta['name'] ?? '') : '',
                'type' => \is_string($decl) && $decl !== '' ? $decl : null,
            ];
        }

        if ($hasRowset) {
            /** @var list<array<string, float|int|string|null>> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return [
                'has_rowset' => true,
                'rows' => $rows,
                'columns' => $columns,
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        $affected = $stmt->rowCount();
        $stmt->closeCursor();

        return [
            'has_rowset' => false,
            'rows' => [],
            'columns' => [],
            'affected_rows' => $affected,
            'last_insert_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    /**
     * @param list<array<string, float|int|string|null>> $rows
     *
     * @return list<array{name: string, type: ?string}>
     */
    private static function columnsFromRows(array $rows): array
    {
        if (!isset($rows[0])) {
            return [];
        }

        $columns = [];
        foreach (array_keys($rows[0]) as $name) {
            $columns[] = ['name' => (string) $name, 'type' => null];
        }

        return $columns;
    }

    /**
     * Prepare, bind, and execute, converting PDO errors into the
     * "SQLSTATE[xxxxx]: message" / MySQL-errno shape the natives throw.
     *
     * @param list<bool|float|int|string|null> $params
     */
    private function prepareAndExecute(string $sql, array $params): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $position = 1;
            foreach ($params as $value) {
                $type = match (true) {
                    $value === null => \PDO::PARAM_NULL,
                    \is_bool($value) => \PDO::PARAM_INT,
                    \is_int($value) => \PDO::PARAM_INT,
                    default => \PDO::PARAM_STR,
                };
                $stmt->bindValue($position++, \is_bool($value) ? (int) $value : $value, $type);
            }

            $stmt->execute();

            return $stmt;
        } catch (\PDOException $e) {
            throw self::mapError($e);
        }
    }

    /**
     * Classify a PDO/SQLite error the way litewire's error_map.rs does and
     * throw it in the exact shape of the natives: \Exception with
     * code = MySQL errno and message "SQLSTATE[xxxxx]: <raw message>".
     */
    private static function mapError(\PDOException $e): \Exception
    {
        // Strip PDO's own "SQLSTATE[..]: Category: N " prefix down to the
        // driver message so the shape matches what litewire forwards.
        $raw = \preg_replace('/^SQLSTATE\[\w+\]:?\s*(?:[^:]+:\s*\d+\s*)?/', '', $e->getMessage()) ?? $e->getMessage();
        $lower = \strtolower($raw);

        [$errno, $sqlstate] = match (true) {
            \str_contains($lower, 'database is locked'),
            \str_contains($lower, 'database table is locked') => [1205, 'HY000'],
            \str_contains($lower, 'unique constraint failed'),
            \str_contains($lower, 'primary key constraint failed') => [1062, '23000'],
            \str_contains($lower, 'foreign key constraint failed') => [1452, '23000'],
            \str_contains($lower, 'readonly database') => [1290, 'HY000'],
            \str_contains($lower, 'syntax error') => [1064, '42000'],
            default => [1105, 'HY000'],
        };

        return new \Exception(\sprintf('SQLSTATE[%s]: %s', $sqlstate, $raw), $errno);
    }

    /**
     * Answer queries litewire rewrites to synthetic results, or null to
     * run the SQL against SQLite.
     *
     * @return list<array<string, float|int|string|null>>|null
     */
    private function shimRowQuery(string $sql): ?array
    {
        $trimmed = \trim($sql);

        // litewire-translate/src/common.rs: VERSION() -> '8.0.0-litewire'.
        if (\preg_match('/^SELECT\s+VERSION\(\)\s*;?$/i', $trimmed) === 1) {
            return [['VERSION()' => '8.0.0-litewire']];
        }

        // litewire-translate/src/common.rs: DATABASE() / SCHEMA() -> 'main'.
        if (\preg_match('/^SELECT\s+(DATABASE|SCHEMA)\(\)\s*;?$/i', $trimmed) === 1) {
            return [['DATABASE()' => 'main']];
        }

        // litewire-translate/src/metadata.rs: information_schema.TABLES ->
        // sqlite_master. Good enough for DBAL's table-name listing; the
        // TABLE_SCHEMA filter is ignored because SQLite only has 'main'
        // (which is exactly what SELECT DATABASE() reports above).
        if (\preg_match('/FROM\s+information_schema\.`?TABLES`?/i', $trimmed) === 1) {
            $stmt = $this->pdo->query(
                "SELECT name AS TABLE_NAME, 'BASE TABLE' AS TABLE_TYPE, 'main' AS TABLE_SCHEMA"
                . " FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
            /** @var list<array<string, float|int|string|null>> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $rows;
        }

        return null;
    }

    /**
     * Minimal MySQL-DDL-to-SQLite rewrites, standing in for litewire's
     * real translator: strip AUTO_INCREMENT and trailing table options
     * (ENGINE / CHARACTER SET / COLLATE) from CREATE TABLE statements.
     */
    private function rewrite(string $sql): string
    {
        if (\preg_match('/^\s*(CREATE|ALTER)\s+TABLE\b/i', $sql) !== 1) {
            return $sql;
        }

        $sql = \preg_replace('/\bAUTO_INCREMENT\b/i', '', $sql) ?? $sql;

        // Drop everything after the closing paren if it is only table options.
        $lastParen = \strrpos($sql, ')');
        if ($lastParen !== false) {
            $tail = \substr($sql, $lastParen + 1);
            $optionPattern = '/^(\s|(DEFAULT\s+)?(CHARACTER\s+SET|CHARSET)\s*=?\s*\w+'
                . '|COLLATE\s*=?\s*`?\w+`?|ENGINE\s*=?\s*\w+|ROW_FORMAT\s*=?\s*\w+'
                . '|COMMENT\s*=?\s*\'[^\']*\')*;?\s*$/i';
            if ($tail !== '' && \preg_match($optionPattern, $tail) === 1) {
                $sql = \substr($sql, 0, $lastParen + 1);
            }
        }

        return $sql;
    }
}
