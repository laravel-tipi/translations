<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

interface TranslatableModel
{
    /**
     * @return array<int, string>
     */
    public static function getTranslatableAttributes(): array;
}
