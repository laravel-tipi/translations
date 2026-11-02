<?php

declare(strict_types=1);

use Tipi\Localization\Exceptions\LocaleNotFoundException;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;

/**
 * Three articles: one translated in English and Georgian, one only in
 * English, one only in German.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function wines(string $class): array
{
    return [
        article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']], ['slug' => 'saperavi'])->getKey(),
        article($class, ['en' => ['title' => 'Mtsvane', 'body' => 'White']], ['slug' => 'mtsvane'])->getKey(),
        article($class, ['de' => ['title' => 'Rkatsiteli']], ['slug' => 'rkatsiteli'])->getKey(),
    ];
}

it('filters by the exact value in the current locale', function (string $class): void {
    [$saperavi] = wines($class);

    useLocale('ka');

    expect($class::whereTranslation('title', '=', 'საფერავი')->pluck('id')->all())->toBe([$saperavi])
        ->and($class::whereTranslation('title', '=', 'Mtsvane')->count())->toBe(0);
})->with('storages');

it('filters by the exact value in an explicit locale', function (string $class): void {
    [, $mtsvane] = wines($class);

    useLocale('ka');

    expect($class::whereTranslation('title', 'like', 'Mts%', 'en')->pluck('id')->all())->toBe([$mtsvane]);
})->with('storages');

it('filters by the resolved value with fallback', function (string $class): void {
    [, $mtsvane] = wines($class);

    useLocale('ka');

    expect($class::whereTranslation('title', '=', 'Mtsvane', fallback: true)->pluck('id')->all())->toBe([$mtsvane]);
})->with('storages');

it('combines with other conditions', function (string $class): void {
    [$saperavi, $mtsvane] = wines($class);

    $ids = $class::query()
        ->where('slug', 'saperavi')
        ->orWhereTranslation('title', '=', 'Mtsvane', 'en')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $scoped = $class::query()
        ->where('slug', 'saperavi')
        ->where(fn ($query) => $query->whereTranslation('title', '=', 'Mtsvane', 'en')->orWhereTranslation('title', '=', 'Saperavi', 'en'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$saperavi, $mtsvane])
        ->and($scoped)->toBe([$saperavi]);
})->with('storages');

it('filters by presence and absence', function (string $class): void {
    [$saperavi, $mtsvane, $rkatsiteli] = wines($class);

    expect($class::whereHasTranslation('title', 'ka')->pluck('id')->all())->toBe([$saperavi])
        ->and($class::whereDoesntHaveTranslation('title', 'en')->pluck('id')->all())->toBe([$rkatsiteli])
        ->and($class::whereHasTranslation('body', 'en')->pluck('id')->all())->toBe([$mtsvane])
        ->and($class::whereTranslation('title', '!=', null, 'de')->pluck('id')->all())->toBe([$rkatsiteli])
        ->and($class::whereTranslation('title', '=', null, 'de')->count())->toBe(2);
})->with('storages');

it('orders by the exact value with missing values last', function (string $class): void {
    [$saperavi, $mtsvane, $rkatsiteli] = wines($class);

    expect($class::orderByTranslation('title', 'asc', 'en')->pluck('id')->all())->toBe([$mtsvane, $saperavi, $rkatsiteli])
        ->and($class::orderByTranslation('title', 'desc', 'en')->pluck('id')->all())->toBe([$saperavi, $mtsvane, $rkatsiteli]);
})->with('storages');

it('orders by the resolved value with fallback', function (string $class): void {
    [$saperavi, $mtsvane, $rkatsiteli] = wines($class);

    useLocale('ka');

    // Resolved Georgian titles: საფერავი, Mtsvane (en), missing.
    expect($class::orderByTranslation('title', fallback: true)->pluck('id')->all())->toBe([$mtsvane, $saperavi, $rkatsiteli]);
})->with('storages');

it('plucks translated values without hydrating models', function (string $class): void {
    [$saperavi, $mtsvane, $rkatsiteli] = wines($class);

    useLocale('ka');

    expect($class::orderBy('id')->pluckTranslation('title')->all())->toBe([$saperavi => 'საფერავი', $mtsvane => null, $rkatsiteli => null])
        ->and($class::orderBy('id')->pluckTranslation('title', fallback: true)->all())->toBe([$saperavi => 'საფერავი', $mtsvane => 'Mtsvane', $rkatsiteli => null])
        ->and($class::whereHasTranslation('title', 'en')->orderBy('slug')->pluckTranslation('title', 'slug', 'en')->all())->toBe(['mtsvane' => 'Mtsvane', 'saperavi' => 'Saperavi']);
})->with('storages');

it('rejects unsupported operators and directions', function (string $class): void {
    expect(fn () => $class::whereTranslation('title', 'in', 'x')->get())->toThrow(InvalidArgumentException::class, 'Unsupported translation query operator')
        ->and(fn () => $class::whereTranslation('title', '; drop table x', 'x')->get())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $class::whereTranslation('title', 'like', null)->get())->toThrow(InvalidArgumentException::class, 'cannot be compared with null')
        ->and(fn () => $class::orderByTranslation('title', 'sideways')->get())->toThrow(InvalidArgumentException::class, 'Order direction');
})->with('storages');

it('rejects unknown attributes and locales in queries', function (string $class): void {
    expect(fn () => $class::whereTranslation('slug', '=', 'x')->get())->toThrow(AttributeNotTranslatableException::class)
        ->and(fn () => $class::orderByTranslation('title', 'asc', 'xx')->get())->toThrow(LocaleNotFoundException::class);
})->with('storages');

it('binds values instead of interpolating them', function (string $class): void {
    article($class, ['en' => ['title' => "O'Brien"]]);

    expect($class::whereTranslation('title', '=', "O'Brien", 'en')->count())->toBe(1)
        ->and($class::whereTranslation('title', '=', "' or 1=1 --", 'en')->count())->toBe(0);
})->with('storages');
