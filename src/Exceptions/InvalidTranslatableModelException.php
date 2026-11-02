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

    public static function invalidConfiguration(string $reason): self
    {
        return new self("Invalid translations configuration: {$reason}.");
    }

    public static function unsupportedKey(string $model, string $reason): self
    {
        return new self("Model [{$model}] cannot use the polymorphic translation tables: {$reason}. See [translations.morph_key_type].");
    }

    public static function connectionMismatch(string $model, string $related, string $connection, string $relatedConnection): self
    {
        return new self(sprintf(
            'Model [%s] is on the [%s] connection, but its translation rows ([%s]) are on [%s]. Both must use one connection, so they are written in one transaction.',
            $model,
            $connection,
            $related,
            $relatedConnection,
        ));
    }

    public static function invalidStatus(string $reason): self
    {
        return new self("Invalid translation status: {$reason}.");
    }
}
