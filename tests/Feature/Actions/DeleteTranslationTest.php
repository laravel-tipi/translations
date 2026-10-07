<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\DeleteTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\DefaultTranslationCannotBeDeletedException;
use Tipi\Translations\Exceptions\TranslationDoesNotExistException;
use Tipi\Translations\Tests\Fixtures\Localization\FakeLocaleProvider;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\TranslationManager;

dataset('translatable models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
    'json columns' => fn () => JsonArticle::query()->create(),
]);

it('deletes a translation', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'ქართული',
            'description' => 'აღწერა',
        ],
        localeCode: 'ka',
    );

    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'ka',
    );

    expect(
        resolve(TranslationManager::class)->exists(
            translatable: $article->fresh(),
            localeCode: 'ka',
        ),
    )->toBeFalse();
})->with('translatable models');

it('uses the current locale when locale is not provided', function (
    Model&TranslatableModel $article,
): void {
    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
    );

    expect(
        resolve(TranslationManager::class)->exists(
            translatable: $article->fresh(),
            localeCode: 'ka',
        ),
    )->toBeFalse();
})->with('translatable models');

it('does not delete the default translation independently', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
    );
})->with('translatable models')
    ->throws(DefaultTranslationCannotBeDeletedException::class);

it('does not delete a translation that does not exist', function (
    Model&TranslatableModel $article,
): void {
    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'ka',
    );
})->with('translatable models')
    ->throws(TranslationDoesNotExistException::class);

it('rejects deleting the default translation even when it does not exist', function (
    Model&TranslatableModel $article,
): void {
    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
    );
})->with('translatable models')
    ->throws(DefaultTranslationCannotBeDeletedException::class);

it('keeps the json model synchronized after deleting a translation', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'ka',
    );

    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    expect($article->title)->toBeNull();
});

it('deleting a json translation preserves all other locales', function (): void {
    $article = JsonArticle::query()->create();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        $article,
        [
            'title' => 'English',
            'description' => 'English description',
        ],
        'en',
    );

    $create->execute(
        $article,
        [
            'title' => 'ქართული',
            'description' => 'ქართული აღწერა',
        ],
        'ka',
    );

    resolve(DeleteTranslation::class)->execute(
        $article,
        'ka',
    );

    $article->refresh();

    expect($article->getAttributeValue('title'))->toBe([
        'en' => 'English',
    ])->and($article->getAttributeValue('description'))->toBe([
        'en' => 'English description',
    ]);
});

it('does not overwrite newer json translations when deleting from a stale model', function (): void {
    $article = JsonArticle::query()->create();

    $create = resolve(CreateTranslation::class);

    $create->execute($article, ['title' => 'English'], 'en');
    $create->execute($article, ['title' => 'ქართული'], 'ka');

    $stale = JsonArticle::query()->findOrFail($article->getKey());

    $create->execute(
        $article,
        ['title' => 'Deutsch'],
        'de',
    );

    resolve(DeleteTranslation::class)->execute(
        $stale,
        'ka',
    );

    expect(
        $article->fresh()->getAttributeValue('title'),
    )->toBe([
        'en' => 'English',
        'de' => 'Deutsch',
    ]);
});

it('fails when the persisted translatable was deleted before translation creation', function (): void {
    $article = JsonArticle::query()->create();

    $stale = JsonArticle::query()->findOrFail($article->getKey());

    $article->delete();

    resolve(CreateTranslation::class)->execute(
        $stale,
        ['title' => 'English'],
        'en',
    );
})->throws(ModelNotFoundException::class);

it('rolls back translation deletion with the surrounding transaction', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English',
        ],
        localeCode: 'en',
    );

    try {
        DB::transaction(function () use ($article): void {
            resolve(DeleteTranslation::class)->execute(
                translatable: $article,
                localeCode: 'en',
                dbTransaction: false,
            );

            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
        //
    }

    $article = $article->fresh();

    $translation = $article?->getTranslation('en');

    expect($article?->translationExists('en'))->toBeTrue()
        ->and($translation)->not->toBeNull()
        ->and($translation->attributes['title'])
        ->toBe('English');

})->with('translatable models');

it('completely removes the last json translation for a locale', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'ქართული',
            'description' => 'ქართული აღწერა',
        ],
        localeCode: 'ka',
    );

    resolve(DeleteTranslation::class)->execute(
        translatable: $article,
        localeCode: 'ka',
    );

    $article->refresh();

    expect($article->translationExists('ka'))->toBeFalse()
        ->and($article->getTranslation('ka'))->toBeNull()
        ->and($article->getTranslations())->toBeEmpty()
        ->and($article->getAttributeValue('title'))->toBe([])
        ->and($article->getAttributeValue('description'))->toBe([]);
});
