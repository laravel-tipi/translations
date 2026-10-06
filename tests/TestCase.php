<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tipi\Translations\Tests\Fixtures\Database\CreatesTestSchema;
use Tipi\Translations\TranslationServiceProvider;

abstract class TestCase extends Orchestra
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
    }

    protected function getPackageProviders($app): array
    {
        return [
            TranslationServiceProvider::class,
        ];
    }
}
