<?php

/*
 * When the native judy extension is absent, expose the polyfill under the
 * global names the extension would provide.
 */

if (!extension_loaded('judy') && !class_exists('Judy', false)) {
    class_alias(\Orieg\JudyPolyfill\Judy::class, 'Judy');
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
        return $array instanceof \Orieg\JudyPolyfill\Judy || $array instanceof \Judy
            ? $array->getType()
            : -1;
    }
}
