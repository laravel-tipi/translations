<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasTableTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

#[Translatable(['title', 'body'])]
class TableArticle extends Model implements TranslatableModel
{
    use HasTableTranslations;

    protected $table = 'table_articles';

    protected $guarded = [];
}
