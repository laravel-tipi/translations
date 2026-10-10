<?php

declare(strict_types=1);

use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Tests\Fixtures\Localization\FakeLocaleProvider;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\PlainArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
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
