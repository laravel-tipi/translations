<?php

declare(strict_types=1);

namespace Tipi\Translations\Support\Filament;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class FilamentValidator
{
    /**
     * Validate Filament form data and map validation errors
     * to the form state path.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = [],
        string $statePath = 'data',
    ): array {
        try {
            return Validator::make(
                data: $data,
                rules: $rules,
                messages: $messages,
                attributes: $attributes,
            )->validate();
        } catch (ValidationException $exception) {
            throw self::validationException(
                errors: $exception->errors(),
                statePath: $statePath,
            );
        }
    }

    /**
     * Create a Filament-aware validation exception.
     *
     * @param  array<string, array<int, string>|string>  $errors
     */
    public static function validationException(
        array $errors,
        string $statePath = 'data',
    ): ValidationException {
        return ValidationException::withMessages(
            collect($errors)
                ->mapWithKeys(
                    fn (array|string $messages, string $field): array => [
                        "$statePath.$field" => $messages,
                    ],
                )
                ->all(),
        );
    }

    public static function fail(
        string $field,
        string $message,
        string $statePath = 'data',
    ): never {
        throw ValidationException::withMessages([
            "$statePath.$field" => $message,
        ]);
    }
}
