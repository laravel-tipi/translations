<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;

/*
 | lockTranslationsForUpdate(): read, decide and write in one locked
 | transaction. ConcurrencyTest runs it with concurrent processes.
 */

it('hands the callback what is stored now, not what the model loaded', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $stale = reload($article);
    $stale->getAllTranslations();

    reload($article)->setTranslation('title', 'ka', 'საფერავი')->save();

    $seen = $stale->lockTranslationsForUpdate(fn ($model) => $model->getAllTranslations());

    expect($seen)->toBe(['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
})->with('models');

it('saves what the callback changes and returns its result', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $result = $article->lockTranslationsForUpdate(function ($model): string {
        $model->setTranslation('title', 'ka', $model->getTranslation('title', 'en').' (ka)');

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($article->getDirtyTranslations())->toBe([])
        ->and(reload($article)->getTranslation('title', 'ka'))->toBe('Saperavi (ka)');
})->with('models');

it('writes nothing and discards the changes when the callback throws', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    expect(fn () => $article->lockTranslationsForUpdate(function ($model): void {
        $model->setTranslation('title', 'ka', 'საფერავი');

        throw new RuntimeException('Conflict');
    }))->toThrow(RuntimeException::class, 'Conflict');

    expect($article->getDirtyTranslations())->toBe([])
        ->and(reload($article)->getTranslatedLocales())->toBe(['en']);
})->with('models');

it('writes nothing when the save fails', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $class::saving(fn () => throw new RuntimeException('Rejected'));

    try {
        expect(fn () => $article->lockTranslationsForUpdate(fn ($model) => $model->setTranslation('title', 'ka', 'საფერავი')))
            ->toThrow(RuntimeException::class, 'Rejected');
    } finally {
        $class::flushEventListeners();
    }

    expect($article->getDirtyTranslations())->toBe([])
        ->and(reload($article)->getTranslatedLocales())->toBe(['en']);
})->with('storages');

it('fails for a model deleted meanwhile', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    reload($article)->delete();

    expect(fn () => $article->lockTranslationsForUpdate(fn ($model) => $model->setTranslation('title', 'ka', 'საფერავი')))
        ->toThrow(ModelNotFoundException::class);
})->with('models');

it('refuses unsaved models and unsaved changes', function (string $class): void {
    expect(fn () => (new $class)->lockTranslationsForUpdate(fn () => null))
        ->toThrow(LogicException::class, 'not saved yet');

    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $article->setTranslation('title', 'ka', 'საფერავი');

    expect(fn () => $article->lockTranslationsForUpdate(fn () => null))
        ->toThrow(LogicException::class, 'unsaved translation changes');
})->with('storages');

it('joins a surrounding transaction', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    try {
        DB::transaction(function () use ($article): void {
            $article->lockTranslationsForUpdate(fn ($model) => $model->setTranslation('title', 'ka', 'საფერავი'));

            throw new RuntimeException('Outer rollback');
        });
    } catch (RuntimeException) {
    }

    expect(reload($article)->getTranslatedLocales())->toBe(['en']);
})->with('storages');

it('reads translation states again', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $article->getTranslationState('ka');

    reload($article)->markTranslationOutdated('ka');

    expect($article->lockTranslationsForUpdate(fn ($model) => $model->isTranslationOutdated('ka')))->toBeTrue();
})->with([StatefulJsonArticle::class, StatefulTableArticle::class, StatefulSharedArticle::class]);
