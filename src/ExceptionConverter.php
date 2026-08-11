<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;

/**
 * Converts bridge errors into DBAL's exception taxonomy.
 *
 * The mapping covers exactly the MySQL error numbers ePHPm's embedded
 * litewire session emits (see litewire's `litewire-session/src/error_map.rs`
 * and `ER_PARSE_ERROR` in `litewire-session/src/lib.rs`):
 *
 *  - 1062 ER_DUP_ENTRY                  -> UniqueConstraintViolationException
 *  - 1064 ER_PARSE_ERROR                -> SyntaxErrorException
 *  - 1205 ER_LOCK_WAIT_TIMEOUT          -> LockWaitTimeoutException
 *  - 1290 ER_OPTION_PREVENTS_STATEMENT  -> ReadOnlyException
 *  - 1452 ER_NO_REFERENCED_ROW_2        -> ForeignKeyConstraintViolationException
 *  - anything else (incl. 1105)         -> DriverException
 *
 * 1290 maps to {@see ReadOnlyException} rather than following DBAL's stock
 * MySQL converter (which leaves it generic): litewire raises it for
 * SQLITE_READONLY, i.e. writes against a read-only database/replica.
 */
final class ExceptionConverter implements ExceptionConverterInterface
{
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        return match ($exception->getCode()) {
            1062 => new UniqueConstraintViolationException($exception, $query),
            1064 => new SyntaxErrorException($exception, $query),
            1205 => new LockWaitTimeoutException($exception, $query),
            1290 => new ReadOnlyException($exception, $query),
            1452 => new ForeignKeyConstraintViolationException($exception, $query),
            default => new DriverException($exception, $query),
        };
    }
}
