<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

class UndeclaredArticle extends Model implements TranslatableModel
{
    use HasJsonTranslations;

    protected $table = 'json_articles';
}
