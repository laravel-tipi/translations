<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Tipi\Translations\Rules\Translations;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;

function validateTranslations(mixed $translations, ?string $context = null): array
{
    return Validator::make(
        ['translations' => $translations],
        [
            'translations' => ['required', new Translations(TableArticle::class, $context)],
            'translations.*.title' => ['nullable', 'string', 'max:20'],
        ],
    )->errors()->all();
}

it('accepts a payload of existing locales and translatable attributes', function (): void {
    expect(validateTranslations([
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'fr' => ['title' => null],
    ]))->toBe([]);
});

it('rejects a payload that is not keyed by locale', function (): void {
    expect(validateTranslations('Saperavi'))->toBe(['The translations field must be a list of translations keyed by locale.'])
        ->and(validateTranslations(['en' => 'Saperavi']))->toBe(['The translations field must contain a list of values for locale [en].']);
});

it('rejects unknown locales and attributes', function (): void {
    expect(validateTranslations(['xx' => ['title' => 'Saperavi'], 'en' => ['slug' => 'saperavi']]))->toBe([
        'The translations field contains an unsupported locale [xx].',
        'The translations field contains an attribute [slug] that cannot be translated.',
    ]);
});

it('limits locales to a context', function (): void {
    expect(validateTranslations(['fr' => ['title' => 'Saperavi']], 'admin'))->toBe(['The translations field contains an unsupported locale [fr].'])
        ->and(validateTranslations(['ka' => ['title' => 'საფერავი']], 'admin'))->toBe([]);
});

it('leaves value rules to wildcard rules', function (): void {
    expect(validateTranslations(['en' => ['title' => str_repeat('a', 21)]]))->toBe(['The translations.en.title field must not be greater than 20 characters.']);
});

it('validates a payload that fills a model', function (): void {
    $payload = ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']];

    expect(validateTranslations($payload))->toBe([]);

    $article = (new TableArticle)->fillTranslations($payload);
    $article->save();

    expect(reload($article)->getAllTranslations())->toBe($payload);
});
