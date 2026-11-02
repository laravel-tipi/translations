<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tipi\Translations\Concerns\HasTranslationStates;

/**
 * Inherits #[Translatable] from TableArticle and defines its own
 * translations() relation.
 */
class StatefulTableArticle extends TableArticle
{
    use HasTranslationStates;
    use SoftDeletes;

    public function translations(): HasMany
    {
        return $this->hasMany(TableArticleTranslation::class, 'table_article_id');
    }
}
