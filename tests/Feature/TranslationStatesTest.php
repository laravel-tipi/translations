<?php

declare(strict_types=1);

use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Tests\Fixtures\Enums\OtherStatus;
use Tipi\Translations\Tests\Fixtures\Enums\TranslationStatus;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;

dataset('stateful storages', [
    'json' => StatefulJsonArticle::class,
    'table' => StatefulTableArticle::class,
]);

/**
 * @return array<string, bool> locale => outdated
 */
function states(object $article): array
{
    return TranslationState::query()
        ->where('translatable_type', $article->getMorphClass())
        ->where('translatable_id', $article->getKey())
        ->orderBy('locale_code')
        ->get()
        ->mapWithKeys(fn (TranslationState $state): array => [$state->locale_code => $state->isOutdated()])
        ->all();
}

it('creates a current state for every saved locale', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);

    expect(states($article))->toBe(['en' => false, 'ka' => false]);
})->with('stateful storages');

it('marks other translations outdated and keeps the time they became outdated', function (string $class): void {
    $this->travelTo('2026-01-01 10:00:00');
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი'], 'de' => ['title' => 'Saperawi']]);

    $article->markOtherTranslationsOutdated('en');

    $this->travelTo('2026-01-02 10:00:00');
    $article->markOtherTranslationsOutdated('en');

    expect(states($article))->toBe(['de' => true, 'en' => false, 'ka' => true])
        ->and($article->isTranslationOutdated('ka'))->toBeTrue()
        ->and($article->isTranslationOutdated('en'))->toBeFalse()
        ->and(reload($article)->getTranslationState('ka')->outdated_at->toDateTimeString())->toBe('2026-01-01 10:00:00');
})->with('stateful storages');

it('marks a locale current when its translation is saved', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $article->markTranslationOutdated('ka');

    $article = reload($article);
    $article->setTranslation('title', 'ka', 'საფერავი 2020')->save();

    expect(states($article))->toBe(['en' => false, 'ka' => false]);
})->with('stateful storages');

it('removes the state of a locale whose translations are removed', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);

    reload($article)->forgetTranslations('ka')->save();

    expect(states($article))->toBe(['en' => false]);
})->with('stateful storages');

it('sets a status from the configured enum', function (string $class): void {
    config()->set('translations.states.status_enum', TranslationStatus::class);

    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $article->setTranslationStatus('en', TranslationStatus::Reviewed);

    expect(reload($article)->getTranslationState('en')->status)->toBe(TranslationStatus::Reviewed);

    $article->setTranslationStatus('en', null);

    expect(reload($article)->getTranslationState('en')->status)->toBeNull();
})->with('stateful storages');

it('rejects statuses that are not configured', function (string $class, ?string $enum, string $message): void {
    config()->set('translations.states.status_enum', $enum);

    article($class, ['en' => ['title' => 'Saperavi']])->setTranslationStatus('en', OtherStatus::Done);
})->with('stateful storages')->with([
    'no enum' => [null, 'statuses are not configured'],
    'other enum' => [TranslationStatus::class, 'expected an instance of'],
    'not an enum' => [stdClass::class, 'must be a backed enum class'],
])->throws(InvalidTranslatableModelException::class);

it('keeps states on soft delete and removes them on force delete', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $article->delete();

    expect(TranslationState::query()->count())->toBe(1);

    $article->forceDelete();

    expect(TranslationState::query()->count())->toBe(0);
})->with('stateful storages');

it('returns no state for an untranslated locale', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    expect($article->getTranslationState('ka'))->toBeNull()
        ->and($article->isTranslationOutdated('ka'))->toBeFalse();
})->with('stateful storages');

it('uses the existing state when another process created it meanwhile', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $stale = reload($article);
    $stale->getTranslationState('ka'); // Loads the states: none for "ka".

    reload($article)->markTranslationOutdated('ka');

    $stale->markTranslationCurrent('ka');

    expect(TranslationState::query()->where('locale_code', 'ka')->count())->toBe(1)
        ->and(reload($article)->isTranslationOutdated('ka'))->toBeFalse();
})->with('stateful storages');
