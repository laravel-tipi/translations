<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasTableTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * UUID keys for the model and its translation rows.
 */
#[Translatable(['title'])]
class UuidArticle extends Model implements TranslatableModel
{
    use HasTableTranslations;
    use HasUuids;
}
