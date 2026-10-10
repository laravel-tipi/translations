<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Enums\TranslationDriver;
use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;

final readonly class TranslationDriverResolver
{
    public function resolve(
        Model&TranslatableModel $translatable,
    ): TranslationDriver {
        $strategies = array_filter([
            TranslationDriver::Json->value => $translatable instanceof JsonTranslatableModel,
            TranslationDriver::DedicatedTable->value => $translatable instanceof DedicatedTableTranslatableModel,
            TranslationDriver::SharedTable->value => $translatable instanceof SharedTableTranslatableModel,
        ]);

        if ($strategies === []) {
            throw InvalidTranslationConfigurationException::missingStorageStrategy(
                model: $translatable,
            );
        }

        if (count($strategies) > 1) {
            throw InvalidTranslationConfigurationException::multipleStorageStrategies(
                model: $translatable,
            );
        }

        return TranslationDriver::from(array_key_first($strategies));
    }
}
