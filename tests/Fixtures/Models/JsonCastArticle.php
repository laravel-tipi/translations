<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * The JSON-storage counterpart of CastArticle: structured and numeric
 * values, typed by JSON itself.
 */
#[Translatable(['content', 'position'])]
class JsonCastArticle extends Model implements TranslatableModel
{
    use HasJsonTranslations;
}
