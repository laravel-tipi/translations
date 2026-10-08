<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Support\ServiceProvider;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\DeleteTranslation;
use Tipi\Translations\Actions\LockTranslatable;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Stores\DedicatedTableTranslationStore;
use Tipi\Translations\Stores\JsonTranslationStore;
use Tipi\Translations\Stores\SharedTableTranslationStore;

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
        $this->app->singleton(SharedTableTranslationStore::class);
        $this->app->singleton(DedicatedTableTranslationStore::class);
        $this->app->singleton(JsonTranslationStore::class);
        $this->app->singleton(TranslationManager::class);
        $this->app->singleton(LockTranslatable::class);

        $this->app->scoped(
            LocaleProvider::class,
            fn (): LocaleProvider => resolve(
                resolve(TranslationConfig::class)->localeProvider,
            ),
        );
        $this->app->scoped(CreateTranslation::class);
        $this->app->scoped(UpdateTranslation::class);
        $this->app->scoped(DeleteTranslation::class);
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
