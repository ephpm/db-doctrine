<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver;

use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Ephpm\DoctrineDriver\Bridge\BridgeInterface;
use Ephpm\DoctrineDriver\Exception\BridgeException;

/**
 * Driver-level connection over the ephpm_db_* bridge.
 *
 * There is no socket and no per-connection server state beyond the
 * per-thread litewire session the natives already maintain — constructing
 * this object performs no I/O.
 */
final class Connection implements DriverConnectionInterface
{
    /**
     * The MySQL server version litewire advertises in its wire handshake
     * (`litewire-mysql/src/handler.rs::version()`). The bridge has no
     * handshake, so we mirror the wire frontend to get identical DBAL
     * platform selection (MySQL 8.0). Deliberately NOT taken from
     * `SELECT VERSION()`, which litewire currently rewrites to
     * '8.0.0-litewire' — a string PHP's version_compare() orders *below*
     * 8.0.0, which would make DBAL fall back to the deprecated
     * MySQL 5.7 platform.
     */
    public const SERVER_VERSION = '8.0.36-litewire';

    private int|string $lastInsertId = 0;

    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function prepare(string $sql): Statement
    {
        return new Statement($this, $sql);
    }

    public function query(string $sql): Result
    {
        return Result::forRows($this->queryBridge($sql, []));
    }

    /**
     * MySQL-style string quoting, done without mysqli: backslash-escape
     * NUL, LF, CR, backslash, single quote, double quote, and Ctrl-Z,
     * then wrap in single quotes. The output is a MySQL-dialect literal;
     * litewire's MySQL parser consumes it and re-emits a correctly quoted
     * SQLite literal.
     */
    public function quote(string $value): string
    {
        return "'" . \strtr($value, [
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            '\\' => '\\\\',
            "'" => "\\'",
            '"' => '\\"',
            "\x1a" => '\\Z',
        ]) . "'";
    }

    public function exec(string $sql): int|string
    {
        return $this->executeBridge($sql, [])['affected_rows'];
    }

    /**
     * The last-insert id cached from the most recent statement this
     * connection ran through `ephpm_db_execute()` — i.e. the most recent
     * `exec()` or prepared-statement execution that was routed as a write.
     * Row-returning statements do not touch the cache. Call this
     * immediately after the INSERT you care about; any intervening write
     * (including an implicit one issued by DBAL) overwrites the value.
     */
    public function lastInsertId(): int|string
    {
        return $this->lastInsertId;
    }

    public function beginTransaction(): void
    {
        $this->executeBridge('BEGIN', []);
    }

    public function commit(): void
    {
        $this->executeBridge('COMMIT', []);
    }

    public function rollBack(): void
    {
        $this->executeBridge('ROLLBACK', []);
    }

    /** See {@see self::SERVER_VERSION} for why this is a constant. */
    public function getServerVersion(): string
    {
        return self::SERVER_VERSION;
    }

    /**
     * There is no native connection object — the bridge talks to the
     * embedded database through per-thread state inside the ePHPm
     * process. The bridge instance is the closest thing to a handle.
     */
    public function getNativeConnection(): BridgeInterface
    {
        return $this->bridge;
    }

    /**
     * Run a row-returning statement through the bridge.
     *
     * @param list<bool|float|int|string|null> $params
     *
     * @return list<array<string, float|int|string|null>>
     *
     * @throws BridgeException
     *
     * @internal called by {@see Statement}
     */
    public function queryBridge(string $sql, array $params): array
    {
        try {
            return $this->bridge->query($sql, $params);
        } catch (\Exception $e) {
            throw BridgeException::fromBridge($e);
        }
    }

    /**
     * Run a non-row-returning statement through the bridge, refreshing
     * the cached last-insert id.
     *
     * @param list<bool|float|int|string|null> $params
     *
     * @return array{affected_rows: int, last_insert_id: int}
     *
     * @throws BridgeException
     *
     * @internal called by {@see Statement}
     */
    public function executeBridge(string $sql, array $params): array
    {
        try {
            $result = $this->bridge->execute($sql, $params);
        } catch (\Exception $e) {
            throw BridgeException::fromBridge($e);
        }

        $this->lastInsertId = $result['last_insert_id'];

        return $result;
    }
}
