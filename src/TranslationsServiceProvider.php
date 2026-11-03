<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tipi\Translations\Commands\PruneOrphanedTranslationsCommand;
use Tipi\Translations\Contracts\FallbackPolicy;

final class TranslationsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('translations')
            ->hasConfigFile()
            ->hasMigrations(['create_translations_table', 'create_translation_states_table'])
            ->hasCommand(PruneOrphanedTranslationsCommand::class);
    }

    public function packageRegistered(): void
    {
        // Scoped like the Localization services they read: reset for every
        // request and queued job.
        $this->app->scopedIf(FallbackPolicy::class, ContextFallbackPolicy::class);
        $this->app->scoped(TranslationLocales::class);
    }
}
