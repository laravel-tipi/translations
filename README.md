# Laravel Tipi Translations

Translatable Eloquent attributes for Laravel 13, built on [`laravel-tipi/localization`](https://github.com/laravel-tipi/localization).

```php
#[Translatable(['name', 'description'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations; // or HasJsonTranslations
}

$wine->name;                                   // "საფერავი", or the fallback when Georgian is missing
$wine->getTranslation('name', 'ka');           // exactly what is stored for Georgian, or null
$wine->setTranslation('name', 'ka', 'საფერავი')->save();
Wine::whereTranslation('name', 'like', '%sap%')->orderByTranslation('name')->get();
```

- **Two storages, one API**: JSON columns on the model's table, or a dedicated translation table.
- **Predictable reads**: attribute access falls back; explicit methods return exactly what is stored.
- **Context-aware fallback** using Localization's locale contexts.
- **Atomic, concurrency-safe writes** through Eloquent's own `save()`.
- **Queries** that filter, order and pluck by translated values on SQLite, MySQL, MariaDB and PostgreSQL.
- **No N+1**: eager load once, read any locale with or without fallback for free.

## Contents

- [Installation](#installation)
- [Making a model translatable](#making-a-model-translatable)
- [Reading translations](#reading-translations) — implicit vs. explicit access
- [Missing translations](#missing-translations)
- [Fallback](#fallback)
- [Writing translations](#writing-translations)
- [Querying](#querying)
- [Eager loading and performance](#eager-loading-and-performance)
- [Serialization and APIs](#serialization-and-apis)
- [Validation](#validation)
- [Choosing a storage](#choosing-a-storage)
- [Translation states](#translation-states)
- [Common mistakes and edge cases](#common-mistakes-and-edge-cases)
- [Configuration](#configuration)

## Requirements

- PHP 8.5+, Laravel 13
- `laravel-tipi/localization` ^0.2, configured with your locales
- SQLite, MySQL 8, MariaDB 11 or PostgreSQL 16 (others may work, but are not tested)

## Installation

```bash
composer require laravel-tipi/translations
```

That is all for the storages. Configuration and a migration are needed only for [translation states](#translation-states).

Locales come from Localization: configure them there (`config/localization.php`). Translations does not define locales of its own.

## Making a model translatable

A translatable model has three parts: the `TranslatableModel` interface, a storage trait, and the `#[Translatable]` attribute listing the translatable attributes.

### JSON storage

Each translatable attribute is a JSON column on the model's table.

```php
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\TranslatableModel;

#[Translatable(['name', 'description'])]
class Grape extends Model implements TranslatableModel
{
    use HasJsonTranslations;
}
```

```php
Schema::create('grapes', function (Blueprint $table) {
    $table->id();
    $table->json('name')->nullable();
    $table->json('description')->nullable();
    $table->timestamps();
});
```

A column holds an object keyed by locale: `{"en": "Saperavi", "ka": "საფერავი"}`. Do not add casts for these columns.

### Table storage

Translations live in a separate table with one row per locale.

```php
use Tipi\Translations\Concerns\HasTableTranslations;

#[Translatable(['name', 'description'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations;
}

class WineTranslation extends Model
{
    // A plain Eloquent model. Add casts here if you need them.
}
```

```php
Schema::create('wine_translations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('wine_id')->constrained()->cascadeOnDelete();
    $table->string('locale_code', 12);
    $table->string('name')->nullable();
    $table->text('description')->nullable();
    $table->timestamps();

    $table->unique(['wine_id', 'locale_code']);
});
```

By convention the translation model is the model's class name plus `Translation`, in the same namespace. To use another model or foreign key, define the relation yourself:

```php
public function translations(): HasMany
{
    return $this->hasMany(WineText::class, 'wine_id');
}
```

The translation table needs the foreign key, a `locale_code` column and one **nullable** column per translatable attribute. The unique index on the foreign key and `locale_code` is required: it keeps one row per locale and is the index every read and query uses.

`#[Translatable]` is inherited: a subclass of `Wine` is translatable without repeating it.

## Reading translations

**This is the most important rule of the package:**

| You write | You get | Falls back? |
|---|---|---|
| `$wine->name` | the current locale's value | **yes** |
| `$wine->getTranslation('name', 'ka')` | exactly the value stored for `ka` | no |
| `$wine->getTranslation('name', 'ka', fallback: true)` | the value for `ka`, or its fallback | yes |
| `$wine->resolveTranslation('name', 'ka')` | the value **and where it came from** | yes |
| `$wine->getTranslationOrFail('name', 'ka')` | exactly the stored value, or an exception | no |

Attribute access is for **displaying** content: it shows the best available text. Explicit methods are for **working with** data, such as edit forms, exports, completeness checks and APIs. They never invent a value that is not stored.

```php
// Stored: en = "Saperavi", ka = (missing). Current locale: ka.

$wine->name;                                        // "Saperavi" (fallback to English)
$wine->getTranslation('name', 'ka');                // null
$wine->getTranslation('name', 'en');                // "Saperavi"
$wine->getTranslation('name');                      // null (current locale, exact)
$wine->getTranslation('name', 'ka', fallback: true);// "Saperavi"
$wine->hasTranslation('name', 'ka');                // false
```

### Telling originals from fallbacks

`resolveTranslation()` resolves like attribute access does, and reports what happened:

```php
$resolved = $wine->resolveTranslation('name');   // current locale, with fallback

$resolved->value;            // "Saperavi"
$resolved->requestedLocale;  // "ka"
$resolved->locale;           // "en": where the value came from (null when missing)

$resolved->isTranslated();   // false: not stored in the requested locale
$resolved->isFallback();     // true
$resolved->isMissing();      // false: no locale had a value
```

Use it to show a "not yet translated" badge, add a `lang` attribute to fallback text, or skip fallback content in a sitemap.

### When a translation is required

```php
use Tipi\Translations\Exceptions\MissingTranslationException;

$name = $wine->getTranslationOrFail('name', 'ka');                 // exact
$name = $wine->getTranslationOrFail('name', 'ka', fallback: true); // throws only if nothing resolves
```

`MissingTranslationException` is a domain exception, not an HTTP one. Your application decides what a missing translation means:

```php
// A localized page that should 404 without Georgian content:
abort_unless($wine->hasTranslation('name', 'ka'), 404);
```

### Several values at once

```php
$wine->getTranslations('ka');   // ['name' => 'საფერავი']: one locale, missing attributes left out
$wine->getTranslations();       // the current locale
$wine->getAllTranslations();    // ['en' => ['name' => ..., 'description' => ...], 'ka' => [...]]
$wine->getTranslatedLocales();  // ['en', 'ka']: locales with at least one value, in display order
```

Explicit reads accept a locale code or a `Tipi\Support\Locale` object, and default to the current locale. A locale that does not exist in the registry throws Localization's `LocaleNotFoundException`. Inactive locales can be read: their data is still data.

## Missing translations

One rule applies to both storages: **a translation is missing when its value is `null`**.

| Stored situation | `getTranslation()` | `hasTranslation()` | `$wine->name` |
|---|---|---|---|
| The locale has no translation at all | `null` | `false` | falls back |
| The locale has other attributes, not this one | `null` | `false` | falls back |
| The value was set to `null` | `null` | `false` | falls back |
| An empty string `''` | `''` | `true` | `''`, **no** fallback |
| `'0'`, `0`, `false`, `[]` | the value | `true` | the value, no fallback |
| Found through fallback | `null` without `fallback: true` | `false` | the fallback value |

- `null` is never stored. Setting a value to `null` removes it. For JSON storage the locale key is removed, and for table storage a row with no values left is deleted. "Has no value" and "was set to null" can't be told apart, so the package doesn't pretend they can.
- Falsy values are real translations. An intentionally empty string stays empty and doesn't show the English text instead.
- Laravel's `ConvertEmptyStringsToNull` middleware turns empty form fields into `null`, so a cleared form field removes the translation and enables fallback. That is usually what you want.

## Fallback

When the requested locale has no value, these locales are tried in order:

1. the **current locale context's default**, then
2. the **global default** locale.

Duplicates and the requested locale itself are skipped. Both are always active locales.

Why this order? Localization lets each interface (context) have its own default locale. Suppose your admin panel runs in an `admin` context that defaults to Georgian, while the site defaults to English. An editor working in German sees the Georgian text first, in the language of the panel they are using, and the English text only if Georgian is missing too. The global default comes last because it is the authoring language: Localization requires every context to support it, so it is the most likely to exist.

```php
// config/localization.php: default_locale "en"; contexts: web (all locales), admin (en, ka, de; default "ka")
// Stored: en = "Saperavi", ka = "საფერავი". Current locale: de.

$wine->name; // in the "web" context:   "Saperavi"  (de → en)
$wine->name; // in the "admin" context: "საფერავი" (de → ka → en)
```

The current context is whatever Localization says it is: `UseLocaleContext` middleware (or `Localization::contexts()->setCurrent()`) for panels and APIs, and the default context for localized routes, console commands and jobs.

The current locale is `Localization::current()`: the application locale (`App::getLocale()`) when it is an active locale, otherwise the current context's default. Reads follow `App::setLocale()` immediately, with no per-model caching to go stale.

### Custom fallback

Bind your own `FallbackPolicy` to change the chain everywhere: attribute access, `fallback: true` reads and `fallback: true` queries.

```php
use Tipi\Translations\Contracts\FallbackPolicy;

final class RegionalFallbackPolicy implements FallbackPolicy
{
    public function fallbacksFor(string $locale): array
    {
        return match ($locale) {
            'uk' => ['ru', 'en'],
            default => $locale === 'en' ? [] : ['en'],
        };
    }
}

// AppServiceProvider::register()
$this->app->scoped(FallbackPolicy::class, RegionalFallbackPolicy::class);
```

Return `[]` to disable fallback entirely. Laravel's `app.fallback_locale` is not used: Localization owns locales.

## Writing translations

Writes are kept on the model and stored by `save()`, like any other attribute change.

```php
$wine = new Wine(['slug' => 'saperavi']);

$wine->setTranslation('name', 'en', 'Saperavi')
    ->setTranslations('ka', ['name' => 'საფერავი', 'description' => 'მშრალი წითელი'])
    ->save();
```

| Method | Effect |
|---|---|
| `$wine->name = 'Saperavi'` | sets the **current** locale |
| `Wine::create([...])`, `$wine->update([...])`, `fill()` | same: translatable keys write the current locale |
| `setTranslation($attribute, $locale, $value)` | sets one value (`null` removes it) |
| `setTranslations($locale, [$attribute => $value])` | sets several values of one locale |
| `fillTranslations([$locale => [$attribute => $value]])` | sets values of several locales; others are untouched |
| `forgetTranslation($attribute, $locale)` | removes one value |
| `forgetTranslations($locale)` | removes every value of a locale |
| `isDirty('name')`, `isClean()`, `wasChanged('name')` | Eloquent's change tracking, aware of translated attributes |
| `getDirtyTranslations()` | the unsaved changes: `[$locale => [$attribute => $value]]` |

```php
// Create a model with translations in several locales:
$wine = (new Wine(['slug' => 'saperavi']))->fillTranslations($request->validated('translations'));
$wine->save();
```

Before saving, reads already see the changes. `refresh()` discards them.

Mass assignment (`create()`, `update()`, `fill()`) follows `$fillable` / `$guarded` as for any attribute, so list translatable attributes in `$fillable` when you use it. `setTranslation()` and `fillTranslations()` are explicit and not subject to mass-assignment rules.

Writes validate eagerly: an attribute that is not translatable throws `AttributeNotTranslatableException`, and a locale that does not exist throws `LocaleNotFoundException`. Any existing locale can be written, **including inactive ones**, so content can be prepared before a locale goes live. Locale contexts don't restrict writes. Contexts narrow what an interface offers, so restrict editors in your forms with the [validation rule](#validation).

### Atomicity

Saving a model with translation changes writes the model and all its translations **in one database transaction**. If any part fails (a translation row, a listener, a state update), nothing is written. A surrounding `DB::transaction()` is joined as usual. A `saving` listener that returns `false` stops the whole save, and the changes stay pending.

`saveQuietly()`, `saveOrFail()`, `push()`, `update()`, `updateQuietly()`, `create()`, `firstOrCreate()` / `updateOrCreate()` (translatable keys in the *values* argument) and model factories all store translations, because they all go through `save()`.

### Change tracking and model events

Unsaved translations make the model dirty, like any changed attribute:

```php
$wine->setTranslation('name', 'ka', 'საფერავი');

$wine->isDirty();               // true
$wine->isDirty('name');         // true
$wine->getDirtyTranslations();  // ['ka' => ['name' => 'საფერავი']]

$wine->save();

$wine->wasChanged('name');      // true (after an update; inserts don't count, as for columns)
```

So saving a translation-only change is an ordinary Eloquent update for both storages: `updating`, `updated`, `saving` and `saved` fire, `updated_at` changes, and `$touches` owners are touched. `discardChanges()` and `refresh()` discard unsaved translations.

`getDirty()`, `getChanges()` and `getOriginal()` stay **column-level**: they describe what Eloquent writes to the model's own table (raw JSON for JSON storage, nothing for table storage). Use `getDirtyTranslations()` and `getTranslation()` for translation values.

During `saving`, `updating`, `updated` and `saved` listeners, the translations being saved are still unsaved (`getDirtyTranslations()` lists them, and reads already show them). Table rows are written after the model row, before `save()` returns. Translations a listener changes during the save stay pending for the next `save()`: they are neither written by this save nor lost.

### Concurrent edits

Two editors working on the same model at the same time don't overwrite each other's translations:

- **Table storage** writes only the changed columns of the changed locale rows. If another process inserted the same locale's row in the meantime, that row is updated instead.
- **JSON storage** locks the row, re-reads the JSON columns and applies only the locales that changed, so a Georgian edit and a German edit saved at the same time both survive.

Two people changing **the same value** is last-write-wins, as with any Eloquent attribute.

### Other Eloquent behavior

- `replicate()` copies translations, with both storages. Attributes in `$except` are left out.
- Deleting a model deletes its translation rows. Soft deletes keep them, and `forceDelete()` removes them. With a `cascadeOnDelete()` foreign key, the database does it as well.

## Querying

```php
// Current locale, exact value:
Wine::whereTranslation('name', '=', 'საფერავი')->get();

// Explicit locale, any supported operator (=, !=, <>, <, >, <=, >=, like, not like, ilike, not ilike):
Wine::whereTranslation('name', 'like', '%sap%', 'en')->get();

// What visitors see: the current locale with fallback:
Wine::whereTranslation('name', 'like', '%sap%', fallback: true)->get();

Wine::where('active', true)->orWhereTranslation('name', '=', 'Saperavi', 'en')->get();

// Presence:
Wine::whereHasTranslation('name', 'ka')->get();          // has a Georgian name
Wine::whereDoesntHaveTranslation('name', 'ka')->get();   // a translator's to-do list

// Ordering: missing values sort last, on every database:
Wine::orderByTranslation('name')->get();
Wine::orderByTranslation('name', 'desc', fallback: true)->get();

// Values without hydrating models, for select options:
Wine::pluckTranslation('name');                        // [id => name] in the current locale
Wine::active()->pluckTranslation('name', 'slug', fallback: true);
```

The same rule as reading applies. Query scopes use the exact locale (the current one by default) unless you pass `fallback: true`, and then they use the same fallback chain as attribute access. Values are always bound, never interpolated. Operators and directions are checked against a fixed list.

**Both storages give the same answers on the same database.** Case sensitivity and sort order follow the database:

| Database | `=` | `like` | Case-insensitive search |
|---|---|---|---|
| MySQL, MariaDB | case-insensitive with the default `utf8mb4_*_ci` collation | case-insensitive | `like` |
| PostgreSQL | case-sensitive | case-sensitive | `ilike` |
| SQLite | case-sensitive | case-insensitive for ASCII | `like` |

On MySQL and MariaDB, JSON values are compared with the connection's collation, as table columns created on that connection are, so both storages agree. In queries, fallback works like reads: an empty string is a value and doesn't fall back.

JSON storage compares values as text on MySQL, MariaDB and PostgreSQL (SQLite compares JSON numbers as numbers). Use table storage with numeric columns for numeric ranges and numeric sorting.

Query builder writes bypass the model: `Wine::query()->update(['name' => ...])`, `insert()`, `upsert()` and lookup attributes of `firstOrCreate(['name' => ...])` refer to columns, which table storage doesn't have and JSON storage stores as whole objects. Use models, or `whereTranslation()` to find them.

## Eager loading and performance

**JSON storage** never queries: translations arrive with the model.

**Table storage** reads from the `translations` relation. Eager load it when reading many models:

```php
$wines = Wine::with('translations')->get();   // 2 queries, however many wines
$wines = Wine::withTranslations()->get();     // the same; a no-op for JSON storage
```

Once loaded, every locale is in memory. Reading any locale, with or without fallback, and serializing cost no further queries. Without eager loading, each model loads its rows on first read (N+1). With `Model::preventLazyLoading()`, Laravel reports it as usual. `Model::automaticallyEagerLoadRelationships()` works too.

Writes stay small:

| | New model | Changing an existing model |
|---|---|---|
| JSON | 1 insert | 1 locked read + 1 update |
| Table | 1 insert + 1 per locale | 1 write per changed locale + 1 model update (`updated_at`) |

Unchanged values aren't written. Setting a value back to what is stored is no change at all.

**Don't constrain the eager-loaded relation** (`with(['translations' => fn ($q) => $q->where('locale_code', 'ka')])`). Translations are read from it, so locales left out would read as missing.

## Serialization and APIs

`toArray()` and `toJson()` serialize translatable attributes exactly like `$wine->name`: the current locale, with fallback. `$hidden`, `$visible` and `makeHidden()` apply as usual. Both storages produce the same output: the raw JSON columns and the `translations` rows are not included (`makeVisible('translations')` if you do want the rows).

For an API that returns every translation, use `getAllTranslations()`. It has the same shape `fillTranslations()` accepts, so a payload round-trips:

```php
return [
    'id' => $wine->id,
    'name' => $wine->name,
    'translations' => $wine->getAllTranslations(),
];
```

Models with unsaved translations survive PHP serialization, for example in queued closures.

## Validation

`Tipi\Translations\Rules\Translations` validates the shape of a translations payload: locales must exist (or belong to a context), and attributes must be translatable. Validate the values with ordinary wildcard rules:

```php
use Tipi\Localization\Facades\Localization;
use Tipi\Translations\Rules\Translations;

$request->validate([
    'slug' => ['required', 'string'],
    'translations' => ['required', new Translations(Wine::class, context: 'admin')],
    'translations.*.name' => ['nullable', 'string', 'max:255'],
    'translations.*.description' => ['nullable', 'string'],
    'translations.'.Localization::locales()->defaultCode().'.name' => ['required'],
]);

$wine->fillTranslations($request->validated('translations'))->save();
```

Authorization is your application's: use policies as for any model. The package doesn't decide who may edit which locale.

## Choosing a storage

| | JSON | Table |
|---|---|---|
| Schema | one JSON column per attribute | a translation table and model |
| Reads | no queries | one query per model, or one per batch when eager loaded |
| Value types | JSON values (string, number, bool, array) | the column type, with the translation model's casts |
| Adding a locale | nothing to migrate | nothing to migrate |
| Indexing translated values | hard (generated columns) | regular indexes on columns |
| Query performance on large tables | JSON extraction per row | indexed subqueries |
| Best for | small models, lookups, settings, taxonomies | content: products, articles, anything searched or sorted |

Both have the same API, so you can change a model's storage later without changing the code that uses it (you migrate the data yourself).

## Translation states

Optional editorial metadata per translated locale: whether it is **outdated**, and an optional **status** from your own enum.

```bash
php artisan vendor:publish --tag=translations-config
php artisan vendor:publish --tag=translations-migrations
php artisan migrate
```

```php
use Tipi\Translations\Concerns\HasTranslationStates;

#[Translatable(['name', 'description'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations;
    use HasTranslationStates;
}
```

```php
// After editing the English source text:
$wine->setTranslation('description', 'en', $new)->save();
$wine->markOtherTranslationsOutdated('en');

$wine->isTranslationOutdated('ka');            // true
$wine->getTranslationState('ka')->outdated_at; // since when

$wine->setTranslationStatus('ka', TranslationStatus::Reviewed); // config: translations.states.status_enum
$wine->markTranslationOutdated('ka');
$wine->markTranslationCurrent('ka');
```

States are kept in sync on `save()`. A locale whose translations changed is marked current, because someone just edited it. A locale that lost all its values loses its state. An already outdated locale keeps the time it became outdated. States are deleted with the model, and kept on soft delete.

## Common mistakes and edge cases

- **Filling edit forms with `$wine->name`.** It returns the fallback text when the locale is missing, and saving the form would store English text as the Georgian translation. Fill forms with `getTranslation($attribute, $locale)` or `getTranslations($locale)`.
- **Assigning in the wrong locale.** `$wine->name = ...` and `Wine::create(['name' => ...])` write the *current* locale, which in an admin panel is the editor's interface language. Use `setTranslation()` / `fillTranslations()` when the target locale isn't the current one.
- **`null` vs `''`.** `''` is a translation and blocks fallback. Clear a value with `null` (empty form fields already arrive as `null`).
- **Constraining eager loads** of `translations` makes the left-out locales read as missing.
- **Overriding `save()`** on a translatable model: call `parent::save($options)`, and note that a `save()` defined directly on the model class replaces the package's transactional `save()`. Prefer model events.
- **Casting JSON translation columns** (`'name' => 'array'`) is unnecessary and should be removed: the trait reads and writes those columns itself.
- **Locales removed from the registry.** Their stored data can't be read or written by locale (`LocaleNotFoundException`) but still appears in `getAllTranslations()`, so you can migrate or clean it up.
- **Stale models.** A table-storage model keeps the rows it loaded, like any Eloquent relation. A JSON-storage model also picks up other processes' locales when it saves, because it merges onto a fresh read.
- **UUID or ULID keys** with translation states: change `translatable_id` in the published migration to match your key type.

## Configuration

`config/translations.php` (publish with `--tag=translations-config`) only configures translation states:

```php
'states' => [
    'table' => 'translation_states',
    'model' => \Tipi\Translations\Models\TranslationState::class,
    'status_enum' => null, // a backed enum class, e.g. App\Enums\TranslationStatus::class
],
```

Everything else is declared on the model.

## Development

```bash
composer check            # Pint + the test suite (SQLite)
```

Run the suite against a real server with `TRANSLATIONS_TEST_DB` (`mysql`, `mariadb` or `pgsql`) plus `TRANSLATIONS_TEST_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME` and `_PASSWORD`. CI runs all four databases.

## License

MIT
