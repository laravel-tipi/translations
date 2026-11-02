# Changelog

All notable changes to this package are documented here. The package follows semantic versioning; before 1.0, minor versions may break the API. See [UPGRADE.md](UPGRADE.md) for moving between them.

## Unreleased (0.2.0)

A redesign around a small model API and Localization 0.2.

### Added

- `#[Translatable([...])]` attribute to declare translatable attributes. It is inherited by subclasses.
- A single `TranslatableModel` contract for both storages: `getTranslation()`, `getTranslationOrFail()`, `resolveTranslation()`, `hasTranslation()`, `getTranslations()`, `getAllTranslations()`, `getTranslatedLocales()`, `setTranslation()`, `setTranslations()`, `fillTranslations()`, `forgetTranslation()`, `forgetTranslations()`, `hasUnsavedTranslations()`.
- Attribute access (`$model->name`) falls back: requested locale → current context default → global default.
- Explicit reads are exact by default, with `fallback: true` to opt in.
- `ResolvedTranslation` reports whether a value is translated, a fallback or missing, and which locale it came from.
- `MissingTranslationException` for required translations, a domain exception rather than an HTTP one.
- `FallbackPolicy` contract with the default `ContextFallbackPolicy`.
- Writes through Eloquent: attribute assignment, `create()`, `update()` and `fill()` write the current locale, and `save()` stores every change in one transaction (also with `saveQuietly()`).
- Concurrency safety: JSON storage merges changed locales into a locked fresh read, and table storage recovers from a concurrently inserted locale row.
- Query scopes `whereHasTranslation()`, `whereDoesntHaveTranslation()` and `withTranslations()`. Every translation scope accepts `fallback:`.
- `orderByTranslation()` sorts missing values last on every database.
- `Rules\Translations` validation rule for translation payloads, optionally limited to a locale context.
- `HasTranslationStates` trait with `getTranslationState()`, `isTranslationOutdated()`, `markTranslationOutdated()`, `markTranslationCurrent()`, `markOtherTranslationsOutdated()` and `setTranslationStatus()`. States are kept in sync on `save()`.
- `replicate()` copies translations for both storages, and `updated_at` reflects translation changes for both storages.
- The test suite runs on SQLite, MySQL, MariaDB and PostgreSQL, including query-count tests.

### Changed

- Requires `laravel-tipi/localization` ^0.2. Locales, the current locale and contexts come from Localization.
- `null` always means "missing" and is never stored. Empty strings, `0` and `false` are values and never fall back.
- Table storage: the relation is `translations()` (was `translationRecords()`), translation models are plain Eloquent models, and empty rows are deleted. The relation is hidden from `toArray()`.
- JSON storage: the package reads and writes the columns itself (no `array` cast), stores unescaped UTF-8 ordered by locale display order, and stores `NULL` instead of an empty object.
- Serialization returns resolved values for both storages.
- `pluckTranslation()` is a model scope instead of a global `Builder` macro.
- `markOtherTranslationsOutdated()` keeps the original `outdated_at` of already outdated locales and covers translated locales without a state row.
- Configuration only covers translation states (`translations.states.*`).
- Service provider renamed to `TranslationsServiceProvider`.
- Exceptions implement the `TranslationException` interface. Locale errors use Localization's exceptions.

### Removed

- Shared polymorphic table storage and its `translations` migration.
- Actions (`CreateTranslation`, `UpdateTranslation`, `DeleteTranslation`, `LockTranslatable`, `MarkOthersAsOutdated`), managers, stores, query drivers and the driver resolver.
- The package's own `LocaleProvider`.
- The `Translation` DTO, `TranslationRules`, `TranslatableRules` and `HasTranslatablePolicy`.
- Benchmarks, replaced by query-count tests.
