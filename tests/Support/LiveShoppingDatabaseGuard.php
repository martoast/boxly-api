<?php

namespace Tests\Support;

use LogicException;

final class LiveShoppingDatabaseGuard
{
    private const MYSQL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'mysql'];

    public static function assertSafe(
        string $appEnvironment,
        bool $mysqlGate,
        string $connection,
        string $driver,
        string $database,
        ?string $host,
    ): void {
        if ($appEnvironment !== 'testing') {
            throw new LogicException('Live shopping tests require APP_ENV=testing.');
        }

        if (! $mysqlGate) {
            if ($connection !== 'sqlite' || $driver !== 'sqlite' || $database !== ':memory:') {
                throw new LogicException('Live shopping tests require SQLite :memory: unless the explicit MySQL gate is enabled.');
            }

            return;
        }

        if ($connection !== 'mysql' || $driver !== 'mysql' || $database !== 'testing') {
            throw new LogicException('The live shopping MySQL gate requires the dedicated testing database.');
        }

        if (! in_array($host, self::MYSQL_HOSTS, true)) {
            throw new LogicException('The live shopping MySQL gate requires an allowlisted local host.');
        }
    }
}
