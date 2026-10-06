<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Database\Eloquent\Model;

interface DedicatedTableTranslatableModel extends TableTranslatableModel
{
    /**
     * @return class-string<Model&TranslationModelContract>
     */
    public static function getTranslationModelClass(): string;
}
