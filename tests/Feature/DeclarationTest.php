<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Support\TranslatableAttributes;
use Tipi\Translations\Tests\Fixtures\Models\OrphanArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;
use Tipi\Translations\Tests\Fixtures\Models\UndeclaredArticle;

it('lists the declared attributes in declaration order', function (string $class): void {
    $article = new $class;

    expect($article)->toBeInstanceOf(TranslatableModel::class)
        ->and($article->translatableAttributes())->toBe(['title', 'body'])
        ->and($article->isTranslatableAttribute('title'))->toBeTrue()
        ->and($article->isTranslatableAttribute('slug'))->toBeFalse();
})->with('storages');

it('inherits the declaration from a parent model', function (): void {
    expect((new StatefulTableArticle)->translatableAttributes())->toBe(['title', 'body']);
});

it('leaves other attributes to Eloquent', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Title']], ['slug' => 'saperavi']);

    expect(reload($article)->slug)->toBe('saperavi');
})->with('storages');

it('requires a declaration', function (): void {
    (new UndeclaredArticle)->translatableAttributes();
})->throws(InvalidTranslatableModelException::class, 'does not declare its translatable attributes');

it('rejects invalid declarations', function (array $attributes, string $message): void {
    $model = new #[Translatable([])] class extends Model {};

    // Anonymous classes cannot take a dynamic attribute list, so validate
    // through a named attribute instance instead.
    $reflection = new ReflectionMethod(TranslatableAttributes::class, 'validate');

    expect(fn () => $reflection->invoke(null, $model::class, $attributes))
        ->toThrow(InvalidTranslatableModelException::class, $message);
})->with([
    'empty' => [[], 'the list is empty'],
    'duplicate' => [['title', 'title'], '[title] is listed twice'],
    'json path' => [['title->en'], 'non-empty column names'],
    'not a string' => [[1], 'non-empty column names'],
]);

it('rejects an empty declaration on a model', function (): void {
    $model = new #[Translatable([])] class extends Model implements TranslatableModel
    {
        use HasJsonTranslations;
    };

    $model->translatableAttributes();
})->throws(InvalidTranslatableModelException::class, 'the list is empty');

it('requires the translation model for table storage', function (): void {
    (new OrphanArticle)->translations();
})->throws(InvalidTranslatableModelException::class, 'OrphanArticleTranslation] does not exist');
