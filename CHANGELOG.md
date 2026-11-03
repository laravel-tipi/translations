# Changelog

All notable changes to this package are documented here. The package follows semantic versioning; before 1.0, minor versions may break the API. See [UPGRADE.md](UPGRADE.md) for moving between them.

## Unreleased (0.2.0)

A redesign around a small model API and Localization 0.2.

### Added

- `#[Translatable([...])]` attribute to declare translatable attributes. It is inherited by subclasses.
- Three storages behind one API: JSON columns (`HasJsonTranslations`), a dedicated table per model (`HasTableTranslations`) and a shared polymorphic table (`HasSharedTableTranslations`).
- A single `TranslatableModel` contract for every storage: `getTranslation()`, `getTranslationOrFail()`, `resolveTranslation()`, `hasTranslation()`, `getTranslations()`, `getAllTranslations()`, `getTranslatedLocales()`, `setTranslation()`, `setTranslations()`, `fillTranslations()`, `forgetTranslation()`, `forgetTranslations()`, `getDirtyTranslations()`, `getTranslatableAttributes()`, `isTranslatableAttribute()`.
- Attribute access (`$model->name`) falls back: requested locale → current context default → global default.
- Explicit reads are exact by default, with `fallback: true` to opt in.
- `ResolvedTranslation` reports whether a value is translated, a fallback or missing, and which locale it came from.
- `MissingTranslationException` for required translations, a domain exception rather than an HTTP one.
- `FallbackPolicy` contract with the default `ContextFallbackPolicy`.
- Writes through Eloquent: attribute assignment, `create()`, `update()` and `fill()` write the current locale. Translations are written inside Eloquent's save lifecycle, in one transaction with the model row, so `saveQuietly()`, `saveOrFail()`, `saveOrIgnore()`, `push()`, `touch()`, factories and models with their own `save()` store them too.
- A defined listener contract: changes made in `saving`, `creating` and `updating` listeners are part of the save; `created`, `updated` and `saved` listeners see translations stored and report them dirty and changed like columns; changes those listeners make stay pending for the next save.
- A failed save (listener, write or commit) leaves every translation change pending, restores the loaded storage state and leaves a new model new, so `save()` can be retried.
- Concurrency safety, tested with concurrent processes on MySQL, MariaDB and PostgreSQL: JSON storage merges changed values into a locked fresh read; table storage writes each locale with one atomic upsert and deletes an emptied row only if it is still empty; shared storage writes each value as its own row with one upsert.
- Every storage locks the model's row before writing translation rows, and `delete()` locks it before deleting them: a model deleted by another process can't receive rows, and saving its translations throws `ModelNotFoundException` (also for JSON storage, which reported such saves as successful).
- `delete()` removes the model and its translation rows (table, shared, states) in one transaction, after `deleting` listeners allowed it, and also with events disabled (`deleteQuietly()`, `forceDeleteQuietly()`).
- `translations:prune-orphans` deletes shared translations and translation states left behind by deletes that bypass Eloquent (`--pretend` counts them).
- `translations.morph_key_type`: `int`, `uuid`, `ulid` or `string` keys for the polymorphic tables (shared translations and states). `string` supports models with mixed key types in one table, on every database. `null` follows Laravel's default morph key type. Models whose keys don't fit throw `InvalidTranslatableModelException` before any query.
- The model and its translation rows must share a connection; a translation model on another connection throws `InvalidTranslatableModelException` instead of writing outside the model's transaction.
- `upgrade_translations_table_from_v0_1`, published with the other migrations, converts a 0.1 shared table without losing data, and does nothing on new installations.
- `Config\TranslationConfig` remains, reduced to the two table names, so migrations published by 0.1 keep running on fresh databases.
- Documented retry behavior: the package never retries a save or delete; regression tests cover deadlocks inside and outside transactions and application-level retries.
- An opt-in benchmark comparing the storages (`vendor/bin/pest tests/Benchmarks`).
- Query scopes `whereHasTranslation()`, `whereDoesntHaveTranslation()` and `withTranslations()`. Every translation scope accepts `fallback:`.
- `orderByTranslation()` sorts missing values last on every database.
- `Rules\Translations` validation rule for translation payloads, optionally limited to a locale context.
- `HasTranslationStates` trait with `getTranslationState()`, `isTranslationOutdated()`, `markTranslationOutdated()`, `markTranslationCurrent()`, `markOtherTranslationsOutdated()` and `setTranslationStatus()`. States are kept in sync on `save()`.
- `isDirty()`, `isClean()` and `wasChanged()` understand translated attributes, with and without timestamps, and survive PHP serialization. A translation-only save is a normal Eloquent update in every storage: update events fire once, `updated_at` changes and `$touches` owners are touched. `discardChanges()` and `refresh()` discard unsaved translations.
- `replicate()` copies translations in every storage.
- JSON- and shared-storage queries compare by the stored JSON type: numbers numerically (with numeric operands) and other values as text in the connection's collation, so the storages compare and sort the same way on every database. Unsupported operands (booleans, arrays, `like` with a number) throw `InvalidArgumentException`.
- `pluckTranslation()` returns values typed like model reads: cast by the translation model, or decoded JSON.
- `TranslatableColumnQueryException`: `where()`, `orderBy()` and `firstOrCreate()` lookups on a translatable attribute throw instead of matching nothing or creating duplicates.
- Saving translation states doesn't trip `Model::preventLazyLoading()`.
- The test suite runs on SQLite, MySQL, MariaDB and PostgreSQL, including query-count tests and multi-process concurrency tests.

### Changed

- Requires `laravel-tipi/localization` ^0.2. Locales, the current locale and contexts come from Localization.
- `null` always means "missing" and is never stored. Empty strings, `0` and `false` are values and never fall back.
- Table storage: the relation is `translations()` (was `translationRecords()`), translation models are plain Eloquent models, and empty rows are deleted. The relation is hidden from `toArray()`. Rows are written with upserts: casts and mutators apply, the translation model's events don't fire.
- JSON storage: the package reads and writes the columns itself (no `array` cast), stores unescaped UTF-8 ordered by locale display order, and stores `NULL` instead of an empty object.
- Serialization returns resolved values in every storage.
- Shared storage: one row per model, locale and attribute with a JSON `value` (was one row per model and locale with a `values` object), `MorphMany` relation `translations()` (was `translationRecords()`), hidden from `toArray()`. Configured with `translations.shared.table` and `translations.shared.model`.
- The package migrations create `locale_code` with 16 characters, the longest code Localization accepts (0.1 used 255).
- `pluckTranslation()` is a model scope instead of a global `Builder` macro.
- `markOtherTranslationsOutdated()` keeps the original `outdated_at` of already outdated locales and covers translated locales without a state row.
- Configuration covers the package's tables: `translations.shared.*`, `translations.morph_key_type` and `translations.states.*`.
- Service provider renamed to `TranslationsServiceProvider`.
- Exceptions implement the `TranslationException` interface. Locale errors use Localization's exceptions.

### Removed

- Actions (`CreateTranslation`, `UpdateTranslation`, `DeleteTranslation`, `LockTranslatable`, `MarkOthersAsOutdated`), managers, stores, query drivers and the driver resolver.
- The package's own `LocaleProvider`.
- The `Translation` DTO, `TranslationRules`, `TranslatableRules` and `HasTranslatablePolicy`.
- The old benchmarks, replaced by query-count tests and the new storage benchmark.
