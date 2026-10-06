<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Support\ServiceProvider;

final class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/translation.php',
            'translation',
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
