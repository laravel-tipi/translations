<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Tests\Fixtures\Localization\FakeLocaleProvider;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\PlainArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingStatefulJsonArticle;
use Tipi\Translations\TranslationQueryManager;

dataset('translation query models', [
    'dedicated table' => DedicatedArticle::class,
    'shared table' => SharedArticle::class,
    'json columns' => JsonArticle::class,
]);

beforeEach(function (): void {
    $this->seedTranslations = function (string $modelClass): array {
        $articles = [];

        foreach (['Alpha', 'Bravo', 'Charlie'] as $index => $title) {
            $article = $modelClass::query()->create();

            resolve(CreateTranslation::class)->execute(
                translatable: $article,
                attributes: ['title' => $title, 'description' => $index === 0 ? 'Featured' : 'Regular'],
                localeCode: 'en',
            );

            resolve(CreateTranslation::class)->execute(
                translatable: $article,
                attributes: ['title' => ['Charlie', 'Bravo', 'Alpha'][$index]],
                localeCode: 'ka',
            );

            $articles[] = $article;
        }

        return $articles;
    };
});

it('filters translated attributes using the current locale', function (string $modelClass): void {
    [$first, , $third] = ($this->seedTranslations)($modelClass);

    expect($modelClass::query()->whereTranslation('title', '=', 'Alpha')->pluck('id')->all())
        ->toBe([$first->id]);

    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    expect($modelClass::query()->whereTranslation('title', '=', 'Alpha')->pluck('id')->all())
        ->toBe([$third->id]);
})->with('translation query models');

it('filters an explicit locale independently of the current locale', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);

    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    expect($modelClass::query()->whereTranslation('title', '=', 'Alpha', 'en')->pluck('id')->all())
        ->toBe([$first->id]);
})->with('translation query models');

it('supports comparison operators and combines translation filters', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);

    expect($modelClass::query()
        ->whereTranslation('title', 'like', 'Al%')
        ->whereTranslation('description', '!=', 'Regular')
        ->pluck('id')->all())->toBe([$first->id]);

    expect($modelClass::query()
        ->whereTranslation('title', '=', 'Alpha')
        ->whereTranslation('description', '=', 'Regular')
        ->count())->toBe(0);
})->with('translation query models');

it('combines translation filters with ordinary filters and grouped or conditions', function (string $modelClass): void {
    [$first, $second, $third] = ($this->seedTranslations)($modelClass);

    expect($modelClass::query()
        ->whereKey($second->id)
        ->orWhereTranslation('title', '=', 'Alpha')
        ->orderBy('id')->pluck('id')->all())->toBe([$first->id, $second->id]);

    expect($modelClass::query()
        ->whereKey($third->id)
        ->where(fn ($query) => $query
            ->whereTranslation('title', '=', 'Bravo')
            ->orWhereTranslation('title', '=', 'Alpha', 'ka'))
        ->pluck('id')->all())->toBe([$third->id]);
})->with('translation query models');

it('does not match translations from another locale or untranslated models', function (string $modelClass): void {
    ($this->seedTranslations)($modelClass);
    $modelClass::query()->create();

    expect($modelClass::query()->whereTranslation('title', '=', 'Alpha', 'de')->count())
        ->toBe(0)
        ->and($modelClass::query()->whereTranslation('title', 'like', '%', 'en')->count())
        ->toBe(3);
})->with('translation query models');

it('sorts translated attributes in both directions using the current locale', function (string $modelClass): void {
    [$first, $second, $third] = ($this->seedTranslations)($modelClass);

    expect($modelClass::query()->orderByTranslation('title')->pluck('id')->all())
        ->toBe([$first->id, $second->id, $third->id])
        ->and($modelClass::query()->orderByTranslation('title', 'DESC')->pluck('id')->all())
        ->toBe([$third->id, $second->id, $first->id]);

    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    expect($modelClass::query()->orderByTranslation('title')->pluck('id')->all())
        ->toBe([$third->id, $second->id, $first->id]);
})->with('translation query models');

