<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Bridge;

/**
 * Backend that calls the global `ephpm_db_*` functions registered by the
 * ePHPm SAPI. Refuses to construct if those functions aren't present so we
 * fail fast outside the runtime instead of producing "Call to undefined
 * function" errors at query time.
 */
final class SapiBridge implements BridgeInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_db_query')) {
            throw new \RuntimeException(
                'ephpm DB SAPI functions are not available. '
                . 'This driver only works inside the ePHPm runtime with '
                . '[db.sqlite] configured; use '
                . 'Ephpm\\DoctrineDriver\\Bridge\\PdoSqliteBridge in tests.'
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        /** @var list<array<string, float|int|string|null>> */
        return \ephpm_db_query($sql, $params);
    }

    public function execute(string $sql, array $params = []): array
    {
        /** @var array{affected_rows: int, last_insert_id: int} */
        return \ephpm_db_execute($sql, $params);
    }
}
