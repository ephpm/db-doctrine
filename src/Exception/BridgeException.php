<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Exception;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;

/**
 * Driver-level exception wrapping an error thrown by the ephpm_db_*
 * natives (or a bridge fake mimicking them).
 *
 * The natives throw plain \Exception with `getCode()` = the MySQL error
 * number and a message of the form `SQLSTATE[xxxxx]: <backend message>`;
 * this class preserves both and parses the SQLSTATE out of the prefix.
 */
final class BridgeException extends \Exception implements DriverExceptionInterface
{
    private ?string $sqlState = null;

    public static function fromBridge(\Throwable $error): self
    {
        $exception = new self($error->getMessage(), (int) $error->getCode(), $error);
        if (\preg_match('/^SQLSTATE\[([A-Za-z0-9]{5})\]/', $error->getMessage(), $matches) === 1) {
            $exception->sqlState = $matches[1];
        }

        return $exception;
    }

    public static function fromMessage(string $message): self
    {
        return new self($message);
    }

    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
