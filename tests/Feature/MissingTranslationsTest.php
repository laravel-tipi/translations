<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;

/*
 | One rule for both storages: a translation is missing when its value is
 | null. Every other value, including '' and '0', is a translation and never
 | triggers fallback.
 */

it('treats a locale without any translation as missing', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]));

    useLocale('ka');

    expect($article->getTranslation('title', 'ka'))->toBeNull()
        ->and($article->hasTranslation('title', 'ka'))->toBeFalse()
        ->and($article->getTranslations('ka'))->toBe([])
        ->and($article->getTranslatedLocales())->toBe(['en'])
        ->and($article->title)->toBe('Saperavi')
        ->and($article->resolveTranslation('title')->isFallback())->toBeTrue();
})->with('storages');

it('treats an attribute the locale lacks as missing', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
    ]));

    useLocale('ka');

    expect($article->getTranslation('body', 'ka'))->toBeNull()
        ->and($article->hasTranslation('body', 'ka'))->toBeFalse()
        ->and($article->hasTranslation('title', 'ka'))->toBeTrue()
        ->and($article->getTranslatedLocales())->toBe(['en', 'ka'])
        ->and($article->title)->toBe('საფერავი')
        ->and($article->body)->toBe('Red');
})->with('storages');

it('treats an explicit null as missing and does not store it', function (string $class): void {
    $article = article($class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი', 'body' => null],
    ]);

    $article = reload($article);

    useLocale('ka');

    expect($article->getTranslation('body', 'ka'))->toBeNull()
        ->and($article->hasTranslation('body', 'ka'))->toBeFalse()
        ->and($article->getTranslations('ka'))->toBe(['title' => 'საფერავი'])
        ->and($article->body)->toBeNull();
})->with('storages');

it('keeps an empty string as a translation without fallback', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => ''],
    ]));

    useLocale('ka');

    $resolved = $article->resolveTranslation('title');

    expect($article->title)->toBe('')
        ->and($article->getTranslation('title', 'ka'))->toBe('')
        ->and($article->hasTranslation('title', 'ka'))->toBeTrue()
        ->and($article->getTranslatedLocales())->toBe(['en', 'ka'])
        ->and($resolved->isTranslated())->toBeTrue()
        ->and($resolved->value)->toBe('');
})->with('storages');

it('keeps "0" as a translation without fallback', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => '0'],
    ]));

    useLocale('ka');

    expect($article->title)->toBe('0')
        ->and($article->hasTranslation('title', 'ka'))->toBeTrue();
})->with('storages');

it('keeps JSON falsy values as translations', function (mixed $value): void {
    $article = reload(article(JsonArticle::class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => $value],
    ]));

    useLocale('ka');

    expect($article->title)->toBe($value)
        ->and($article->resolveTranslation('title')->isTranslated())->toBeTrue();
})->with([
    'zero' => 0,
    'false' => false,
    'empty array' => [[]],
]);

it('ignores null values already stored in a JSON column', function (): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi']]);

    DB::table('json_articles')->where('id', $article->id)->update(['title' => '{"en":"Saperavi","ka":null}']);

    $article = reload($article);

    useLocale('ka');

    expect($article->title)->toBe('Saperavi')
        ->and($article->hasTranslation('title', 'ka'))->toBeFalse()
        ->and($article->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
});

it('reads values of locales unknown to the registry from all translations only', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    // Data left behind by a locale that was removed from the registry.
    if ($class === JsonArticle::class) {
        DB::table('json_articles')->where('id', $article->id)->update(['title' => '{"en":"Saperavi","ru":"Саперави"}']);
    } else {
        DB::table('table_article_translations')->insert(['table_article_id' => $article->id, 'locale_code' => 'ru', 'title' => 'Саперави']);
    }

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi'],
        'ru' => ['title' => 'Саперави'],
    ]);
})->with('storages');
