<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class DelegatingArticle extends Model
{
    protected $table = 'json_articles';

    public $timestamps = false;

    public function getAttribute($key): mixed
    {
        return parent::getAttribute($key);
    }
}
