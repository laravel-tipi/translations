<?php

declare(strict_types=1);

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Exceptions\MalformedTranslationsException;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Support\MorphKeyType;
use Tipi\Translations\Tests\Fixtures\Models\ElsewhereTranslation;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedCastArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\UlidSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\UuidSharedArticle;
use Tipi\Translations\Tests\TestCase;

afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    SchemaBuilder::$defaultMorphKeyType = 'int';
});

/**
 * Create the shared translations and states tables for a key type, under
 * their own names, and use them.
 */
function usePolymorphicTables(string $keyType): void
{
    config([
        'translations.morph_key_type' => $keyType,
        'translations.shared.table' => "translations_{$keyType}",
        'translations.states.table' => "translation_states_{$keyType}",
    ]);

    (require __DIR__.'/../../database/migrations/create_translations_table.php')->up();
    (require __DIR__.'/../../database/migrations/create_translation_states_table.php')->up();
}

/**
 * @return list<array<string, mixed>>
 */
function sharedRows(): array
{
    return DB::table(config('translations.shared.table'))
        ->orderBy('translatable_type')->orderBy('translatable_id')->orderBy('locale_code')->orderBy('attribute')
        ->get(['translatable_type', 'translatable_id', 'locale_code', 'attribute', 'value'])
        ->map(fn (object $row): array => [...(array) $row, 'value' => json_decode($row->value, true)])
        ->all();
}

describe('storage', function (): void {
    it('stores one row per locale and attribute, the value as JSON', function (): void {
        $article = article(SharedArticle::class, [
            'en' => ['title' => 'Saperavi', 'body' => 'Dark red'],
            'ka' => ['title' => 'საფერავი'],
        ]);

        expect(sharedRows())->toBe([
            ['translatable_type' => SharedArticle::class, 'translatable_id' => $article->id, 'locale_code' => 'en', 'attribute' => 'body', 'value' => 'Dark red'],
            ['translatable_type' => SharedArticle::class, 'translatable_id' => $article->id, 'locale_code' => 'en', 'attribute' => 'title', 'value' => 'Saperavi'],
            ['translatable_type' => SharedArticle::class, 'translatable_id' => $article->id, 'locale_code' => 'ka', 'attribute' => 'title', 'value' => 'საფერავი'],
        ])
            // Stored like JSON storage stores it: Unicode unescaped.
            ->and(DB::table('translations')->where('locale_code', 'ka')->value('value'))->toContain('საფერავი');
    });

    it('keeps the JSON type of values, like JSON storage', function (mixed $value): void {
        $article = article(SharedCastArticle::class, ['en' => ['content' => $value]]);

        // JSON columns of MySQL and PostgreSQL normalize the order of
        // object keys, as for JSON storage: compare values, not order.
        expect(reload($article)->getTranslation('content', 'en'))->toEqual($value)
            ->and(SharedCastArticle::query()->pluckTranslation('content', locale: 'en')->all())->toEqual([$article->id => $value]);
    })->with([
        'a string' => 'Saperavi',
        'an empty string' => '',
        'an integer' => 42,
        'a float' => 1.5,
        'false' => false,
        'a list' => [[1, 'two', 3.5]],
        'an object' => [['blocks' => [['type' => 'text', 'text' => 'Dark red']]]],
    ]);

    it('deletes the row of a removed value, and never stores null', function (): void {
        $article = reload(article(SharedArticle::class, [
            'en' => ['title' => 'Saperavi', 'body' => 'Dark red'],
            'ka' => ['title' => 'საფერავი', 'body' => 'მუქი წითელი'],
        ]));

        $article->forgetTranslation('body', 'en')->forgetTranslations('ka')->save();

        expect(array_column(sharedRows(), 'attribute', 'locale_code'))->toBe(['en' => 'title'])
            ->and($article->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
    });

    it('keeps rows of attributes no longer declared, without reading them', function (): void {
        $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);

        DB::table('translations')->insert(['translatable_type' => SharedArticle::class, 'translatable_id' => $article->id, 'locale_code' => 'en', 'attribute' => 'subtitle', 'value' => '"Old"']);

        expect(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
            ->and(DB::table('translations')->count())->toBe(2);
    });

    it('treats a stored JSON null as missing in reads and queries', function (): void {
        $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);

        DB::table('translations')->insert(['translatable_type' => SharedArticle::class, 'translatable_id' => $article->id, 'locale_code' => 'ka', 'attribute' => 'title', 'value' => 'null']);

        useLocale('ka');

        expect(reload($article)->title)->toBe('Saperavi')
            ->and(reload($article)->hasTranslation('title', 'ka'))->toBeFalse()
            ->and(SharedArticle::whereDoesntHaveTranslation('title', 'ka')->count())->toBe(1)
            ->and(SharedArticle::pluckTranslation('title', locale: 'ka', fallback: true)->all())->toBe([$article->id => 'Saperavi']);
    });

    it('reads malformed stored JSON as an error', function (): void {
        $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);
        DB::table('translations')->update(['value' => '{not json']);

        reload($article)->getTranslation('title', 'en');
    })->throws(MalformedTranslationsException::class)->skip(
        fn () => TestCase::databaseDriver() !== 'sqlite',
        'MySQL, MariaDB and PostgreSQL validate JSON columns themselves.',
    );

    it('uses the configured table', function (): void {
        config(['translations.shared.table' => 'wine_translations']);
        (require __DIR__.'/../../database/migrations/create_translations_table.php')->up();

        $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);

        expect(DB::table('wine_translations')->count())->toBe(1)
            ->and(DB::table('translations')->count())->toBe(0)
            ->and(SharedArticle::whereTranslation('title', '=', 'Saperavi', 'en')->pluck('id')->all())->toBe([$article->id])
            ->and(SharedArticle::withTranslations()->get()->first()->getTranslation('title', 'en'))->toBe('Saperavi');
    });

    it('rejects a translation model that does not extend the package model', function (): void {
        config(['translations.shared.model' => TranslationState::class]);

        (new SharedArticle)->translations();
    })->throws(InvalidTranslatableModelException::class, '[translations.shared.model] must extend');
});

