<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

final class ArrayHelper
{
    /**
     * Prefix all array keys with the given prefix.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $items
     * @return array<string, TValue>
     */
    public static function prefixKeys(array $items, string $prefix): array
    {
        $prefixed = [];

        foreach ($items as $key => $value) {
            $prefixed["$prefix.$key"] = $value;
        }

        return $prefixed;
    }
}
