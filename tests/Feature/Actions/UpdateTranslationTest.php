<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Contracts\TracksOutdatedTranslations;
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
dataset('outdated tracking translatable models', [
    'dedicated table' => fn () => new DedicatedArticle,
    'shared table' => fn () => new SharedArticle,
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

it('partially updating json translation preserves every unrelated value', function (): void {
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

    resolve(UpdateTranslation::class)->execute(
        $article,
        [
            'title' => 'Updated English',
        ],
        'en',
    );

    $article->refresh();

    expect($article->getAttributeValue('title'))->toBe([
        'en' => 'Updated English',
        'ka' => 'ქართული',
    ])->and($article->getAttributeValue('description'))->toBe([
        'en' => 'English description',
        'ka' => 'ქართული აღწერა',
    ]);
});

it('does not consider a json translation missing when its value is explicitly null', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        $article,
        ['title' => null],
        'ka',
    );

    expect($article->translationExists('ka'))->toBeTrue()
        ->and($article->getTranslation('ka')?->attributes)->toBe([
            'title' => null,
        ]);
});

it('does not overwrite newer json translations when updating from a stale model', function (): void {
    $article = JsonArticle::query()->create();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        $article,
        ['title' => 'English'],
        'en',
    );

    $create->execute(
        $article,
        ['title' => 'ქართული'],
        'ka',
    );

    $stale = JsonArticle::query()->findOrFail($article->getKey());

    resolve(UpdateTranslation::class)->execute(
        $article,
        ['title' => 'Updated English'],
        'en',
    );

    resolve(UpdateTranslation::class)->execute(
        $stale,
        ['title' => 'განახლებული ქართული'],
        'ka',
    );

    expect(
        $article->fresh()->getAttributeValue('title'),
    )->toBe([
        'en' => 'Updated English',
        'ka' => 'განახლებული ქართული',
    ]);
});

it('does not discard unrelated dirty attributes when locking a translatable', function (): void {
    $article = new JsonArticle;
    $article->slug = 'original';
    $article->save();
    $article->slug = 'unsaved-change';

    resolve(CreateTranslation::class)->execute(
        $article,
        ['title' => 'English'],
        'en',
    );

    expect($article->slug)
        ->toBe('unsaved-change')
        ->and($article->isDirty('slug'))
        ->toBeTrue()
        ->and($article->fresh()->slug)
        ->toBe('original');
});

it('rolls back translation updates with the surrounding transaction', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Original',
        ],
        localeCode: 'en',
    );

    try {
        DB::transaction(function () use ($article): void {
            resolve(UpdateTranslation::class)->execute(
                translatable: $article,
                attributes: [
                    'title' => 'Changed',
                ],
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

    expect($translation)
        ->not->toBeNull()
        ->and($translation->attributes['title'])
        ->toBe('Original');

})->with('translatable models');

it('does not partially update a translation when an attribute is invalid', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'Original title',
            'description' => 'Original description',
        ],
        localeCode: 'en',
    );

    try {
        resolve(UpdateTranslation::class)->execute(
            translatable: $article,
            attributes: [
                'title' => 'Changed title',
                'invalid_attribute' => 'Invalid',
            ],
            localeCode: 'en',
        );
    } catch (InvalidTranslationAttributeException) {
        //
    }

    $article = $article->fresh();

    expect($article?->getTranslation('en')?->attributes)
        ->toBe([
            'title' => 'Original title',
            'description' => 'Original description',
        ]);
})->with('translatable models');

