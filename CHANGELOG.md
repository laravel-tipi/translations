# Changelog

All notable changes to this package are documented here. The package follows semantic versioning; before 1.0, minor versions may break the API.

## Unreleased (0.2.0)

A rewrite around a small model API and Localization 0.2. It shares no API, configuration or schema with 0.1 and has no upgrade path from it: install it as a new package.

- Requires PHP 8.5, Laravel 13 and `laravel-tipi/localization` ^0.2. Locales, the current locale and contexts come from Localization.
- `#[Translatable([...])]` attribute to declare translatable attributes. It is inherited by subclasses.
- Three storages behind one API: JSON columns (`HasJsonTranslations`), a dedicated table per model (`HasTableTranslations`) and a shared polymorphic table (`HasSharedTableTranslations`, one row per model, locale and attribute with a JSON `value`).
- A single `TranslatableModel` contract for every storage: `getTranslation()`, `getTranslationOrFail()`, `resolveTranslation()`, `hasTranslation()`, `getTranslations()`, `getAllTranslations()`, `getTranslatedLocales()`, `setTranslation()`, `setTranslations()`, `fillTranslations()`, `forgetTranslation()`, `forgetTranslations()`, `getDirtyTranslations()`, `getTranslatableAttributes()`, `isTranslatableAttribute()`.
- Attribute access (`$model->name`) falls back: requested locale → current context default → global default. Explicit reads are exact by default, with `fallback: true` to opt in.
- `ResolvedTranslation` reports whether a value is translated, a fallback or missing, and which locale it came from.
- `MissingTranslationException` for required translations, a domain exception rather than an HTTP one.
- `FallbackPolicy` contract with the default `ContextFallbackPolicy`.
- `null` always means "missing" and is never stored. Empty strings, `0` and `false` are values and never fall back.
- Writes through Eloquent: attribute assignment, `create()`, `update()` and `fill()` write the current locale. Translations are written inside Eloquent's save lifecycle, in one transaction with the model row, so `saveQuietly()`, `saveOrFail()`, `saveOrIgnore()`, `push()`, `touch()`, factories and models with their own `save()` store them too.
- A defined listener contract: changes made in `saving`, `creating` and `updating` listeners are part of the save; `created`, `updated` and `saved` listeners see translations stored and report them dirty and changed like columns; changes those listeners make stay pending for the next save.
- A failed save (listener, write or commit) leaves every translation change pending, restores the loaded storage state and leaves a new model new, so `save()` can be retried.
- The package never retries a save or delete itself; retrying is the application's choice, documented with its effect on events.
- Concurrency safety, tested with concurrent processes on MySQL, MariaDB and PostgreSQL: JSON storage merges changed values into a locked fresh read; table storage writes each locale with one atomic upsert and deletes an emptied row only if it is still empty; shared storage writes each value as its own row with one upsert.
- Every storage locks the model's row before writing translation rows, and `delete()` locks it before deleting them: a model deleted by another process can't receive rows, and saving its translations throws `ModelNotFoundException`.
- `delete()` removes the model and its translation rows (table, shared, states) in one transaction, after `deleting` listeners allowed it, and also with events disabled (`deleteQuietly()`, `forceDeleteQuietly()`).
- `translations:prune-orphans` deletes shared translations and translation states left behind by deletes that bypass Eloquent (`--pretend` counts them).
- `translations.morph_key_type`: `int`, `uuid`, `ulid` or `string` keys for the polymorphic tables (shared translations and states). `string` supports models with mixed key types in one table, on every database. `null` follows Laravel's default morph key type. Models whose keys don't fit throw `InvalidTranslatableModelException` before any query.
- The model and its translation rows must share a connection; a translation model on another connection throws `InvalidTranslatableModelException` instead of writing outside the model's transaction.
- Table and shared storage expose a `translations()` relation, hidden from `toArray()`. Rows are written with upserts: casts and mutators apply, the translation model's events don't fire.
- JSON storage reads and writes its columns itself (no `array` cast), stores unescaped UTF-8 ordered by locale display order, and stores `NULL` instead of an empty object.
- Serialization returns resolved values in every storage.
- Query scopes `whereTranslation()`, `orderByTranslation()`, `pluckTranslation()`, `whereHasTranslation()`, `whereDoesntHaveTranslation()` and `withTranslations()`. Every translation scope accepts `fallback:`. `orderByTranslation()` sorts missing values last on every database.
- JSON- and shared-storage queries compare by the stored JSON type: numbers numerically (with numeric operands) and other values as text in the connection's collation, so the storages compare and sort the same way on every database. Unsupported operands (booleans, arrays, `like` with a number) throw `InvalidArgumentException`.
- `pluckTranslation()` returns values typed like model reads: cast by the translation model, or decoded JSON.
- `TranslatableColumnQueryException`: `where()`, `orderBy()` and `firstOrCreate()` lookups on a translatable attribute throw instead of matching nothing or creating duplicates.
- `Rules\Translations` validation rule for translation payloads, optionally limited to a locale context.
- `HasTranslationStates` trait with `getTranslationState()`, `isTranslationOutdated()`, `markTranslationOutdated()`, `markTranslationCurrent()`, `markOtherTranslationsOutdated()` and `setTranslationStatus()`. States are kept in sync on `save()` and don't trip `Model::preventLazyLoading()`.
- `isDirty()`, `isClean()` and `wasChanged()` understand translated attributes, with and without timestamps, and survive PHP serialization. A translation-only save is a normal Eloquent update in every storage: update events fire once, `updated_at` changes and `$touches` owners are touched. `discardChanges()` and `refresh()` discard unsaved translations.
- `replicate()` copies translations in every storage.
- Two migrations, `create_translations_table` and `create_translation_states_table`, configured by `translations.shared.*`, `translations.states.*` and `translations.morph_key_type`.
- Exceptions implement the `TranslationException` interface. Locale errors use Localization's exceptions.
- The test suite runs on SQLite, MySQL, MariaDB and PostgreSQL, including query-count tests and multi-process concurrency tests, plus an opt-in storage benchmark (`vendor/bin/pest tests/Benchmarks`).
