<?php

declare(strict_types=1);

use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;

it('serializes translatable attributes as resolved values', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
    ], ['slug' => 'saperavi']));

    useLocale('ka');

    $array = $article->toArray();

    expect($array['slug'])->toBe('saperavi')
        ->and($array['title'])->toBe('საფერავი')
        ->and($array['body'])->toBe('Red')
        ->and(json_decode($article->toJson(), true)['title'])->toBe('საფერავი');
})->with('storages');

it('serializes missing values as null', function (string $class): void {
    $article = reload(article($class, ['ka' => ['title' => 'საფერავი']]));

    expect($article->toArray())->toHaveKey('title', null)->toHaveKey('body', null);
})->with('storages');

it('respects hidden and visible attributes', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']], ['slug' => 'saperavi']));

    expect($article->makeHidden('body')->toArray())->toHaveKey('title')->not->toHaveKey('body')
        ->and($article->setVisible(['slug'])->toArray())->toBe(['slug' => 'saperavi']);
})->with('storages');

it('does not expose raw JSON columns', function (): void {
    $article = reload(article(JsonArticle::class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]));

    expect($article->toArray()['title'])->toBe('Saperavi');
});

it('leaves JSON columns that were not selected out of the array', function (): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi']]);

    expect(JsonArticle::query()->select(['id', 'title'])->find($article->id)->toArray())
        ->toHaveKey('title', 'Saperavi')
        ->not->toHaveKey('body');
});

it('serializes table storage like JSON storage', function (): void {
    $json = reload(article(JsonArticle::class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']));
    $table = reload(article(TableArticle::class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']));

    $keys = fn (array $array): array => collect($array)->keys()->diff(['deleted_at'])->sort()->values()->all();

    expect($keys($table->toArray()))->toBe($keys($json->toArray()))
        ->and(TableArticle::with('translations')->find($table->id)->toArray())->not->toHaveKey('translations');
});

it('includes translation rows when made visible', function (): void {
    $article = article(TableArticle::class, ['en' => ['title' => 'Saperavi']]);

    expect(TableArticle::with('translations')->find($article->id)->makeVisible('translations')->toArray()['translations'][0])
        ->toMatchArray(['locale_code' => 'en', 'title' => 'Saperavi']);
});

it('serializes every translation for APIs that need them', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]));

    expect(json_encode(['translations' => $article->getAllTranslations()], JSON_UNESCAPED_UNICODE))
        ->toBe('{"translations":{"en":{"title":"Saperavi"},"ka":{"title":"საფერავი"}}}');
})->with('storages');

it('survives PHP serialization with unsaved translations', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));
    $article->setTranslation('title', 'ka', 'საფერავი');

    $copy = unserialize(serialize($article));

    expect($copy->getAllTranslations())->toBe($article->getAllTranslations())
        ->and($copy->hasUnsavedTranslations())->toBeTrue();
})->with('storages');
