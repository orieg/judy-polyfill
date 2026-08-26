<?php

/*
 * When the native judy extension is absent, expose the polyfill under the
 * global names the extension would provide.
 */

/*
 * The alias is registered LAZILY rather than created here.
 *
 * class_alias() takes the target as a string, so calling it at load time
 * autoloads Orieg\JudyPolyfill\Judy immediately — and this file runs from
 * composer's "files" autoload, i.e. inside vendor/autoload.php. That put the
 * class in memory before anything a test runner does, which silently broke
 * mutation testing: vendor/bin/phpunit requires vendor/autoload.php to boot
 * itself, so src/Judy.php was already compiled by the time Infection enabled
 * its include-interceptor, and every mutant ran against the ORIGINAL code.
 * The result was a green suite for all 839 mutants and an MSI of 0%.
 *
 * Deferring the alias to an autoloader keeps the same observable contract —
 * `new Judy(...)` and class_exists('Judy') both work — while loading the class
 * only when it is first named. It is also simply less work per request for
 * consumers that never touch the global name.
 */
if (!extension_loaded('judy') && !class_exists('Judy', false)) {
    spl_autoload_register(static function (string $class): void {
        if ($class === 'Judy') {
            class_alias(\Orieg\JudyPolyfill\Judy::class, 'Judy');
        }
    });
}

if (!function_exists('judy_version')) {
    function judy_version(): string
    {
        return \Orieg\JudyPolyfill\Judy::POLYFILL_VERSION;
    }
}

if (!function_exists('judy_type')) {
    function judy_type(mixed $array): int
    {
        if (is_object($array) && method_exists($array, 'getType')) {
            return (int) $array->getType();
        }
        return -1;
    }
}
