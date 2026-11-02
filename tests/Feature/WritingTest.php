<?php

declare(strict_types=1);

use Tipi\Localization\Exceptions\LocaleNotFoundException;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;

it('writes the current locale through attribute assignment', function (string $class): void {
    useLocale('ka');

    $article = new $class;
    $article->title = 'საფერავი';
    $article->save();

    expect(reload($article)->getAllTranslations())->toBe(['ka' => ['title' => 'საფერავი']]);
})->with('storages');

it('writes the current locale through create() and update()', function (string $class): void {
    $article = $class::create(['slug' => 'saperavi', 'title' => 'Saperavi']);

    useLocale('ka');
    $article->update(['title' => 'საფერავი']);

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi'],
        'ka' => ['title' => 'საფერავი'],
    ])->and(reload($article)->slug)->toBe('saperavi');
})->with('storages');

it('removes the current locale value when null is assigned', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]);

    $article->update(['body' => null]);

    expect(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
})->with('storages');

it('sets translations of explicit locales', function (string $class): void {
    $article = new $class;

    $result = $article
        ->setTranslation('title', 'en', 'Saperavi')
        ->setTranslations('ka', ['title' => 'საფერავი', 'body' => 'წითელი'])
        ->fillTranslations(['de' => ['title' => 'Saperawi']]);

    $article->save();

    expect($result)->toBe($article)
        ->and(reload($article)->getAllTranslations())->toBe([
            'en' => ['title' => 'Saperavi'],
            'ka' => ['title' => 'საფერავი', 'body' => 'წითელი'],
            'de' => ['title' => 'Saperawi'],
        ]);
})->with('storages');

it('updates some values and leaves the others', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი'],
    ]));

    $article->setTranslations('en', ['body' => 'Dark red'])->save();

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi', 'body' => 'Dark red'],
        'ka' => ['title' => 'საფერავი'],
    ]);
})->with('storages');

it('forgets one value or a whole locale', function (string $class): void {
    $article = reload(article($class, [
        'en' => ['title' => 'Saperavi', 'body' => 'Red'],
        'ka' => ['title' => 'საფერავი', 'body' => 'წითელი'],
        'de' => ['title' => 'Saperawi'],
    ]));

    $article->forgetTranslation('body', 'en')->forgetTranslations('ka')->save();

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi'],
        'de' => ['title' => 'Saperawi'],
    ]);
})->with('storages');

it('reads unsaved changes before saving', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]));

    $article->setTranslation('title', 'ka', 'საფერავი')->forgetTranslation('body', 'en');

    useLocale('ka');

    expect($article->hasUnsavedTranslations())->toBeTrue()
        ->and($article->title)->toBe('საფერავი')
        ->and($article->body)->toBeNull()
        ->and($article->getTranslatedLocales())->toBe(['en', 'ka'])
        ->and($article->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']])
        ->and(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi', 'body' => 'Red']]);

    $article->save();

    expect($article->hasUnsavedTranslations())->toBeFalse()
        ->and(reload($article)->getAllTranslations())->toBe($article->getAllTranslations());
})->with('storages');

it('does not record a change that restores the stored value', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'en', 'Changed')->setTranslation('title', 'en', 'Saperavi');
    $article->setTranslation('body', 'ka', null);

    expect($article->hasUnsavedTranslations())->toBeFalse();
})->with('storages');

it('rejects attributes that are not translatable', function (string $class): void {
    $article = new $class;

    expect(fn () => $article->setTranslation('slug', 'en', 'x'))->toThrow(AttributeNotTranslatableException::class)
        ->and(fn () => $article->setTranslations('en', ['title' => 'Title', 'slug' => 'x']))->toThrow(AttributeNotTranslatableException::class)
        ->and(fn () => $article->fillTranslations(['en' => ['slug' => 'x']]))->toThrow(AttributeNotTranslatableException::class)
        ->and($article->hasUnsavedTranslations())->toBeFalse();
})->with('storages');

it('rejects locales that do not exist', function (string $class): void {
    $article = new $class;

    expect(fn () => $article->setTranslation('title', 'xx', 'x'))->toThrow(LocaleNotFoundException::class)
        ->and(fn () => $article->fillTranslations(['en' => ['title' => 'Title'], 'xx' => ['title' => 'x']]))->toThrow(LocaleNotFoundException::class)
        ->and(fn () => $article->forgetTranslations('xx'))->toThrow(LocaleNotFoundException::class)
        ->and($article->hasUnsavedTranslations())->toBeFalse();
})->with('storages');

it('rejects a malformed translations payload', function (string $class): void {
    (new $class)->fillTranslations(['en' => 'Saperavi']);
})->with('storages')->throws(InvalidArgumentException::class, 'must be an array');

it('writes translations of inactive locales', function (string $class): void {
    $this->useDatabaseLocales(inactive: ['fr']);

    $article = article($class, ['fr' => ['title' => 'Saperavi (fr)']]);

    expect(reload($article)->getTranslation('title', 'fr'))->toBe('Saperavi (fr)');
})->with('storages');
