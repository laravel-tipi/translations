<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Support\ServiceProvider;
use Tipi\Translations\Config\TranslationConfig;

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
                localeProvider: resolve(
                    config('translation.locale_provider'),
                ),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            __DIR__.'/../database/migrations',
        );
        $this->loadViewsFrom(
            __DIR__.'/../resources/views',
            'tipi-translations',
        );

        $this->publishes([
            __DIR__.'/../config/translation.php' => config_path('translation.php'),
        ], 'translation-config');
    }
}
