<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tipi\Localization\LocaleResolver;
use Tipi\Localization\Models\LocaleModel;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * @template TParent of Model&TranslatableModel
 */
trait IsTranslation
{
    protected static ?string $translationParentModel = null;

    public function initializeIsTranslation(): void
    {
        $this->mergeCasts([
            'outdated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ]);
    }

    public static function getTranslationParentModelClass(): string
    {
        if (static::$translationParentModel !== null) {
            return static::$translationParentModel;
        }

        static::$translationParentModel = static::resolveTranslationParentModelClass();

        return static::$translationParentModel;
    }

    /**
     * @return BelongsTo<TParent, $this>
     */
    public function translationParent(): BelongsTo
    {
        return $this->belongsTo(
            static::getTranslationParentModelClass(),
            static::translationParentForeignKey(),
        );
    }

    /**
     * @return BelongsTo<LocaleModel, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(
            LocaleModel::class,
            'locale_code',
        );
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isDefault();
    }

    public function canBeUpdated(): bool
    {
        return true;
    }

    public function isOutdated(): bool
    {
        return $this->outdated_at !== null;
    }

    public function isDefault(): bool
    {
        return $this->locale?->code === static::getLocaleResolver()->getDefaultCode();
    }

    protected static function translationParentForeignKey(): string
    {
        $model = static::getTranslationParentModelClass();

        return (new $model)->getForeignKey();
    }

    protected static function resolveTranslationParentModelClass(): string
    {
        return str(static::class)
            ->beforeLast('Translation')
            ->toString();
    }

    protected static function getLocaleResolver(): LocaleResolver
    {
        return app(LocaleResolver::class);
    }
}
