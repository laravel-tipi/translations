<?php

declare(strict_types=1);

namespace Tipi\Translations\Attributes;

use Attribute;

/**
 * Declares which attributes of an Eloquent model are translatable.
 *
 *     #[Translatable(['name', 'description'])]
 *     class Wine extends Model implements TranslatableModel
 *     {
 *         use HasTableTranslations;
 *     }
 *
 * Subclasses inherit the declaration of their closest declaring parent.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Translatable
{
    /**
     * @param  list<string>  $attributes
     */
    public function __construct(
        public array $attributes,
    ) {}
}
