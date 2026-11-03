# Laravel Tipi Translations

Translatable Eloquent attributes for Laravel 13, built on [`laravel-tipi/localization`](https://github.com/laravel-tipi/localization).

```php
#[Translatable(['name', 'description'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations; // or HasJsonTranslations, or HasSharedTableTranslations
}

$wine->name;                                   // "საფერავი", or the fallback when Georgian is missing
$wine->getTranslation('name', 'ka');           // exactly what is stored for Georgian, or null
$wine->setTranslation('name', 'ka', 'საფერავი')->save();
Wine::whereTranslation('name', 'like', '%sap%')->orderByTranslation('name')->get();
```

- **Three storages, one API**: JSON columns on the model's table, a dedicated translation table per model, or one shared polymorphic table for every model.
- **Predictable reads**: attribute access falls back; explicit methods return exactly what is stored.
- **Context-aware fallback** using Localization's locale contexts.
- **Atomic, concurrency-safe writes** inside Eloquent's own save lifecycle, with model events and change tracking that behave like columns.
- **Queries** that filter, order and pluck by translated values on SQLite, MySQL, MariaDB and PostgreSQL.
- **No N+1**: eager load once, read any locale with or without fallback for free.

## Contents

- [Installation](#installation)
- [Making a model translatable](#making-a-model-translatable): JSON, table and shared storage
- [Reading translations](#reading-translations) — implicit vs. explicit access
- [Missing translations](#missing-translations)
- [Fallback](#fallback)
- [Writing translations](#writing-translations)
- [Deleting models](#deleting-models)
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
- SQLite, MySQL, MariaDB or PostgreSQL. The suite runs on SQLite, MySQL 8.4, MariaDB 11 and PostgreSQL 16. Querying JSON and shared storage needs one of these four; other databases throw a `LogicException` instead of guessing.

## Installation

```bash
composer require laravel-tipi/translations
```

That is all for JSON and table storage. [Shared storage](#shared-storage) and [translation states](#translation-states) use the package's tables:

```bash
php artisan vendor:publish --tag=translations-config       # optional, see Configuration
php artisan vendor:publish --tag=translations-migrations   # create_translations_table, create_translation_states_table
php artisan migrate
```

Delete the migration you don't need before migrating. Both create polymorphic tables whose `translatable_id` column follows [`morph_key_type`](#model-keys-and-morph-types): set it first if your models use UUID or ULID keys.

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
    $table->string('locale_code', 16);
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

The translation table needs the foreign key, a `locale_code` column (16 characters fit every locale code Localization accepts) and one **nullable** column per translatable attribute. The unique index on the foreign key and `locale_code` is required: rows are written with upserts against it, it keeps one row per locale, and every read and query uses it. A foreign key constraint with `cascadeOnDelete()` is recommended: the package deletes rows with the model, and the constraint also covers deletes that bypass Eloquent.

Rows are written with upsert queries, so the translation model's casts and mutators apply, but its model events and `$touches` don't run.

### Shared storage

Every model's translations live in one polymorphic table, one row per model, locale and attribute. No translation table or model per model, and no migration when you make another model translatable or add an attribute.

```php
use Tipi\Translations\Concerns\HasSharedTableTranslations;

#[Translatable(['name', 'description'])]
class Region extends Model implements TranslatableModel
{
    use HasSharedTableTranslations;
}
```

Publish and run the `create_translations_table` migration (see [Installation](#installation)). The table has `translatable_type`, `translatable_id`, `locale_code`, `attribute`, a JSON `value` and timestamps, with a unique index on the first four.

- Values are JSON, as in JSON storage: strings, numbers, booleans and arrays keep their type, and queries compare them the same way. There are no per-attribute casts or column types.
- The rows are the `translations` relation (`MorphMany` of `Tipi\Translations\Models\Translation`). Eager load it like table storage's.
- `translatable_type` is the model's [morph class](https://laravel.com/docs/eloquent-relationships#custom-polymorphic-types): the class name, or its alias in the morph map. Use a morph map (`Relation::enforceMorphMap()`) so renaming a class doesn't strand its rows. A subclass is its own morph class unless mapped, so its rows are separate from its parent's.
- The `Translation` model is for reading across models (reports, exports, "everything missing in Georgian"). Write through the translatable models.

#### Model keys and morph types

`translatable_id` has one type per table, set by `morph_key_type` in `config/translations.php` before migrating. It applies to the shared table and the translation states table.

| `morph_key_type` | Column | Models |
|---|---|---|
| `null` (default) | follows Laravel's default morph key type (`Schema::morphUsingUuids()` / `morphUsingUlids()`), else `int` | |
| `'int'` | unsigned big integer | integer keys |
| `'uuid'` | `uuid` (native on PostgreSQL and MariaDB) | UUID keys (`HasUuids`) |
| `'ulid'` | `char(26)` | ULID keys (`HasUlids`) |
| `'string'` | `varchar(64)` | **any mix** of integer, UUID, ULID and string keys |

Use `'string'` when your translatable models mix key types. Keys are then stored and compared as strings: the package's relation casts the model's key in its subqueries, so PostgreSQL's strict typing and MySQL's indexes both work, including in `has()`, `whereHas()` and `withCount()`. A model whose key type or value doesn't fit the column throws `InvalidTranslatableModelException` before any query, because databases would otherwise reject it, find nothing or store a wrong value depending on the engine.

`morph_key_type` must match the columns: change it only together with them.

#### Integrity without a foreign key

A polymorphic table can't have a foreign key. The package makes up for it where it can:

- Deleting a model deletes its rows (and translation states) in the same transaction, also with events disabled (`deleteQuietly()`). Soft deletes keep them; `forceDelete()` removes them.
- Writing translations locks the model's row first. A model deleted by another process while you edit it can't get rows: `save()` throws `ModelNotFoundException`, as a foreign key would fail the insert, and your changes stay pending.

Rows are still left behind when a model is deleted **without Eloquent**: `Region::query()->delete()`, raw SQL, `DB::table(...)`, or a database cascade from another table. Remove them with:

```bash
php artisan translations:prune-orphans --pretend   # count them
php artisan translations:prune-orphans             # delete them
```

It checks every type in the shared and states tables against its model's table, and skips (and reports) types without a model class, so it never deletes data it can't verify. Schedule it if your application deletes models in bulk.

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

One rule applies to every storage: **a translation is missing when its value is `null`**.

| Stored situation | `getTranslation()` | `hasTranslation()` | `$wine->name` |
|---|---|---|---|
| The locale has no translation at all | `null` | `false` | falls back |
| The locale has other attributes, not this one | `null` | `false` | falls back |
| The value was set to `null` | `null` | `false` | falls back |
| An empty string `''` | `''` | `true` | `''`, **no** fallback |
| `'0'`, `0`, `false`, `[]` | the value | `true` | the value, no fallback |
| Found through fallback | `null` without `fallback: true` | `false` | the fallback value |

- `null` is never stored. Setting a value to `null` removes it. For JSON storage the locale key is removed, for table storage a row with no values left is deleted, and for shared storage the value's row is deleted. "Has no value" and "was set to null" can't be told apart, so the package doesn't pretend they can.
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

`save()` writes the model and its translations **in one database transaction**. If anything fails (a translation write, a listener, a state update, the commit), nothing is written, and the model's translations are as they were before `save()`: every translation change is still pending, so you can fix the cause and call `save()` again. Column attributes behave as Eloquent's always do. A surrounding `DB::transaction()` is joined as usual. If *that* transaction later rolls back, the model doesn't know, exactly as with any Eloquent attribute.

A `saving`, `creating` or `updating` listener that returns `false` stops the save, and the changes stay pending.

Translations are written as part of Eloquent's own save, not by a wrapper around it. So `save()`, `saveQuietly()`, `saveOrFail()`, `saveOrIgnore()`, `push()`, `update()`, `updateQuietly()`, `create()`, `firstOrCreate()` / `updateOrCreate()` (translatable keys in the *values* argument), `touch()` and model factories all store translations, with or without events.

### Change tracking and model events

Translated attributes take part in Eloquent's change tracking like columns, with and without timestamps:

```php
$wine->setTranslation('name', 'ka', 'საფერავი');

$wine->isDirty();               // true
$wine->isDirty('name');         // true
$wine->getDirtyTranslations();  // ['ka' => ['name' => 'საფერავი']]

$wine->save();

$wine->isClean();               // true
$wine->wasChanged('name');      // true (after an update; inserts don't count, as for columns)
```

A translation-only change is an ordinary Eloquent update in every storage: `saving`, `updating`, `updated` and `saved` fire once each, `updated_at` changes (when the model has timestamps), and `$touches` owners are touched. `discardChanges()` and `refresh()` discard unsaved translations.

`getDirty()`, `getChanges()` and `getOriginal()` stay **column-level**: they describe what Eloquent writes to the model's own table (raw JSON for JSON storage, nothing for table and shared storage). Use `getDirtyTranslations()` and `wasChanged()` for translations.

**Listeners.** The save runs in this order:

| Step | Translations |
|---|---|
| `saving`, then `creating` / `updating` listeners | changes they make are **part of this save** |
| the model row is written, then the translations | |
| `created` / `updated`, then `saved` listeners | already stored; `isDirty('name')`, `wasChanged('name')` and `getDirtyTranslations()` report them, as for columns |
| after `save()` | the model is clean |

Translations a `created`, `updated` or `saved` listener changes **stay pending** for the next `save()`. They are neither written behind your back nor lost, and the model stays dirty until they are saved. To store them in the same request, save from the listener, typically with `saveQuietly()` to avoid re-running your listeners. That nested save writes only the listener's changes.

### Concurrent edits

Concurrent saves of the same model, from separate processes, behave like this on MySQL, MariaDB and PostgreSQL:

| Two writers change… | Result |
|---|---|
| different locales | both kept |
| different attributes of one locale | both kept |
| a locale that didn't exist yet | one row (table storage) with both values |
| the same value | the last commit wins |
| one empties a locale, the other adds to it | the added value is kept |
| one deletes the model, the other saves translations | no rows are left; the save fails with `ModelNotFoundException` if the model was gone first |

- **JSON storage** locks the model row, re-reads the JSON columns and applies only the changed values, so concurrent saves are serialized on that row.
- **Table storage** locks the model row, then writes each changed locale with one atomic upsert of the changed columns, and deletes an emptied row only if it is still empty when deleted.
- **Shared storage** locks the model row, then writes every changed value with one atomic upsert (each value is its own row) and deletes removed values. It never reads rows with locks, so writers of *different* models sharing the table don't block each other.

Each storage locks the model's row before writing rows, and `delete()` locks it before deleting them, so a model is never deleted between the check and the write. These rules are tested with concurrent processes on MySQL, MariaDB and PostgreSQL, including many writers on different models of one shared table. The package doesn't add optimistic locking: the same value saved by two editors is last-write-wins, as for any Eloquent attribute. Changes are computed from the model as you loaded it, so setting a value to what *your copy* already holds is no change, even if another process changed it meanwhile.

SQLite allows one writer at a time, so its writes never interleave.

### Deadlocks and retries

**The package never retries.** `save()` and `delete()` run their transaction once, so their model events, and whatever your listeners do, run once per call. A deadlock or serialization failure reaches your code like any other database error:

- The transaction is rolled back and the translation changes stay pending, so calling `save()` again saves them (and runs the events again, because it is a new save).
- Inside your own `DB::transaction()`, Laravel throws `DeadlockException`. The database has already rolled back the whole transaction, including models saved earlier in it, which still look saved: as with any Eloquent attribute, the model doesn't know.

Saves of one model don't deadlock each other: they queue on its row. Transactions that save **several** models in different orders can deadlock, as any such transactions can. To retry them, retry the whole transaction, which is your decision:

```php
DB::transaction(function () use ($ids, $names, $editors) {
    // Load inside the callback: every attempt starts from the database.
    foreach (Wine::findMany($ids) as $wine) {
        $wine->setTranslation('name', 'ka', $names[$wine->id])->save();
    }

    // Side effects that must happen once: after the commit.
    DB::afterCommit(fn () => Notification::send($editors, new WinesTranslated($ids)));
}, attempts: 3);
```

On every attempt the callback runs again, with all its model events and listeners. Database work is rolled back with the failed attempt, but **side effects outside the database are not**: an email sent or an API called from a `saved` listener happens once per attempt. Run them after the commit: `DB::afterCommit()`, observers with `$afterCommit = true`, events implementing `ShouldDispatchAfterCommit`, or queued jobs dispatched after commit (`afterCommit()`, or `after_commit` in the queue configuration). Those run once, for the attempt that commits. Models loaded outside the callback and saved in an attempt that failed look saved but aren't, so load or re-apply changes inside the callback.

### Other Eloquent behavior

- `replicate()` copies translations, in every storage. Attributes in `$except` are left out.
- A model may define its own `save()`. Translations are still written, but a `save()` declared on the model class replaces the package's, which adds the transaction and the recovery after a failure. Wrap your logic in a `DB::transaction()` if you need both.
- The model and its translation rows must use one database connection, so they share a transaction and lock. Rows follow the model's connection (`Model::on('tenant')`) unless the translation model sets its own, which throws `InvalidTranslatableModelException` on write. Run the package's migrations on every connection that uses them.

## Deleting models

`delete()` deletes the model and the rows that hold its translations (table and shared storage, translation states) **in one transaction**:

- the rows go only once `deleting` listeners allowed the delete, and if anything fails later (the model's row, a `deleted` listener, a surrounding transaction), they are restored with it;
- it works with events disabled (`deleteQuietly()`, `forceDeleteQuietly()`);
- soft deletes keep everything, `restore()` brings the model back with its translations, `forceDelete()` removes them;
- JSON storage needs nothing: the translations are the model's row.

Deletes that bypass Eloquent (query builder, raw SQL) don't touch translation rows. Table storage's foreign key with `cascadeOnDelete()` covers them; for shared storage and states, run [`translations:prune-orphans`](#integrity-without-a-foreign-key).

## Querying

```php
// Current locale, exact value:
Wine::whereTranslation('name', '=', 'საფერავი')->get();

// Explicit locale, any supported operator (=, !=, <>, <, >, <=, >=, like, not like; ilike and not ilike on PostgreSQL):
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

The same rule as reading applies. Query scopes use the exact locale (the current one by default) unless you pass `fallback: true`, and then they use the same fallback chain as attribute access. Values are always bound, never interpolated. Operators and directions are checked against a fixed list; `ilike` on another database throws `InvalidArgumentException` instead of failing in SQL.

**Every storage gives the same answers on the same database.** Case sensitivity and sort order follow the database:

| Database | `=` | `like` | Case-insensitive search |
|---|---|---|---|
| MySQL, MariaDB | case-insensitive with the default `utf8mb4_*_ci` collation | case-insensitive | `like` |
| PostgreSQL | case-sensitive | case-sensitive | `ilike` |
| SQLite | case-sensitive | case-insensitive for ASCII | `like` |

On MySQL and MariaDB, JSON values are compared with the connection's collation, as table columns created on that connection are, so the storages agree. In queries, fallback works like reads: an empty string is a value and doesn't fall back.

**Numbers compare as numbers.** Table storage compares like its columns (an integer column compares numerically). JSON and shared storage compare by the stored JSON type: a JSON number compared with a number (`10`, `9.5` or a numeric string like `'10'`) compares numerically, and every other value compares as text. `orderByTranslation()` sorts JSON numbers numerically, before text values. So the storages agree for numeric data on every database:

```php
Product::whereTranslation('position', '>', 9)->get();      // 10 and 100, not "9" < "10"
Product::orderByTranslation('position')->get();            // 9, 10, 100
```

Operands must be strings, finite numbers, backed enums (compared by value) or `Stringable` objects, and `like` needs a string. Anything else, such as a boolean or an array, throws `InvalidArgumentException` instead of being compared in some database-specific way. PostgreSQL also rejects a fractional number for an integer column of table storage, as it does with `where()`.

`pluckTranslation()` returns the values a model read would return: cast by the translation model (table storage), or decoded JSON (JSON and shared storage).

MySQL and PostgreSQL normalize JSON objects, so an array value's keys may come back in another order (JSON and shared storage). Compare such values by content, not order.

**Translatable attributes aren't columns.** A `where()`, `whereIn()`, `whereNull()`, `whereColumn()` or `orderBy()` on a translatable attribute throws `TranslatableColumnQueryException`. JSON storage would compare the whole JSON object, and table storage has no such column, so these queries would fail or silently match nothing. The common trap is `Wine::firstOrCreate(['name' => 'Saperavi'])`, which would create a duplicate on every call. Look models up by a column, and put translatable attributes in the *values* argument. JSON paths such as `where('name->en', ...)` are left alone, and `withoutGlobalScope(TranslatableColumnGuard::class)` skips the check.

Query builder writes (`Wine::query()->update()`, `insert()`, `upsert()`) bypass the model, as in Laravel. They write raw columns, which table and shared storage don't have and JSON storage stores as whole objects. The package doesn't offer bulk translation writes; save models.

## Eager loading and performance

**JSON storage** never queries: translations arrive with the model.

**Table and shared storage** read from the `translations` relation. Eager load it when reading many models:

```php
$wines = Wine::with('translations')->get();   // 2 queries, however many wines
$wines = Wine::withTranslations()->get();     // the same; a no-op for JSON storage
```

Once loaded, every locale is in memory. Reading any locale, with or without fallback, and serializing cost no further queries. Without eager loading, each model loads its rows on first read (N+1). With `Model::preventLazyLoading()`, Laravel reports it as usual. `Model::automaticallyEagerLoadRelationships()` works too.

Writes stay small:

| | New model | Changing an existing model |
|---|---|---|
| JSON | 1 insert | 1 locked read + 1 update |
| Table | 1 insert + 1 upsert | 1 model update (`updated_at`) + 1 row lock + 1 upsert |
| Shared | 1 insert + 1 upsert | 1 model update (`updated_at`) + 1 row lock + 1 upsert |

A table-storage save writes one upsert for each set of changed attributes, so usually one for all changed locales. Removing every value of a locale adds one update and one delete. A shared-storage save writes one upsert for all set values and one delete for all removed values. Deleting a model costs one row lock and one delete per translation table. If the `translations` relation was loaded, it is reloaded after the write (one query), so the model shows exactly what is stored.

Unchanged values aren't written. Setting a value back to what is stored is no change at all. On MySQL and MariaDB an upsert can use up auto-increment values without inserting a row, so keep the translation table's default 64-bit `id()`.

**Don't constrain the eager-loaded relation** (`with(['translations' => fn ($q) => $q->where('locale_code', 'ka')])`). Translations are read from it, so locales left out would read as missing.

## Serialization and APIs

`toArray()` and `toJson()` serialize translatable attributes exactly like `$wine->name`: the current locale, with fallback. `$hidden`, `$visible` and `makeHidden()` apply as usual. Every storage produces the same output: the raw JSON columns and the `translations` rows are not included (`makeVisible('translations')` if you do want the rows).

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

| | JSON | Table | Shared |
|---|---|---|---|
| Schema | one JSON column per attribute | a translation table and model per translatable model | one table for every model, migrated once |
| Adding a translatable model or attribute | a column | a table / a column | nothing |
| Reads | no queries | one query per model, or one per batch when eager loaded | same as table |
| Value types | JSON values | the column types, with the translation model's casts | JSON values |
| Database constraints | none per locale | foreign key with cascade, `NOT NULL`, lengths, column types | unique key only; deletes handled by the package |
| Filtering and sorting large sets | JSON extraction per row | indexed subquery per row; columns can be indexed for your own queries | indexed subquery per row and expression |
| Concurrency | writers of one model serialize on its row | per-locale upserts | per-value upserts |
| Cross-model tooling (progress, exports, "missing in Georgian") | per table | per table | one query on one table |
| Best for | small models, settings, taxonomies; fastest reads | content that is searched, sorted or constrained: products, articles | many translatable models, packages and CMS-like apps, translation workflows |

**Measured** with the package's benchmark (`vendor/bin/pest tests/Benchmarks`), 20,000 models per storage, two attributes in three locales, and 30,000 other models' rows in the shared table. Median milliseconds:

| | JSON | Table | Shared |
|---|---|---|---|
| Eager load 100 models, read every locale (MySQL / PostgreSQL) | 3 / 1 | 5 / 5 | 8 / 8 |
| `whereTranslation('name', '=', …)` over all models | 6 / 9 | 21 / 14 | 46 / 39 |
| `orderByTranslation()`, first 20 | 23 / 39 | 43 / 28 | 176 / 220 |
| Save one value of an existing model | 1.5 / 1.4 | 2.2 / 2.2 | 2.7 / 2.3 |

Reads of loaded models and writes cost about the same everywhere. Filtering and sorting by translated values scan the models and grow linearly with them in every storage. Shared storage pays most there, because each row needs index lookups into a large table, so sorting thousands of models by a translated value is noticeably slower. Rows of other models in the shared table don't slow these down: lookups use the unique index.

All three have the same API, so you can change a model's storage later without changing the code that uses it (you migrate the data yourself). Models with different storages work side by side.

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

The states table is polymorphic, like shared storage: its `translatable_id` follows [`morph_key_type`](#model-keys-and-morph-types), and [`translations:prune-orphans`](#integrity-without-a-foreign-key) cleans up after deletes that bypass Eloquent.

## Common mistakes and edge cases

- **Filling edit forms with `$wine->name`.** It returns the fallback text when the locale is missing, and saving the form would store English text as the Georgian translation. Fill forms with `getTranslation($attribute, $locale)` or `getTranslations($locale)`.
- **Assigning in the wrong locale.** `$wine->name = ...` and `Wine::create(['name' => ...])` write the *current* locale, which in an admin panel is the editor's interface language. Use `setTranslation()` / `fillTranslations()` when the target locale isn't the current one.
- **`null` vs `''`.** `''` is a translation and blocks fallback. Clear a value with `null` (empty form fields already arrive as `null`).
- **Constraining eager loads** of `translations` makes the left-out locales read as missing.
- **Overriding `save()`** on a translatable model: call `parent::save($options)`. Translations are written either way, but a `save()` declared on the model class replaces the package's transaction and failure recovery. Prefer model events.
- **Looking up by a translatable attribute** with `where()` or `firstOrCreate()` throws: use `whereTranslation()`, and pass translations in `firstOrCreate()`'s second argument.
- **Casting JSON translation columns** (`'name' => 'array'`) is unnecessary and should be removed: the trait reads and writes those columns itself.
- **Locales removed from the registry.** Their stored data can't be read or written by locale (`LocaleNotFoundException`) but still appears in `getAllTranslations()`, so you can migrate or clean it up.
- **Stale models.** A model shows what it loaded, like any Eloquent model, until it is saved or refreshed. Saving never overwrites other processes' locales or attributes. After a save, a JSON-storage model shows the merged columns, and a table-storage model with loaded `translations` reloads them.
- **UUID or ULID keys** with shared storage or translation states: set [`morph_key_type`](#model-keys-and-morph-types) before running the migrations. Use `'string'` if your models mix key types.
- **Renaming a model class** with shared storage or states strands its rows unless the type is in the morph map. Map translatable models from the start.
- **Bulk deletes** (`Model::query()->delete()`) skip the package: run `translations:prune-orphans` for shared storage and states.

## Configuration

`config/translations.php` (publish with `--tag=translations-config`) configures the package's own tables:

```php
'shared' => [
    'table' => 'translations',
    'model' => \Tipi\Translations\Models\Translation::class, // or a subclass
],

'morph_key_type' => null, // 'int', 'uuid', 'ulid' or 'string'; null follows Laravel's default

'states' => [
    'table' => 'translation_states',
    'model' => \Tipi\Translations\Models\TranslationState::class,
    'status_enum' => null, // a backed enum class, e.g. App\Enums\TranslationStatus::class
],
```

The migrations read the table names and key type when they run. Everything else is declared on the model.

## Development

The suite needs the `pcntl`, `posix` and `pdo_sqlite` extensions, plus `pdo_mysql` and `pdo_pgsql` for those databases.

```bash
composer check            # Pint + the suite on in-memory SQLite: the everyday check

docker compose up -d --wait
composer check:all        # Pint + the suite on SQLite, MySQL, MariaDB and PostgreSQL
composer test:pgsql       # one database: test:mysql, test:mariadb, test:pgsql
docker compose down
```

`compose.yaml` starts MySQL 8.4, MariaDB 11 and PostgreSQL 16 on ports 33061 to 33063. To use other servers, set `TRANSLATIONS_TEST_DB` (`sqlite`, `mysql`, `mariadb` or `pgsql`) and `TRANSLATIONS_TEST_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME` and `_PASSWORD`, then run `vendor/bin/pest`. Any other `TRANSLATIONS_TEST_DB` value is an error, and a test checks that the server reached is the one named.

The concurrency tests run writers in separate processes, so they are the only tests skipped on SQLite: an in-memory database can't be shared between processes. Everything else runs on every database, and nothing is skipped on MySQL, MariaDB or PostgreSQL. CI runs all four databases on every push.

`vendor/bin/pest tests/Benchmarks` compares the storages on the configured database (`BENCH_MODELS` and `BENCH_NOISE` set the data size).

## License

MIT
