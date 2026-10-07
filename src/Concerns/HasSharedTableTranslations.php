<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\Contracts\LocaleProvider;

/**
 * @mixin Model
 */
trait HasSharedTableTranslations
{
    use HasLocalizedTranslations;

    public function translationRecords(): MorphMany
    {
        return $this->morphMany(
            resolve(TranslationConfig::class)->translationModel,
            'translatable',
        );
    }

    public function defaultTranslationRecord(): MorphOne
    {
        return $this->morphOne(
            resolve(TranslationConfig::class)->translationModel,
            'translatable',
        )->where(
            'locale_code',
            resolve(LocaleProvider::class)->default()->code,
        );
    }

    public static function bootHasSharedTableTranslations(): void
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

    public function hasOutdatedTranslations(): bool
    {
        return $this->translationRecords()
            ->whereNotNull('outdated_at')
            ->exists();
    }
}
