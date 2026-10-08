<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class ControlArticle extends Model
{
    protected $table = 'json_articles';

    public $timestamps = false;
}
