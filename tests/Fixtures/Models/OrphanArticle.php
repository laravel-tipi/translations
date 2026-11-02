<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasTableTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * Table storage without an OrphanArticleTranslation model.
 */
#[Translatable(['title'])]
class OrphanArticle extends Model implements TranslatableModel
{
    use HasTableTranslations;

    protected $table = 'table_articles';
}
