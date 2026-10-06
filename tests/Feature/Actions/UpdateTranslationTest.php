<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\InvalidTranslationAttributeException;
use Tipi\Translations\Exceptions\TranslationDoesNotExistException;
use Tipi\Translations\Tests\Fixtures\Localization\FakeLocaleProvider;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;

dataset('translatable models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
    'json columns' => fn () => JsonArticle::query()->create(),
]);

it('partially updates a translation', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Original title',
            'description' => 'Original description',
        ],
        localeCode: 'en',
    );

    $translation = resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Updated title',
        ],
        localeCode: 'en',
    );

    expect($translation->attributes)->toBe([
        'title' => 'Updated title',
        'description' => 'Original description',
    ]);
})->with('translatable models');

it('allows a translated attribute to be explicitly cleared', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Title',
            'description' => 'Description',
        ],
        localeCode: 'en',
    );

    $translation = resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'description' => null,
        ],
        localeCode: 'en',
    );

    expect($translation->attributes)->toBe([
        'title' => 'Title',
        'description' => null,
    ]);
})->with('translatable models');

it('does not update a translation that does not exist', function (
    Model&TranslatableModel $article,
): void {
    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Updated title',
        ],
        localeCode: 'ka',
    );
})->with('translatable models')
    ->throws(TranslationDoesNotExistException::class);

it('rejects attributes that are not translatable', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Title',
        ],
        localeCode: 'en',
    );

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'invalid' => 'Value',
        ],
        localeCode: 'en',
    );
})->with('translatable models')
    ->throws(InvalidTranslationAttributeException::class);

it('uses the current locale when locale is not provided', function (
    Model&TranslatableModel $article,
): void {
    resolve(FakeLocaleProvider::class)->setCurrent('ka');

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'ქართული სათაური',
        ],
        localeCode: 'ka',
    );

    $translation = resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'განახლებული სათაური',
        ],
    );

    expect($translation->localeCode)->toBe('ka')
        ->and($translation->attributes['title'])
        ->toBe('განახლებული სათაური');
})->with('translatable models');

dataset('outdated tracking models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
]);

it('marks other translations as outdated when updating the default translation', function (
    Model&TranslatableModel $article,
): void {
    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'Deutsch'],
        localeCode: 'de',
    );

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Updated English'],
        localeCode: 'en',
        markOthersAsOutdated: true,
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'en')
            ->whereNotNull('outdated_at')
            ->exists(),
    )->toBeFalse()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'ka')
                ->whereNotNull('outdated_at')
                ->exists(),
        )->toBeTrue()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'de')
                ->whereNotNull('outdated_at')
                ->exists(),
        )->toBeTrue();
})->with('outdated tracking models');

it('does not allow a non-default translation to mark others as outdated', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'განახლებული'],
        localeCode: 'ka',
        markOthersAsOutdated: true,
    );
})->with('outdated tracking models')
    ->throws(
        LogicException::class,
        'Only the default translation can mark other translations as outdated.',
    );

it('does not allow json translations to mark others as outdated', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Updated English'],
        localeCode: 'en',
        markOthersAsOutdated: true,
    );
})->throws(
    LogicException::class,
    'does not support outdated translation tracking',
);

it('keeps the json model synchronized after updating a translation', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Original'],
        localeCode: 'en',
    );

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Updated'],
        localeCode: 'en',
    );

    expect($article->title)->toBe('Updated');
});