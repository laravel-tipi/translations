<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\InvalidTranslationAttributeException;

final readonly class TranslationAttributeValidator
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function validate(
        TranslatableModel $translatable,
        array $attributes,
    ): void {
        $translatableAttributes = $translatable::getTranslatableAttributes();

        $invalid = array_diff(
            array_keys($attributes),
            $translatableAttributes,
        );

        if ($invalid !== []) {
            throw InvalidTranslationAttributeException::forAttributes(
                model: $translatable::class,
                attributes: $invalid,
            );
        }
    }
}
