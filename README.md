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
    "suggest": { "ext-judy": "C-speed operations and lower memory" }
}
```

That `suggest` line deliberately carries no memory multiplier. The saving is
real but strongly type-dependent, and no single range describes it: measured at
500k keys, `Judy::BITSET` is smaller by more than 40x and `INT_TO_INT` by about
2.1x, while `INT_TO_MIXED` — which stores a `zval` pointer per slot — is about
1.11x **larger** than the PHP array it replaces
([php-judy#172](https://github.com/orieg/php-judy/issues/172), which is also
where the old "2-4x" figure came from and why it did not survive being measured
with an instrument that can see a Judy index).

This package deliberately does **not** declare `"provide": {"ext-judy": "*"}`:
a package that hard-requires `ext-judy` is asking for the native
performance profile, and the polyfill cannot honestly satisfy that.

## What you get (and don't)

The polyfill is **API-compatible, not performance-equivalent**. It is backed
by a native PHP array:

- ✅ All 10 Judy type constants, full method surface of ext-judy 2.6
- ✅ Same coercion, ordering, exception, and edge-case semantics — verified
  by a [parity suite](tests/parity.php) that runs every covered scenario
  against both implementations in CI (1512 checks)
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
- ⚠️ String keys must not contain a **NUL byte** (`0x00`), anywhere in them.
  A PHP array is binary-safe and would store `"ab\0cd"` happily; JudySL indexes
  NUL-*terminated* C strings and cannot, and the hash and adaptive types share
  that trie for every seek and range. The extension rejects such a key from
  2.5.1 ([php-judy#117](https://github.com/orieg/php-judy/issues/117)) and so
  does this class, on every method that takes a key or a range bound. `0x00` is
  the only byte treated this way — `0x80`–`0xFF` keys store, round-trip and sort
  in unsigned byte order on both sides, and the empty string is a valid key
- ⚠️ On string-keyed types an **ArrayAccess offset is not coerced**: `$j[42]`
  is a `TypeError`, not the key `"42"`. This is the only place the extension
  refuses to coerce — `putAll([1 => 'x'])`, `getAll([1])`, `fromArray()`,
  `increment()` and the seeks all cast to `"1"` as usual — so it is a property
  of the offset syntax rather than of string keys, and this class matches both
  halves. `slice()` is strict about its bounds the same way, and rejects `null`
  there too, where `keys()`/`values()`/`toArray()`/`size()` read `null` as
  "unbounded"
- ⚠️ `$optimizeIteration` (constructor, `fromArray()`) is accepted and ignored,
  and `isIterationOptimized()` always returns `false`. It is a native-memory
  read/write trade with no meaning for a PHP array — and the extension's own
  contract already covers not honouring it, since types that cannot mirror
  behave the same way. Pass it unconditionally in generic code

### Known divergences

- Iteration while mutating is undefined in both implementations, and the
  undefined behavior differs.
- `$j[] = 1` on a string-keyed array reports the offset `TypeError` here, where
  the extension raises its own "values cannot be set without specifying a key"
  Exception. PHP passes `offsetSet()` a null offset for both `$j[] = 1` and
  `$j[null] = 1`, so no userland class can tell the two apart; the extension
  can. Both forms are errors on both sides — only the class and message differ.
- An **array or object** passed where a key or bound is expected diverges on
  `deleteRange()`, `first()`, `last()`, `searchNext()` and `prev()`: the
  extension declares a string parameter and the engine rejects it with
  `Argument #1 ($start) must be of type string, array given`, while this class
  takes `mixed` and converts. The methods that type-check their own bounds —
  `slice()`, `keys()`, `values()`, `toArray()`, `size()` — and every
  ArrayAccess offset agree on both sides.

