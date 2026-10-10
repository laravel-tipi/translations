<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Tipi\Translations\Contracts\TranslatableModel;

final readonly class Translation
{
    /**
     * @param  array<string, mixed>  $attributes
     */

    public function __construct(
        public TranslatableModel $translatable,
        public string $localeCode,
        public array $attributes,
    ) {}
}
