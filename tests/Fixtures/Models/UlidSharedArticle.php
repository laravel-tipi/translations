<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

#[Translatable(['title'])]
class UlidSharedArticle extends Model implements TranslatableModel
{
    use HasSharedTableTranslations;
    use HasTranslationStates;
    use HasUlids;
}
