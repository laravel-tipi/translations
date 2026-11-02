<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;
use Tipi\Translations\Tests\Fixtures\Models\UuidArticle;
use Tipi\Translations\Tests\Fixtures\Models\UuidArticleTranslation;

afterEach(function (): void {
    foreach ([JsonArticle::class, TableArticle::class, TableArticleTranslation::class, SharedArticle::class, Translation::class, TranslationState::class] as $model) {
        $model::flushEventListeners();
    }
});

it('creates a model with translations in several locales', function (string $class): void {
    $article = article($class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი'],
    ], ['slug' => 'saperavi']);

    expect($article->exists)->toBeTrue()
        ->and(reload($article)->slug)->toBe('saperavi')
        ->and(reload($article)->getTranslatedLocales())->toBe(['en', 'ka']);
})->with('storages');

it('rolls back the model when a translation cannot be written', function (): void {
    Schema::drop('table_article_translations');

    $article = (new TableArticle(['slug' => 'saperavi']))->setTranslation('title', 'en', 'Saperavi');

    expect(fn () => $article->save())->toThrow(QueryException::class)
        ->and(TableArticle::query()->count())->toBe(0)
        ->and($article->getDirtyTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
});

it('rolls back the model and its translations when a later step fails', function (): void {
    TranslationState::creating(fn () => throw new RuntimeException('State write failed.'));

    $article = (new StatefulJsonArticle)->setTranslation('title', 'en', 'Saperavi');

    expect(fn () => $article->save())->toThrow(RuntimeException::class)
        ->and(StatefulJsonArticle::query()->count())->toBe(0);
});

it('keeps an existing model unchanged when translation writes fail', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']], ['slug' => 'before']);

    $class::updating(fn () => throw new RuntimeException('Update failed.'));

    $article->slug = 'after';
    $article->setTranslation('title', 'en', 'Changed');

    expect(fn () => $article->save())->toThrow(RuntimeException::class);

    $fresh = reload($article);

    expect($fresh->slug)->toBe('before')
        ->and($fresh->getTranslation('title', 'en'))->toBe('Saperavi');
})->with('storages');

it('does not write translations when a saving listener halts the save', function (string $class): void {
    $class::saving(fn () => false);

    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');

    expect($article->save())->toBeFalse()
        ->and($article->isDirty())->toBeTrue()
        ->and($class::query()->count())->toBe(0)
        ->and(TableArticleTranslation::query()->count())->toBe(0);
})->with('storages');

it('writes translations with saveQuietly()', function (string $class): void {
    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');

    $article->saveQuietly();

    expect(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi');
})->with('storages');

it('joins a surrounding transaction', function (string $class): void {
    DB::beginTransaction();

    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    DB::rollBack();

    expect($class::query()->count())->toBe(0)
        ->and(TableArticleTranslation::query()->count())->toBe(0);
})->with('storages');

it('discards unsaved translations on refresh()', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'en', 'Changed')->refresh();

    expect($article->isDirty())->toBeFalse()
        ->and($article->title)->toBe('Saperavi');
})->with('storages');

it('replicates translations', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
    ]));

    $copy = $article->replicate();
    $copy->save();

    $withoutBody = $article->replicate(['body']);
    $withoutBody->save();

    expect($copy->getKey())->not->toBe($article->getKey())
        ->and(reload($copy)->getAllTranslations())->toBe($article->getAllTranslations())
        ->and(reload($withoutBody)->getAllTranslations())->toBe([
            'en' => ['title' => 'Saperavi'],
            'ka' => ['title' => 'საფერავი'],
        ])
        ->and(reload($article)->getAllTranslations())->toBe($article->getAllTranslations());
})->with('storages');

it('touches updated_at when only translations change', function (string $class): void {
    $this->travelTo('2026-01-01 10:00:00');
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $this->travelTo('2026-01-02 10:00:00');
    reload($article)->setTranslation('title', 'ka', 'საფერავი')->save();

    expect(reload($article)->updated_at->toDateTimeString())->toBe('2026-01-02 10:00:00');
})->with('storages');

it('does not write anything when nothing changed', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'en', 'Saperavi');

    expect(countQueries(fn () => $article->save()))->toBe(0);
})->with('storages');

/*
 | Concurrency: two copies of the same model, edited independently.
 */

it('keeps concurrent edits of different locales', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $first = reload($article);
    $second = reload($article);
    $first->title;
    $second->title;

    $first->setTranslation('title', 'ka', 'საფერავი')->save();
    $second->setTranslation('title', 'de', 'Saperawi')->save();

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი'],
        'de' => ['title' => 'Saperawi'],
    ]);
})->with('storages');

it('keeps concurrent edits of different attributes of one locale', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]);

    $first = reload($article);
    $second = reload($article);
    $first->title;
    $second->title;

    $first->setTranslation('title', 'en', 'Saperavi 2020')->save();
    $second->setTranslation('body', 'en', 'Dark red')->save();

    expect(reload($article)->getTranslations('en'))->toBe(['title' => 'Saperavi 2020', 'body' => 'Dark red']);
})->with('storages');

it('lets the last write win for the same value', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $first = reload($article);
    $second = reload($article);
    $first->title;
    $second->title;

    $first->setTranslation('title', 'ka', 'first')->save();
    $second->setTranslation('title', 'ka', 'second')->save();

    expect(reload($article)->getTranslation('title', 'ka'))->toBe('second');
})->with('storages');

it('updates a translation row another process created meanwhile', function (): void {
    $article = article(TableArticle::class, ['en' => ['title' => 'Saperavi']]);

    $stale = reload($article);
    $stale->title; // Loads the rows: no "ka" row yet.

    reload($article)->setTranslation('title', 'ka', 'first')->setTranslation('body', 'ka', 'body')->save();

    $stale->setTranslation('title', 'ka', 'second')->save();

    expect(TableArticleTranslation::query()->where('locale_code', 'ka')->count())->toBe(1)
        ->and(reload($article)->getTranslations('ka'))->toBe(['title' => 'second', 'body' => 'body']);
});

it('keeps a locale another process filled meanwhile when a stale copy empties it', function (string $class): void {
    $article = article($class, ['ka' => ['title' => 'საფერავი']]);

    $stale = reload($article);
    $stale->title; // Loads the rows: "ka" has only a title.

    reload($article)->setTranslation('body', 'ka', 'წითელი')->save();

    $stale->forgetTranslation('title', 'ka')->save();

    expect(reload($article)->getAllTranslations())->toBe(['ka' => ['body' => 'წითელი']]);
})->with('storages');

it('stores a translation another process removed meanwhile', function (string $class): void {
    $article = article($class, ['ka' => ['title' => 'საფერავი']]);

    $stale = reload($article);
    $stale->title;

    reload($article)->forgetTranslations('ka')->save();

    $stale->setTranslation('body', 'ka', 'წითელი')->save();

    expect(reload($article)->getAllTranslations())->toBe(['ka' => ['body' => 'წითელი']]);
})->with('storages');

it('writes translation models with generated keys', function (): void {
    $article = article(UuidArticle::class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);

    $article->setTranslation('title', 'en', 'Changed')->save();

    $ids = UuidArticleTranslation::query()->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids->every(fn (string $id): bool => Str::isUuid($id)))->toBeTrue()
        ->and(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Changed'], 'ka' => ['title' => 'საფერავი']]);
});
