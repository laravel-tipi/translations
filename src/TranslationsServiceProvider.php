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

    public function packageBooted(): void
    {
        // Only for applications that used shared storage in v0.1.
        $this->publishes([
            __DIR__.'/../database/upgrades/upgrade_translations_table_from_v0_1.php' => database_path('migrations/'.date('Y_m_d_His').'_upgrade_translations_table_from_v0_1.php'),
        ], 'translations-upgrade-v0.1');
    }

    public function packageRegistered(): void
    {
        // Scoped like the Localization services they read: reset for every
        // request and queued job.
        $this->app->scopedIf(FallbackPolicy::class, ContextFallbackPolicy::class);
        $this->app->scoped(TranslationLocales::class);
    }
}
