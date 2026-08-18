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
    "require": { "orieg/judy-polyfill": "^2.5" },
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
  against both implementations in CI (458 checks)
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

- `size($start, $end)` with **string** bounds counts the range here; the
  extension currently ignores the bounds and returns the whole-array count
  ([php-judy#105](https://github.com/orieg/php-judy/issues/105)). This is the
  one place the polyfill is deliberately *more* correct than the extension:
  matching it would mean baking in a wrong answer that is about to be fixed.
  Expected to resolve itself when that issue lands.
- Iteration while mutating is undefined in both implementations, and the
  undefined behavior differs.

Empty-slot scans at the unsigned 64-bit boundary used to be listed here. They
now agree: integer keys are compared and stepped as unsigned words, so
`firstEmpty(PHP_INT_MAX)` returns `PHP_INT_MIN` rather than overflowing to a
float, and running off either end returns `null` instead of wrapping.

## Testing

```sh
php tests/behavior.php                      # polyfill standalone
php -d extension=judy tests/parity.php      # diff every scenario vs native
```

CI runs both legs on PHP 8.1–8.5, with the extension installed via
[PIE](https://github.com/php/pie).

The parity suite is **version-aware**, because PIE installs the latest
*released* extension while this package tracks the latest extension *API*, and
those are not the same thing between a merge and a release. Each check that
needs a newer extension than the one loaded is skipped and named, with the
version it wants:

```
SKIP [range/int] needs ext-judy >= 2.5.0, have 2.4.2
ext-judy 2.4.2: 298 checks, 0 divergences, 11 skipped (need a newer extension)
ext-judy 2.5.0: 458 checks, 0 divergences
```

Nothing is silently dropped, and the suite strengthens on its own the moment
CI's extension catches up. Failing instead would produce a permanently red
build that everyone learns to ignore, which is worse than no check at all.

## License

MIT. The Judy extension itself is licensed under the PHP License.
