<?php

declare(strict_types=1);

namespace Tipi\Translations\Rules;

use Illuminate\Database\Eloquent\Model;
use Tipi\Support\Arr\ArrayHelper;

abstract class TranslatableRules
{
    public static function create(array $data = []): array
    {
        return [
            ...static::modelRules(),
            ...static::modelCreateRules($data),
            ...ArrayHelper::prefixKeys(
                items: static::translationRules()::create(),
                prefix: 'translation',
            ),
        ];
    }

    public static function update(?Model $record, array $data = []): array
    {
        return [
            ...static::modelRules(),
            ...static::modelUpdateRules($record, $data),
            ...ArrayHelper::prefixKeys(
                items: static::translationRules()::update($record, $data),
                prefix: 'translation',
            ),
        ];
    }

    public static function attributes(): array
    {
        return [
            ...static::modelAttributes(),
            ...ArrayHelper::prefixKeys(
                items: static::translationRules()::attributes(),
                prefix: 'translation',
            ),
        ];
    }

    public static function messages(): array
    {
        return [
            ...static::modelMessages(),
            ...ArrayHelper::prefixKeys(
                items: static::translationRules()::messages(),
                prefix: 'translation',
            ),
        ];
    }

    /**
     * @return class-string<TranslationRules>
     */
    abstract protected static function translationRules(): string;

    protected static function modelRules(): array
    {
        return [];
    }

    protected static function modelCreateRules(array $data): array
    {
        return [];
    }

    protected static function modelUpdateRules(?Model $record, array $data): array
    {
        return [];
    }

    protected static function modelAttributes(): array
    {
        return [];
    }

    protected static function modelMessages(): array
    {
        return [];
    }
}
