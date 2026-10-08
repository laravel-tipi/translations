<?php

declare(strict_types=1);

use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;
use Tipi\Translations\Tests\Fixtures\Models\ConflictingTranslatable;
use Tipi\Translations\Tests\Fixtures\Models\InvalidTranslatable;
use Tipi\Translations\TranslationManager;

it('rejects a model without a translation storage strategy', function (): void {
    $article = new InvalidTranslatable;

    resolve(TranslationManager::class)->create(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English',
        ],
    );
})->throws(
    InvalidTranslationConfigurationException::class,
    'does not define a supported translation storage strategy',
);

it('rejects a model with multiple translation storage strategies', function (): void {
    $article = new ConflictingTranslatable;

    resolve(TranslationManager::class)->create(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English',
        ],
    );
})->throws(InvalidTranslationConfigurationException::class);

it('rejects multiple storage strategies when getting a translation', function (): void {
    $article = new ConflictingTranslatable;

    resolve(TranslationManager::class)->get(
        translatable: $article,
        localeCode: 'en',
    );
})->throws(
    InvalidTranslationConfigurationException::class,
    'defines multiple translation storage strategies',
);

it('rejects multiple storage strategies when getting all translations', function (): void {
    $article = new ConflictingTranslatable;

    resolve(TranslationManager::class)->getAll(
        translatable: $article,
    );
})->throws(
    InvalidTranslationConfigurationException::class,
    'defines multiple translation storage strategies',
);

it('rejects multiple storage strategies when checking if a translation exists', function (): void {
    $article = new ConflictingTranslatable;

    resolve(TranslationManager::class)->exists(
        translatable: $article,
        localeCode: 'en',
    );
})->throws(
    InvalidTranslationConfigurationException::class,
    'defines multiple translation storage strategies',
);

it('rejects multiple storage strategies when updating a translation', function (): void {
    $article = new ConflictingTranslatable;

    resolve(TranslationManager::class)->update(
        translatable: $article,
        localeCode: 'en',
        attributes: [
            'title' => 'English',
        ],
    );
})->throws(
    InvalidTranslationConfigurationException::class,
    'defines multiple translation storage strategies',
);

it('rejects multiple storage strategies when deleting a translation', function (): void {
    $article = new ConflictingTranslatable;

    resolve(TranslationManager::class)->delete(
        translatable: $article,
        localeCode: 'en',
    );
})->throws(
    InvalidTranslationConfigurationException::class,
    'defines multiple translation storage strategies',
);
