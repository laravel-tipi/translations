<?php

declare(strict_types=1);

use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;
use Tipi\Translations\Tests\Fixtures\Enums\OtherTranslationStatus;
use Tipi\Translations\Tests\Fixtures\Enums\TranslationStatus;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\TranslationStateManager;

it('creates a translation state', function (): void {
    $article = DedicatedArticle::query()->create();

    $state = resolve(TranslationStateManager::class)->create(
        translatable: $article,
        localeCode: 'en',
    );

    expect($state->translatable->is($article))->toBeTrue()
        ->and($state->locale_code)->toBe('en')
        ->and($state->status)->toBeNull()
        ->and($state->outdated_at)->toBeNull();
});

it('gets a translation state', function (): void {
    $article = DedicatedArticle::query()->create();

    resolve(TranslationStateManager::class)->create(
        translatable: $article,
        localeCode: 'en',
    );

    $state = resolve(TranslationStateManager::class)->get(
        translatable: $article,
        localeCode: 'en',
    );

    expect($state)->not->toBeNull()
        ->and($state->locale_code)->toBe('en');
});

it('returns null when a translation state does not exist', function (): void {
    $article = DedicatedArticle::query()->create();

    expect(
        resolve(TranslationStateManager::class)->get(
            translatable: $article,
            localeCode: 'en',
        ),
    )->toBeNull();
});

it('deletes a translation state', function (): void {
    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $manager->delete(
        translatable: $article,
        localeCode: 'en',
    );

    expect(
        $manager->get(
            translatable: $article,
            localeCode: 'en',
        ),
    )->toBeNull();
});

it('marks a translation state as outdated', function (): void {
    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $state = $manager->markAsOutdated(
        translatable: $article,
        localeCode: 'en',
    );

    expect($state->outdated_at)->not->toBeNull();
});

it('marks a translation state as current', function (): void {
    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $manager->markAsOutdated(
        translatable: $article,
        localeCode: 'en',
    );

    $state = $manager->markAsCurrent(
        translatable: $article,
        localeCode: 'en',
    );

    expect($state->outdated_at)->toBeNull();
});

it('marks all other translation states as outdated', function (): void {
    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    foreach (['en', 'ka', 'de'] as $localeCode) {
        $manager->create(
            translatable: $article,
            localeCode: $localeCode,
        );
    }

    $manager->markOthersAsOutdated(
        translatable: $article,
        localeCode: 'en',
    );

    expect(
        $manager->get($article, 'en')?->outdated_at,
    )->toBeNull()
        ->and(
            $manager->get($article, 'ka')?->outdated_at,
        )->not->toBeNull()
        ->and(
            $manager->get($article, 'de')?->outdated_at,
        )->not->toBeNull();
});

it('sets a translation status', function (): void {
    config()->set(
        'translation.translation_state_status_enum',
        TranslationStatus::class,
    );

    app()->forgetInstance(TranslationConfig::class);

    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $state = $manager->setStatus(
        translatable: $article,
        localeCode: 'en',
        status: TranslationStatus::Draft,
    );

    expect($state->status)->toBe(TranslationStatus::Draft);
});

it('casts a persisted translation status to the configured enum', function (): void {
    config()->set(
        'translation.translation_state_status_enum',
        TranslationStatus::class,
    );

    app()->forgetInstance(TranslationConfig::class);

    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $manager->setStatus(
        translatable: $article,
        localeCode: 'en',
        status: TranslationStatus::Published,
    );

    $state = $manager->get(
        translatable: $article,
        localeCode: 'en',
    );

    expect($state)->not->toBeNull()
        ->and($state->status)->toBe(TranslationStatus::Published);
});

it('clears a translation status', function (): void {
    config()->set(
        'translation.translation_state_status_enum',
        TranslationStatus::class,
    );

    app()->forgetInstance(TranslationConfig::class);

    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $manager->setStatus(
        translatable: $article,
        localeCode: 'en',
        status: TranslationStatus::Draft,
    );

    $state = $manager->setStatus(
        translatable: $article,
        localeCode: 'en',
        status: null,
    );

    expect($state->status)->toBeNull();
});

it('rejects a translation state status type that is not a backed enum', function (): void {
    config()->set(
        'translation.translation_state_status_enum',
        stdClass::class,
    );

    app()->forgetInstance(TranslationConfig::class);

    resolve(TranslationConfig::class);
})->throws(
    InvalidArgumentException::class,
    'The translation state status enum must be a backed enum.',
);

it('rejects a status when no status enum is configured', function (): void {
    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $manager->setStatus(
        translatable: $article,
        localeCode: 'en',
        status: TranslationStatus::Draft,
    );
})->throws(InvalidTranslationConfigurationException::class);

it('rejects a status from a different enum', function (): void {
    config()->set(
        'translation.translation_state_status_enum',
        TranslationStatus::class,
    );

    app()->forgetInstance(TranslationConfig::class);

    $article = DedicatedArticle::query()->create();

    $manager = resolve(TranslationStateManager::class);

    $manager->create(
        translatable: $article,
        localeCode: 'en',
    );

    $manager->setStatus(
        translatable: $article,
        localeCode: 'en',
        status: OtherTranslationStatus::Pending,
    );
})->throws(InvalidTranslationConfigurationException::class);
