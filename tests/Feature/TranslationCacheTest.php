<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\DeleteTranslation;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;

dataset('cached translation storage strategies', [
    'dedicated table' => fn (): DedicatedArticle => new DedicatedArticle,
    'shared table' => fn (): SharedArticle => new SharedArticle,
    'json' => fn (): JsonArticle => new JsonArticle,
]);

function createCachedTranslationArticle(
    Model&TranslatableModel $article,
): Model&TranslatableModel {
    $article->save();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
    );

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'ka',
        attributes: [
            'title' => 'ქართული სათაური',
            'description' => 'ქართული აღწერა',
        ],
    );

    return $article;
}

it('reuses a resolved translation', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $first = $article->getTranslation('en');
    $second = $article->getTranslation('en');
    $third = $article->getTranslation('en');

    expect($first)->not->toBeNull()
        ->and($second)->toBe($first)
        ->and($third)->toBe($first);
})->with('cached translation storage strategies');

it('caches translations independently by locale', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $english = $article->getTranslation('en');
    $georgian = $article->getTranslation('ka');

    expect($english)->not->toBeNull()
        ->and($georgian)->not->toBeNull()
        ->and($georgian)->not->toBe($english)
        ->and($article->getTranslation('en'))->toBe($english)
        ->and($article->getTranslation('ka'))->toBe($georgian);
})->with('cached translation storage strategies');

it('reuses the cached translation when reading translated attributes', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $translation = $article->getTranslation('en');

    expect($article->translated('title', localeCode: 'en'))
        ->toBe('English title')
        ->and($article->getTranslation('en'))
        ->toBe($translation);
})->with('cached translation storage strategies');

it('invalidates a cached translation after update', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $before = $article->getTranslation('en');

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'Updated title',
        ],
    );

    $after = $article->getTranslation('en');

    expect($after)->not->toBeNull()
        ->and($after)->not->toBe($before)
        ->and($after->attributes['title'])->toBe('Updated title');
})->with('cached translation storage strategies');

it('updates the cache after deletion', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $before = $article->getTranslation('ka');

    expect($before)->not->toBeNull();

    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'ka',
    );

    expect($article->getTranslation('ka'))->toBeNull();
})->with('cached translation storage strategies');

it('invalidates a cached missing translation after creation', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    // The missing result itself should be cached.
    expect($article->getTranslation('en'))->toBeNull()
        ->and($article->getTranslation('en'))->toBeNull();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English title',
        ],
    );

    $translation = $article->getTranslation('en');

    expect($translation)->not->toBeNull()
        ->and($translation->attributes['title'])->toBe('English title');
})->with('cached translation storage strategies');

it('populates the translation cache when retrieving all translations', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $translations = $article->getTranslations();

    $english = $translations->first(
        fn ($translation): bool => $translation->localeCode === 'en',
    );

    $georgian = $translations->first(
        fn ($translation): bool => $translation->localeCode === 'ka',
    );

    expect($english)->not->toBeNull()
        ->and($georgian)->not->toBeNull()
        ->and($article->getTranslation('en'))->toBe($english)
        ->and($article->getTranslation('ka'))->toBe($georgian);
})->with('cached translation storage strategies');

it('reuses resolved translations when retrieving all translations', function (
    Model&TranslatableModel $article,
): void {
    $article = createCachedTranslationArticle($article);

    $english = $article->getTranslation('en');

    $translations = $article->getTranslations();

    $englishFromAll = $translations->first(
        fn ($translation): bool => $translation->localeCode === 'en',
    );

    expect($englishFromAll)->toBe($english);
})->with('cached translation storage strategies');

it('replaces a cached missing translation after creation', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    expect($article->getTranslation('en'))->toBeNull();

    $created = resolve(CreateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English title',
        ],
    );

    $resolved = $article->getTranslation('en');

    expect($resolved)->toBe($created)
        ->and($resolved->attributes['title'])->toBe('English title');
})->with('cached translation storage strategies');