it('clears outdated state only for the translation being updated', function (
    Model&TracksOutdatedTranslations $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute($article, ['title' => 'English'], 'en');
    $create->execute($article, ['title' => 'ქართული'], 'ka');
    $create->execute($article, ['title' => 'Deutsch'], 'de');

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Updated English'],
        localeCode: 'en',
        markOthersAsOutdated: true,
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'ka')
            ->firstOrFail()
            ->outdated_at,
    )->not->toBeNull()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'de')
                ->firstOrFail()
                ->outdated_at,
        )->not->toBeNull();

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'განახლებული'],
        localeCode: 'ka',
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'ka')
            ->firstOrFail()
            ->outdated_at,
    )->toBeNull()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'de')
                ->firstOrFail()
                ->outdated_at,
        )->not->toBeNull();
})->with('outdated tracking translatable models');
it('does not mark the updated default translation as outdated', function (
    Model&TracksOutdatedTranslations $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute($article, ['title' => 'English'], 'en');
    $create->execute($article, ['title' => 'ქართული'], 'ka');
    $create->execute($article, ['title' => 'Deutsch'], 'de');

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Updated English'],
        localeCode: 'en',
        markOthersAsOutdated: true,
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'en')
            ->firstOrFail()
            ->outdated_at,
    )->toBeNull()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'ka')
                ->firstOrFail()
                ->outdated_at,
        )->not->toBeNull()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'de')
                ->firstOrFail()
                ->outdated_at,
        )->not->toBeNull();
})->with('outdated tracking translatable models');

it('clears outdated state after a partial translation update', function (
    Model&TracksOutdatedTranslations $article,
): void {
    $article->save();

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

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'Updated English'],
        localeCode: 'en',
        markOthersAsOutdated: true,
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'ka')
            ->firstOrFail()
            ->outdated_at,
    )->not->toBeNull();

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'განახლებული',
        ],
        localeCode: 'ka',
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'ka')
            ->firstOrFail()
            ->outdated_at,
    )->toBeNull();

    $translation = $article->getTranslation('ka');

    expect($translation?->attributes['title'])
        ->toBe('განახლებული')
        ->and($translation?->attributes['description'])
        ->toBe('ქართული აღწერა');
})->with('outdated tracking translatable models');

it('can mark translations outdated repeatedly without marking the default translation', function (
    Model&TracksOutdatedTranslations $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute($article, ['title' => 'English'], 'en');
    $create->execute($article, ['title' => 'ქართული'], 'ka');

    $update = resolve(UpdateTranslation::class);

    $update->execute(
        $article,
        ['title' => 'English v2'],
        'en',
        markOthersAsOutdated: true,
    );

    $update->execute(
        $article,
        ['title' => 'English v3'],
        'en',
        markOthersAsOutdated: true,
    );

    expect(
        $article->translationRecords()
            ->where('locale_code', 'en')
            ->firstOrFail()
            ->outdated_at,
    )->toBeNull()
        ->and(
            $article->translationRecords()
                ->where('locale_code', 'ka')
                ->firstOrFail()
                ->outdated_at,
        )->not->toBeNull()
        ->and($article->getTranslation('en')?->attributes['title'])
        ->toBe('English v3');
})->with('outdated tracking translatable models');

it('preserves unicode and json-looking strings exactly', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => '{"foo":"bar"}',
            'description' => 'ქართული 中文 😀 "quoted" \\ backslash',
        ],
    );

    $article->refresh();

    $translation = $article->getTranslation('en');

    expect($translation)->not->toBeNull()
        ->and($translation->attributes['title'])
        ->toBe('{"foo":"bar"}')
        ->and($translation->attributes['description'])
        ->toBe('ქართული 中文 😀 "quoted" \\ backslash');

    resolve(UpdateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => '["still","a","string"]',
            'description' => 'განახლებული 中文 🚀 "quotes" \\ path',
        ],
    );

    $article->refresh();

    $translation = $article->getTranslation('en');

    expect($translation)->not->toBeNull()
        ->and($translation->attributes['title'])
        ->toBe('["still","a","string"]')
        ->and($translation->attributes['description'])
        ->toBe('განახლებული 中文 🚀 "quotes" \\ path');
})->with('translatable models');

it('preserves structured translation values', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $richContent = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'ქართული 中文 😀',
                    ],
                ],
            ],
        ],
    ];

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'description' => $richContent,
        ],
    );

    $article = $article->fresh();

    expect($article)->not->toBeNull();

    $translation = $article->getTranslation('en');

    expect($translation)->not->toBeNull()
        ->and($translation->attributes['description'])
        ->toBe($richContent);
})->with('translatable models');
