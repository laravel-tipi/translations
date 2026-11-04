<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;

/*
 | Drafts: unpublished values beside the published ones, never read as
 | translations. Sources: what a translation was made from.
 */

dataset('stateful storages', [
    'json' => StatefulJsonArticle::class,
    'table' => StatefulTableArticle::class,
    'shared' => StatefulSharedArticle::class,
]);

it('stores drafts and sources with the next save', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $article->setTranslationDraft('title', 'ka', 'საფერ…')->setTranslationSource('title', 'ka', ['0' => 'abc']);

    expect($article->getTranslationDraft('title', 'ka'))->toBe('საფერ…')
        ->and($article->getDirtyTranslationStates())->toHaveKey('ka')
        ->and(reload($article)->hasTranslationDraft('title', 'ka'))->toBeFalse();

    $article->save();

    $fresh = reload($article);

    expect($fresh->getTranslationDraft('title', 'ka'))->toBe('საფერ…')
        ->and($fresh->getTranslationDrafts('ka'))->toBe(['title' => 'საფერ…'])
        ->and($fresh->getTranslationSource('title', 'ka'))->toBe(['0' => 'abc'])
        ->and($article->getDirtyTranslationStates())->toBe([]);

    $fresh->forgetTranslationDraft('title', 'ka')->save();

    expect(reload($article)->getTranslationDrafts('ka'))->toBe([])
        ->and(reload($article)->getTranslationSource('title', 'ka'))->toBe(['0' => 'abc']);
})->with('stateful storages');

it('keeps a draft’s source with the draft, apart from the published one', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $article->setTranslationSource('title', 'ka', ['0' => 'published'])->save();

    reload($article)->setTranslationDraft('title', 'ka', 'საფერ…', ['0' => 'draft'])->save();

    $fresh = reload($article);

    expect($fresh->getTranslationDraftSource('title', 'ka'))->toBe(['0' => 'draft'])
        ->and($fresh->getTranslationSource('title', 'ka'))->toBe(['0' => 'published'])
        ->and($fresh->getTranslationDrafts('ka'))->toBe(['title' => 'საფერ…']);

    // Publishing: the translation with the draft's source.
    $fresh->setTranslation('title', 'ka', 'საფერავი!')
        ->setTranslationSource('title', 'ka', $fresh->getTranslationDraftSource('title', 'ka'))
        ->forgetTranslationDraft('title', 'ka')
        ->save();

    $fresh = reload($article);

    expect($fresh->getTranslationSource('title', 'ka'))->toBe(['0' => 'draft'])
        ->and($fresh->hasTranslationDraft('title', 'ka'))->toBeFalse()
        ->and($fresh->getTranslationDraftSource('title', 'ka'))->toBeNull();
})->with('stateful storages');

it('forgets the source of a translation changed without one', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Dry red.'], 'ka' => ['title' => 'საფერავი', 'body' => 'მშრალი.']]);
    $article->setTranslationSource('title', 'ka', 't')->setTranslationSource('body', 'ka', 'b')->save();

    // Written by other code: the old source no longer describes it.
    reload($article)->setTranslation('title', 'ka', 'სხვა')->save();

    $fresh = reload($article);

    expect($fresh->getTranslationSource('title', 'ka'))->toBeNull()
        ->and($fresh->getTranslationSource('body', 'ka'))->toBe('b');

    // Set together, the new source is kept; saving the same value changes nothing.
    $fresh->setTranslation('title', 'ka', 'სათაური')->setTranslationSource('title', 'ka', 'new')->save();
    reload($article)->setTranslation('title', 'ka', 'სათაური')->save();

    expect(reload($article)->getTranslationSource('title', 'ka'))->toBe('new');
})->with('stateful storages');

it('never reads drafts as translations', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $article->setTranslationDraft('title', 'ka', 'Draft')->save();

    $fresh = reload($article);
    $fresh->load('translationStates');
    useLocale('ka');

    expect($fresh->title)->toBe('Saperavi')
        ->and($fresh->getTranslation('title', 'ka'))->toBeNull()
        ->and($fresh->resolveTranslation('title', 'ka')->isFallback())->toBeTrue()
        ->and($fresh->getTranslatedLocales())->toBe(['en'])
        ->and($fresh->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
        ->and($class::query()->whereHasTranslation('title', 'ka')->count())->toBe(0)
        ->and(json_encode($fresh->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('Draft');
})->with('stateful storages');

it('keeps the published value while a draft is saved, and publishes explicitly', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $article->setTranslationDraft('title', 'ka', 'საფერავი (ახალი)')->save();

    expect(reload($article)->getTranslation('title', 'ka'))->toBe('საფერავი')
        ->and(reload($article)->getTranslationDraft('title', 'ka'))->toBe('საფერავი (ახალი)');

    reload($article)->setTranslation('title', 'ka', 'საფერავი (ახალი)')->forgetTranslationDraft('title', 'ka')->save();

    expect(reload($article)->getTranslation('title', 'ka'))->toBe('საფერავი (ახალი)')
        ->and(reload($article)->hasTranslationDraft('title', 'ka'))->toBeFalse();
})->with('stateful storages');

it('writes drafts in the save’s transaction, and keeps them pending when it fails', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $class::saved(fn () => throw new RuntimeException('Rejected'));

    $article->setTranslation('body', 'ka', 'წითელი')->setTranslationDraft('title', 'ka', 'Draft');

    try {
        expect(fn () => $article->save())->toThrow(RuntimeException::class);
    } finally {
        $class::flushEventListeners();
    }

    expect(TranslationState::query()->where('locale_code', 'ka')->count())->toBe(0)
        ->and(reload($article)->getTranslatedLocales())->toBe(['en'])
        ->and($article->getTranslationDraft('title', 'ka'))->toBe('Draft');

    $article->save();

    expect(reload($article)->getTranslationDraft('title', 'ka'))->toBe('Draft')
        ->and(reload($article)->getTranslation('body', 'ka'))->toBe('წითელი');
})->with('stateful storages');

