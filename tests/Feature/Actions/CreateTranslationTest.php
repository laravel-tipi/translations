<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\EmptyTranslationException;
use Tipi\Translations\Exceptions\InvalidTranslationAttributeException;
use Tipi\Translations\Exceptions\TranslationAlreadyExistsException;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\TranslationManager;
use Tipi\Translations\TranslationStateManager;

dataset('translatable models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
    'json columns' => fn () => JsonArticle::query()->create(),
]);

dataset('stateful translatable models', [
    'dedicated table' => fn () => DedicatedArticle::query()->create(),
    'shared table' => fn () => SharedArticle::query()->create(),
    'json columns' => fn () => StatefulJsonArticle::query()->create(),
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

it('creates a translation state with the translation', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English title'],
        localeCode: 'en',
    );

    $state = resolve(TranslationStateManager::class)->get(
        translatable: $article,
        localeCode: 'en',
    );

    expect($state)->not->toBeNull()
        ->and($state->locale_code)->toBe('en')
        ->and($state->outdated_at)->toBeNull()
        ->and($state->status)->toBeNull();
})->with('stateful translatable models');

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

it('stores json translations as json objects instead of double encoded json strings', function (): void {
    $article = JsonArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
        localeCode: 'en',
    );

    $raw = $article->fresh()->getAttributes();

    expect(json_decode($raw['title'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'en' => 'English title',
        ])
        ->and(json_decode($raw['description'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'en' => 'English description',
        ]);
});

it('automatically casts json translatable attributes to arrays', function (): void {
    $article = new JsonArticle;

    expect($article->getCasts())
        ->toMatchArray([
            'title' => 'array',
            'description' => 'array',
        ]);
});

it('preserves existing json locales when creating another translation', function (): void {
    $article = JsonArticle::query()->create();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: [
            'title' => 'English',
            'description' => 'English description',
        ],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: [
            'title' => 'ქართული',
        ],
        localeCode: 'ka',
    );

    $article->refresh();

    expect($article->getAttributeValue('title'))->toBe([
        'en' => 'English',
        'ka' => 'ქართული',
    ])->and($article->getAttributeValue('description'))->toBe([
        'en' => 'English description',
    ]);
});

it('considers a json translation existing when only one translatable attribute contains the locale', function (): void {
    $article = JsonArticle::query()->create();

    $article->setAttribute('title', [
        'en' => 'English',
    ]);

    $article->setAttribute('description', [
        'ka' => 'ქართული აღწერა',
    ]);

    $article->save();

    expect($article->translationExists('en'))->toBeTrue()
        ->and($article->translationExists('ka'))->toBeTrue()
        ->and($article->getTranslation('en')?->attributes)->toBe([
            'title' => 'English',
        ])
        ->and($article->getTranslation('ka')?->attributes)->toBe([
            'description' => 'ქართული აღწერა',
        ]);
});

it('can create a translation on an unsaved json model without persisting the model', function (): void {
    $article = new JsonArticle;

    $translation = resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
            'description' => 'English description',
        ],
        localeCode: 'en',
        dbTransaction: false,
    );

    expect($article->exists)->toBeFalse()
        ->and($article->getKey())->toBeNull()
        ->and(JsonArticle::query()->count())->toBe(0)
        ->and($article->getAttributeValue('title'))->toBe([
            'en' => 'English title',
        ])
        ->and($article->getAttributeValue('description'))->toBe([
            'en' => 'English description',
        ])
        ->and($translation->localeCode)->toBe('en');

    $article->save();

    expect(JsonArticle::query()->count())->toBe(1)
        ->and($article->fresh()->getAttributeValue('title'))->toBe([
            'en' => 'English title',
        ]);
});

it('can accumulate multiple translations on an unsaved json model before it is persisted', function (): void {
    $article = new JsonArticle;

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
        dbTransaction: false,
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
        dbTransaction: false,
    );

    expect($article->exists)->toBeFalse()
        ->and(JsonArticle::query()->count())->toBe(0)
        ->and($article->getAttributeValue('title'))->toBe([
            'en' => 'English',
            'ka' => 'ქართული',
        ]);

    $article->save();

    expect($article->fresh()->getAttributeValue('title'))->toBe([
        'en' => 'English',
        'ka' => 'ქართული',
    ]);
});

it('rolls back persisted json changes when the surrounding transaction fails', function (): void {
    $article = JsonArticle::query()->create();

    try {
        DB::transaction(function () use ($article): void {
            resolve(CreateTranslation::class)->execute(
                translatable: $article,
                attributes: ['title' => 'English'],
                localeCode: 'en',
                dbTransaction: false,
            );

            throw new RuntimeException('Failure');
        });
    } catch (RuntimeException) {
        //
    }

    expect(
        $article->fresh()->getAttributeValue('title'),
    )->toBeNull();
});