it('sorts an explicit locale and preserves translation filters', function (string $modelClass): void {
    [$first, $second] = ($this->seedTranslations)($modelClass);

    expect($modelClass::query()
        ->whereTranslation('title', '!=', 'Charlie', 'en')
        ->orderByTranslation('title', 'asc', 'ka')
        ->pluck('id')->all())->toBe([$second->id, $first->id]);
})->with('translation query models');

it('rejects attributes that are not translatable', function (string $modelClass): void {
    foreach (['whereTranslation', 'orWhereTranslation', 'orderByTranslation'] as $scope) {
        expect(fn () => $scope === 'orderByTranslation'
            ? $modelClass::query()->orderByTranslation('id')
            : $modelClass::query()->{$scope}('id', '=', 1))
            ->toThrow(InvalidArgumentException::class, "Attribute [id] is not translatable on model [$modelClass].");
    }
})->with('translation query models');

it('rejects unsupported sorting directions', function (string $modelClass): void {
    expect(fn () => $modelClass::query()->orderByTranslation('title', 'sideways'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported sorting direction [sideways].');
})->with('translation query models');

it('rejects query models that do not implement the translatable contract', function (): void {
    $manager = resolve(TranslationQueryManager::class);

    expect(fn () => $manager->where(PlainArticle::query(), 'title', '=', 'Alpha'))
        ->toThrow(LogicException::class, 'The query model must implement TranslatableModel.')
        ->and(fn () => $manager->orderBy(PlainArticle::query(), 'title'))
        ->toThrow(LogicException::class, 'The query model must implement TranslatableModel.');
});

it('plucks translations using the current or explicit locale', function (string $modelClass): void {
    [$first, $second, $third] = ($this->seedTranslations)($modelClass);

    $values = $modelClass::query()->orderBy('id')->pluckTranslation(attribute: 'title');

    expect($values)->toBeInstanceOf(Collection::class)
        ->and($values)->not->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class)
        ->and($values->all())->toBe([$first->id => 'Alpha', $second->id => 'Bravo', $third->id => 'Charlie']);

    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    expect($modelClass::query()->orderBy('id')->pluckTranslation('title')->all())
        ->toBe([$first->id => 'Charlie', $second->id => 'Bravo', $third->id => 'Alpha'])
        ->and($modelClass::query()->whereKey($first->id)->pluckTranslation(attribute: 'title', localeCode: 'en')->all())
        ->toBe([$first->id => 'Alpha']);
})->with('translation query models');

it('returns null for missing records, locales and attributes', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);
    $missing = $modelClass::query()->create();

    expect($modelClass::query()->whereKey($missing->id)->pluckTranslation('title')->all())
        ->toBe([$missing->id => null])
        ->and($modelClass::query()->whereKey($first->id)->pluckTranslation('title', localeCode: 'de')->all())
        ->toBe([$first->id => null])
        ->and($modelClass::query()->whereKey($first->id)->pluckTranslation('description', localeCode: 'ka')->all())
        ->toBe([$first->id => null]);
})->with('translation query models');

it('falls back to a regular column only for missing values', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);
    $missing = $modelClass::query()->create();

    expect($modelClass::query()->whereKey([$first->id, $missing->id])->orderBy('id')
        ->pluckTranslation(attribute: 'title', fallback: 'id')->all())
        ->toBe([$first->id => 'Alpha', $missing->id => $missing->id])
        ->and($modelClass::query()->whereKey($first->id)->pluckTranslation('title', localeCode: 'de', fallback: 'id')->all())
        ->toBe([$first->id => $first->id]);

    resolve(CreateTranslation::class)->execute($missing, ['title' => ''], 'en');

    expect($modelClass::query()->whereKey($missing->id)->pluckTranslation('title', fallback: 'id')->all())
        ->toBe([$missing->id => '']);
})->with('translation query models');

it('preserves filters, ordering, limits and offsets when plucking', function (string $modelClass): void {
    [, $second] = ($this->seedTranslations)($modelClass);

    expect($modelClass::query()
        ->where('id', '>', 0)
        ->whereTranslation('title', '!=', 'Charlie')
        ->orderByTranslation('title', 'desc')
        ->orderBy('id')
        ->limit(1)
        ->pluckTranslation('title')->all())->toBe([$second->id => 'Bravo'])
        ->and($modelClass::query()->orderByTranslation('title')->offset(1)->limit(1)->pluckTranslation('title')->all())
        ->toBe([$second->id => 'Bravo']);
})->with('translation query models');

