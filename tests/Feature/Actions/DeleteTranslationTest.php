<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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