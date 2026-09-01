# ephpm/db-doctrine

[Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html) driver
that runs SQL through [ePHPm](https://ephpm.dev)'s in-process database
bridge via the `ephpm_db_query()` / `ephpm_db_execute()` SAPI functions.
Same DBAL API your app already speaks, zero socket round-trips, zero wire
protocol — every query is a direct C call into the litewire session
embedded next to PHP, the same backend the MySQL wire frontend serves.

**Compatibility:** `php: ^8.2`, `doctrine/dbal: ^4.0` (developed and
tested against DBAL 4.4). DBAL 4 is the only supported major — DBAL 3 has
a different driver-level API and is not supported. Tests run on PHP
8.2 / 8.3 / 8.4 in CI.

```php
use Doctrine\DBAL\DriverManager;
use Ephpm\DoctrineDriver\Driver;

$conn = DriverManager::getConnection(['driverClass' => Driver::class]);

$conn->executeStatement(
    'INSERT INTO posts (title) VALUES (:title)',
    ['title' => 'Hello from the bridge'],
);
$posts = $conn->fetchAllAssociative('SELECT * FROM posts');
```

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Usage with DriverManager](#usage-with-drivermanager)
- [Symfony / DoctrineBundle configuration](#symfony--doctrinebundle-configuration)
- [Connection parameters](#connection-parameters)
- [What's verified, what isn't](#whats-verified-what-isnt)
- [Behavior notes](#behavior-notes)
- [Testing without ePHPm](#testing-without-ephpm)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **`doctrine/dbal` ^4.0** (Composer pulls this in automatically)
- **ePHPm v0.6.3 or newer** (current release: v0.8.6), **with
  `[db.sqlite]` configured.** The `ephpm_db_*` SAPI functions this
  driver calls first shipped in the v0.6.3 release.
  They are registered only when the embedded database is active
  (`[db.sqlite]` in `ephpm.toml`); without it, every query throws
  `ephpm_db: no embedded database is active (requires [db.sqlite])`.
  Under PHP-FPM, mod_php, or the stock CLI the functions don't exist at
  all and `connect()` fails fast. For development without ePHPm, see
  [Testing without ePHPm](#testing-without-ephpm).

You can confirm the SAPI surface is present from any PHP file:

```php
var_dump(function_exists('ephpm_db_query'));   // expect bool(true)
```

---

## Install

ePHPm packages are distributed via their GitHub repositories, not
Packagist. Add this repo as a Composer `vcs` repository, then require
the package (`ephpm/db-doctrine` is tagged `v0.1.0`, so `^0.1`
resolves):

```bash
composer config repositories.ephpm/db-doctrine vcs https://github.com/ephpm/db-doctrine
composer require ephpm/db-doctrine:^0.1
```

---

## Usage with DriverManager

The driver is registered through DBAL's `driverClass` parameter — there is
no URL scheme:

```php
use Doctrine\DBAL\DriverManager;
use Ephpm\DoctrineDriver\Driver;

$conn = DriverManager::getConnection(['driverClass' => Driver::class]);
```

Everything above the driver is stock DBAL: `fetchAllAssociative()`,
`executeStatement()`, `transactional()`, prepared statements, named
parameters (DBAL converts them to positional before the driver sees
them), the query builder, and exception classes like
`UniqueConstraintViolationException`.

---

## Symfony / DoctrineBundle configuration

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            default:
                driver_class: Ephpm\DoctrineDriver\Driver
```

`driver_class` is DoctrineBundle's standard passthrough for DBAL's
`driverClass`; no host/port/user settings are needed (they'd be ignored —
see below). ORM configuration on top is unchanged. This package ships no
bundle of its own.

---

## Connection parameters

There is no socket to connect to, so the usual connection parameters are
**accepted and ignored**: `host`, `port`, `user`, `password`, `dbname`,
`charset`. The bridge always talks to the embedded database the ePHPm
process was configured with. The one parameter the driver reads is
`driverOptions.bridge` — an optional
`Ephpm\DoctrineDriver\Bridge\BridgeInterface` used by tests to substitute
a fake for the SAPI natives.

---

## What's verified, what isn't

The test suite runs the driver through DBAL 4.4 against a pdo_sqlite fake
of the natives ([`PdoSqliteBridge`](src/Bridge/PdoSqliteBridge.php)) that
mimics the bridge's error mapping and the MySQL-ism rewrites litewire
implements. "Verified" below means covered by that suite; behavior of the
real runtime additionally depends on ePHPm's litewire translation layer,
which the last column notes.

| Area | Status |
| --- | --- |
| `fetchAssociative` / `fetchNumeric` / `fetchOne` / `fetchAll*` / `fetchFirstColumn` | Verified. Native int/float/null/string scalars. |
| Prepared statements, `?` placeholders, `ParameterType` conversions | Verified. Bools bind as 1/0, LARGE_OBJECT/BINARY pass through (streams read eagerly), null binds as NULL regardless of declared type. |
| Named parameters via the DBAL wrapper API | Verified — DBAL 4 converts them to positional before the driver sees them. Named placeholders in *direct driver-level* `bindValue()` calls are rejected. |
| `executeStatement` / `exec` affected-rows counts | Verified. |
| `lastInsertId()` | Verified. See the [ordering contract](#behavior-notes). |
| `transactional()` commit and rollback-on-throw | Verified. |
| Nested transactions (savepoints) | Verified against the fake; litewire passes `SAVEPOINT` / `RELEASE` / `ROLLBACK TO` through to SQLite, so the platform's savepoint SQL survives translation. |
| Exception mapping (1062, 1064, 1205, 1290, 1452 → DBAL classes) | Verified. Unmapped codes (incl. 1105) become generic `DriverException`. |
| `quote()` | Verified as string output (MySQL-style backslash escaping). The output is a MySQL-dialect literal consumed by litewire's MySQL parser; it is **not** valid raw-SQLite quoting, so it cannot be round-tripped through the pdo_sqlite fake. Prefer prepared statements. |
| Platform selection (`MySQL80Platform` from the advertised `8.0.36-litewire` version) | Verified. |
| Schema manager: `createTable()` via platform DDL + `listTableNames()` | Verified against the fake. On the real runtime this rides litewire's `information_schema.TABLES` and `SELECT DATABASE()` emulation. |
| Schema manager: `introspectTable()` / column introspection | **Not supported.** DBAL 4's MySQL column introspection query joins `information_schema.COLUMNS` with `information_schema.TABLES`; litewire currently misdetects that query as a table listing, and its COLUMNS emulation lacks the `COLUMN_TYPE` / `EXTRA` / collation columns DBAL reads. Expect wrong results until the bridge closes this gap. |
| `getNativeConnection()` | Returns the `BridgeInterface` instance — there is no underlying socket/handle object. |
| Row-count of a top-level writable CTE (`WITH ... UPDATE/DELETE`) | **Not observable.** The statement classifies as row-returning, the write executes, but `rowCount()` reports 0. |
| `columnCount()` / `getColumnName()` on empty result sets | **Degrades to 0 / error.** The bridge carries no column metadata separate from rows. |

---

## Behavior notes

**Statement routing.** The `ephpm_db_*` surface is split into a
row-returning call and an OK-metadata call. The driver routes by first
keyword: `SELECT` / `SHOW` / `DESCRIBE` / `EXPLAIN` / `WITH` / `VALUES` /
`TABLE` / `PRAGMA` go through `ephpm_db_query()`; everything else through
`ephpm_db_execute()`.

**`lastInsertId()` ordering contract.** The value is cached from the most
recent statement this connection routed through `ephpm_db_execute()` —
i.e. the latest `exec()` / write-statement execution. Row-returning
statements don't touch it. Read it immediately after the INSERT you care
about; any intervening write overwrites it.

**Server version.** `getServerVersion()` returns the fixed string
`8.0.36-litewire`, mirroring the MySQL version litewire advertises in its
wire handshake, so DBAL selects the MySQL 8.0 platform — the same platform
a mysqli/PDO client talking to the wire frontend would get. It is
deliberately not taken from `SELECT VERSION()`, which the bridge currently
answers with `8.0.0-litewire` — a string PHP's `version_compare()` orders
*below* `8.0.0`, which would push DBAL onto the deprecated MySQL 5.7
platform.

**Transactions.** `beginTransaction` / `commit` / `rollBack` are plain
`BEGIN` / `COMMIT` / `ROLLBACK` through the bridge; the per-thread
litewire session tracks transaction state exactly as it does on the wire
path, and ePHPm rolls back transactions still open at request end. DBAL
handles nesting with savepoints one layer up.

**Error shape.** Bridge errors carry the MySQL errno as the exception
code and a `SQLSTATE[xxxxx]: ...` message; the driver preserves both
(`getSQLState()` parses the prefix) and the exception converter maps the
errnos ePHPm actually emits: 1062 → `UniqueConstraintViolationException`,
1064 → `SyntaxErrorException`, 1205 → `LockWaitTimeoutException`,
1290 → `ReadOnlyException`, 1452 →
`ForeignKeyConstraintViolationException`, anything else →
`DriverException`.

---

## Testing without ePHPm

The driver takes an optional bridge implementation through
`driverOptions`, so standard PHPUnit suites can run on plain php-cli with
the bundled pdo_sqlite fake:

```php
use Doctrine\DBAL\DriverManager;
use Ephpm\DoctrineDriver\Bridge\PdoSqliteBridge;
use Ephpm\DoctrineDriver\Driver;

$conn = DriverManager::getConnection([
    'driverClass' => Driver::class,
    'driverOptions' => ['bridge' => new PdoSqliteBridge()],
]);
```

`PdoSqliteBridge` is for tests and local development only. It approximates
the runtime — same error-code mapping, same `information_schema.TABLES` /
`VERSION()` / `DATABASE()` rewrites — but it is not litewire: SQL that
leans on MySQL-only syntax runs on the real runtime and fails on the fake.
Don't use it in production.

---

## How it works

ePHPm runs PHP inside the same OS process as its embedded database. With
`[db.sqlite]` active, it registers `ephpm_db_query()` and
`ephpm_db_execute()` into PHP's global function table. Each call executes
MySQL-dialect SQL through a per-thread litewire session — the same
translation layer (MySQL → SQLite, `SHOW`/`DESCRIBE`/`information_schema`
emulation, `SET NAMES` no-ops) that serves ePHPm's MySQL wire frontend on
`127.0.0.1:3306` — without the TCP round trip, connection pooling, or
wire-protocol parsing.

This package implements DBAL 4's driver-level interfaces
(`Driver`, `Driver\Connection`, `Driver\Statement`, `Driver\Result`) on
top of those two functions. Everything above the driver boundary is stock
Doctrine.

See [ephpm.dev](https://ephpm.dev) for the runtime's architecture.

---

## License

MIT — see [LICENSE](LICENSE).
