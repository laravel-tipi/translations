<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\TestCase as Orchestra;
use Tipi\Localization\Config\LocalizationConfig;
use Tipi\Localization\LocaleContexts;
use Tipi\Localization\LocaleRegistry;
use Tipi\Localization\Localization;
use Tipi\Localization\LocalizationServiceProvider;
use Tipi\Translations\Contracts\FallbackPolicy;
use Tipi\Translations\TranslationLocales;
use Tipi\Translations\TranslationsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LocalizationServiceProvider::class, TranslationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::databaseConnection());

        $app['config']->set('localization.locales', [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English'],
            ['code' => 'ka', 'name' => 'Georgian', 'native_name' => 'ქართული'],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français'],
        ]);
        $app['config']->set('localization.default_locale', 'en');
        $app['config']->set('localization.default_context', 'web');
        $app['config']->set('localization.contexts', [
            'web' => ['locales' => '*'],
            'admin' => ['locales' => ['en', 'ka', 'de'], 'default' => 'ka'],
        ]);
    }

    /**
     * SQLite in memory by default. Set TRANSLATIONS_TEST_DB to "mysql",
     * "mariadb" or "pgsql" (plus TRANSLATIONS_TEST_DB_HOST/PORT/DATABASE/
     * USERNAME/PASSWORD) to run the suite against a real server.
     *
     * @return array<string, mixed>
     */
    private static function databaseConnection(): array
    {
        $driver = self::databaseDriver();

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        return [
            'driver' => $driver,
            'host' => getenv('TRANSLATIONS_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TRANSLATIONS_TEST_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('TRANSLATIONS_TEST_DB_DATABASE') ?: 'tipi',
            'username' => getenv('TRANSLATIONS_TEST_DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => getenv('TRANSLATIONS_TEST_DB_PASSWORD') ?: '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'search_path' => 'public',
        ];
    }

    public static function databaseDriver(): string
    {
        return getenv('TRANSLATIONS_TEST_DB') ?: 'sqlite';
    }

    protected function defineDatabaseMigrations(): void
    {
        if (self::databaseDriver() !== 'sqlite') {
            $this->artisan('db:wipe', ['--force' => true]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel-tipi/localization/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /**
     * Change localization config and rebuild every service that caches it.
     *
     * @param  array<string, mixed>  $config
     */
    protected function configureLocalization(array $config): void
    {
        foreach ($config as $key => $value) {
            config()->set("localization.{$key}", $value);
        }

        foreach ([LocalizationConfig::class, LocaleRegistry::class, LocaleContexts::class, Localization::class, FallbackPolicy::class, TranslationLocales::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        Facade::clearResolvedInstances();
    }

    /**
     * Switch to database-backed locales, some of them inactive.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $inactive
     */
    protected function useDatabaseLocales(array $codes = ['en', 'ka', 'de', 'fr'], string $default = 'en', array $inactive = []): void
    {
        foreach ($codes as $position => $code) {
            DB::table('locales')->insert([
                'code' => $code,
                'name' => strtoupper($code),
                'native_name' => $code,
                'text_direction' => 'ltr',
                'position' => $position,
                'is_active' => ! in_array($code, $inactive, true),
                'is_default' => $code === $default,
            ]);
        }

        $this->configureLocalization(['locales_driver' => 'database', 'contexts' => []]);
    }
}
