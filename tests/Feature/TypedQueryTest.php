<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Tipi\Translations\Tests\Fixtures\Enums\TranslationStatus;
use Tipi\Translations\Tests\Fixtures\Models\CastArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonCastArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | Numbers compare and sort as numbers in both storages: table storage by
 | its column types, JSON storage by the JSON type of the stored value.
 */

dataset('typed storages', [
    'json' => JsonCastArticle::class,
    'table' => CastArticle::class,
]);

/**
 * @param  class-string<Model>  $class
 * @return array<int, Model>
 */
function positioned(string $class): array
{
    return [
        9 => article($class, ['en' => ['position' => 9]]),
        10 => article($class, ['en' => ['position' => 10]]),
        100 => article($class, ['en' => ['position' => 100], 'ka' => ['position' => 1]]),
        0 => article($class, ['ka' => ['position' => 5]]),
    ];
}

/**
 * @param  iterable<Model>  $models
 * @param  array<int, Model>  $positioned
 * @return list<int>
 */
function positions(iterable $models, array $positioned): array
{
    $byKey = [];

    foreach ($positioned as $position => $model) {
        $byKey[$model->getKey()] = $position;
    }

    $positions = [];

    foreach ($models as $model) {
        $positions[] = $byKey[$model->getKey()];
    }

    return $positions;
}

it('compares numbers numerically', function (string $class, string $operator, mixed $value, array $expected): void {
    $articles = positioned($class);

    if ($class === CastArticle::class && is_float($value) && TestCase::databaseDriver() === 'pgsql') {
        // Table storage compares like its columns: PostgreSQL rejects a
        // fractional number for an integer column, as with where().
        expect(fn () => $class::query()->whereTranslation('position', $operator, $value, 'en')->get())->toThrow(QueryException::class);

        return;
    }

    $found = $class::query()->whereTranslation('position', $operator, $value, 'en')->orderBy('id')->get();

    expect(positions($found, $articles))->toBe($expected);
})->with('typed storages')->with([
    'greater than an int' => ['>', 9, [10, 100]],
    'less than an int' => ['<', 10, [9]],
    'greater than a numeric string' => ['>', '9', [10, 100]],
    'at most a float' => ['<=', 10.5, [9, 10]],
    'equal to a padded numeric string' => ['=', '010', [10]],
    'not equal' => ['!=', 10, [9, 100]],
]);

it('sorts numbers numerically, missing values last', function (string $class, string $direction, array $expected): void {
    $articles = positioned($class);

    $sorted = $class::query()->orderByTranslation('position', $direction, 'en')->orderBy('id')->get();

    expect(positions($sorted, $articles))->toBe($expected);
})->with('typed storages')->with([
    'ascending' => ['asc', [9, 10, 100, 0]],
    'descending' => ['desc', [100, 10, 9, 0]],
]);

it('compares and sorts fallback values as numbers', function (string $class): void {
    $articles = positioned($class);

    useLocale('ka');

    $found = $class::query()->whereTranslation('position', '>=', 5, fallback: true)->orderBy('id')->get();
    $sorted = $class::query()->orderByTranslation('position', fallback: true)->orderBy('id')->get();

    expect(positions($found, $articles))->toBe([9, 10, 0])
        ->and(positions($sorted, $articles))->toBe([100, 0, 9, 10]);
})->with('typed storages');

it('plucks the values a model read returns, with their types', function (string $class): void {
    $article = article($class, ['en' => ['content' => ['blocks' => [1, 2]], 'position' => 7]]);

    expect($class::query()->pluckTranslation('content', locale: 'en')->all())->toBe([$article->getKey() => ['blocks' => [1, 2]]])
        ->and($class::query()->pluckTranslation('position', locale: 'en')->all())->toBe([$article->getKey() => 7])
        ->and($class::query()->pluckTranslation('position', locale: 'ka', fallback: true)->all())->toBe([$article->getKey() => 7])
        ->and(reload($article)->getTranslation('position', 'en'))->toBe(7);
})->with('typed storages');

it('accepts backed enums and stringable values as operands', function (string $class): void {
    $article = article($class, ['en' => ['position' => 1]]);

    expect($class::query()->whereTranslation('position', '=', new class implements Stringable
    {
        public function __toString(): string
        {
            return '1';
        }
    }, 'en')->pluck('id')->all())->toBe([$article->getKey()]);
})->with('typed storages');

it('rejects operands translations cannot be compared with', function (string $class, string $operator, mixed $value): void {
    expect(fn () => $class::query()->whereTranslation('position', $operator, $value)->get())
        ->toThrow(InvalidArgumentException::class);
})->with('typed storages')->with([
    'a boolean' => ['=', true],
    'an array' => ['=', [1]],
    'an object' => ['=', new stdClass],
    'a number with like' => ['like', 1],
    'infinity' => ['>', INF],
]);

it('accepts a backed enum operand', function (): void {
    $article = article(JsonCastArticle::class, ['en' => ['content' => 'reviewed']]);

    expect(JsonCastArticle::query()->whereTranslation('content', '=', TranslationStatus::Reviewed, 'en')->pluck('id')->all())
        ->toBe([$article->getKey()]);
});
