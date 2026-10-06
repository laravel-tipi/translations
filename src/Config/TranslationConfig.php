<?php

declare(strict_types=1);

namespace Tipi\Translations\Config;

use Tipi\Translations\Enums\TranslationDriver;

final readonly class TranslationConfig
{
    /**
     * @param  array<int, string>  $attributes
     */
    public function __construct(
        public TranslationDriver $driver,
        public array $attributes,
        public ?string $translationModel = null,
    ) {}
}
