<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

#[Translatable(['content', 'position'])]
class SharedCastArticle extends Model implements TranslatableModel
{
    use HasSharedTableTranslations;
}
