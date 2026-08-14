<?php

namespace Orieg\JudyPolyfill;

/**
 * Pure-PHP implementation of the Judy class from the judy extension
 * (https://github.com/orieg/php-judy).
 *
 * API-compatible with ext-judy 2.4; backed by a native PHP array, so it
 * provides compatibility, not the extension's memory/performance profile.
 * Behavioral notes and known divergences are documented in the README.
 */
class Judy implements \ArrayAccess, \Countable, \Iterator, \JsonSerializable
{
    public const POLYFILL_VERSION = '2.4.2-polyfill';

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

    public function __construct(int $type)
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

    public function free(): int
    {
        $bytes = $this->estimateBytes();
        $this->data = [];
        $this->sorted = true;
        return $bytes;
    }

    public function memoryUsage(): ?int
    {
        if (!$this->intKeyed()) {
            return null; // matches ext: JudySL/JudyHS provide no accounting
        }
        return $this->estimateBytes();
    }

    public function size(mixed $index_start = 0, mixed $index_end = -1): int
    {
        if ($index_start === 0 && $index_end === -1) {
            return \count($this->data);
        }
        return $this->countRange($index_start, $index_end);
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
        return $this->seekEmpty((int) $index + 1, forward: true);
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
        return $this->seekEmpty((int) $index - 1, forward: false);
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

    public function toArray(): array
    {
        $this->ensureSorted();
        if ($this->type === self::BITSET) {
            return \array_keys($this->data);
        }
        return $this->data;
    }

    public static function fromArray(int $type, array $data): static
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

    public function keys(): array
    {
        $this->ensureSorted();
        return \array_keys($this->data);
    }

    public function values(): array
    {
        $this->ensureSorted();
        if ($this->type === self::BITSET) {
            return \array_keys($this->data);
        }
        return \array_values($this->data);
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
        return $this->countRange($start, $end);
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
        if (!$this->sorted) {
            \ksort($this->data, $this->sortFlags());
            $this->sorted = true;
        }
    }

    private function cmpKeys(int|string $a, mixed $b): int
    {
        if ($this->intKeyed()) {
            return (int) $a <=> (int) $b;
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

    private function seekEmpty(int $start, bool $forward): mixed
    {
        if (!$this->intKeyed()) {
            return null;
        }
        $i = $start;
        while (true) {
            if (!\array_key_exists($i, $this->data)) {
                return $i;
            }
            $i += $forward ? 1 : -1;
        }
    }

    private function countRange(mixed $start, mixed $end): int
    {
        if ($end === -1 && $this->intKeyed()) {
            $end = PHP_INT_MAX;
        }
        $count = 0;
        foreach (\array_keys($this->data) as $k) {
            if ($this->cmpKeys($k, $start) >= 0 && $this->cmpKeys($k, $end) <= 0) {
                $count++;
            }
        }
        return $count;
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
}
