<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedCastArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;

/*
 | Rows that hold a model's translations (table and shared storage,
 | translation states) are deleted with the model, in one transaction,
 | also with events disabled. A soft delete keeps them.
 */

afterEach(function (): void {
    foreach ([TableArticle::class, SharedArticle::class, StatefulJsonArticle::class, StatefulTableArticle::class, StatefulSharedArticle::class] as $model) {
        $model::flushEventListeners();
    }
});

dataset('row storages', [
    'table' => TableArticle::class,
    'shared' => SharedArticle::class,
]);

dataset('stateful storages', [
    'json' => StatefulJsonArticle::class,
    'table' => StatefulTableArticle::class,
    'shared' => StatefulSharedArticle::class,
]);

/**
 * Translation rows of the storage the model class uses, and its states.
 *
 * @param  class-string<Model>  $class
 * @return array{translations: int, states: int}
 */
function storedRows(string $class): array
{
    return [
        'translations' => match (true) {
            is_a($class, TableArticle::class, true) => TableArticleTranslation::query()->count(),
            is_a($class, SharedArticle::class, true) => Translation::query()->count(),
            default => 0,
        },
        'states' => TranslationState::query()->count(),
    ];
}

it('deletes translation rows with the model and keeps other models\' rows', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $other = article($class, ['en' => ['title' => 'Mtsvane']]);

    $article->delete();

    expect(reload($other)->getAllTranslations())->toBe(['en' => ['title' => 'Mtsvane']])
        ->and(storedRows($class)['translations'])->toBe(1)
        ->and($article->relationLoaded('translations'))->toBeFalse();
})->with('row storages');

it('keeps rows and states when soft deleting and deletes them when force deleting', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $before = storedRows($class);

    $article->delete();

    expect(storedRows($class))->toBe($before)
        ->and($before['states'])->toBe(1);

    $article->restore();

    expect(reload($article)->title)->toBe('Saperavi');

    $article->forceDelete();

    expect(storedRows($class))->toBe(['translations' => 0, 'states' => 0]);
})->with('stateful storages');

it('deletes rows and states when events are disabled', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $article->forceDeleteQuietly();

    expect(storedRows($class))->toBe(['translations' => 0, 'states' => 0]);
})->with('stateful storages');

it('keeps the rows when a "deleting" listener cancels the delete', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $before = storedRows($class);

    $class::deleting(fn (): bool => false);

    expect($article->forceDelete())->toBeFalse()
        ->and(storedRows($class))->toBe($before)
        ->and(reload($article)->title)->toBe('Saperavi');
})->with('stateful storages');

it('restores the rows when the delete fails after they were deleted', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $before = storedRows($class);

    $class::deleted(fn () => throw new RuntimeException('Failed.'));

    expect(fn () => $article->forceDelete())->toThrow(RuntimeException::class, 'Failed.')
        ->and(storedRows($class))->toBe($before)
        ->and(reload($article)->title)->toBe('Saperavi');
})->with('stateful storages');

it('deletes rows inside a surrounding transaction, and restores them with it', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $before = storedRows($class);

    try {
        DB::transaction(function () use ($article, $class): void {
            $article->forceDelete();

            expect(storedRows($class)['translations'])->toBe(0);

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
    }

    expect(storedRows($class))->toBe($before);
})->with('stateful storages');

it('deletes only the rows of the model\'s own type when keys are equal', function (): void {
    $article = article(SharedArticle::class, ['en' => ['title' => 'Saperavi']]);
    $cast = article(SharedCastArticle::class, ['en' => ['position' => 1]]);

    expect($article->getKey())->toBe($cast->getKey());

    $article->delete();

    expect(reload($cast)->getTranslation('position', 'en'))->toBe(1)
        ->and(Translation::query()->count())->toBe(1);
});

it('deletes a table translation row that has no values left', function (): void {
    $article = reload(article(TableArticle::class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი', 'body' => 'წითელი'],
    ]));

    $article->setTranslation('title', 'ka', null)->save();

    expect(TableArticleTranslation::query()->where('locale_code', 'ka')->exists())->toBeTrue();

    $article->setTranslation('body', 'ka', null)->save();

    expect(TableArticleTranslation::query()->where('locale_code', 'ka')->exists())->toBeFalse()
        ->and($article->getTranslatedLocales())->toBe(['en'])
        ->and($article->translations->pluck('locale_code')->all())->toBe(['en']);
});