it('saves a draft without updating the model', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $updated = (string) reload($article)->updated_at;
    $events = 0;
    $class::updated(function () use (&$events): void {
        $events++;
    });

    $this->travel(1)->hours();

    try {
        reload($article)->setTranslationDraft('title', 'ka', 'Draft')->save();
    } finally {
        $class::flushEventListeners();
    }

    expect((string) reload($article)->updated_at)->toBe($updated)
        ->and($events)->toBe(0)
        ->and(reload($article)->getTranslationDraft('title', 'ka'))->toBe('Draft');
})->with('stateful storages');

it('merges drafts of different attributes saved from stale copies', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]);
    $first = reload($article);
    $second = reload($article);
    $first->getTranslationState('ka');
    $second->getTranslationState('ka');

    $first->setTranslationDraft('title', 'ka', 'სათაური')->save();
    $second->setTranslationDraft('body', 'ka', 'ტექსტი')->save();

    expect(reload($article)->getTranslationDrafts('ka'))->toBe(['title' => 'სათაური', 'body' => 'ტექსტი']);
})->with('stateful storages');

it('reads, decides and writes drafts inside lockTranslationsForUpdate()', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $stale = reload($article);
    $stale->getTranslationState('ka');

    reload($article)->setTranslationDraft('title', 'ka', 'Theirs')->save();

    $seen = $stale->lockTranslationsForUpdate(function ($model): mixed {
        $seen = $model->getTranslationDraft('title', 'ka');
        $model->setTranslationDraft('body', 'ka', 'Mine');

        return $seen;
    });

    expect($seen)->toBe('Theirs')
        ->and(reload($article)->getTranslationDrafts('ka'))->toBe(['title' => 'Theirs', 'body' => 'Mine']);

    expect(fn () => $stale->lockTranslationsForUpdate(function ($model): void {
        $model->setTranslationDraft('title', 'ka', 'Discarded');

        throw new RuntimeException('Abort');
    }))->toThrow(RuntimeException::class);

    expect($stale->getDirtyTranslationStates())->toBe([])
        ->and(reload($article)->getTranslationDraft('title', 'ka'))->toBe('Theirs');

    $stale->setTranslationDraft('title', 'ka', 'Pending');

    expect(fn () => $stale->lockTranslationsForUpdate(fn () => null))->toThrow(LogicException::class);
})->with('stateful storages');

it('keeps the drafts of a locale whose translations are removed', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $article->setTranslationDraft('body', 'ka', 'Draft')->save();
    reload($article)->markTranslationOutdated('ka');

    reload($article)->forgetTranslations('ka')->save();

    $fresh = reload($article);

    expect($fresh->getTranslationDraft('body', 'ka'))->toBe('Draft')
        ->and($fresh->isTranslationOutdated('ka'))->toBeFalse();
})->with('stateful storages');

it('deletes drafts with the model', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $article->setTranslationDraft('title', 'ka', 'Draft')->save();

    $fresh = reload($article);
    method_exists($fresh, 'forceDelete') ? $fresh->forceDelete() : $fresh->delete();

    expect(TranslationState::query()->count())->toBe(0);
})->with('stateful storages');

it('refuses drafts of attributes that aren’t translatable', function (string $class): void {
    expect(fn () => (new $class)->setTranslationDraft('slug', 'ka', 'x'))
        ->toThrow(AttributeNotTranslatableException::class);
})->with('stateful storages');

it('hides drafts and sources when states are serialized', function (): void {
    $article = article(StatefulJsonArticle::class, ['en' => ['title' => 'Saperavi']]);
    $article->setTranslationDraft('title', 'ka', 'Draft')->setTranslationSource('title', 'ka', 'x')->save();

    expect(DB::table(config('translations.states.table', 'translation_states'))->where('locale_code', 'ka')->first()->drafts)->not->toBeNull()
        ->and(TranslationState::query()->where('locale_code', 'ka')->first()->toArray())->not->toHaveKeys(['drafts', 'sources']);
});
