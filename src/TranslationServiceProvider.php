<?php

declare(strict_types=1);

namespace Tipi\Translations;

use BackedEnum;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Actions\DeleteTranslation;
use Tipi\Translations\Actions\LockTranslatable;
use Tipi\Translations\Actions\MarkOthersAsOutdated;
use Tipi\Translations\Actions\UpdateTranslation;
use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Stores\DedicatedTableTranslationStore;
use Tipi\Translations\Stores\JsonTranslationStore;
use Tipi\Translations\Stores\SharedTableTranslationStore;

final class TranslationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('translations')
            ->hasConfigFile()
            ->hasMigrations([
                'create_translations_table',
                'create_translation_states_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            TranslationConfig::class,
            function (): TranslationConfig {
                $translationStateStatusEnum = config(
                    'translations.translation_state_status_enum',
                );
                if (
                    $translationStateStatusEnum !== null
                    && ! is_subclass_of($translationStateStatusEnum, BackedEnum::class)
                ) {
                    throw new InvalidArgumentException(
                        'The translation state status enum must be a backed enum.',
                    );
                }

                return new TranslationConfig(
                    translationsTable: (string) config('translations.translations_table'),
                    translationStatesTable: (string) config('translations.translation_states_table'),
                    translationModel: (string) config('translations.translation_model'),
                    translationStateModel: (string) config('translations.translation_state_model'),
                    translationStateStatusEnum: $translationStateStatusEnum,
                    localeProvider: (string) config('translations.locale_provider'),
                );
            },
        );
        $this->app->singleton(SharedTableTranslationStore::class);
        $this->app->singleton(DedicatedTableTranslationStore::class);
        $this->app->singleton(JsonTranslationStore::class);
        $this->app->singleton(TranslationManager::class);
        $this->app->singleton(TranslationStateManager::class);
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
        $this->app->scoped(MarkOthersAsOutdated::class);
    }
}
