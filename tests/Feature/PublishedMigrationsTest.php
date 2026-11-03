<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\TestCase;
use Tipi\Translations\TranslationsServiceProvider;

/*
 | The package's migrations as an application has them: published into
 | its database/migrations directory, then run, rolled back and replayed
 | by Laravel's migrator. Applications upgrading from 0.1 have 0.1's
 | published migrations in their history.
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
 * Put 0.1 migrations into the application, as 0.1 published them.
 *
 * @return array<string, string> file => contents
 */
function publishV01Migrations(string $release, string $directory): array
{
    $files = [];
    $timestamp = 1;

    foreach (['create_translations_table', 'create_translation_states_table'] as $name) {
        $source = __DIR__."/../Fixtures/v0.1-migrations/{$release}/{$name}.php";

        if (file_exists($source)) {
            $target = sprintf('%s/migrations/2026_10_01_00000%d_%s.php', $directory, $timestamp++, $name);
            copy($source, $target);
            $files[$target] = (string) file_get_contents($target);
        }
    }

    return $files;
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
 * vendor:publish as the application runs it: publishing paths are
 * computed when the provider boots, from the migrations present then.
 */
function publishMigrations(): void
{
    app()->register(TranslationsServiceProvider::class, force: true);

    test()->artisan('vendor:publish', ['--tag' => 'translations-migrations'])->assertSuccessful();
}

function migrateApp(string $directory, string $command = 'migrate'): void
{
    test()->artisan($command, ['--path' => "{$directory}/migrations", '--realpath' => true, '--force' => true])->assertSuccessful();
}

it('installs, rolls back and reinstalls from published migrations', function (): void {
    publishMigrations();

    expect(appMigrationNames($this->appDatabase))->toBe(['create_translations_table', 'create_translation_states_table', 'upgrade_translations_table_from_v0_1']);

    migrateApp($this->appDatabase);

    $article = article(StatefulSharedArticle::class, ['en' => ['title' => 'Saperavi']]);

    expect(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and(reload($article)->getTranslationState('en'))->not->toBeNull()
        ->and(Schema::hasTable('translations_v0_1'))->toBeFalse();

    migrateApp($this->appDatabase, 'migrate:reset');

    expect(Schema::hasTable('translations'))->toBeFalse()
        ->and(Schema::hasTable('translation_states'))->toBeFalse();

    migrateApp($this->appDatabase);

    expect(Schema::hasColumn('translations', 'attribute'))->toBeTrue();
});

it('upgrades an application with 0.1 migrations without touching them', function (string $release): void {
    if ($release === '0.1.0' && in_array(TestCase::databaseDriver(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This migration never ran on MySQL or MariaDB: its index name is too long.');
    }

    // The application's 0.1 history runs on a fresh database.
    $published = publishV01Migrations($release, $this->appDatabase);
    migrateApp($this->appDatabase);

    expect(Schema::hasColumn('translations', 'values'))->toBeTrue();

    $article = new SharedArticle(['slug' => 'saperavi']);
    $article->saveQuietly();
    DB::table('translations')->insert([
        'translatable_type' => SharedArticle::class,
        'translatable_id' => $article->id,
        'locale_code' => 'ka',
        'values' => json_encode(['title' => 'საფერავი', 'body' => null]),
    ]);

    // Upgrading: publish without --force, then migrate.
    publishMigrations();

    foreach ($published as $file => $contents) {
        expect(file_get_contents($file))->toBe($contents);
    }

    $added = array_values(array_diff(appMigrationNames($this->appDatabase), array_map(
        fn (string $file): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file, '.php')),
        array_keys($published),
    )));

    expect($added)->toBe($release === '0.1.16'
        ? ['upgrade_translations_table_from_v0_1']
        : ['create_translation_states_table', 'upgrade_translations_table_from_v0_1']);

    migrateApp($this->appDatabase);

    expect(SharedArticle::query()->findOrFail($article->id)->getAllTranslations())->toBe(['ka' => ['title' => 'საფერავი']])
        ->and(DB::table('translations_v0_1')->count())->toBe(1)
        ->and(Schema::hasTable('translation_states'))->toBeTrue();

    // Rolling back the upgrade restores the 0.1 table, and migrating
    // upgrades it again.
    $this->artisan('migrate:rollback', ['--path' => "{$this->appDatabase}/migrations", '--realpath' => true, '--step' => 1, '--force' => true])->assertSuccessful();

    expect(Schema::hasColumn('translations', 'values'))->toBeTrue();

    migrateApp($this->appDatabase);

    expect(Schema::hasColumn('translations', 'attribute'))->toBeTrue();

    // A fresh database replays the whole history: 0.1 tables, then the
    // upgrade, which drops the empty 0.1 table.
    migrateApp($this->appDatabase, 'migrate:fresh');

    expect(Schema::hasColumn('translations', 'attribute'))->toBeTrue()
        ->and(Schema::hasTable('translations_v0_1'))->toBeFalse()
        ->and(Schema::hasTable('translation_states'))->toBeTrue();

    migrateApp($this->appDatabase, 'migrate:reset');

    expect(Schema::hasTable('translations'))->toBeFalse();
})->with(['0.1.0', '0.1.13', '0.1.16']);

it('gives 0.1 migrations the configured table names', function (): void {
    config(['translations.shared.table' => 'wine_translations', 'translations.states.table' => 'wine_translation_states']);

    $config = resolve(TranslationConfig::class);

    expect($config->translationsTable)->toBe('wine_translations')
        ->and($config->translationStatesTable)->toBe('wine_translation_states');
});