`size($start, $end)` with **string** bounds used to be listed here, back when
the extension ignored the bounds and returned the whole-array count. It counts
the range on both sides since ext-judy 2.5.0
([php-judy#105](https://github.com/orieg/php-judy/issues/105)), and the parity
suite pins it.

Empty-slot scans at the unsigned 64-bit boundary used to be listed here too.
They now agree: integer keys are compared and stepped as unsigned words, so
`firstEmpty(PHP_INT_MAX)` returns `PHP_INT_MIN` rather than overflowing to a
float, and running off either end returns `null` instead of wrapping.

## Testing

```sh
php tests/behavior.php                      # polyfill standalone
php -d extension=judy tests/parity.php      # diff every scenario vs native
```

CI runs both legs on PHP 8.1–8.5, with the extension installed via
[PIE](https://github.com/php/pie), and a third leg that builds ext-judy 2.4.2,
2.5.0 and 2.6.0 from source. PIE installs the *latest released* extension, so
without that leg the version gates below are never exercised — and a scenario
that calls a method with arguments an older extension cannot accept looks green
until someone runs an old build by hand.

The PIE leg takes the extension's **default** build. Since 2.6.0 that is the
bundled, patched libJudy: `./configure` needs no system library and downloads
nothing, so parity is measured against what `pie install orieg/judy` actually
gives a user. The `--with-judy=DIR` path that links a system libJudy is still
supported, and keeps parity coverage through the 2.6.0 entry in the
from-source leg — the one build there that is not testing an old release.

The parity suite is **version-aware**, because PIE installs the latest
*released* extension while this package tracks the latest extension *API*, and
those are not the same thing between a merge and a release. Each check that
needs a newer extension than the one loaded is skipped and named, with the
version it wants:

```
SKIP [string/embedded-NUL keys] needs ext-judy >= 2.5.1, have 2.5.0
SKIP [string/high-byte keys, bounded] needs ext-judy >= 2.5.0, have 2.4.2
SKIP [2.6.0 conformance: void offsets, required $type] needs ext-judy >= 2.6.0, have 2.5.2
ext-judy 2.4.2: 999 checks, 0 divergences, 16 skipped (need a newer extension)
ext-judy 2.5.0: 1270 checks, 0 divergences, 2 skipped (need a newer extension)
ext-judy 2.5.2: 1501 checks, 0 divergences, 1 skipped (need a newer extension)
ext-judy 2.6.0: 1512 checks, 0 divergences
```

ext-judy 2.6.0 added no API, so the polyfill needed no change to match it. It
did make the extension conform to two contracts its own stub already declared,
and in both the behaviour it moved to is the one the polyfill already had — so
the 11 checks the 2.6.0 row adds are checks the *extension* newly passes:

- `offsetSet()`/`offsetUnset()` called as methods now evaluate to `null`. Before
  2.6.0 they returned a bool that reported whether the backing array had been
  allocated yet rather than whether anything was unset, so the same call read
  `false` on a fresh Judy and `true` on a populated one. The `$j[$k] = $v` and
  `unset($j[$k])` operator forms always discarded it and are unaffected.
- `new Judy()` with no arguments now raises `ArgumentCountError` for the `$type`
  the signature has always declared required, instead of yielding a type-0
  object whose every write warned.

Both are gated at `requires: '2.6.0'`: against an older extension the polyfill
is the correct one and the extension diverges, which is not a polyfill defect
to fail the suite on.

Nothing is silently dropped, and the suite strengthens on its own the moment
CI's extension catches up. Failing instead would produce a permanently red
build that everyone learns to ignore, which is worse than no check at all.

## Versioning and releases

Release numbers track the **ext-judy API level** this package implements, not
its own feature count: `v2.6.0` means "matches the 2.6 Judy contract", and
`Judy::POLYFILL_VERSION` reports the same level at runtime as
`2.6.0-polyfill`. So `"orieg/judy-polyfill": "^2.6"` asks for the contract the
2.6 extension has, and a caller can use the ordinary
`version_compare(judy_version(), '2.6.0', '>=')` on either implementation.

That coupling is the point — this package exists to be swapped for the
extension — but it means a release here is not a semver statement about this
package's own API. A behavioural fix that brings the polyfill *closer* to the
extension can change what your code sees, because matching the extension is the
contract; [CHANGELOG.md](CHANGELOG.md) calls out every such change.

Releasing: update `CHANGELOG.md` in the PR, merge it, then push the tag. The
release workflow turns the tag into a GitHub Release using that version's
changelog section, and fails if the section is missing rather than publishing
an empty release. Packagist picks the tag up from the repository webhook.

## License

MIT. The Judy extension itself is licensed under the PHP License.
