<?php

declare(strict_types=1);
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;

/*
 | isDirty(), isClean() and wasChanged() understand translated attributes.
 | getDirty(), getChanges() and getOriginal() stay column-level, because
 | Eloquent writes exactly what getDirty() returns.
 */

it('reports unsaved translations as dirty attributes', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']));

    expect($article->isDirty())->toBeFalse();

    $article->setTranslation('title', 'ka', 'საფერავი');

    expect($article->isDirty())->toBeTrue()
        ->and($article->isDirty('title'))->toBeTrue()
        ->and($article->isDirty('body'))->toBeFalse()
        ->and($article->isDirty('slug'))->toBeFalse()
        ->and($article->isDirty(['slug', 'title']))->toBeTrue()
        ->and($article->isDirty('slug', 'title'))->toBeTrue()
        ->and($article->isClean('title'))->toBeFalse()
        ->and($article->isClean('body'))->toBeTrue();

    $article->save();

    expect($article->isDirty())->toBeFalse()
        ->and($article->isClean('title'))->toBeTrue();
})->with('storages');

it('is clean again when a change is reverted', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'en', 'Changed')->setTranslation('title', 'en', 'Saperavi');

    expect($article->isDirty())->toBeFalse();
})->with('storages');

it('lists the unsaved translations', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]));

    $article->setTranslation('title', 'ka', 'საფერავი')->forgetTranslation('body', 'en');

    expect($article->getDirtyTranslations())->toBe([
        'en' => ['body' => null],
        'ka' => ['title' => 'საფერავი'],
    ]);
})->with('storages');

it('reports translated attributes changed by the last update', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']);

    // As for columns: wasChanged() describes updates, not inserts.
    expect($article->wasChanged('title'))->toBeFalse();

    $this->travel(1)->minute();
    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect($article->wasChanged('title'))->toBeTrue()
        ->and($article->wasChanged('body'))->toBeFalse()
        ->and($article->wasChanged())->toBeTrue();

    $this->travel(1)->minute();
    $article->update(['slug' => 'saperavi-2020']);

    expect($article->wasChanged('slug'))->toBeTrue()
        ->and($article->wasChanged('title'))->toBeFalse();
})->with('storages');

it('never puts table-stored translations into the column-level dirty set', function (): void {
    $article = reload(article(TableArticle::class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'ka', 'საფერავი');

    expect($article->getDirty())->toBe([]);
});
