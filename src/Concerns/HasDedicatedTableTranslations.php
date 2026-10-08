<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslationModel;

/**
 * @mixin Model
 */
trait HasDedicatedTableTranslations
{
    use HasLocalizedTranslations;

    /**
     * @return class-string<Model&TranslationModel>
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

    public function hasOutdatedTranslations(): bool
    {
        return $this->translationRecords()->whereNotNull('outdated_at')->exists();
    }
}
