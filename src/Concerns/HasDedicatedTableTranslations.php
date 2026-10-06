<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslationModelContract;
use Tipi\Translations\TranslationManager;

/**
 * @mixin Model
 */
trait HasDedicatedTableTranslations
{
    use HasLocalizedTranslations;

    /**
     * @return class-string<Model&TranslationModelContract>
     */
    public static function getTranslationModelClass(): string
    {
        return static::$translationModel ?? static::class.'Translation';
    }

    public function translationRecords(): HasMany
    {
        return $this->hasMany(
            static::getTranslationModelClass(),
        );
    }

    public static function bootHasDedicatedTableTranslations(): void
    {
        static::deleting(function (Model $model): void {
            if (
                method_exists($model, 'isForceDeleting')
                && ! $model->isForceDeleting()
            ) {
                return;
            }

            $model->translationRecords()->delete();
        });
    }

    public function defaultTranslationRecord(): HasOne
    {
        return $this->hasOne(static::getTranslationModelClass())
            ->where(
                'locale_code',
                resolve(LocaleProvider::class)->default()->code,
            );
    }

    public function translationExists(?string $localeCode = null): bool
    {
        $localeCode ??= resolve(LocaleProvider::class)->current()->code;

        /** @var Model&DedicatedTableTranslatableModel $this */
        return resolve(TranslationManager::class)->exists(
            translatable: $this,
            localeCode: $localeCode,
        );
    }

    public function isTranslationMissing(string $localeCode): bool
    {
        return ! $this->translationExists($localeCode);
    }

    public function canBeTranslated(): bool
    {
        return $this->hasMissingTranslations();
    }

    public function hasMissingTranslations(): bool
    {
        $locales = resolve(LocaleProvider::class);

        $defaultCode = $locales->default()->code;

        return $locales->supported()
            ->except($defaultCode)
            ->keys()
            ->contains(
                fn (string $localeCode): bool => $this->isTranslationMissing($localeCode),
            );
    }

    public function hasOutdatedTranslations(): bool
    {
        return $this->translationRecords()->whereNotNull('outdated_at')->exists();
    }
}
