<?php

declare(strict_types=1);

use Tipi\Translations\Contracts\FallbackPolicy;
use Tipi\Translations\TranslationLocales;

/*
 | Fallback order: the requested locale, the current context's default,
 | then the global default. Test contexts: "web" (all locales, default
 | follows the global default "en") and "admin" (en, ka, de; default "ka").
 */

it('falls back to the context default before the global default', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი'],
    ]));

    useContext('admin');
    useLocale('de');

    expect($article->title)->toBe('საფერავი')
        ->and($article->resolveTranslation('title')->locale)->toBe('ka');

    useContext('web');

    expect($article->title)->toBe('Saperavi')
        ->and($article->resolveTranslation('title')->locale)->toBe('en');
})->with('storages');

it('falls back to the global default when the context default is missing', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    useContext('admin');
    useLocale('de');

    expect($article->title)->toBe('Saperavi');
})->with('storages');

it('does not fall back to locales outside the policy', function (string $class): void {
    $article = reload(article($class, ['ka' => ['title' => 'საფერავი']]));

    useContext('web');
    useLocale('de');

    expect($article->title)->toBeNull();
})->with('storages');

it('falls back from the context default itself to the global default', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    useContext('admin');
    useLocale('ka');

    expect($article->title)->toBe('Saperavi');
})->with('storages');

it('lists the fallback chain without the requested locale or duplicates', function (): void {
    $locales = app(TranslationLocales::class);

    expect($locales->fallbacks('de'))->toBe(['en'])
        ->and($locales->fallbacks('en'))->toBe([]);

    useContext('admin');

    expect($locales->fallbacks('de'))->toBe(['ka', 'en'])
        ->and($locales->fallbacks('ka'))->toBe(['en'])
        ->and($locales->fallbacks('en'))->toBe(['ka']);
});

it('uses a custom fallback policy for attributes, explicit reads and queries', function (string $class): void {
    app()->instance(FallbackPolicy::class, new class implements FallbackPolicy
    {
        public function fallbacksFor(string $locale): array
        {
            return $locale === 'fr' ? ['de'] : [];
        }
    });
    app()->forgetInstance(TranslationLocales::class);

    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi'],
        'de' => ['title' => 'Saperawi'],
    ]));

    useLocale('fr');

    expect($article->title)->toBe('Saperawi')
        ->and($article->getTranslation('title', 'ka', fallback: true))->toBeNull()
        ->and($class::whereTranslation('title', '=', 'Saperawi', fallback: true)->count())->toBe(1);
})->with('storages');

it('uses the context default when the application locale is inactive', function (string $class): void {
    $this->useDatabaseLocales(inactive: ['fr']);

    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi'],
        'fr' => ['title' => 'Saperavi (fr)'],
    ]));

    useLocale('fr');

    expect($article->title)->toBe('Saperavi')
        ->and($article->getTranslation('title', 'fr'))->toBe('Saperavi (fr)');
})->with('storages');

it('ignores unsupported application locales', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    useLocale('pt');

    expect($article->title)->toBe('Saperavi');
})->with('storages');
