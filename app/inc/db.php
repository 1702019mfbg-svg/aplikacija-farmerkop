<?php
/**
 * Veza sa bazom. Svi upiti idu kroz pripremljene upite (prepared statements),
 * vrednosti se nikada ne ubacuju direktno u SQL tekst.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . (int)DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }
    return $pdo;
}

/** Izvrši upit i vrati statement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Svi redovi. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Prvi red ili null. */
function db_one(string $sql, array $params = []): ?array
{
    $r = db_run($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** Prva kolona prvog reda ili null. */
function db_val(string $sql, array $params = []): mixed
{
    $r = db_run($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

function db_id(): int
{
    return (int)db()->lastInsertId();
}

/** Izvrši funkciju u transakciji; pri grešci poništi sve. */
function db_trans(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rez = $fn();
        $pdo->commit();
        return $rez;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
