<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * Defines its own save(), which replaces the trait's.
 */
#[Translatable(['title', 'body'])]
class CustomSaveJsonArticle extends Model implements TranslatableModel
{
    use HasJsonTranslations;

    protected $table = 'json_articles';

    public function save(array $options = []): bool
    {
        $this->slug ??= 'custom';

        return parent::save($options);
    }
}
