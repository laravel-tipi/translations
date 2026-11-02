<?php

declare(strict_types=1);

use Tipi\Translations\Tests\Fixtures\Models\CastArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;

it('stores one row per locale', function (): void {
    $article = article(TableArticle::class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
    ]);

    expect(TableArticleTranslation::query()->orderBy('locale_code')->get(['table_article_id', 'locale_code', 'title', 'body'])->toArray())->toBe([
        ['table_article_id' => $article->id, 'locale_code' => 'en', 'title' => 'Saperavi', 'body' => 'Red'],
        ['table_article_id' => $article->id, 'locale_code' => 'ka', 'title' => 'საფერავი', 'body' => null],
    ]);
});

it('uses the translations relation defined on the model', function (): void {
    $article = article(StatefulTableArticle::class, ['en' => ['title' => 'Saperavi']]);

    expect($article->translations()->getRelated())->toBeInstanceOf(TableArticleTranslation::class)
        ->and(TableArticleTranslation::query()->value('table_article_id'))->toBe($article->id);
});

it('applies the translation model casts', function (): void {
    $article = article(CastArticle::class, [
        'en' => ['content' => ['type' => 'doc', 'blocks' => ['Red']], 'position' => '3'],
    ]);

    $fresh = reload($article);

    expect($fresh->content)->toBe(['type' => 'doc', 'blocks' => ['Red']])
        ->and($fresh->position)->toBe(3)
        ->and(CastArticle::whereTranslation('position', '>', 2, 'en')->count())->toBe(1);
});

it('keeps the loaded relation in sync with saved changes', function (): void {
    $article = TableArticle::with('translations')->find(article(TableArticle::class, ['en' => ['title' => 'Saperavi']])->id);

    $article->setTranslation('title', 'ka', 'საფერავი')->setTranslation('title', 'en', 'Saperavi 2020')->save();

    expect($article->translations->pluck('title', 'locale_code')->all())->toBe(['en' => 'Saperavi 2020', 'ka' => 'საფერავი']);
});

it('writes changes when the relation was unloaded', function (): void {
    $article = reload(article(TableArticle::class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]));

    $article->setTranslation('title', 'ka', 'ახალი')->setTranslation('title', 'de', 'Saperawi');
    $article->unsetRelation('translations');
    $article->save();

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'ახალი'],
        'de' => ['title' => 'Saperawi'],
    ]);
});
