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
        if (is_object($array) && ($array instanceof \Orieg\JudyPolyfill\Judy || is_a($array, 'Judy'))) {
            /** @var \Orieg\JudyPolyfill\Judy $array */
            return $array->getType();
        }
        return -1;
    }
}
