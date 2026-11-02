<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tipi\Localization\LocaleContexts;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/*
 | Every behavior that does not depend on the storage runs against both.
 */
dataset('storages', [
    'json' => JsonArticle::class,
    'table' => TableArticle::class,
]);

/**
 * Create and save a model with translations.
 *
 * @template T of Model&TranslatableModel
 *
 * @param  class-string<T>  $class
 * @param  array<string, array<string, mixed>>  $translations
 * @param  array<string, mixed>  $attributes
 * @return T
 */
function article(string $class, array $translations = [], array $attributes = []): Model
{
    /** @var T $article */
    $article = new $class($attributes);
    $article->fillTranslations($translations)->save();

    return $article;
}

/**
 * A fresh copy, with nothing loaded.
 *
 * @template T of Model
 *
 * @param  T  $model
 * @return T
 */
function reload(Model $model): Model
{
    return $model->newQuery()->findOrFail($model->getKey());
}

function useLocale(string $code): void
{
    app()->setLocale($code);
}

function useContext(string $name): void
{
    app(LocaleContexts::class)->setCurrent($name);
}

/**
 * The number of queries the callback runs.
 */
function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}
