<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Support\Collection;
use Tipi\Translations\Translation;

interface TranslatableModel
{
    /**
     * @return array<int, string>
     */
    public static function getTranslatableAttributes(): array;

    public function translated(string $attribute, mixed $default = null, ?string $localeCode = null): mixed;

    public function getTranslation(?string $localeCode = null): ?Translation;

    /**
     * @return Collection<int, Translation>
     */
    public function getTranslations(): Collection;

    public function translationExists(?string $localeCode = null): bool;

    public function isTranslationMissing(string $localeCode): bool;

    public function hasMissingTranslations(): bool;

    public function canBeTranslated(): bool;
}
