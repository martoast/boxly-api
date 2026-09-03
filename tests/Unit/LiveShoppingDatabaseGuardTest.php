<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LiveShoppingDatabaseGuard;

class LiveShoppingDatabaseGuardTest extends TestCase
{
    public function test_inherited_app_mysql_without_the_gate_cannot_be_used(): void
    {
        $this->expectException(LogicException::class);

        LiveShoppingDatabaseGuard::assertSafe('testing', false, 'mysql', 'mysql', 'boxly_local', 'mysql');
    }

    #[DataProvider('unsafeMysqlGates')]
    public function test_unsafe_mysql_gates_are_rejected(
        string $environment,
        string $database,
        string $host,
    ): void {
        $this->expectException(LogicException::class);

        LiveShoppingDatabaseGuard::assertSafe($environment, true, 'mysql', 'mysql', $database, $host);
    }

    public static function unsafeMysqlGates(): array
    {
        return [
            'application database' => ['testing', 'boxly_local', 'mysql'],
            'remote host' => ['testing', 'testing', 'db.production.example'],
            'non-testing environment' => ['local', 'testing', 'mysql'],
        ];
    }

    public function test_valid_explicit_local_mysql_gate_is_allowed(): void
    {
        LiveShoppingDatabaseGuard::assertSafe('testing', true, 'mysql', 'mysql', 'testing', 'mysql');

        $this->addToAssertionCount(1);
    }

    public function test_sqlite_memory_default_is_allowed(): void
    {
        LiveShoppingDatabaseGuard::assertSafe('testing', false, 'sqlite', 'sqlite', ':memory:', null);

        $this->addToAssertionCount(1);
    }
}