it('rolls back translation creation with an outer transaction', function (
    Model&TranslatableModel $article,
): void {
    try {
        DB::transaction(function () use ($article): void {
            resolve(CreateTranslation::class)->execute(
                translatable: $article,
                attributes: ['title' => 'English'],
                localeCode: 'en',
                dbTransaction: false,
            );

            throw new RuntimeException('Failure');
        });
    } catch (RuntimeException) {
        //
    }

    expect(
        resolve(TranslationManager::class)->exists(
            translatable: $article->fresh(),
            localeCode: 'en',
        ),
    )->toBeFalse();
})->with('translatable models');

dataset('table translatable models', [
    'dedicated table' => fn () => new DedicatedArticle,
    'shared table' => fn () => new SharedArticle,
]);

it('does not create a table translation for an unsaved model', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
        ],
        localeCode: 'en',
    );
})->with('table translatable models')
    ->throws(LogicException::class);

it('fails clearly when creating a table translation for an unsaved model', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => 'English title',
        ],
        localeCode: 'en',
    );
})->with('table translatable models')
    ->throws(
        LogicException::class,
        'Translations using table storage require the translatable model to be persisted.',
    );

it('does not persist an unsaved table model when translation creation fails', function (
    Model&TranslatableModel $article,
): void {
    try {
        resolve(CreateTranslation::class)->execute(
            translatable: $article,
            attributes: ['title' => 'English'],
            localeCode: 'en',
        );
    } catch (LogicException) {
        //
    }

    expect($article->exists)->toBeFalse()
        ->and($article->getKey())->toBeNull();
})->with('table translatable models');

it('does not leave translation records behind after attempting to translate an unsaved shared model', function (): void {
    $article = new SharedArticle;

    try {
        resolve(CreateTranslation::class)->execute(
            $article,
            ['title' => 'English'],
            'en',
        );
    } catch (LogicException) {
        //
    }

    expect(DB::table('shared_articles')->count())->toBe(0)
        ->and(DB::table('translations')->count())->toBe(0);
});

it('does not leave translation records behind after attempting to translate an unsaved dedicated model', function (): void {
    $article = new DedicatedArticle;

    try {
        resolve(CreateTranslation::class)->execute(
            $article,
            ['title' => 'English'],
            'en',
        );
    } catch (LogicException) {
        //
    }

    expect(DB::table('dedicated_articles')->count())->toBe(0)
        ->and(DB::table('dedicated_article_translations')->count())->toBe(0);
});

it('rejects creating an empty translation', function (
    Model&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [],
        localeCode: 'en',
    );
})->with('translatable models')
    ->throws(EmptyTranslationException::class);

it('does not partially create a translation when one attribute is invalid', function (
    Model&TranslatableModel $article,
): void {
    try {
        resolve(CreateTranslation::class)->execute(
            $article,
            [
                'title' => 'Valid',
                'invalid' => 'Invalid',
            ],
            'en',
        );
    } catch (InvalidTranslationAttributeException) {
        //
    }

    expect(
        resolve(TranslationManager::class)->exists(
            $article,
            'en',
        ),
    )->toBeFalse();
})->with('translatable models');

it('allows falsy translation values', function (
    Model&TranslatableModel $article,
): void {
    $translation = resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: [
            'title' => '',
            'description' => null,
        ],
        localeCode: 'en',
    );

    expect($translation->attributes)->toBe([
        'title' => '',
        'description' => null,
    ]);
})->with('translatable models');

it('does not overwrite newer json translations when creating from a stale model', function (): void {
    $article = JsonArticle::query()->create();

    $stale = JsonArticle::query()->findOrFail($article->getKey());
    $fresh = JsonArticle::query()->findOrFail($article->getKey());

    resolve(CreateTranslation::class)->execute(
        translatable: $fresh,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    resolve(CreateTranslation::class)->execute(
        translatable: $stale,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    expect(
        $article->fresh()->getAttributeValue('title'),
    )->toBe([
        'en' => 'English',
        'ka' => 'ქართული',
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

it('rolls back translation creation with the surrounding transaction', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    try {
        DB::transaction(function () use ($article): void {
            resolve(CreateTranslation::class)->execute(
                translatable: $article,
                attributes: [
                    'title' => 'English',
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

    expect($article)->not->toBeNull()
        ->and($article->translationExists('en'))->toBeFalse();
})->with('translatable models');

it('does not modify an existing translation when duplicate creation is attempted', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: [
            'title' => 'Original',
        ],
        localeCode: 'en',
    );

    try {
        $create->execute(
            translatable: $article,
            attributes: [
                'title' => 'Changed',
            ],
            localeCode: 'en',
        );
    } catch (TranslationAlreadyExistsException) {
        //
    }

    $article = $article->fresh();

    $translation = $article?->getTranslation('en');

    expect($translation)
        ->not->toBeNull()
        ->and($translation->attributes['title'])
        ->toBe('Original');

})->with('translatable models');
