<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tipi\Localization\Facades\Localization;
use Tipi\Localization\Http\Middleware\UseLocaleContext;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;

/*
 | Translations read through Localization's own HTTP layer: localized
 | routes (default context "web") and the UseLocaleContext middleware
 | (context "admin": en, ka, de; default ka). No locale or context is set
 | by hand.
 */

beforeEach(function (): void {
    $this->article = article(JsonArticle::class, [
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი'],
    ]);

    $show = fn (int $article): string => JsonArticle::query()->findOrFail($article)->title;

    Localization::routes(fn () => Route::get('articles/{article}', $show)->name('articles.show'));

    Route::middleware(['web', UseLocaleContext::with('admin')])->get('admin/articles/{article}', $show);
});

it('reads the locale of a localized URL', function (): void {
    $this->get('/ka/articles/'.$this->article->getKey())->assertOk()->assertSeeText('საფერავი');
    $this->get('/articles/'.$this->article->getKey())->assertOk()->assertSeeText('Saperavi');
});

it('falls back to the default of the localized routes’ context', function (): void {
    $this->get('/de/articles/'.$this->article->getKey())->assertOk()->assertSeeText('Saperavi');
});

it('reads the locale and falls back as the context of a non-localized area says', function (): void {
    $url = '/admin/articles/'.$this->article->getKey();

    // Nothing the context supports: the context default. (Test requests
    // send "Accept-Language: en-us,en" unless told otherwise.)
    $this->withHeader('Accept-Language', 'fr')->get($url)->assertOk()->assertSeeText('საფერავი');

    // Negotiated within the context; German is missing, so the context
    // default is shown, not the global default.
    $this->withHeader('Accept-Language', 'de')->get($url)->assertOk()->assertSeeText('საფერავი');

    // A remembered choice for the context.
    $this->withCookie('locale_admin', 'en')->get($url)->assertOk()->assertSeeText('Saperavi');
});
