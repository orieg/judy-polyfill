# judy-polyfill

[![CI](https://github.com/orieg/judy-polyfill/actions/workflows/ci.yml/badge.svg)](https://github.com/orieg/judy-polyfill/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/orieg/judy-polyfill)](https://packagist.org/packages/orieg/judy-polyfill)
[![License](https://img.shields.io/packagist/l/orieg/judy-polyfill)](LICENSE)

Pure-PHP polyfill for the [Judy extension](https://github.com/orieg/php-judy) (sparse dynamic arrays and radix tries). When `ext-judy` is not installed, this package provides an API-compatible `Judy` class and helper functions (`judy_version()`, `judy_type()`), allowing code to run anywhere while seamlessly using native C Judy when available.

```sh
composer require orieg/judy-polyfill
```

```php
$judy = new Judy(Judy::INT_TO_INT);   // Native C class if ext-judy loaded, pure-PHP polyfill otherwise
$judy[42] = 1000;
```

---

## For Library Authors

Depend on the polyfill and suggest the extension. Your library runs out of the box anywhere, and environments with `ext-judy` (`pie install orieg/judy`) automatically get native C performance and memory compaction:

```json
{
    "require": { "orieg/judy-polyfill": "^2.6" },
    "suggest": { "ext-judy": "Native C speed and lower memory footprint" }
}
```

---

## Compatibility & Feature Matrix

The polyfill is **API-compatible and signature-identical** to `ext-judy 2.6.0`:

- ✅ **Full 2.6 Method Surface**: Implements all 11 Judy type constants (`BITSET`, `INT_TO_INT`, `STRING_TO_MIXED`, `STRING_TO_ENTRY`, etc.) and all public methods.
- ✅ **Strict Parity Verified**: 1,500+ automated test checks verifying ordering, type coercion, unsigned 64-bit boundaries, and exceptions against native `ext-judy`.
- ✅ **Signature Parity**: Reflection tests diff parameter names, defaults, and return types against the extension stub.
- ❌ **Memory Profile**: Backed by standard PHP arrays; hardware-accelerated memory compression requires `ext-judy`.
- ⚠️ **String Keys**: Embedded NUL bytes (`0x00`) are rejected (matching JudySL C string trie rules).
- ⚠️ **Offset Types**: On string-keyed types, integer offsets in array syntax (`$j[42]`) raise `TypeError` rather than implicit string coercion (matching extension syntax rules).

---

## Testing & Parity Validation

```sh
php tests/behavior.php                      # Polyfill standalone test suite
php -d extension=judy tests/parity.php      # Differential parity test vs native ext-judy
```

CI continuously validates the test suite across PHP 8.1–8.5 against both the bundled extension and legacy extension versions.

---

## Versioning & Releases

Release versions track the **ext-judy API level** implemented (`v2.6.0` matches the 2.6 Judy specification). Runtime introspection reports `2.6.0-polyfill` via `judy_version()`.

---

## License

MIT. The Judy extension itself is licensed under the PHP License.
