<?php

declare(strict_types=1);

namespace Tipi\Translations\Rules;

use Illuminate\Database\Eloquent\Model;

abstract class TranslationRules
{
    public static function create(array $data = []): array
    {
        return [
            ...static::localeRules(),
            ...static::modelRules(),
            ...static::modelCreateRules($data),
        ];
    }

    public static function update(?Model $record, array $data = []): array
    {
        return [
            ...static::localeRules(),
            ...static::modelRules(),
            ...static::modelUpdateRules($record, $data),
        ];
    }

    public static function messages(): array
    {
        return [
            ...static::localeMessages(),
            ...static::modelMessages(),
        ];
    }

    public static function attributes(): array
    {
        return [
            ...static::localeAttributes(),
            ...static::modelAttributes(),
        ];
    }

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

    /**
     * Rules for the locale when creating a translation.
     *
     * @return array<string, array<int, string>>
     */
    protected static function localeRules(): array
    {
        return [
            'locale_code' => [
                'sometimes',
                'string',
                'max:10',
                'exists:locales,code',
            ],
        ];
    }

    protected static function localeMessages(): array
    {
        return [
            'locale_code.exists' => 'The selected locale is not supported.',
        ];
    }

    protected static function localeAttributes(): array
    {
        return [
            'locale_code' => 'locale',
        ];
    }
}
