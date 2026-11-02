<?php

declare(strict_types=1);

namespace Tipi\Translations\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Tipi\Localization\LocaleContexts;
use Tipi\Localization\LocaleRegistry;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Support\TranslatableAttributes;

/**
 * Validates the shape of a translations payload, the one fillTranslations()
 * accepts: locale => attribute => value.
 *
 *     'translations' => ['required', new Translations(Wine::class)],
 *     'translations.*.name' => ['nullable', 'string', 'max:255'],
 *
 * Locales must exist in the global registry, or be supported by the given
 * context. Attributes must be translatable. Validate the values themselves
 * with ordinary wildcard rules.
 */
final readonly class Translations implements ValidationRule
{
    /**
     * @param  class-string<Model&TranslatableModel>  $model
     */
    public function __construct(
        private string $model,
        private ?string $context = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute field must be a list of translations keyed by locale.');

            return;
        }

        $translatable = TranslatableAttributes::of($this->model);
        $context = $this->context === null ? null : app(LocaleContexts::class)->get($this->context);
        $registry = app(LocaleRegistry::class);

        foreach ($value as $locale => $values) {
            $locale = (string) $locale;

            $supported = $context === null ? $registry->find($locale) !== null : $context->supports($locale);

            if (! $supported) {
                $fail("The :attribute field contains an unsupported locale [{$locale}].");

                continue;
            }

            if (! is_array($values)) {
                $fail("The :attribute field must contain a list of values for locale [{$locale}].");

                continue;
            }

            foreach (array_keys($values) as $key) {
                if (! isset($translatable[(string) $key])) {
                    $fail("The :attribute field contains an attribute [{$key}] that cannot be translated.");
                }
            }
        }
    }
}
