<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;

afterEach(function (): void {
    Model::preventLazyLoading(false);
    Model::automaticallyEagerLoadRelationships(false);
});

function seedArticles(string $class, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        article($class, [
            'en' => ['title' => "Wine {$i}", 'body' => 'Red'],
            'ka' => ['title' => "ღვინო {$i}"],
        ]);
    }
}

/**
 * Queries to load every article and read all of its translations, with
 * and without fallback.
 */
function readEverything(Closure $load): int
{
    return countQueries(function () use ($load): void {
        foreach ($load() as $article) {
            useLocale('ka');
            $article->title;
            $article->body;
            useLocale('de');
            $article->title;
            $article->getAllTranslations();
            $article->toArray();
        }
    });
}

it('reads table translations in a constant number of queries when eager loaded', function (bool $scope): void {
    $load = fn () => $scope ? TableArticle::withTranslations()->get() : TableArticle::with('translations')->get();

    seedArticles(TableArticle::class, 5);
    $few = readEverything($load);

    seedArticles(TableArticle::class, 45);
    $many = readEverything($load);

    expect($few)->toBe(2)->and($many)->toBe(2);
})->with(['with()' => false, 'withTranslations()' => true]);

it('reads JSON translations with the model query only', function (): void {
    seedArticles(JsonArticle::class, 50);

    expect(readEverything(fn () => JsonArticle::withTranslations()->get()))->toBe(1);
});

it('loads table translations once per model when not eager loaded', function (): void {
    seedArticles(TableArticle::class, 5);

    expect(readEverything(fn () => TableArticle::all()))->toBe(6);
});

it('works with automatic eager loading', function (): void {
    seedArticles(TableArticle::class, 5);

    Model::automaticallyEagerLoadRelationships();

    expect(readEverything(fn () => TableArticle::all()))->toBe(2);
});

it('reports lazy loading when Eloquent prevents it', function (): void {
    seedArticles(TableArticle::class, 2);

    Model::preventLazyLoading();

    TableArticle::all()->first()->title;
})->throws(LazyLoadingViolationException::class);

it('reads without queries after saving', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $article->setTranslation('title', 'ka', 'საფერავი')->forgetTranslation('title', 'en')->save();

    expect(countQueries(fn () => [$article->title, $article->getAllTranslations()]))->toBe(0)
        ->and($article->getAllTranslations())->toBe(['ka' => ['title' => 'საფერავი']]);
})->with('storages');

it('writes a new model and its translations in a bounded number of queries', function (string $class, int $expected): void {
    $queries = countQueries(fn () => article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი', 'body' => 'წითელი'],
        'de' => ['title' => 'Saperawi'],
    ]));

    expect($queries)->toBe($expected);
})->with([
    // One insert.
    'json' => [JsonArticle::class, 1],
    // The model, one upsert per set of changed attributes ("title, body"
    // for en and ka, "title" for de), and a reload of the loaded rows.
    'table' => [TableArticle::class, 4],
]);

it('updates an existing model in a bounded number of queries', function (string $class, int $expected): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $article = $class === TableArticle::class ? $class::with('translations')->find($article->getKey()) : reload($article);

    $this->travel(1)->minute();

    $queries = countQueries(fn () => $article->setTranslation('title', 'ka', 'ახალი')->setTranslation('title', 'de', 'Saperawi')->save());

    expect($queries)->toBe($expected);
})->with([
    // Locked read of the column, then the update.
    'json' => [JsonArticle::class, 2],
    // Touch updated_at, one upsert for both locales, reload the rows.
    'table' => [TableArticle::class, 3],
]);
