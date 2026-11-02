<?php

declare(strict_types=1);

use Tipi\Localization\Exceptions\LocaleNotFoundException;
use Tipi\Localization\Facades\Localization;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;
use Tipi\Translations\Exceptions\MissingTranslationException;
use Tipi\Translations\ResolvedTranslation;

/*
 | Implicit access ($model->title) resolves with fallback. Explicit methods
 | are exact unless fallback is requested.
 */

it('reads the current locale through attribute access', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);

    useLocale('ka');

    expect(reload($article)->title)->toBe('საფერავი');
})->with('storages');

it('falls back to the default locale through attribute access', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    useLocale('ka');

    expect(reload($article)->title)->toBe('Saperavi');
})->with('storages');

it('returns null through attribute access when no locale has a value', function (string $class): void {
    $article = article($class, ['ka' => ['title' => 'საფერავი']]);

    useLocale('de');

    expect(reload($article)->title)->toBeNull()
        ->and(isset(reload($article)->title))->toBeFalse();
})->with('storages');

it('follows the current locale when it changes during a request', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]));

    $titles = [];

    foreach (['en', 'ka', 'de', 'ka'] as $locale) {
        useLocale($locale);
        $titles[] = $article->title;
    }

    expect($titles)->toBe(['Saperavi', 'საფერავი', 'Saperavi', 'საფერავი']);
})->with('storages');

it('reads the exact translation of a locale', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    useLocale('ka');

    expect($article->title)->toBe('Saperavi')
        ->and($article->getTranslation('title', 'ka'))->toBeNull()
        ->and($article->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and($article->getTranslation('title'))->toBeNull();
})->with('storages');

it('falls back on request', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    expect($article->getTranslation('title', 'ka', fallback: true))->toBe('Saperavi')
        ->and($article->getTranslation('body', 'ka', fallback: true))->toBeNull();
})->with('storages');

it('accepts Locale objects', function (string $class): void {
    $article = reload(article($class, ['ka' => ['title' => 'საფერავი']]));

    $georgian = Localization::locales()->get('ka');

    expect($article->getTranslation('title', $georgian))->toBe('საფერავი')
        ->and($article->hasTranslation('title', $georgian))->toBeTrue();
})->with('storages');

it('fails for a required translation', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    expect($article->getTranslationOrFail('title', 'en'))->toBe('Saperavi')
        ->and($article->getTranslationOrFail('title', 'ka', fallback: true))->toBe('Saperavi');

    try {
        $article->getTranslationOrFail('title', 'ka');
        $this->fail('Expected a MissingTranslationException.');
    } catch (MissingTranslationException $exception) {
        expect($exception->model)->toBe($class)
            ->and($exception->key)->toBe($article->getKey())
            ->and($exception->attribute)->toBe('title')
            ->and($exception->locale)->toBe('ka')
            ->and($exception->fallback)->toBeFalse()
            ->and($exception->getMessage())->toContain('[title]', '[ka]');
    }
})->with('storages');

it('fails for a required translation when fallback finds nothing', function (string $class): void {
    reload(article($class, ['de' => ['title' => 'Saperavi']]))->getTranslationOrFail('title', 'ka', fallback: true);
})->with('storages')->throws(MissingTranslationException::class, 'including its fallback locales');

it('reports where a resolved value came from', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]));

    $translated = $article->resolveTranslation('title', 'ka');
    $fallback = $article->resolveTranslation('title', 'de');
    $missing = $article->resolveTranslation('body', 'de');

    expect($translated)->toBeInstanceOf(ResolvedTranslation::class)
        ->and([$translated->value, $translated->locale, $translated->requestedLocale])->toBe(['საფერავი', 'ka', 'ka'])
        ->and([$translated->isTranslated(), $translated->isFallback(), $translated->isMissing()])->toBe([true, false, false])
        ->and([$fallback->value, $fallback->locale, $fallback->requestedLocale])->toBe(['Saperavi', 'en', 'de'])
        ->and([$fallback->isTranslated(), $fallback->isFallback(), $fallback->isMissing()])->toBe([false, true, false])
        ->and([$missing->value, $missing->locale])->toBe([null, null])
        ->and([$missing->isTranslated(), $missing->isFallback(), $missing->isMissing()])->toBe([false, false, true]);
})->with('storages');

it('resolves the current locale by default', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    useLocale('ka');

    expect($article->resolveTranslation('title')->requestedLocale)->toBe('ka');
})->with('storages');

it('reads all values of one locale', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
    ]));

    useLocale('ka');

    expect($article->getTranslations('en'))->toBe(['title' => 'Saperavi', 'body' => 'Red'])
        ->and($article->getTranslations())->toBe(['title' => 'საფერავი'])
        ->and($article->getTranslations('de'))->toBe([]);
})->with('storages');

it('reads every value in display order', function (string $class): void {
    $article = reload(article($class, [
        'de' => ['body' => 'Rot'],
        'ka' => ['title' => 'საფერავი'],
        'en' => ['body' => 'Red', 'title' => 'Saperavi'],
    ]));

    expect($article->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
        'de' => ['body' => 'Rot'],
    ])->and($article->getTranslatedLocales())->toBe(['en', 'ka', 'de']);
})->with('storages');

it('rejects unknown attributes in explicit reads', function (string $class, string $method): void {
    (new $class)->{$method}('slug', 'en');
})->with('storages')
    ->with(['getTranslation', 'getTranslationOrFail', 'resolveTranslation', 'hasTranslation'])
    ->throws(AttributeNotTranslatableException::class, 'Attribute [slug] is not translatable');

it('rejects locales that do not exist', function (string $class): void {
    (new $class)->getTranslation('title', 'xx');
})->with('storages')->throws(LocaleNotFoundException::class, 'Locale [xx] does not exist.');
