<?php

namespace Orieg\JudyPolyfill;

/**
 * Pure-PHP implementation of the Judy class from the judy extension
 * (https://github.com/orieg/php-judy).
 *
 * API-compatible with ext-judy 2.5; backed by a native PHP array, so it
 * provides compatibility, not the extension's memory/performance profile.
 * Behavioral notes and known divergences are documented in the README.
 */
class Judy implements \ArrayAccess, \Countable, \Iterator, \JsonSerializable
{
    public const POLYFILL_VERSION = '2.5.0-polyfill';

    public const BITSET = 1;
    public const INT_TO_INT = 2;
    public const INT_TO_MIXED = 3;
    public const STRING_TO_INT = 4;
    public const STRING_TO_MIXED = 5;
    public const INT_TO_PACKED = 6;
    public const STRING_TO_MIXED_HASH = 7;
    public const STRING_TO_INT_HASH = 8;
    public const STRING_TO_MIXED_ADAPTIVE = 9;
    public const STRING_TO_INT_ADAPTIVE = 10;

    private const INT_KEYED = [self::BITSET, self::INT_TO_INT, self::INT_TO_MIXED, self::INT_TO_PACKED];
    private const INT_VALUED = [self::INT_TO_INT, self::STRING_TO_INT, self::STRING_TO_INT_HASH, self::STRING_TO_INT_ADAPTIVE];
    private const INCREMENTABLE = [self::INT_TO_INT, self::STRING_TO_INT, self::STRING_TO_INT_HASH];

    private int $type;
    /** @var array<int|string, mixed> For BITSET: set indices stored as $data[$index] = true. */
    private array $data = [];
    private bool $sorted = true;

    /** @var list<int|string> */
    private array $iterKeys = [];
    private int $iterPos = 0;

    /**
     * $optimizeIteration is accepted and ignored.
     *
     * In the extension it trades write speed for ordered-read speed by keeping
     * a second copy of each payload in the key index. That is a native-memory
     * trade with no meaning for a PHP array, and the extension's own contract
     * already covers being unable to honour it: types that cannot mirror accept
     * the argument and report false from isIterationOptimized(). The polyfill is
     * simply always in that position, so generic code can pass the flag
     * unconditionally against either implementation.
     */
    public function __construct(int $type, bool $optimizeIteration = false)
    {
        if ($type < self::BITSET || $type > self::STRING_TO_INT_ADAPTIVE) {
            throw new \Exception('Judy::__construct(): Not a valid Judy type. Please check the documentation for valid Judy type constant.');
        }
        $this->type = $type;
    }

    public function __destruct()
    {
    }

    /* ── Core ─────────────────────────────────────────────────── */

    public function getType(): int
    {
        return $this->type;
    }

    /** Always false: a PHP array has no key index to mirror into. */
    public function isIterationOptimized(): bool
    {
        return false;
    }

    public function free(): int
    {
        $bytes = $this->estimateBytes();
        $this->data = [];
        $this->sorted = true;
        return $bytes;
    }

    /**
     * Approximate bytes. Never null for an initialised array, and 0 when empty.
     *
     * The extension returns two different kinds of number here and documents
     * both: an EXACT libJudy figure for integer-keyed types, and — since it
     * gained string-keyed accounting — an APPROXIMATE payload-only figure for
     * string-keyed ones, because JudySL/JudyHS expose no accounting of their
     * own. Neither is reproducible from a PHP array, so this is an estimate of
     * the same *shape*: useful for tracking growth within one array, not
     * comparable byte-for-byte against the extension.
     */
    public function memoryUsage(): ?int
    {
        if ($this->intKeyed()) {
            return $this->estimateBytes();
        }
        return $this->estimateStringBytes();
    }

    /**
     * Count the keys in the inclusive [$start, $end] range, or all of them
     * when both bounds are omitted.
     *
     * The bounds are keys, not offsets, and they follow the same rules as
     * keys()/values()/toArray(): integer keys compare as unsigned words (so
     * size(0, -1) is the whole array, -1 being the maximum), and string-keyed
     * arrays compare lexicographically and reject non-string bounds.
     *
     * Unlike populationCount(), which reads libJudy's population cache and is
     * therefore integer-keyed only, size() ranges over every type.
     */
    public function size(mixed $start = null, mixed $end = null): int
    {
        if ($start === null && $end === null) {
            return \count($this->data);
        }
        $this->assertRangeBounds('size', $start, $end);
        return \count($this->rangeKeys($start, $end));
    }

