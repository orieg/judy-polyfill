# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Version numbers track the **ext-judy API level** this polyfill implements
rather than counting its own features: a release is numbered for the extension
version whose behaviour it matches, so `^2.6` here means "the 2.6 Judy
contract". `Judy::POLYFILL_VERSION` reports the same level at runtime.

## [Unreleased]

### Added

- Support for compound cache entry type `Judy::STRING_TO_ENTRY` (11) and native TTL cache entry methods:
  - `set(string $key, mixed $value, int $ttl = 0, int $flags = 0): void`
  - `get(string $key, mixed &$expiresAt = null, mixed &$flags = null): mixed`
  - `pruneExpired(?int $now = null): int`
  - `getEntry(string $key): ?array`
  - `getExpiry(string $key): ?int`
  - `getFlags(string $key): ?int`
- Pure-PHP expiration handling, TTL pruning, entry metadata inspection, and unboxed ArrayAccess/Iterator integration.
- Differential parity and behavior tests covering `STRING_TO_ENTRY` operations. ([#10])

## [2.6.0] - 2026-08-19

Matches the ext-judy 2.6 contract. 2.6.0 added no API, so nothing in the class
surface moved; what changed is that two long-standing behaviours are now
implemented correctly, and the parity suite can prove that against every
extension version it claims to support.

### Fixed

- **ArrayAccess offsets are no longer coerced on string-keyed types.** `$j[42]`
  on a `STRING_TO_*` array raises `TypeError: Judy offset must be of type
  string for string-based arrays`, as the extension has since 2.1.0, instead of
  silently storing the key `"42"`. The strictness is confined to the offset
  syntax — `putAll([1 => 'x'])`, `getAll([1])`, `fromArray()`, `increment()`
  and the seeks still coerce to `"1"`, matching the extension — and the three
  read/delete handlers keep the extension's empty-array reprieve, answering
  absent rather than throwing when the array holds nothing. ([#4])
- **`slice()` now type-checks its bounds.** `slice(1, 2)` on a string-keyed
  array raises `TypeError: Judy::slice() expects string arguments for
  string-keyed arrays` instead of returning an empty result. `slice()` rejects
  `null` as well, because its two bounds are required, where
  `keys()`/`values()`/`toArray()`/`size()` read `null` as "unbounded". It
  checks bound bytes before bound types, the opposite order from those four, so
  `slice(1, "a\0b")` remains the embedded-NUL `Exception`. `deleteRange()` is
  unchanged: the extension does not type-check its bounds either. ([#4])

### Changed

- `POLYFILL_VERSION` is now `2.6.0-polyfill` (was `2.5.0-polyfill`), and the
  class doc names ext-judy 2.6 as the implemented API level.

### Added

- Parity coverage for both fixes across all six string-keyed types, asserting
  exception **messages** rather than only that something was thrown: every
  ArrayAccess handler against every non-string offset PHP can pass (int, `0`,
  float, bool, `null`, array, object), on populated and empty arrays and across
  the three ways an array becomes empty; and `slice()` over the same bound
  shapes with `deleteRange()` pinned alongside as the control. ([#4])
- A `parity-legacy` CI leg that builds ext-judy from source (2.4.2, 2.5.0,
  2.6.0) and runs both suites against it. PIE installs only the latest released
  extension, so the suite's `requires:` version gates had never been exercised
  — three separate rounds of scenarios had reached `main` calling methods an
  older extension cannot accept, each invisible until an old build was run by
  hand. ([#5], [#6])
- Conformance coverage for ext-judy 2.6.0's void-offset and required-`$type`
  fixes, and a CI leg on 2.6.0's default bundled-libJudy build. ([#6])

### Fixed (test suite)

- Three scenarios called `keys()`/`values()`/`toArray()`/`size()` with range
  arguments while ungated. Those arguments arrived in ext-judy 2.5.0, so
  against 2.4.2 the suite reported `ArgumentCountError` as a divergence — 57 of
  them, none a fault in the polyfill. The bounded calls moved into gated
  sibling scenarios; 2.4.2 is green for the first time. ([#5])

## [2.5.2] - 2026-08-17

### Fixed

- String keys containing an embedded NUL byte (`0x00`) are rejected on every
  method that takes a key or a range bound, matching ext-judy 2.5.1
  ([php-judy#117]). A PHP array is binary-safe and would store `"ab\0cd"`
  happily; JudySL indexes NUL-terminated C strings and cannot. Position is
  irrelevant — leading, trailing, interior and NUL-only keys are all rejected —
  and the empty string remains a valid key. ([#3])

### Added

- High-byte key coverage as the negative control on that rejection: `0x00` is
  the only byte treated specially, and `0x80`–`0xFF` store, round-trip and sort
  in unsigned byte order, which is what prefix-successor range bounds are carry
  arithmetic over. ([#3])

## [2.5.1] - 2026-08-17

### Fixed

- Numeric string keys are returned as **strings** from `keys()` and the seeks,
  matching the extension. A PHP array silently converts the key `"42"` to the
  integer `42`; the extension does not, and neither does this class any more.
  `toArray()` is the deliberate exception — it returns a PHP array, so both
  sides coerce. ([#2])

## [2.5.0] - 2026-08-17

### Added

- Signature parity with ext-judy 2.5: range arguments on `keys()`, `values()`
  and `toArray()` ([php-judy#96]), the `$optimizeIteration` flag on
  `__construct()` and `fromArray()`, and `isIterationOptimized()`. The parity
  suite now reflects over both classes and diffs every public method's
  parameters, defaults and return type — behaviour parity alone cannot catch
  "the method exists but will not accept those arguments", which is how these
  went unnoticed. ([#1])

## [2.4.2] - 2026-08-14

### Added

- Initial release: pure-PHP `Judy` class covering all 10 type constants and the
  ext-judy 2.4.2 method surface, the `judy_version()` / `judy_type()` functions,
  and the parity suite that diffs every scenario against the native extension.

[2.6.0]: https://github.com/orieg/judy-polyfill/compare/v2.5.2...v2.6.0
[2.5.2]: https://github.com/orieg/judy-polyfill/compare/v2.5.1...v2.5.2
[2.5.1]: https://github.com/orieg/judy-polyfill/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/orieg/judy-polyfill/compare/v2.4.2...v2.5.0
[2.4.2]: https://github.com/orieg/judy-polyfill/releases/tag/v2.4.2
[#1]: https://github.com/orieg/judy-polyfill/pull/1
[#2]: https://github.com/orieg/judy-polyfill/pull/2
[#3]: https://github.com/orieg/judy-polyfill/pull/3
[#4]: https://github.com/orieg/judy-polyfill/pull/4
[#5]: https://github.com/orieg/judy-polyfill/pull/5
[#6]: https://github.com/orieg/judy-polyfill/pull/6
[#10]: https://github.com/orieg/judy-polyfill/issues/10
[php-judy#96]: https://github.com/orieg/php-judy/issues/96
[php-judy#117]: https://github.com/orieg/php-judy/issues/117
