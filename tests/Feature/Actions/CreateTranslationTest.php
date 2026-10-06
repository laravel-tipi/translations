<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\InvalidTranslationAttributeException;
use Tipi\Translations\Exceptions\TranslationAlreadyExistsException;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\TranslationManager;

dataset('translatable models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
    'json columns' => fn () => JsonArticle::query()->create(),
]);

it('creates a translation', function (
    Model&TranslatableModel $article,
): void {
    $translation = resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
        localeCode: 'en',
    );

    expect($translation->translatable->is($article))->toBeTrue()
        ->and($translation->localeCode)->toBe('en')
        ->and($translation->attributes)->toBe([
            'title' => 'English title',
            'description' => 'English description',
        ]);
})->with('translatable models');

it('uses the default locale when locale is not provided', function (
    Model&TranslatableModel $article,
): void {
    $translation = resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
        ],
    );

    expect($translation->localeCode)->toBe('en');
})->with('translatable models');

it('does not create a translation that already exists', function (
    Model&TranslatableModel $article,
): void {
    $action = resolve(CreateTranslation::class);

    $action->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
        ],
        localeCode: 'en',
    );

    $action->execute(
        translatable: $article,
        attributes: [
            'title' => 'Another title',
        ],
        localeCode: 'en',
    );
})->with('translatable models')
    ->throws(TranslationAlreadyExistsException::class);

it('rejects attributes that are not translatable', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
            'invalid' => 'Not allowed',
        ],
        localeCode: 'en',
    );
})->with('translatable models')
    ->throws(InvalidTranslationAttributeException::class);

it('persists the created translation', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
        localeCode: 'en',
    );

    $translation = resolve(TranslationManager::class)->get(
        translatable: $article->fresh(),
        localeCode: 'en',
    );

    expect($translation)->not->toBeNull()
        ->and($translation->attributes)->toBe([
            'title' => 'English title',
            'description' => 'English description',
        ]);
})->with('translatable models');
