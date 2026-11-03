<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tipi\Translations\Tests\TestCase;

/*
 | The suite runs on the server TRANSLATIONS_TEST_DB names, not on one a
 | wrong port or a compatible driver happens to reach.
 */

it('runs on the configured database', function (): void {
    $connection = DB::connection();

    expect($connection->getDriverName())->toBe(TestCase::databaseDriver());

    match (TestCase::databaseDriver()) {
        'sqlite' => expect($connection->getDatabaseName())->toBe(':memory:'),
        'mysql' => expect($connection->scalar('select version()'))->not->toContain('MariaDB'),
        'mariadb' => expect($connection->scalar('select version()'))->toContain('MariaDB'),
        'pgsql' => expect($connection->scalar('select version()'))->toStartWith('PostgreSQL'),
    };
});
