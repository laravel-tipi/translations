<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Models\TranslationModel;
use Tipi\Translations\TranslationManager;

/**
 * @mixin Model
 */
trait HasSharedTableTranslations
{
    use HasLocalizedTranslations;

    public function translationRecords(): MorphMany
    {
        return $this->morphMany(
            TranslationModel::class,
            'translatable',
        );
    }

    public function defaultTranslationRecords(): MorphMany
    {
        return $this->morphMany(
            TranslationModel::class,
            'translatable',
        )->where(
            'locale_code',
            resolve(LocaleProvider::class)->default()->code,
        );
    }

    public function translationExists(?string $localeCode = null): bool
    {
        $localeCode ??= resolve(LocaleProvider::class)->current()->code;

        /** @var Model&SharedTableTranslatableModel $this */
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
        return $this->translationRecords()
            ->whereNotNull('outdated_at')
            ->exists();
    }
}
