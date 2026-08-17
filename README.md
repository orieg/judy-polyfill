# judy-polyfill

[![CI](https://github.com/orieg/judy-polyfill/actions/workflows/ci.yml/badge.svg)](https://github.com/orieg/judy-polyfill/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/orieg/judy-polyfill)](https://packagist.org/packages/orieg/judy-polyfill)
[![License](https://img.shields.io/packagist/l/orieg/judy-polyfill)](LICENSE)

Pure-PHP polyfill for the [Judy extension](https://github.com/orieg/php-judy)
(sparse dynamic arrays). When `ext-judy` is not installed, this package
provides an API-compatible `Judy` class plus the `judy_version()` /
`judy_type()` functions, so your code — and your library's users — run
everywhere, and get the native extension's speed and memory profile wherever
it is installed.

```sh
composer require orieg/judy-polyfill
```

```php
$judy = new Judy(Judy::INT_TO_INT);   // native class if ext-judy is loaded,
$judy[42] = 1000;                     // polyfill otherwise — same API
```

## For library authors

Depend on the polyfill and suggest the extension. Your library works out of
the box, and users who install `ext-judy` (`pie install orieg/judy`) get the
native performance transparently:

```json
{
    "require": { "orieg/judy-polyfill": "^2.4" },
    "suggest": { "ext-judy": "2-4x less memory and C-speed operations" }
}
```

This package deliberately does **not** declare `"provide": {"ext-judy": "*"}`:
a package that hard-requires `ext-judy` is asking for the native
performance profile, and the polyfill cannot honestly satisfy that.

## What you get (and don't)

The polyfill is **API-compatible, not performance-equivalent**. It is backed
by a native PHP array:

- ✅ All 10 Judy type constants, full method surface of ext-judy 2.5
- ✅ Same coercion, ordering, exception, and edge-case semantics — verified
  by a [parity suite](tests/parity.php) that runs every covered scenario
  against both implementations in CI (421 checks)
- ✅ **Signature** parity too, not just behavior: the suite reflects over both
  classes and diffs every public method's parameters, defaults and return type.
  Behavior parity alone cannot catch "the method exists but will not accept
  those arguments", which is how the extension's range arguments went unnoticed
  here for a while
- ❌ No memory savings — that is the extension's job
- ❌ `memoryUsage()` / `free()` byte counts are estimates. Both implementations
  return an approximation for string-keyed types (the extension has no
  allocator accounting to read there) and the two approximations are composed
  the same way but will not match byte-for-byte; only the *shape* is a parity
  target
- ⚠️ `$optimizeIteration` (constructor, `fromArray()`) is accepted and ignored,
  and `isIterationOptimized()` always returns `false`. It is a native-memory
  read/write trade with no meaning for a PHP array — and the extension's own
  contract already covers not honouring it, since types that cannot mirror
  behave the same way. Pass it unconditionally in generic code

### Known divergences

- `lastEmpty()` / empty-slot scans near the unsigned 64-bit boundary follow
  signed-integer wrap semantics; identical for practical key ranges.
- Iteration while mutating is undefined in both implementations, and the
  undefined behavior differs.

## Testing

```sh
php tests/behavior.php                      # polyfill standalone
php -d extension=judy tests/parity.php      # diff every scenario vs native
```

CI runs both legs on PHP 8.1–8.5, with the extension installed via
[PIE](https://github.com/php/pie).

## License

MIT. The Judy extension itself is licensed under the PHP License.
