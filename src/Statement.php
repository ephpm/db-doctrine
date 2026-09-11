<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver;

use Doctrine\DBAL\Driver\Statement as DriverStatementInterface;
use Doctrine\DBAL\ParameterType;
use Ephpm\DoctrineDriver\Exception\BridgeException;

/**
 * Prepared statement over the ephpm_db_* bridge.
 *
 * The bridge supports `?` positional placeholders only. That is not a
 * practical restriction when using DBAL's wrapper API: DBAL 4 converts
 * named parameters to positional ones before the driver sees the SQL
 * (`Doctrine\DBAL\Connection::expandArrayParameters()`, which runs the
 * SQL through the platform parser whenever `$params` has string keys).
 * Only direct driver-level use with named placeholders is unsupported,
 * and bindValue() rejects string parameter names for that reason.
 *
 * There is no server-side prepare: the SQL and bound values are staged
 * in PHP and shipped through the bridge on execute().
 */
final class Statement implements DriverStatementInterface
{
    /** @var array<int, bool|float|int|string|null> keyed by 1-based position */
    private array $params = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $sql,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if (!\is_int($param)) {
            throw BridgeException::fromMessage(
                'The ephpm bridge only supports positional parameters; '
                . 'use the DBAL wrapper API for named-parameter conversion.',
            );
        }

        $this->params[$param] = $this->convertValue($value, $type);
    }

    public function execute(): Result
    {
        $params = $this->params;
        \ksort($params);
        $params = \array_values($params);

        // The unified ephpm_db_run() reports has_rowset from the executed
        // statement, so there is no first-keyword classification: a writable
        // CTE (WITH ... DELETE) reports its affected-row count correctly, and
        // a zero-row result set still carries its column metadata.
        return Result::fromRun($this->connection->runBridge($this->sql, $params));
    }

    /**
     * Map a DBAL ParameterType onto what the bridge can bind (null, bool,
     * int, float, string). Null always binds as SQL NULL regardless of the
     * declared type, matching the PDO drivers.
     */
    private function convertValue(mixed $value, ParameterType $type): bool|float|int|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ParameterType::NULL => null,
            ParameterType::INTEGER => (int) $value,
            ParameterType::BOOLEAN => (bool) $value ? 1 : 0,
            ParameterType::LARGE_OBJECT,
            ParameterType::BINARY => $this->contentsOf($value),
            ParameterType::STRING,
            ParameterType::ASCII => $this->stringify($value),
        };
    }

    /** LARGE_OBJECT/BINARY: accept streams like the PDO drivers do. */
    private function contentsOf(mixed $value): string
    {
        if (\is_resource($value)) {
            $contents = \stream_get_contents($value);
            if ($contents === false) {
                throw BridgeException::fromMessage('Failed to read LARGE_OBJECT stream parameter.');
            }

            return $contents;
        }

        return $this->stringify($value);
    }

    private function stringify(mixed $value): string
    {
        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw BridgeException::fromMessage(
            'Cannot bind a value of type ' . \get_debug_type($value) . ' as a string parameter.',
        );
    }
}
