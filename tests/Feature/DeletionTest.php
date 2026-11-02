<?php

declare(strict_types=1);

use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;

it('deletes translation rows with the model', function (): void {
    $article = article(TableArticle::class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
    $other = article(TableArticle::class, ['en' => ['title' => 'Mtsvane']]);

    $article->delete();

    expect(TableArticleTranslation::query()->pluck('table_article_id')->all())->toBe([$other->id]);
});

it('keeps translation rows when soft deleting and deletes them when force deleting', function (): void {
    $article = article(StatefulTableArticle::class, ['en' => ['title' => 'Saperavi']]);

    $article->delete();

    expect(TableArticleTranslation::query()->count())->toBe(1);

    $article->restore();

    expect(reload($article)->title)->toBe('Saperavi');

    $article->forceDelete();

    expect(TableArticleTranslation::query()->count())->toBe(0);
});

it('deletes a translation row that has no values left', function (): void {
    $article = reload(article(TableArticle::class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი', 'body' => 'წითელი'],
    ]));

    $article->setTranslation('title', 'ka', null)->save();

    expect(TableArticleTranslation::query()->where('locale_code', 'ka')->exists())->toBeTrue();

    $article->setTranslation('body', 'ka', null)->save();

    expect(TableArticleTranslation::query()->where('locale_code', 'ka')->exists())->toBeFalse()
        ->and($article->getTranslatedLocales())->toBe(['en'])
        ->and($article->translations->pluck('locale_code')->all())->toBe(['en']);
});
