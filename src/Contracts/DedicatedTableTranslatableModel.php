<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

interface DedicatedTableTranslatableModel extends TableTranslatableModel
{
    /**
     * @return class-string<TranslationModelContract>
     */
    public static function getTranslationModelClass(): string;
}
