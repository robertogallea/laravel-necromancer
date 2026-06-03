<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * Provides a SQLite in-memory connection for all names to avoid blocking
 * on unreachable database servers during model introspection.
 */
final class InMemoryConnectionResolver implements ConnectionResolverInterface
{
    private ConnectionInterface $connection;

    public function __construct()
    {
        $this->connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    }

    public function connection($name = null): ConnectionInterface
    {
        return $this->connection;
    }

    public function getDefaultConnection(): string
    {
        return 'default';
    }

    public function setDefaultConnection($name): void {}
}