it('supports custom and qualified key columns', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);
    $table = $first->getTable();

    expect($modelClass::query()->whereKey($first->id)->pluckTranslation('title', key: 'created_at')->all())
        ->toBe([(string) $first->getRawOriginal('created_at') => 'Alpha'])
        ->and($modelClass::query()->whereKey($first->id)->pluckTranslation('title', key: "$table.id")->all())
        ->toBe([$first->id => 'Alpha']);
})->with('translation query models');

it('returns an empty collection for empty results', function (string $modelClass): void {
    expect($modelClass::query()->pluckTranslation('title')->all())->toBe([]);
})->with('translation query models');

it('leaves the original builder unchanged including its selection and bindings', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);
    $query = $modelClass::query()->select('id')->selectRaw('? as marker', ['original'])
        ->whereKey($first->id)->orderByTranslation('title');
    $sql = $query->toSql();
    $bindings = $query->getBindings();

    expect($query->pluckTranslation('title', localeCode: 'ka')->all())->toBe([$first->id => 'Charlie'])
        ->and($query->toSql())->toBe($sql)
        ->and($query->getBindings())->toBe($bindings)
        ->and(resolve(TranslationQueryManager::class)->pluck($query, 'title')->all())->toBe([$first->id => 'Alpha'])
        ->and($query->toSql())->toBe($sql)
        ->and($query->getBindings())->toBe($bindings);
})->with('translation query models');

it('uses one query without hydrating models or eager loading relations', function (string $modelClass): void {
    [$first] = ($this->seedTranslations)($modelClass);
    $query = $modelClass::query()->whereKey($first->id)->with('translationStates');
    $hydrated = [];
    Event::listen('eloquent.retrieved: *', function ($event) use (&$hydrated): void {
        $hydrated[] = $event;
    });
    $connection = $query->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    expect($query->pluckTranslation('title')->all())->toBe([$first->id => 'Alpha'])
        ->and($hydrated)->toBe([])
        ->and($connection->getQueryLog())->toHaveCount(1);
})->with('translation query models');

it('validates plucked attributes and query models', function (): void {
    expect(fn () => PlainArticle::query()->pluckTranslation('title'))
        ->toThrow(LogicException::class, 'The query model must implement TranslatableModel.');
});

it('rejects plucking an attribute that is not translatable', function (string $modelClass): void {
    expect(fn () => $modelClass::query()->pluckTranslation('id'))
        ->toThrow(InvalidArgumentException::class, "Attribute [id] is not translatable on model [$modelClass].");
})->with('translation query models');

it('keeps shared table translations isolated by morph type', function (): void {
    $other = SoftDeletingSharedArticle::query()->create();
    resolve(CreateTranslation::class)->execute($other, ['title' => 'Other model'], 'en');
    $article = SharedArticle::query()->create();
    resolve(CreateTranslation::class)->execute($article, ['title' => 'Correct model'], 'en');

    expect($other->id)->toBe($article->id)
        ->and(SharedArticle::query()->pluckTranslation('title')->all())->toBe([$article->id => 'Correct model']);
});

it('preserves global scopes and joined query constraints', function (): void {
    $modelClass = SoftDeletingStatefulJsonArticle::class;
    [$first, $second] = ($this->seedTranslations)($modelClass);
    $first->delete();

    expect($modelClass::query()->orderBy('id')->pluckTranslation('title')->keys()->all())
        ->not->toContain($first->id)
        ->and($modelClass::withTrashed()->whereKey($first->id)->pluckTranslation('title')->all())
        ->toBe([$first->id => 'Alpha'])
        ->and($modelClass::query()
            ->join('json_articles as joined', 'joined.id', '=', 'json_articles.id')
            ->where('joined.id', $second->id)
            ->orderByTranslation('title')
            ->pluckTranslation('title')->all())->toBe([$second->id => 'Bravo']);
});