    public function count(): int
    {
        return \count($this->data);
    }

    public function byCount(mixed $nth_index): mixed
    {
        if (!$this->intKeyed()) {
            return null;
        }
        $n = (int) $nth_index;
        if ($n < 1 || $n > \count($this->data)) {
            return null;
        }
        $this->ensureSorted();
        return \array_keys($this->data)[$n - 1];
    }

    /* ── Navigation ───────────────────────────────────────────── */

    public function first(mixed $index = null): mixed
    {
        return $this->seek($index, inclusive: true, forward: true);
    }

    public function searchNext(mixed $index): mixed
    {
        return $this->seek($index, inclusive: false, forward: true);
    }

    public function last(mixed $index = null): mixed
    {
        return $this->seek($index, inclusive: true, forward: false);
    }

    public function prev(mixed $index): mixed
    {
        return $this->seek($index, inclusive: false, forward: false);
    }

    public function firstEmpty(mixed $index = null): mixed
    {
        $this->assertIntArg($index, __FUNCTION__, nullable: true);
        return $this->seekEmpty($index === null ? 0 : (int) $index, forward: true);
    }

    public function nextEmpty(mixed $index): mixed
    {
        $this->assertIntArg($index, __FUNCTION__, nullable: false);
        // Exclusive: step one key up in unsigned order first, and there is
        // nothing above -1 to step to.
        return $this->seekEmpty(self::unsignedSucc((int) $index), forward: true);
    }

    public function lastEmpty(mixed $index = null): mixed
    {
        $this->assertIntArg($index, __FUNCTION__, nullable: true);
        // Native scans down from the unsigned word max, which reads back as
        // -1 in PHP; mirror that by starting at -1 and decrementing.
        return $this->seekEmpty($index === null ? -1 : (int) $index, forward: false);
    }

    public function prevEmpty(mixed $index): mixed
    {
        $this->assertIntArg($index, __FUNCTION__, nullable: false);
        // Exclusive: step one key down in unsigned order, nothing below 0.
        return $this->seekEmpty(self::unsignedPred((int) $index), forward: false);
    }

    /* ── Set operations ───────────────────────────────────────── */

    public function union(Judy|\Judy $other): static
    {
        $this->assertSameType($other);
        $result = new static($this->type);
        $result->data = $this->data;
        foreach ($this->entriesOf($other) as $k => $v) {
            $result->data[$k] = $v; // other overwrites on duplicates
        }
        $result->sorted = false;
        return $result;
    }

    public function intersect(Judy|\Judy $other): static
    {
        $this->assertSameType($other);
        $result = new static($this->type);
        $otherData = $this->entriesOf($other);
        foreach ($this->data as $k => $v) {
            if (\array_key_exists($k, $otherData)) {
                $result->data[$k] = $v; // values from $this
            }
        }
        $result->sorted = false;
        return $result;
    }

    public function diff(Judy|\Judy $other): static
    {
        $this->assertSameType($other);
        $result = new static($this->type);
        $otherData = $this->entriesOf($other);
        foreach ($this->data as $k => $v) {
            if (!\array_key_exists($k, $otherData)) {
                $result->data[$k] = $v;
            }
        }
        $result->sorted = false;
        return $result;
    }

    public function xor(Judy|\Judy $other): static
    {
        $this->assertSameType($other);
        $result = new static($this->type);
        $otherData = $this->entriesOf($other);
        foreach ($this->data as $k => $v) {
            if (!\array_key_exists($k, $otherData)) {
                $result->data[$k] = $v;
            }
        }
        foreach ($otherData as $k => $v) {
            if (!\array_key_exists($k, $this->data)) {
                $result->data[$k] = $v;
            }
        }
        $result->sorted = false;
        return $result;
    }

