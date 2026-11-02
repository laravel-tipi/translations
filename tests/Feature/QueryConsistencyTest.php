<?php

declare(strict_types=1);

use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | The same query must give the same answer for both storages, on every
 | database. Case sensitivity follows the database's collation.
 */

/**
 * Run the same query against identical data in both storages.
 *
 * @return array{json: list<string>, table: list<string>}
 */
function bothStorages(Closure $query): array
{
    $results = [];

    foreach (['json' => JsonArticle::class, 'table' => TableArticle::class] as $storage => $class) {
        foreach (['apple' => 'apple', 'Banana' => 'Banana', 'cherry' => 'cherry', 'Apple-2' => 'Apple'] as $slug => $title) {
            article($class, ['en' => ['title' => $title]], ['slug' => $slug]);
        }

        $results[$storage] = $query($class::query())->pluck('slug')->all();
    }

    return $results;
}

function caseInsensitiveDatabase(): bool
{
    return in_array(TestCase::databaseDriver(), ['mysql', 'mariadb'], true);
}

it('compares equality with the same case sensitivity', function (): void {
    $results = bothStorages(fn ($query) => $query->whereTranslation('title', '=', 'APPLE', 'en')->orderBy('slug'));

    expect($results['json'])->toBe($results['table'])
        ->and($results['table'])->toBe(caseInsensitiveDatabase() ? ['apple', 'Apple-2'] : []);
});

it('compares like with the same case sensitivity', function (): void {
    $results = bothStorages(fn ($query) => $query->whereTranslation('title', 'like', 'b%', 'en')->orderBy('slug'));

    // SQLite's LIKE ignores ASCII case; PostgreSQL's does not (use ilike).
    expect($results['json'])->toBe($results['table'])
        ->and($results['table'])->toBe(TestCase::databaseDriver() === 'pgsql' ? [] : ['Banana']);
});

it('orders with the same collation', function (): void {
    $results = bothStorages(fn ($query) => $query->orderByTranslation('title', 'asc', 'en')->orderBy('id'));

    expect($results['json'])->toBe($results['table']);
});

it('matches case-insensitively with ilike on PostgreSQL', function (): void {
    $results = bothStorages(fn ($query) => $query->whereTranslation('title', 'ilike', 'b%', 'en'));

    expect($results['json'])->toBe(['Banana'])->and($results['table'])->toBe(['Banana']);
})->skip(fn () => TestCase::databaseDriver() !== 'pgsql', 'ilike is PostgreSQL only');

it('does not fall back from an empty string in queries', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => '']]);

    expect($class::whereTranslation('title', '=', '', 'ka', fallback: true)->pluck('id')->all())->toBe([$article->id])
        ->and($class::whereTranslation('title', '=', 'Saperavi', 'ka', fallback: true)->count())->toBe(0)
        ->and($class::pluckTranslation('title', locale: 'ka', fallback: true)->all())->toBe([$article->id => '']);
})->with('storages');

it('handles locale codes with quotes and backslashes safely', function (string $class): void {
    $odd = 'x\'y"z\\w';

    // Configured locales are validated by Localization; database rows
    // written directly are not.
    $this->useDatabaseLocales(['en', $odd]);

    $article = article($class, [$odd => ['title' => 'Odd'], 'en' => ['title' => 'English']]);
    article($class, ['en' => ['title' => 'Other']]);

    expect($class::query()->whereTranslation('title', '=', 'Odd', $odd)->pluck('id')->all())->toBe([$article->getKey()])
        ->and($class::query()->orderByTranslation('title', locale: $odd)->first()->is($article))->toBeTrue()
        ->and($class::query()->pluckTranslation('title', locale: $odd)->filter()->all())->toBe([$article->getKey() => 'Odd'])
        ->and(reload($article)->getTranslation('title', $odd))->toBe('Odd');
})->with('storages');
