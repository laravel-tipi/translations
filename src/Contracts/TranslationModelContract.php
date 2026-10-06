<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tipi\Localization\Locale;

/**
 * @property $outdated_at
 * @property $locale_code
 * @property $translationParent
 */
interface TranslationModelContract
{
    /**
     * @return class-string<Model&TranslatableModel>
     */
    public static function getTranslatableModelClass(): string;

    public function translatable(): BelongsTo;

    public function locale(): Locale;

    public function isDefault(): bool;

    public function canBeDeleted(): bool;

    public function canBeUpdated(): bool;

    public function isOutdated(): bool;
}
