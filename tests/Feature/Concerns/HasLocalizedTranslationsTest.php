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
