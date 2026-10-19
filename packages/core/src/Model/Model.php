<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Kernel\Kernel;

abstract class Model
{
    /** @var array<class-string<Model>, Connection> */
    private static array $connections = [];

    abstract public static function table(): TableDefinition;

    /** @return class-string<object>|null */
    public static function rowClass(): ?string
    {
        return static::class;
    }

    final public static function bareTableName(): string
    {
        return static::table()->tableName();
    }

    final public static function tableName(): string
    {
        return static::connection()->tableName(static::bareTableName());
    }

    final public static function primaryKey(): string
    {
        $primaryKey = static::table()->primaryKey();

        if ($primaryKey === null) {
            throw new SchemaException('model requires a single-column primary key: ' . static::class);
        }

        return $primaryKey;
    }

    final public static function query(): QueryBuilder
    {
        $query = new QueryBuilder(static::connection(), static::bareTableName());
        $rowClass = static::rowClass();

        return $rowClass === null ? $query : $query->as($rowClass);
    }

    final public static function find(int|string $id): ?object
    {
        return static::query()->where(static::primaryKey(), $id)->first();
    }

    /** @param array<string, mixed> $values */
    final public static function insert(array $values): int
    {
        $connection = static::connection();

        if ($values === []) {
            $connection->execute('INSERT INTO ' . static::tableName() . ' () VALUES ()');

            return $connection->insertId();
        }

        return $connection->insert(static::bareTableName(), $values);
    }

    /** @param array<string, mixed> $values */
    final public static function update(int|string $id, array $values): int
    {
        return static::query()->where(static::primaryKey(), $id)->update($values);
    }

    final public static function delete(int|string $id): int
    {
        return static::query()->where(static::primaryKey(), $id)->delete();
    }

    final public static function count(): int
    {
        return static::query()->count();
    }

    final public static function connection(): Connection
    {
        $class = static::class;

        if (\array_key_exists($class, self::$connections)) {
            return self::$connections[$class];
        }

        $connection = Kernel::forClass($class)->container()->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new KernelException('container entry Connection must resolve to Connection');
        }

        return $connection;
    }

    final public static function useConnection(Connection $connection): void
    {
        self::$connections[static::class] = $connection;
    }

    /**
     * @internal
     */
    final public static function resetConnections(): void
    {
        self::$connections = [];
    }
}
