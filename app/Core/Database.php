<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO wrapper. All queries go through prepared statements with bound parameters.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = config('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            $c['port'],
            $c['database'],
            $c['charset']
        );
        try {
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            Logger::error('Database connection failed', ['code' => $e->getCode()]);
            throw new HttpException(503, 'The database is currently unavailable. Please try again shortly.');
        }
        return self::$pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::connection()->lastInsertId();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
