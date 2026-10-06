<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tipi\Localization\Locale;
use Tipi\Translations\Contracts\LocaleProvider;
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

    public static function getTranslatableModelClass(): string
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
    public function translatable(): BelongsTo
    {
        return $this->belongsTo(
            static::getTranslatableModelClass(),
            static::translationParentForeignKey(),
        );
    }

    public function locale(): Locale
    {
        return resolve(LocaleProvider::class)->supportedLocale($this->locale_code);
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
        return $this->locale_code === resolve(LocaleProvider::class)->default()->code;
    }

    protected static function translationParentForeignKey(): string
    {
        $model = static::getTranslatableModelClass();

        return (new $model)->getForeignKey();
    }

    protected static function resolveTranslationParentModelClass(): string
    {
        return str(static::class)
            ->beforeLast('Translation')
            ->toString();
    }
}
