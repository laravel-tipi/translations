<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Tests\Fixtures\Database\CreatesTestSchema;
use Tipi\Translations\Tests\Fixtures\Localization\FakeLocaleProvider;
use Tipi\Translations\TranslationServiceProvider;

abstract class TestCase extends Orchestra
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $locales = new FakeLocaleProvider;

        $this->app->instance(
            FakeLocaleProvider::class,
            $locales,
        );

        $this->app->instance(
            LocaleProvider::class,
            $locales,
        );

        $this->artisan('migrate');

        $this->createTestSchema();
    }

    protected function getPackageProviders($app): array
    {
        return [
            TranslationServiceProvider::class,
        ];
    }
}