describe('polymorphism', function (): void {
    it('keeps models of different types with equal keys apart', function (): void {
        $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);
        $cast = article(SharedCastArticle::class, ['en' => ['position' => 7]]);

        expect($article->id)->toBe($cast->id)
            ->and(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
            ->and(reload($cast)->getAllTranslations())->toBe(['en' => ['position' => 7]])
            ->and(SharedArticle::withTranslations()->get()->first()->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
            ->and(SharedCastArticle::whereHasTranslation('position', 'en')->count())->toBe(1)
            ->and(SharedArticle::whereHasTranslation('title', 'en')->count())->toBe(1)
            ->and(SharedCastArticle::pluckTranslation('position', locale: 'en')->all())->toBe([$cast->id => 7]);
    });

    it('stores the morph map alias and reads by it', function (): void {
        Relation::enforceMorphMap(['shared-article' => SharedArticle::class, 'shared-cast-article' => SharedCastArticle::class]);

        $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);

        expect(DB::table('translations')->value('translatable_type'))->toBe('shared-article')
            ->and(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
            ->and(SharedArticle::whereTranslation('title', '=', 'Saperavi', 'en')->count())->toBe(1)
            ->and(Translation::query()->first()->translatable->is($article))->toBeTrue();
    });

    it('rejects unmapped models when the morph map is enforced', function (): void {
        Relation::enforceMorphMap(['shared-cast-article' => SharedCastArticle::class]);

        article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);
    })->throws(ClassMorphViolationException::class);

    it('works with has(), whereHas() and withCount() on the relation', function (): void {
        article(SharedArticle::class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']], ['slug' => 'two']);
        article(SharedArticle::class, ['en' => ['title' => 'Mtsvane']], ['slug' => 'one']);
        article(SharedArticle::class, [], ['slug' => 'none']);

        expect(SharedArticle::has('translations')->orderBy('slug')->pluck('slug')->all())->toBe(['one', 'two'])
            ->and(SharedArticle::whereHas('translations', fn ($query) => $query->where('locale_code', 'ka'))->pluck('slug')->all())->toBe(['two'])
            ->and(SharedArticle::withCount('translations')->orderBy('slug')->pluck('translations_count', 'slug')->all())->toEqual(['none' => 0, 'one' => 1, 'two' => 2]);
    });
});

describe('key types', function (): void {
    it('follows Laravel\'s default morph key type when not configured', function (): void {
        config(['translations.morph_key_type' => null]);

        expect(MorphKeyType::configured())->toBe(MorphKeyType::Int);

        Schema::morphUsingUlids();

        expect(MorphKeyType::configured())->toBe(MorphKeyType::Ulid);
    });

    it('rejects an unknown key type', function (): void {
        config(['translations.morph_key_type' => 'bigint']);

        MorphKeyType::configured();
    })->throws(InvalidTranslatableModelException::class, '[translations.morph_key_type]');

    it('stores translations of :dataset models', function (string $keyType, string $class): void {
        usePolymorphicTables($keyType);

        $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
        $other = article($class, ['en' => ['title' => 'Mtsvane']]);

        useLocale('de');

        expect(reload($article)->title)->toBe('Saperavi')
            ->and($class::withTranslations()->get()->map->getTranslation('title', 'ka')->all())->toEqualCanonicalizing(['საფერავი', null])
            ->and($class::whereTranslation('title', '=', 'Mtsvane', 'en')->pluck('id')->all())->toBe([$other->id])
            ->and($class::orderByTranslation('title', 'desc', 'ka')->first()->is($article))->toBeTrue()
            ->and($class::pluckTranslation('title', locale: 'ka', fallback: true)->sort()->values()->all())->toBe(['Mtsvane', 'საფერავი']);

        $article->markTranslationOutdated('ka');
        method_exists($article, 'forceDelete') ? $article->forceDelete() : $article->delete();

        expect(DB::table("translations_{$keyType}")->pluck('translatable_id')->map(strval(...))->unique()->values()->all())->toBe([(string) $other->getKey()])
            ->and(DB::table("translation_states_{$keyType}")->pluck('translatable_id')->map(strval(...))->unique()->values()->all())->toBe([(string) $other->getKey()]);
    })->with([
        'integer' => ['int', StatefulSharedArticle::class],
        'UUID' => ['uuid', UuidSharedArticle::class],
        'ULID' => ['ulid', UlidSharedArticle::class],
    ]);

    it('stores models with integer, UUID and ULID keys in one string-keyed table', function (): void {
        usePolymorphicTables('string');

        $int = article(SharedArticle::class, ['en' => ['title' => 'Integer']]);
        $uuid = article(UuidSharedArticle::class, ['en' => ['title' => 'UUID']]);
        $ulid = article(UlidSharedArticle::class, ['en' => ['title' => 'ULID']]);
        $others = [article(SharedArticle::class, ['ka' => ['title' => 'სხვა']]), article(UuidSharedArticle::class), article(UlidSharedArticle::class)];

        expect(DB::table('translations_string')->pluck('translatable_id')->sort()->values()->all())
            ->toBe(collect([(string) $int->id, $uuid->id, $ulid->id, (string) $others[0]->id])->sort()->values()->all());

        foreach ([$int, $uuid, $ulid] as $model) {
            $class = $model::class;

            expect(reload($model)->getTranslation('title', 'en'))->toBe($model->getTranslation('title', 'en'))
                ->and($class::with('translations')->get()->keyBy->getKey()->map->getTranslation('title', 'en')->filter()->all())->toBe([$model->getKey() => $model->getTranslation('title', 'en')])
                ->and($class::has('translations')->pluck('id')->all())->toContain($model->getKey())
                ->and($class::withCount('translations')->get()->pluck('translations_count', 'id')->get($model->getKey()))->toEqual(1)
                ->and($class::whereTranslation('title', 'like', '%'.substr($model->getTranslation('title', 'en'), 1).'%', 'en')->pluck('id')->all())->toBe([$model->getKey()])
                ->and($class::pluckTranslation('title', locale: 'en')->filter()->all())->toBe([$model->getKey() => $model->getTranslation('title', 'en')]);
        }

        $uuid->delete();

        expect(DB::table('translations_string')->count())->toBe(3);
    });

    it('rejects models whose key type does not fit the column', function (string $keyType, string $class): void {
        usePolymorphicTables($keyType);

        (new $class)->translations();
    })->throws(InvalidTranslatableModelException::class, 'translatable_id column')->with([
        'UUID model, integer column' => ['int', UuidSharedArticle::class],
        'integer model, UUID column' => ['uuid', SharedArticle::class],
        'integer model, ULID column' => ['ulid', SharedArticle::class],
    ]);

    it('rejects keys that do not fit the column before any query', function (string $keyType, string $class, string $key): void {
        usePolymorphicTables($keyType);

        $article = (new $class)->forceFill(['id' => $key]);

        expect(fn () => $article->setTranslation('title', 'en', 'Saperavi'))->toThrow(InvalidTranslatableModelException::class, "the key [{$key}] does not fit")
            ->and(fn () => $article->getTranslation('title', 'en'))->toThrow(InvalidTranslatableModelException::class)
            ->and(DB::table("translations_{$keyType}")->count())->toBe(0);
    })->with([
        'a ULID in a UUID column' => ['uuid', UlidSharedArticle::class, (string) Str::ulid()],
        'a UUID in a ULID column' => ['ulid', UuidSharedArticle::class, (string) Str::uuid()],
    ]);

    it('rejects string keys longer than the column', function (): void {
        usePolymorphicTables('string');

        expect(fn () => MorphKeyType::String->ensureKey((new UuidSharedArticle)->forceFill(['id' => str_repeat('k', 65)])))
            ->toThrow(InvalidTranslatableModelException::class, 'does not fit the [string] translatable_id column');
    });
});

describe('connections', function (): void {
    beforeEach(function (): void {
        config(['database.connections.elsewhere' => config('database.connections.testing')]);
    });

    it('writes the rows on the model\'s own connection, in its transaction', function (): void {
        if (TestCase::databaseDriver() === 'sqlite') {
            // A separate in-memory database: create the tables there.
            Schema::connection('elsewhere')->create('shared_articles', function ($table): void {
                $table->id();
                $table->foreignId('parent_id')->nullable();
                $table->string('slug')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });

            config(['database.default' => 'elsewhere']);
            (require __DIR__.'/../../database/migrations/create_translations_table.php')->up();
            config(['database.default' => 'testing']);
        }

        $article = (new SharedArticle)->setConnection('elsewhere')->setTranslation('title', 'en', 'Saperavi');
        $article->save();

        expect($article->translations()->getRelated()->getConnectionName())->toBe('elsewhere')
            ->and(SharedArticle::on('elsewhere')->findOrFail($article->id)->getTranslation('title', 'en'))->toBe('Saperavi')
            ->and(DB::connection('elsewhere')->table('translations')->count())->toBe(1);

        if (TestCase::databaseDriver() === 'sqlite') {
            expect(DB::table('translations')->count())->toBe(0);
        }

        SharedArticle::saved(fn () => throw new RuntimeException('Failed.'));

        expect(fn () => $article->setTranslation('title', 'ka', 'საფერავი')->save())->toThrow(RuntimeException::class)
            ->and(DB::connection('elsewhere')->table('translations')->count())->toBe(1);

        SharedArticle::flushEventListeners();
    });

    it('rejects a translation model on another connection than the model', function (): void {
        config(['translations.shared.model' => ElsewhereTranslation::class]);

        $article = (new SharedArticle)->setTranslation('title', 'en', 'Saperavi');

        expect(fn () => $article->save())->toThrow(InvalidTranslatableModelException::class, 'Both must use one connection')
            ->and($article->exists)->toBeFalse()
            ->and(SharedArticle::query()->count())->toBe(0)
            ->and($article->getDirtyTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
    });
});

describe('integrity', function (): void {
    it('refuses to save translations of a model deleted meanwhile', function (string $class): void {
        $article = article($class, ['en' => ['title' => 'Saperavi']]);
        $stale = reload($article);

        $article->delete();
        $stale->setTranslation('title', 'ka', 'საფერავი');

        expect(fn () => $stale->save())->toThrow(ModelNotFoundException::class)
            ->and($stale->getDirtyTranslations())->toBe(['ka' => ['title' => 'საფერავი']])
            ->and(storedTranslationRows($class))->toBe(0);
    })->with('models');

    it('prunes rows whose model was deleted without Eloquent', function (): void {
        $kept = article(StatefulSharedArticle::class, ['en' => ['title' => 'Saperavi']]);
        $gone = article(StatefulSharedArticle::class, ['en' => ['title' => 'Mtsvane'], 'ka' => ['title' => 'მწვანე']]);
        $trashed = article(StatefulSharedArticle::class, ['en' => ['title' => 'Rkatsiteli']]);
        $trashed->delete();

        StatefulSharedArticle::query()->whereKey($gone->id)->forceDelete();
        DB::table('translations')->insert(['translatable_type' => 'App\\Models\\Removed', 'translatable_id' => 1, 'locale_code' => 'en', 'attribute' => 'title', 'value' => '"x"']);

        $this->artisan('translations:prune-orphans', ['--pretend' => true])
            ->expectsOutputToContain('Found 2 orphaned rows')
            ->expectsOutputToContain('Skipped [App\\Models\\Removed]')
            ->assertSuccessful();

        expect(Translation::query()->count())->toBe(5)
            ->and(TranslationState::query()->count())->toBe(4);

        $this->artisan('translations:prune-orphans')->assertSuccessful();

        expect(Translation::query()->where('translatable_type', StatefulSharedArticle::class)->pluck('translatable_id')->unique()->sort()->values()->all())->toBe([$kept->id, $trashed->id])
            ->and(Translation::query()->where('translatable_type', 'App\\Models\\Removed')->count())->toBe(1)
            ->and(TranslationState::query()->pluck('translatable_id')->unique()->sort()->values()->all())->toBe([$kept->id, $trashed->id]);
    });
});

/**
 * Translation rows of the storage the model class uses.
 *
 * @param  class-string<Model>  $class
 */
function storedTranslationRows(string $class): int
{
    return match (true) {
        is_a($class, SharedArticle::class, true) => Translation::query()->count(),
        str_contains($class, 'Table') => DB::table('table_article_translations')->count(),
        default => count(array_filter(DB::table('json_articles')->pluck('title')->all())),
    };
}
