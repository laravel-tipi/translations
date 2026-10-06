<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Support\ServiceProvider;
use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\Contracts\LocaleProvider;

final class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/translation.php',
            'translation',
        );

        $this->app->singleton(
            TranslationConfig::class,
            fn (): TranslationConfig => new TranslationConfig(
                translationsTable: (string) config('translation.translations_table'),
                translationModel: (string) config('translation.translation_model'),
                localeProvider: (string) config('translation.locale_provider'),
            ),
        );

        $this->app->singleton(
            LocaleProvider::class,
            fn (): LocaleProvider => resolve(
                resolve(TranslationConfig::class)->localeProvider,
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            __DIR__.'/../database/migrations',
        );

        $this->publishes([
            __DIR__.'/../config/translation.php' => config_path('translation.php'),
        ], 'translation-config');
    }
}
