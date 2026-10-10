# Laravel Tipi Translations

Flexible Eloquent model translations for Laravel.

The package lets each translatable model choose the storage strategy that fits it:

- **Dedicated table** — one translation table per model.
- **Shared table** — one polymorphic translations table for many model types.
- **JSON columns** — translations stored directly on the model.

It integrates with `laravel-tipi/localization` for supported, current, and default locales.

## Requirements

- PHP 8.5+
- Laravel 13
- `laravel-tipi/localization ^0.1`

## Installation

Install the package with Composer:

```bash
composer require laravel-tipi/translations
```

Laravel discovers `Tipi\Translations\TranslationServiceProvider` automatically.

The package migrations create the shared translations and translation states tables. Run your application migrations:

```bash
php artisan migrate
```

To publish the package configuration:

```bash
php artisan vendor:publish --tag=translation-config
```

## Configuration

`config/translations.php` controls package-wide infrastructure:

```php
return [
    'translations_table' => 'translations',
    'translation_model' => \Tipi\Translations\Models\Translation::class,
    'translation_states_table' => 'translation_states',
    'translation_state_model' => \Tipi\Translations\Models\TranslationState::class,
    'translation_state_status_enum' => null,
    'locale_provider' => \Tipi\Translations\Providers\LocalizationLocaleProvider::class,
];
```

Storage strategy is selected by each model through its contract and trait; it is not a global driver setting.

## Dedicated translation table

Use this strategy when a model should have its own normalized translation table.

```php
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasDedicatedTableTranslations;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;

final class Article extends Model implements DedicatedTableTranslatableModel
{
    use HasDedicatedTableTranslations;

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
```

By convention the package resolves `ArticleTranslation`. The translation model uses `IsTranslation`:

```php
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\IsTranslation;
use Tipi\Translations\Contracts\TranslationModel;

final class ArticleTranslation extends Model implements TranslationModel
{
    use IsTranslation;
}
```

Your translation table should contain the parent foreign key, `locale_code`, translated columns and timestamps. Add a unique constraint for the parent foreign key plus `locale_code`.

Translated attributes use the translation model's normal Eloquent casting behavior. Configure casts on the translation model when an attribute contains structured values such as JSON or rich-text document data:

```php
protected function casts(): array
{
    return [
        'content' => 'array',
    ];
}
```

The package does not coerce dedicated-table translation values to strings; their persisted representation is determined by the translation model's casts and database schema.

## Shared translation table

Use the shared polymorphic table when many models can use the same translation schema.

```php
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;

final class Article extends Model implements SharedTableTranslatableModel
{
    use HasSharedTableTranslations;

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
```

The package migration stores one row per model and locale. Translated values are stored in the row's `values` JSON column.

## JSON translations

Use JSON storage when translated values should live directly on the model table.

```php
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\JsonTranslatableModel;

final class Article extends Model implements JsonTranslatableModel
{
    use HasJsonTranslations;

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
```

Each translatable attribute must be backed by a JSON column. `HasJsonTranslations` automatically registers an `array` cast for every translatable attribute so Eloquent owns JSON encoding and decoding.

JSON translations can be created on unsaved models and accumulated before the parent model is first persisted. Dedicated-table and shared-table translations require an already-persisted parent model.

Translation states, including statuses and outdated tracking, are independent of storage strategy. JSON models can opt in alongside dedicated-table and shared-table models by implementing `HasTranslationStates` and using `InteractsWithTranslationStates`. The `outdated_at` timestamp belongs exclusively to `TranslationState`, not to translation records.

```php
use Tipi\Translations\Concerns\InteractsWithTranslationStates;
use Tipi\Translations\Contracts\HasTranslationStates;

final class Article extends Model implements JsonTranslatableModel, HasTranslationStates
{
    use HasJsonTranslations;
    use InteractsWithTranslationStates;

    protected static array $translatableAttributes = ['title', 'description'];
}
```

## Reading translations

Translatable attributes resolve using the current locale:

```php
$article->title;
```

You can explicitly choose a locale or provide a fallback value:

```php
$article->translated(
    attribute: 'title',
    default: 'Untitled',
    localeCode: 'en',
);
```

## Creating translations

Use the package actions for writes:

```php
use Tipi\Translations\Actions\CreateTranslation;

$translation = resolve(CreateTranslation::class)->execute(
    translatable: $article,
    attributes: [
        'title' => 'ქართული სათაური',
        'description' => 'ქართული აღწერა',
    ],
    localeCode: 'ka',
);
```

If `localeCode` is omitted when creating a translation, the current locale is used.

## Updating translations

```php
use Tipi\Translations\Actions\UpdateTranslation;

$translation = resolve(UpdateTranslation::class)->execute(
    translatable: $article,
    attributes: [
        'title' => 'Updated title',
    ],
    localeCode: 'en',
);
```

Updates are partial: omitted attributes remain unchanged, while an explicitly supplied `null` clears that translated value.

For models implementing `HasTranslationStates`, updating any translation can mark every other locale as outdated, regardless of storage strategy:

```php
resolve(UpdateTranslation::class)->execute(
    translatable: $article,
    attributes: ['title' => 'Updated source title'],
    localeCode: 'ka',
    markOthersAsOutdated: true,
);
```

The updated/source locale is marked current. With `markOthersAsOutdated: false`, other locales retain their existing freshness. Translation freshness is independent of the application’s default locale.

## Deleting translations

```php
use Tipi\Translations\Actions\DeleteTranslation;

resolve(DeleteTranslation::class)->execute(
    translatable: $article,
    localeCode: 'en',
);
```

The default translation cannot be deleted independently.

Hard-deleting a table-backed translatable model deletes its translation records. Soft-deleting the parent keeps them; force-deleting removes them.

`InteractsWithTranslationStates` also preserves translation states on soft delete and deletes them when the parent is permanently deleted, for every storage strategy.

## Transactions

Create, update, and delete actions use a database transaction by default.

Existing translatable models are locked before translation writes to prevent stale model state from overwriting newer translation data. Unsaved JSON models are the exception: because no database row exists yet, their translations are accumulated in memory until the parent model is first persisted.

If you already own the surrounding transaction, you can disable the action's transaction wrapper:

```php
resolve(UpdateTranslation::class)->execute(
    translatable: $article,
    attributes: ['title' => 'Updated title'],
    dbTransaction: false,
);
```

## Development

Run the complete package check:

```bash
composer check
```

Or run the tools separately:

```bash
composer test
composer format:test
```

## License

Laravel Tipi Translations is open-source software licensed under the MIT License.