    public function mergeWith(Judy|\Judy $other): void
    {
        $thisCat = $this->intKeyed() ? 'integer' : 'string';
        $otherCat = \in_array($other->getType(), self::INT_KEYED, true) ? 'integer' : 'string';
        if ($thisCat !== $otherCat) {
            throw new \Exception("Cannot merge Judy arrays with incompatible key types ($thisCat vs $otherCat)");
        }
        foreach ($this->entriesOf($other) as $k => $v) {
            if ($this->type === self::BITSET) {
                $this->data[(int) $k] = true;
            } else {
                $this->data[$k] = $this->coerceValue($v);
            }
        }
        $this->sorted = false;
    }

    public function slice(mixed $start, mixed $end): static
    {
        $result = new static($this->type);
        $this->ensureSorted();
        foreach ($this->data as $k => $v) {
            if ($this->cmpKeys($k, $start) >= 0 && $this->cmpKeys($k, $end) <= 0) {
                $result->data[$k] = $v;
            }
        }
        return $result;
    }

    public function equals(Judy|\Judy $other): bool
    {
        if ($this->type !== $other->getType() || \count($this->data) !== $other->count()) {
            return false;
        }
        $mine = $this->data;
        $theirs = $this->entriesOf($other);
        \ksort($mine, $this->sortFlags());
        \ksort($theirs, $this->sortFlags());
        return $mine === $theirs;
    }

    /* ── ArrayAccess ──────────────────────────────────────────── */

