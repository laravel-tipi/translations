<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

#[Translatable(['title', 'body'])]
class SharedArticle extends Model implements TranslatableModel
{
    use HasSharedTableTranslations;

    protected $table = 'shared_articles';

    protected $guarded = [];
}
