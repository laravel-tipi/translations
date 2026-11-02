<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use ReflectionClass;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;

/**
 * Reads and caches #[Translatable] declarations, once per class.
 *
 * @internal
 */
final class TranslatableAttributes
{
    /**
     * @var array<class-string, array<string, true>>
     */
    private static array $declarations = [];

    /**
     * @param  class-string  $class
     * @return array<string, true> attribute => true, in declaration order
     */
    public static function of(string $class): array
    {
        return self::$declarations[$class] ??= self::read($class);
    }

    /**
     * @param  class-string  $class
     * @return array<string, true>
     */
    private static function read(string $class): array
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $attribute = $reflection->getAttributes(Translatable::class)[0] ?? null;

            if ($attribute !== null) {
                return self::validate($class, $attribute->newInstance()->attributes);
            }
        }

        throw InvalidTranslatableModelException::missingDeclaration($class);
    }

    /**
     * @param  class-string  $class
     * @param  array<mixed>  $attributes
     * @return array<string, true>
     */
    private static function validate(string $class, array $attributes): array
    {
        if ($attributes === []) {
            throw InvalidTranslatableModelException::invalidDeclaration($class, 'the list is empty');
        }

        $map = [];

        foreach ($attributes as $attribute) {
            if (! is_string($attribute) || $attribute === '' || str_contains($attribute, '->') || str_contains($attribute, '.')) {
                throw InvalidTranslatableModelException::invalidDeclaration(
                    $class,
                    'attribute names must be non-empty column names',
                );
            }

            if (isset($map[$attribute])) {
                throw InvalidTranslatableModelException::invalidDeclaration($class, "[{$attribute}] is listed twice");
            }

            $map[$attribute] = true;
        }

        return $map;
    }
}
