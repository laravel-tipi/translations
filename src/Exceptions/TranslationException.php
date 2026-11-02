<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use Throwable;

/**
 * Marker implemented by every exception thrown by the package.
 *
 * Invalid locales are reported with Localization's own exceptions
 * (LocaleNotFoundException), which implement LocalizationException.
 */
interface TranslationException extends Throwable {}
