<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Localization\FakeLocaleProvider;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;

dataset('translatable models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
    'json columns' => fn () => JsonArticle::query()->create(),
]);

it('returns the translated attribute for the current locale', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
        localeCode: 'en',
    );

    expect($article->title)->toBe('English title')
        ->and($article->description)->toBe('English description');
})->with('translatable models');

it('returns translated attributes for the current locale', function (
    Model&TranslatableModel $article,
): void {
    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English title'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული სათაური'],
        localeCode: 'ka',
    );

    $locales = resolve(FakeLocaleProvider::class);

    $locales->setCurrent('en');

    expect($article->title)->toBe('English title');

    $locales->setCurrent('ka');

    expect($article->title)->toBe('ქართული სათაური');
})->with('translatable models');

it('considers an explicitly null json value to be an existing translation', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => null,
        ],
        localeCode: 'en',
    );

    expect($article->translationExists('en'))->toBeTrue()
        ->and($article->getTranslation('en')?->attributes)
        ->toBe([
            'title' => null,
        ]);
});

it('considers an empty string json value to be an existing translation', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => '',
        ],
        localeCode: 'en',
    );

    expect($article->translationExists('en'))->toBeTrue()
        ->and($article->getTranslation('en')?->attributes)
        ->toBe([
            'title' => '',
        ]);
});

it('returns sparse json translations without inventing missing attributes', function (): void {
    $article = JsonArticle::query()->create();

    $article->setAttribute('title', [
        'en' => 'English',
        'ka' => 'ქართული',
    ]);

    $article->setAttribute('description', [
        'en' => 'English description',
        'de' => 'Deutsche Beschreibung',
    ]);

    $article->save();

    $translations = $article->getTranslations()
        ->keyBy(
            fn ($translation) => $translation->localeCode,
        );

    expect($translations)->toHaveCount(3)
        ->and($translations['en']->attributes)->toBe([
            'title' => 'English',
            'description' => 'English description',
        ])
        ->and($translations['ka']->attributes)->toBe([
            'title' => 'ქართული',
        ])
        ->and($translations['de']->attributes)->toBe([
            'description' => 'Deutsche Beschreibung',
        ]);
});
