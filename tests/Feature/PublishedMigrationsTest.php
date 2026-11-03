<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\TranslationsServiceProvider;

/*
 | The package's migrations as an application has them: published into
 | its database/migrations directory, then run, rolled back, reset and
 | replayed by Laravel's migrator.
 */

beforeEach(function (): void {
    // The tables the package migrations already created for the suite.
    Schema::dropIfExists('translations');
    Schema::dropIfExists('translation_states');

    $this->appDatabase = sys_get_temp_dir().'/tipi-app-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists("{$this->appDatabase}/migrations");
    $this->app->useDatabasePath($this->appDatabase);
});

afterEach(function (): void {
    File::deleteDirectory($this->appDatabase);
});

/**
 * vendor:publish as the application runs it: publishing paths are
 * computed when the provider boots.
 */
function publishMigrations(): void
{
    app()->register(TranslationsServiceProvider::class, force: true);

    test()->artisan('vendor:publish', ['--tag' => 'translations-migrations'])->assertSuccessful();
}

/**
 * @return list<string>
 */
function appMigrationNames(string $directory): array
{
    return array_map(
        fn (string $file): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file, '.php')),
        File::glob("{$directory}/migrations/*.php"),
    );
}

/**
 * @param  array<string, mixed>  $options
 */
function migrateApp(string $directory, string $command = 'migrate', array $options = []): void
{
    test()->artisan($command, ['--path' => "{$directory}/migrations", '--realpath' => true, '--force' => true, ...$options])->assertSuccessful();
}

it('publishes the two table migrations, once', function (): void {
    publishMigrations();
    publishMigrations();

    expect(appMigrationNames($this->appDatabase))->toBe(['create_translations_table', 'create_translation_states_table']);
});

it('installs, rolls back, resets and reinstalls the published migrations', function (): void {
    publishMigrations();
    migrateApp($this->appDatabase);

    $article = article(StatefulSharedArticle::class, ['en' => ['title' => 'Saperavi']]);

    expect(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and(reload($article)->getTranslationState('en'))->not->toBeNull();

    migrateApp($this->appDatabase, 'migrate:rollback');

    expect(Schema::hasTable('translations'))->toBeFalse()
        ->and(Schema::hasTable('translation_states'))->toBeFalse();

    migrateApp($this->appDatabase);

    expect(Schema::hasColumns('translations', ['translatable_type', 'translatable_id', 'locale_code', 'attribute', 'value']))->toBeTrue()
        ->and(Schema::hasColumns('translation_states', ['translatable_type', 'translatable_id', 'locale_code', 'status', 'outdated_at']))->toBeTrue();

    migrateApp($this->appDatabase, 'migrate:reset');

    expect(Schema::hasTable('translations'))->toBeFalse()
        ->and(Schema::hasTable('translation_states'))->toBeFalse();

    // fresh drops every table, the suite's fixtures too: check the schema.
    migrateApp($this->appDatabase, 'migrate:fresh');

    expect(Schema::hasColumns('translations', ['translatable_type', 'translatable_id', 'locale_code', 'attribute', 'value']))->toBeTrue()
        ->and(Schema::hasTable('translation_states'))->toBeTrue();
});

it('creates and drops the configured tables', function (): void {
    config(['translations.shared.table' => 'wine_translations', 'translations.states.table' => 'wine_translation_states']);

    publishMigrations();
    migrateApp($this->appDatabase);

    expect(Schema::hasTable('wine_translations'))->toBeTrue()
        ->and(Schema::hasTable('wine_translation_states'))->toBeTrue()
        ->and(Schema::hasTable('translations'))->toBeFalse();

    migrateApp($this->appDatabase, 'migrate:reset');

    expect(Schema::hasTable('wine_translations'))->toBeFalse()
        ->and(Schema::hasTable('wine_translation_states'))->toBeFalse();
});
