<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedCastArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | The v0.1 shared table, as its migrations created it: one row per model
 | and locale with a "values" object. 0.1.0–0.1.12 also had an
 | "outdated_at" column (until 0.1.15) and Laravel's default unique index
 | name.
 */

function createV01Table(bool $early): void
{
    Schema::drop('translations');

    Schema::create('translations', function (Blueprint $table) use ($early): void {
        $table->id();
        $table->string('translatable_type');
        $table->unsignedBigInteger('translatable_id');
        $table->string('locale_code');
        $table->json('values');

        if ($early) {
            $table->timestamp('outdated_at')->nullable();
        }

        $table->timestamps();

        if (! $early) {
            $table->unique(['translatable_type', 'translatable_id', 'locale_code'], 'translations_owner_locale_unique');
        } elseif (! in_array(TestCase::databaseDriver(), ['mysql', 'mariadb'], true)) {
            $table->unique(['translatable_type', 'translatable_id', 'locale_code']);
        }
        // The early migration's default index name is longer than MySQL
        // allows: it failed after creating the table, without the index.
    });
}

/**
 * A v0.1 row; values encoded like v0.1's "array" cast did.
 *
 * @param  array<string, mixed>|null  $values
 */
function insertV01Row(string $type, int $id, string $locale, ?array $values, string $raw = ''): void
{
    DB::table('translations')->insert([
        'translatable_type' => $type,
        'translatable_id' => $id,
        'locale_code' => $locale,
        'values' => $raw !== '' ? $raw : json_encode($values),
        'created_at' => '2026-10-01 10:00:00',
        'updated_at' => '2026-10-02 10:00:00',
    ]);
}

function upgradeMigration(): object
{
    return require __DIR__.'/../../database/migrations/upgrade_translations_table_from_v0_1.php';
}

it('copies v0.1 translations into the new table and keeps the old one', function (bool $early): void {
    $article = new SharedArticle(['slug' => 'saperavi']);
    $article->saveQuietly();
    $cast = new SharedCastArticle;
    $cast->saveQuietly();

    createV01Table($early);
    insertV01Row(SharedArticle::class, $article->id, 'en', ['title' => 'Saperavi', 'body' => null]);
    insertV01Row(SharedArticle::class, $article->id, 'ka', ['title' => 'საფერავი', 'body' => 'მუქი წითელი']);
    insertV01Row(SharedCastArticle::class, $cast->id, 'en', ['content' => ['blocks' => [1, 2]], 'position' => 7]);
    insertV01Row(SharedCastArticle::class, $cast->id, 'de', null, 'null');

    upgradeMigration()->up();

    $article = SharedArticle::query()->findOrFail($article->id);

    expect($article->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი', 'body' => 'მუქი წითელი'],
    ])
        ->and(SharedCastArticle::query()->findOrFail($cast->id)->getAllTranslations())->toEqual(['en' => ['content' => ['blocks' => [1, 2]], 'position' => 7]])
        ->and(SharedArticle::whereTranslation('title', '=', 'საფერავი', 'ka')->count())->toBe(1)
        ->and(SharedCastArticle::whereTranslation('position', '>', 6, 'en')->count())->toBe(1)
        ->and(DB::table('translations')->count())->toBe(5)
        ->and((string) DB::table('translations')->where('attribute', 'body')->value('updated_at'))->toStartWith('2026-10-02 10:00:00')
        ->and(DB::table('translations_v0_1')->count())->toBe(4)
        ->and(Schema::hasTable('translations_v0_2'))->toBeFalse();

    // The upgraded table takes writes like a new one.
    $article->setTranslation('title', 'de', 'Saperawi')->forgetTranslation('body', 'ka')->save();

    expect(SharedArticle::query()->findOrFail($article->id)->getTranslatedLocales())->toBe(['en', 'ka', 'de'])
        ->and(DB::table('translations_v0_1')->count())->toBe(4);
})->with(['0.1.0–0.1.12' => true, '0.1.16 and later' => false]);

it('restores the v0.1 table when rolled back', function (): void {
    createV01Table(early: false);
    insertV01Row(SharedArticle::class, 1, 'en', ['title' => 'Saperavi']);

    upgradeMigration()->up();
    upgradeMigration()->down();

    expect(Schema::hasColumn('translations', 'values'))->toBeTrue()
        ->and(Schema::hasTable('translations_v0_1'))->toBeFalse()
        ->and(DB::table('translations')->count())->toBe(1);

    // And upgrades again.
    upgradeMigration()->up();

    expect(DB::table('translations')->value('attribute'))->toBe('title');
});

it('does nothing for a table that is not a v0.1 table', function (): void {
    $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);

    upgradeMigration()->up();
    upgradeMigration()->down();

    expect(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and(Schema::hasTable('translations_v0_1'))->toBeFalse();
});

it('refuses key types that cannot hold v0.1 integer keys, before changing anything', function (): void {
    createV01Table(early: false);
    insertV01Row(SharedArticle::class, 1, 'en', ['title' => 'Saperavi']);
    config(['translations.morph_key_type' => 'uuid']);

    expect(fn () => upgradeMigration()->up())->toThrow(RuntimeException::class, 'Set [translations.morph_key_type] to "int" or "string"')
        ->and(Schema::hasColumn('translations', 'values'))->toBeTrue();
});

it('copies integer keys into a string-keyed table', function (): void {
    createV01Table(early: false);
    insertV01Row(SharedArticle::class, 1, 'en', ['title' => 'Saperavi']);
    config(['translations.morph_key_type' => 'string']);

    upgradeMigration()->up();

    expect(DB::table('translations')->value('translatable_id'))->toBe('1');
});

it('stops on malformed values, leaving the v0.1 table in place', function (): void {
    createV01Table(early: false);
    insertV01Row(SharedArticle::class, 1, 'en', ['title' => 'Saperavi']);
    insertV01Row(SharedArticle::class, 2, 'en', null, '["a list"]');

    expect(fn () => upgradeMigration()->up())->toThrow(RuntimeException::class, 'row [2]')
        ->and(Schema::hasColumn('translations', 'values'))->toBeTrue()
        ->and(DB::table('translations')->count())->toBe(2)
        ->and(Schema::hasTable('translations_v0_1'))->toBeFalse();

    // A retry after fixing the row starts over.
    DB::table('translations')->where('translatable_id', 2)->update(['values' => '{"title":"Mtsvane"}']);
    upgradeMigration()->up();

    expect(DB::table('translations')->count())->toBe(2)
        ->and(DB::table('translations_v0_1')->count())->toBe(2);
});

it('drops an empty v0.1 table instead of keeping it', function (): void {
    createV01Table(early: false);

    upgradeMigration()->up();

    expect(Schema::hasColumn('translations', 'attribute'))->toBeTrue()
        ->and(Schema::hasTable('translations_v0_1'))->toBeFalse();

    // Nothing to restore: rolling back leaves the table to the migration
    // that created it.
    upgradeMigration()->down();

    expect(Schema::hasColumn('translations', 'attribute'))->toBeTrue();
});
