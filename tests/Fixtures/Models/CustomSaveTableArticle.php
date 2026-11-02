<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasTableTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * Defines its own save(), which replaces the trait's.
 */
#[Translatable(['title', 'body'])]
class CustomSaveTableArticle extends Model implements TranslatableModel
{
    use HasTableTranslations;

    protected $table = 'table_articles';

    public function translations(): HasMany
    {
        return $this->hasMany(TableArticleTranslation::class, 'table_article_id');
    }

    public function save(array $options = []): bool
    {
        $this->slug ??= 'custom';

        return parent::save($options);
    }
}
