<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

#[Translatable(['title'])]
class UuidSharedArticle extends Model implements TranslatableModel
{
    use HasSharedTableTranslations;
    use HasTranslationStates;
    use HasUuids;
}
