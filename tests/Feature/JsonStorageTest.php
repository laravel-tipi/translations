<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Exceptions\MalformedTranslationsException;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;

afterEach(fn () => Model::preventAccessingMissingAttributes(false));

function storedJson(Model $article, string $column): mixed
{
    $raw = DB::table('json_articles')->where('id', $article->getKey())->value($column);

    return $raw === null ? null : json_decode($raw, true);
}

it('stores one JSON object per attribute, keyed by locale in display order', function (): void {
    $article = article(JsonArticle::class, [
        'ka' => ['title' => 'საფერავი'],
        'en' => ['title' => 'Saperavi'],
    ]);

    expect(storedJson($article, 'title'))->toBe(['en' => 'Saperavi', 'ka' => 'საფერავი'])
        ->and(storedJson($article, 'body'))->toBeNull()
        ->and($article->getAttributes()['title'])->toBe('{"en":"Saperavi","ka":"საფერავი"}');
});

it('removes keys instead of storing null, and stores NULL when nothing is left', function (): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi', 'body' => 'Red'], 'ka' => ['title' => 'საფერავი']]);

    $article->forgetTranslation('title', 'ka')->forgetTranslations('en')->save();

    expect(storedJson($article, 'title'))->toBeNull()
        ->and(storedJson($article, 'body'))->toBeNull();
});

it('accumulates translations on an unsaved model and inserts them once', function (): void {
    $article = (new JsonArticle)
        ->setTranslation('title', 'en', 'Saperavi')
        ->setTranslation('title', 'ka', 'საფერავი');

    expect(JsonArticle::query()->count())->toBe(0)
        ->and(countQueries(fn () => $article->save()))->toBe(1)
        ->and(storedJson($article, 'title'))->toBe(['en' => 'Saperavi', 'ka' => 'საფერავი']);
});

it('rejects columns that are not JSON objects', function (string $raw): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi']]);

    DB::table('json_articles')->where('id', $article->id)->update(['title' => $raw]);

    reload($article)->title;
})->with([
    'a plain string' => '"Saperavi"',
    'a list' => '["Saperavi"]',
])->throws(MalformedTranslationsException::class, 'must contain a JSON object keyed by locale code');

it('accepts an empty JSON array as no translations', function (): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi']]);

    DB::table('json_articles')->where('id', $article->id)->update(['body' => '[]']);

    expect(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
});

it('treats a column left out of the select as unavailable', function (): void {
    $article = article(JsonArticle::class, ['en' => ['title' => 'Saperavi']]);

    expect(JsonArticle::query()->select('id')->find($article->id)->title)->toBeNull();

    Model::preventAccessingMissingAttributes();

    JsonArticle::query()->select('id')->find($article->id)->title;
})->throws(MissingAttributeException::class);