    public function offsetExists(mixed $offset): bool
    {
        return \array_key_exists($this->coerceKey($offset), $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $key = $this->coerceKey($offset);
        if (!\array_key_exists($key, $this->data)) {
            // Native quirk: BITSET reads of absent bits return false unless
            // the whole bitset is empty, which returns null.
            return $this->type === self::BITSET && \count($this->data) > 0 ? false : null;
        }
        return $this->type === self::BITSET ? true : $this->data[$key];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $key = $this->coerceKey($offset);
        if ($this->type === self::BITSET) {
            if ((bool) $value) {
                $this->data[$key] = true;
                $this->sorted = false;
            } else {
                unset($this->data[$key]);
            }
            return;
        }
        $this->data[$key] = $this->coerceValue($value);
        $this->sorted = false;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$this->coerceKey($offset)]);
    }

    /* ── Serialization ────────────────────────────────────────── */

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function __serialize(): array
    {
        return ['type' => $this->type, 'data' => $this->toArray()];
    }

    public function __unserialize(array $data): void
    {
        if (!isset($data['type']) || !\is_int($data['type']) || !isset($data['data']) || !\is_array($data['data'])) {
            throw new \Exception('Invalid serialized Judy data');
        }
        $this->__construct($data['type']);
        $this->putAll($data['data']);
    }

    /* ── Batch operations ─────────────────────────────────────── */

    public function toArray(mixed $start = null, mixed $end = null): array
    {
        $this->assertRangeBounds('toArray', $start, $end);
        $this->ensureSorted();
        if ($start === null && $end === null) {
            if ($this->type === self::BITSET) {
                return \array_keys($this->data);
            }
            return $this->data;
        }
        $keys = $this->rangeKeys($start, $end);
        if ($this->type === self::BITSET) {
            return \array_values($keys);
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->data[$k];
        }
        return $out;
    }

    /** @param bool $optimizeIteration Accepted and ignored; see __construct(). */
    public static function fromArray(int $type, array $data, bool $optimizeIteration = false): static
    {
        $judy = new static($type);
        $judy->putAll($data);
        return $judy;
    }

    public function putAll(array $data): void
    {
        if ($this->type === self::BITSET) {
            foreach ($data as $index) {
                $this->data[(int) $index] = true;
            }
        } else {
            foreach ($data as $k => $v) {
                $this->data[$this->coerceKey($k)] = $this->coerceValue($v);
            }
        }
        $this->sorted = false;
    }

    public function getAll(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $k = $this->coerceKey($key);
            $result[$k] = $this->offsetGet($k);
        }
        return $result;
    }

    public function keys(mixed $start = null, mixed $end = null): array
    {
        $this->assertRangeBounds('keys', $start, $end);
        $this->ensureSorted();
        $keys = ($start === null && $end === null)
            ? \array_keys($this->data)
            : \array_values($this->rangeKeys($start, $end));

        /* Rows live in a PHP array, whose keys coerce canonical decimal
           strings to int — so the key "42" comes back from array_keys() as
           int(42). The native extension builds its list with
           add_next_index_string() and returns "42". Normalise through
           keyOut(), the same way key()/first()/searchNext() already do. */
        return \array_map([$this, 'keyOut'], $keys);
    }

    public function values(mixed $start = null, mixed $end = null): array
    {
        $this->assertRangeBounds('values', $start, $end);
        $this->ensureSorted();
        if ($start === null && $end === null) {
            if ($this->type === self::BITSET) {
                return \array_keys($this->data);
            }
            return \array_values($this->data);
        }
        $keys = $this->rangeKeys($start, $end);
        if ($this->type === self::BITSET) {
            return \array_values($keys);
        }
        $out = [];
        foreach ($keys as $k) {
            $out[] = $this->data[$k];
        }
        return $out;
    }

    public function increment(mixed $key, int $amount = 1): int
    {
        if (!\in_array($this->type, self::INCREMENTABLE, true)) {
            throw new \Exception('Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types');
        }
        $k = $this->coerceKey($key);
        $new = (int) ($this->data[$k] ?? 0) + $amount;
        $this->data[$k] = $new;
        $this->sorted = false;
        return $new;
    }

    /* ── Iterator ─────────────────────────────────────────────── */

    public function rewind(): void
    {
        $this->ensureSorted();
        $this->iterKeys = \array_keys($this->data);
        $this->iterPos = 0;
    }

    public function valid(): bool
    {
        return isset($this->iterKeys[$this->iterPos])
            && \array_key_exists($this->iterKeys[$this->iterPos], $this->data);
    }

    public function current(): mixed
    {
        if (!$this->valid()) {
            return null;
        }
        $key = $this->iterKeys[$this->iterPos];
        return $this->type === self::BITSET ? true : $this->data[$key];
    }

    public function key(): mixed
    {
        return $this->valid() ? $this->keyOut($this->iterKeys[$this->iterPos]) : null;
    }

    public function next(): void
    {
        $this->iterPos++;
    }

    /* ── Functional / aggregation ─────────────────────────────── */

    public function forEach(callable $callback): void
    {
        $this->ensureSorted();
        foreach ($this->data as $k => $v) {
            $callback($this->type === self::BITSET ? true : $v, $k);
        }
    }

    public function filter(callable $predicate): static
    {
        $result = new static($this->type);
        $this->ensureSorted();
        foreach ($this->data as $k => $v) {
            $value = $this->type === self::BITSET ? true : $v;
            if ($predicate($value, $k)) {
                $result->data[$k] = $this->type === self::BITSET ? true : $v;
            }
        }
        return $result;
    }

    public function map(callable $transform): static
    {
        $result = new static($this->type);
        $this->ensureSorted();
        foreach ($this->data as $k => $v) {
            $value = $this->type === self::BITSET ? true : $v;
            $mapped = $transform($value, $k);
            if ($this->type === self::BITSET) {
                if ((bool) $mapped) {
                    $result->data[$k] = true;
                }
            } else {
                $result->data[$k] = $this->coerceValue($mapped);
            }
        }
        return $result;
    }

    public function sumValues(): int|float
    {
        if ($this->type === self::BITSET) {
            return \count($this->data);
        }
        if (!\in_array($this->type, self::INT_VALUED, true)) {
            throw new \Exception('sumValues() is only supported for integer-valued Judy types');
        }
        return \array_sum($this->data);
    }

    public function averageValues(): ?float
    {
        if (\count($this->data) === 0) {
            return null;
        }
        if ($this->type === self::BITSET) {
            return 1.0;
        }
        if (!\in_array($this->type, self::INT_VALUED, true)) {
            throw new \Exception('averageValues() is only supported for integer-valued Judy types');
        }
        return \array_sum($this->data) / \count($this->data);
    }

    public function populationCount(mixed $start = 0, mixed $end = -1): int
    {
        if (!$this->intKeyed()) {
            throw new \Exception('populationCount() is only supported for integer-keyed Judy types');
        }
        if ($start === 0 && $end === -1) {
            return \count($this->data);
        }
        return \count($this->rangeKeys($start, $end));
    }

    public function deleteRange(mixed $start, mixed $end): int
    {
        $deleted = 0;
        foreach (\array_keys($this->data) as $k) {
            if ($this->cmpKeys($k, $start) >= 0 && $this->cmpKeys($k, $end) <= 0) {
                unset($this->data[$k]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /* ── Internals ────────────────────────────────────────────── */

    private function assertIntArg(mixed $index, string $method, bool $nullable): void
    {
        if (\is_int($index) || ($nullable && $index === null)) {
            return;
        }
        throw new \TypeError(sprintf(
            'Judy::%s(): Argument #1 ($index) must be of type int, %s given',
            $method,
            \get_debug_type($index)
        ));
    }

    private function intKeyed(): bool
    {
        return \in_array($this->type, self::INT_KEYED, true);
    }

    private function coerceKey(mixed $offset): int|string
    {
        if ($this->intKeyed()) {
            return (int) $offset;
        }
        return (string) $offset;
    }

    private function coerceValue(mixed $value): mixed
    {
        if (\in_array($this->type, self::INT_VALUED, true)) {
            return (int) $value;
        }
        return $value;
    }

    private function sortFlags(): int
    {
        return $this->intKeyed() ? SORT_NUMERIC : SORT_STRING;
    }

    private function ensureSorted(): void
    {
        if ($this->sorted) {
            return;
        }
        if ($this->intKeyed()) {
            // Unsigned key order, not PHP's signed numeric order — see cmpKeys().
            \uksort($this->data, fn(int|string $a, int|string $b): int => self::cmpUnsigned((int) $a, (int) $b));
        } else {
            \ksort($this->data, $this->sortFlags());
        }
        $this->sorted = true;
    }

    /**
     * Compare two integer keys as UNSIGNED machine words, which is what they are.
     *
     * A negative PHP int has its high bit set, so it addresses the top of the
     * key space: -1 is the largest key there is, and PHP_INT_MAX sits just below
     * the entire negative half. Signed comparison would order -1 first, which is
     * where the polyfill diverged once the extension started storing negative
     * offsets as keys (php-judy 2.5.0) instead of discarding them.
     *
     * Consequences that fall out of this and are pinned by the parity suite:
     * keys() puts negatives last, first()/last() follow, and size(0, -1) covers
     * the whole key space because -1 IS the maximum bound.
     */
    private static function cmpUnsigned(int $a, int $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if (($a < 0) !== ($b < 0)) {
            return $a < 0 ? 1 : -1;   // the negative one has the high bit set
        }
        return $a < $b ? -1 : 1;
    }

    private function cmpKeys(int|string $a, mixed $b): int
    {
        if ($this->intKeyed()) {
            return self::cmpUnsigned((int) $a, (int) $b);
        }
        return \strcmp((string) $a, (string) $b);
    }

    /** Native string-keyed types return keys as strings even when numeric. */
    private function keyOut(int|string $key): int|string
    {
        return $this->intKeyed() ? (int) $key : (string) $key;
    }

    private function seek(mixed $index, bool $inclusive, bool $forward): mixed
    {
        if (\count($this->data) === 0) {
            return null;
        }
        $this->ensureSorted();
        $keys = \array_keys($this->data);
        if ($index === null) {
            return $this->keyOut($forward ? $keys[0] : $keys[\count($keys) - 1]);
        }
        if ($forward) {
            foreach ($keys as $k) {
                $cmp = $this->cmpKeys($k, $index);
                if ($cmp > 0 || ($inclusive && $cmp === 0)) {
                    return $this->keyOut($k);
                }
            }
        } else {
            for ($i = \count($keys) - 1; $i >= 0; $i--) {
                $cmp = $this->cmpKeys($keys[$i], $index);
                if ($cmp < 0 || ($inclusive && $cmp === 0)) {
                    return $this->keyOut($keys[$i]);
                }
            }
        }
        return null;
    }

    /**
     * The next key upward in UNSIGNED order, or null past the top.
     *
     * PHP_INT_MAX (0x7fff…f) is followed by PHP_INT_MIN (0x8000…0), not by a
     * float: incrementing across that edge is where the scans used to promote to
     * double and emit "not representable as an int". -1 (0xffff…f) is the last
     * key there is, so there is nothing after it.
     */
    private static function unsignedSucc(int $i): ?int
    {
        if ($i === -1) {
            return null;
        }
        return $i === PHP_INT_MAX ? PHP_INT_MIN : $i + 1;
    }

    /** The next key downward in unsigned order, or null past the bottom (0). */
    private static function unsignedPred(int $i): ?int
    {
        if ($i === 0) {
            return null;
        }
        return $i === PHP_INT_MIN ? PHP_INT_MAX : $i - 1;
    }

    private function seekEmpty(?int $start, bool $forward): mixed
    {
        if (!$this->intKeyed() || $start === null) {
            return null;
        }
        $i = $start;
        while (true) {
            if (!\array_key_exists($i, $this->data)) {
                return $i;
            }
            $i = $forward ? self::unsignedSucc($i) : self::unsignedPred($i);
            if ($i === null) {
                return null;   // ran off the end of the key space
            }
        }
    }

    /**
     * Keys inside the inclusive [$start, $end] range, in stored order.
     *
     * One implementation behind size(), populationCount(), keys(), values() and
     * toArray(), so the five cannot drift apart. null on either side leaves it
     * unbounded.
     *
     * Integer bounds need no special case for negatives: cmpKeys() compares
     * integer keys as unsigned words, so a bound of -1 is already the maximum
     * (which is why size(0, -1) means "everything") and keys(-5, 10) is already
     * empty because the start sits above the end.
     */
    /**
     * String-keyed arrays compare bounds with strcmp(), so the extension rejects
     * a non-string bound outright rather than coercing it. null is always fine:
     * it means "unbounded on that side", not "a bound".
     */
    private function assertRangeBounds(string $method, mixed $start, mixed $end): void
    {
        if ($this->intKeyed()) {
            return;
        }
        foreach ([$start, $end] as $bound) {
            if ($bound !== null && !\is_string($bound)) {
                throw new \TypeError(
                    "Judy::$method() expects string arguments for string-keyed arrays"
                );
            }
        }
    }

    private function rangeKeys(mixed $start, mixed $end): array
    {
        $this->ensureSorted();
        $out = [];
        foreach (\array_keys($this->data) as $k) {
            if ($start !== null && $this->cmpKeys($k, $start) < 0) {
                continue;
            }
            if ($end !== null && $this->cmpKeys($k, $end) > 0) {
                continue;
            }
            $out[] = $k;
        }
        return $out;
    }

    private function assertSameType(Judy|\Judy $other): void
    {
        if ($this->type !== $other->getType()) {
            throw new \Exception('Both Judy arrays must be the same type for set operations');
        }
        if ($this->type !== self::BITSET && !\in_array($this->type, self::INT_VALUED, true)) {
            throw new \Exception('Set operations are only supported on BITSET and integer-valued arrays');
        }
    }

    /**
     * Entries of a polyfill or native instance as key => value
     * (BITSET: index => true), so mixed usage works when ext-judy is loaded.
     */
    private function entriesOf(self|\Judy $other): array
    {
        if ($other instanceof self) {
            return $other->data;
        }
        $arr = $other->toArray();
        if ($other->getType() === self::BITSET) {
            $out = [];
            foreach ($arr as $index) {
                $out[(int) $index] = true;
            }
            return $out;
        }
        return $arr;
    }

    /**
     * Rough byte estimate standing in for the extension's real accounting.
     */
    private function estimateBytes(): int
    {
        $n = \count($this->data);
        return $n === 0 ? 0 : 40 + 9 * $n;
    }

    /**
     * Payload-only estimate for string-keyed types, composed the way the
     * extension composes its own: the stored key bytes, counted twice for the
     * _HASH types because those hold each key in both the value store and the
     * key index, plus one machine word per value slot, plus a zval box for each
     * _MIXED value. Like the extension's, it excludes container overhead and is
     * therefore a lower bound.
     */
    private function estimateStringBytes(): int
    {
        if ($this->data === []) {
            return 0;
        }

        $hashed = \in_array($this->type, [
            self::STRING_TO_INT_HASH,
            self::STRING_TO_MIXED_HASH,
        ], true);
        $mixed = !\in_array($this->type, self::INT_VALUED, true);

        $bytes = 0;
        foreach (\array_keys($this->data) as $k) {
            $keyBytes = \strlen((string) $k);
            $bytes += $hashed ? $keyBytes * 2 : $keyBytes;
            $bytes += \PHP_INT_SIZE;        // one word per value slot
            if ($mixed) {
                $bytes += 16;               // the zval box the extension allocates
            }
        }
        return $bytes;
    }
}
