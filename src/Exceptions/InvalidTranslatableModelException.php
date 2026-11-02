<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use LogicException;
use Tipi\Translations\Attributes\Translatable;

/**
 * A model is not set up correctly for translations.
 */
final class InvalidTranslatableModelException extends LogicException implements TranslationException
{
    public static function missingDeclaration(string $model): self
    {
        return new self(sprintf(
            'Model [%s] uses translations but does not declare its translatable attributes. Add #[%s([...])] to the class.',
            $model,
            Translatable::class,
        ));
    }

    public static function invalidDeclaration(string $model, string $reason): self
    {
        return new self("Invalid translatable attributes on model [{$model}]: {$reason}.");
    }

    public static function missingTranslationModel(string $model, string $translationModel): self
    {
        return new self(sprintf(
            'Model [%s] stores translations in a table, but [%s] does not exist. Create it, or define a translations() HasMany relation on the model.',
            $model,
            $translationModel,
        ));
    }

    public static function invalidStatus(string $reason): self
    {
        return new self("Invalid translation status: {$reason}.");
    }
}
