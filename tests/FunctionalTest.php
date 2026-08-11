<?php

declare(strict_types=1);

namespace Ephpm\DoctrineDriver\Tests;

use Doctrine\DBAL\Connection as WrapperConnection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;

/**
 * DBAL wrapper-level behavior: DriverManager with `driverClass`, routed
 * through the pdo_sqlite fake of the ephpm_db_* natives.
 */
final class FunctionalTest extends TestCase
{
    private WrapperConnection $conn;

    protected function setUp(): void
    {
        $this->conn = TestConnectionFactory::wrapperConnection();
        $this->conn->executeStatement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(64) UNIQUE, age INT)',
        );
    }

    public function testInsertSelectRoundTripWithNamedParameters(): void
    {
        // DBAL 4 converts named parameters to positional before the driver
        // sees them (Connection::expandArrayParameters) — the bridge itself
        // only speaks `?` placeholders.
        $affected = $this->conn->executeStatement(
            'INSERT INTO users (email, age) VALUES (:email, :age)',
            ['email' => 'a@example.com', 'age' => 30],
        );
        self::assertSame(1, $affected);

        $row = $this->conn->fetchAssociative(
            'SELECT email, age FROM users WHERE email = :email',
            ['email' => 'a@example.com'],
        );
        self::assertSame(['email' => 'a@example.com', 'age' => 30], $row);
    }

    public function testFetchModesThroughWrapper(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO users (email, age) VALUES (?, ?), (?, ?)',
            ['a@x.com', 20, 'b@x.com', 40],
        );

        self::assertSame(
            [['a@x.com', 20], ['b@x.com', 40]],
            $this->conn->fetchAllNumeric('SELECT email, age FROM users ORDER BY id'),
        );
        self::assertSame(
            ['a@x.com', 'b@x.com'],
            $this->conn->fetchFirstColumn('SELECT email FROM users ORDER BY id'),
        );
        self::assertSame(2, $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testLastInsertIdThroughWrapper(): void
    {
        $this->conn->executeStatement("INSERT INTO users (email) VALUES ('x@x.com')");

        self::assertSame(1, $this->conn->lastInsertId());
    }

    public function testTransactionalCommits(): void
    {
        $this->conn->transactional(function (WrapperConnection $conn): void {
            $conn->executeStatement("INSERT INTO users (email) VALUES ('t@x.com')");
        });

        self::assertSame(1, $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testTransactionalRollsBackOnThrow(): void
    {
        try {
            $this->conn->transactional(function (WrapperConnection $conn): void {
                $conn->executeStatement("INSERT INTO users (email) VALUES ('t@x.com')");
                throw new \DomainException('abort');
            });
            self::fail('Expected DomainException');
        } catch (\DomainException) {
        }

        self::assertSame(0, $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        // DBAL manages nesting via SAVEPOINT DOCTRINE_n; litewire passes
        // SAVEPOINT / RELEASE / ROLLBACK TO through to SQLite untranslated,
        // and so does the pdo_sqlite fake.
        $this->conn->beginTransaction();
        $this->conn->executeStatement("INSERT INTO users (email) VALUES ('outer@x.com')");

        $this->conn->beginTransaction();
        $this->conn->executeStatement("INSERT INTO users (email) VALUES ('inner@x.com')");
        $this->conn->rollBack();

        $this->conn->commit();

        self::assertSame(
            ['outer@x.com'],
            $this->conn->fetchFirstColumn('SELECT email FROM users ORDER BY id'),
        );
    }

    public function testDuplicateKeyThrowsUniqueConstraintViolation(): void
    {
        $this->conn->executeStatement("INSERT INTO users (email) VALUES ('dup@x.com')");

        $this->expectException(UniqueConstraintViolationException::class);
        $this->conn->executeStatement("INSERT INTO users (email) VALUES ('dup@x.com')");
    }

    public function testSyntaxErrorThrowsSyntaxErrorException(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->conn->executeStatement('SELECTT 1');
    }

    public function testForeignKeyViolationThrowsForeignKeyException(): void
    {
        $this->conn->executeStatement(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY,'
            . ' user_id INT REFERENCES users (id))',
        );

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->conn->executeStatement('INSERT INTO posts (user_id) VALUES (999)');
    }

    public function testSchemaManagerSmokeCreateTableAndListTableNames(): void
    {
        $table = new Table('sm_smoke');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 32]);
        $table->setPrimaryKey(['id']);

        $schemaManager = $this->conn->createSchemaManager();
        $schemaManager->createTable($table);

        $names = $schemaManager->listTableNames();
        self::assertContains('sm_smoke', $names);
        self::assertContains('users', $names);
    }
}
