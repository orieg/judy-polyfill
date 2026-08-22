<?php
/**
 * Extension-free behavior tests: assert the polyfill's documented semantics
 * directly (expected values verified against ext-judy 2.4.2 by tests/parity.php).
 *
 * Run: php tests/behavior.php
 */

require __DIR__ . '/../src/Judy.php';
require __DIR__ . '/../src/bootstrap.php';

$failures = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL $label\n  expected: ", json_encode($expected), "\n  actual:   ", json_encode($actual), "\n";
    }
}

function throws(string $label, string $class, callable $fn): void
{
    global $failures;
    try {
        $fn();
        $failures++;
        echo "FAIL $label: expected $class, nothing thrown\n";
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            $failures++;
            echo "FAIL $label: expected $class, got ", get_class($e), "\n";
        }
    }
}

/** Assert both the exception class AND its message — the message IS the parity. */
function throwsWith(string $label, string $class, string $message, callable $fn): void
{
    global $failures;
    try {
        $fn();
        $failures++;
        echo "FAIL $label: expected $class, nothing thrown\n";
        return;
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            $failures++;
            echo "FAIL $label: expected $class, got ", get_class($e), ": ", $e->getMessage(), "\n";
            return;
        }
        if ($e->getMessage() !== $message) {
            $failures++;
            echo "FAIL $label: message\n  expected: ", json_encode($message), "\n  actual:   ", json_encode($e->getMessage()), "\n";
        }
    }
}

$judyClass = \Orieg\JudyPolyfill\Judy::class;

// Bootstrap aliases the global names when ext-judy is absent.
if (!extension_loaded('judy')) {
    check('global alias', true, class_exists('Judy'));
    check('judy_version()', \Orieg\JudyPolyfill\Judy::POLYFILL_VERSION, judy_version());
    check('judy_type()', Judy::INT_TO_INT, judy_type(new Judy(Judy::INT_TO_INT)));
}

// Core int-keyed behavior
$j = new $judyClass($judyClass::INT_TO_INT);
$j[5] = 50; $j[1] = 10; $j[300] = 3000;
check('get', 50, $j[5]);
check('absent get', null, $j[999]);
check('count', 3, count($j));
check('toArray sorted', [1 => 10, 5 => 50, 300 => 3000], $j->toArray());
check('first/last', [1, 300], [$j->first(), $j->last()]);
check('first(2)', 5, $j->first(2));
check('searchNext(5)', 300, $j->searchNext(5));
check('prev(300)', 5, $j->prev(300));
check('byCount(2)', 5, $j->byCount(2));
check('firstEmpty', 0, $j->firstEmpty());
check('firstEmpty(1)', 2, $j->firstEmpty(1));
check('lastEmpty default', -1, $j->lastEmpty());
check('size ranged', 2, $j->size(2, 400));
check('size unbounded', 3, $j->size());
check('size(0, -1) is everything', 3, $j->size(0, -1));
check('increment', [1, 6], [$j->increment(77), $j->increment(77, 5)]);
check('sumValues', 10 + 50 + 3000 + 6, $j->sumValues());
unset($j[77]);

// Iteration order and callback signature ($value, $key)
$seen = [];
$j->forEach(function ($v, $k) use (&$seen) { $seen[] = [$k, $v]; });
check('forEach', [[1, 10], [5, 50], [300, 3000]], $seen);
$iter = [];
foreach ($j as $k => $v) { $iter[] = [$k, $v]; }
check('foreach', [[1, 10], [5, 50], [300, 3000]], $iter);

// Coercion (matches native extension)
$c = new $judyClass($judyClass::INT_TO_INT);
$c['abc'] = 7;
check('string key coerces to 0', [0 => 7], $c->toArray());
$c[3] = 'xy';
check('string value coerces to 0', 0, $c[3]);

// BITSET semantics
$b = new $judyClass($judyClass::BITSET);
check('empty bitset get', null, $b[4]);
$b[9] = true; $b[2] = true; $b[5] = false;
check('bitset toArray is index list', [2, 9], $b->toArray());
check('bitset absent get is false when populated', false, $b[4]);
check('bitset values == keys', [2, 9], $b->values());
check('bitset popcount', 2, $b->sumValues());
check('bitset fromArray indices', [4, 7], $judyClass::fromArray($judyClass::BITSET, [4, 7])->toArray());

// String-keyed: sorted navigation, numeric-string keys come back as strings
$s = new $judyClass($judyClass::STRING_TO_INT);
$s['zz'] = 1; $s['aa'] = 2; $s['123'] = 9;
check('trie first is lexicographic', '123', $s->first());
check('trie searchNext', 'zz', $s->searchNext('aa'));
check('string byCount null', null, $s->byCount(1));
// The extension gained approximate string-keyed accounting, so this reports an
// int rather than null now; only the emptied case is pinned to an exact value.
check('string memoryUsage is an int', 'integer', gettype($s->memoryUsage()));
check('string memoryUsage grows', true, $s->memoryUsage() > 0);
check('emptied string memoryUsage is 0', 0, (new $judyClass($judyClass::STRING_TO_INT))->memoryUsage());
check('getAll', ['aa' => 2, 'missing' => null], $s->getAll(['aa', 'missing']));
// size() counts a string range rather than ignoring the bounds (ext 2.5.0).
check('string size unbounded', 3, $s->size());
check('string size ranged', 1, $s->size('a', 'b'));
check('string size agrees with keys', true, $s->size('a', 'b') === count($s->keys('a', 'b')));

/* String keys must not contain 0x00 (ext-judy 2.5.1, php-judy#117).
 *
 * A PHP array would store "ab\0cd" perfectly well; JudySL indexes
 * NUL-TERMINATED C strings and cannot, and the hash and adaptive types share
 * that trie for every seek and range. Asserted here as well as in the parity
 * suite because parity SKIPS this until PIE ships 2.5.1, and an unasserted
 * rejection is one refactor away from disappearing. The two adaptive types
 * share one message; the other four name themselves. */
$nulKey = "ab\x00cd";
foreach ([
    $judyClass::STRING_TO_INT            => 'Judy STRING_TO_INT keys must not contain embedded null bytes',
    $judyClass::STRING_TO_MIXED          => 'Judy STRING_TO_MIXED keys must not contain embedded null bytes',
    $judyClass::STRING_TO_MIXED_HASH     => 'Judy STRING_TO_MIXED_HASH keys must not contain embedded null bytes',
    $judyClass::STRING_TO_INT_HASH       => 'Judy STRING_TO_INT_HASH keys must not contain embedded null bytes',
    $judyClass::STRING_TO_MIXED_ADAPTIVE => 'Judy adaptive keys must not contain embedded null bytes',
    $judyClass::STRING_TO_INT_ADAPTIVE   => 'Judy adaptive keys must not contain embedded null bytes',
    $judyClass::STRING_TO_ENTRY          => 'Judy STRING_TO_ENTRY keys must not contain embedded null bytes',
] as $nulType => $message) {
    $msg = function (callable $fn): string {
        try {
            $fn();
            return 'nothing thrown';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    };
    $populated = function () use ($judyClass, $nulType) {
        $n = new $judyClass($nulType);
        $n['aa'] = 1;
        $n['zz'] = 2;
        return $n;
    };
    foreach ([
        'offsetSet'   => function () use ($populated, $nulKey) { $n = $populated(); $n[$nulKey] = 1; },
        'offsetGet'   => fn() => $populated()[$nulKey],
        'offsetExists' => fn() => isset($populated()[$nulKey]),
        'offsetUnset' => function () use ($populated, $nulKey) { $n = $populated(); unset($n[$nulKey]); },
        'first'       => fn() => $populated()->first($nulKey),
        'last'        => fn() => $populated()->last($nulKey),
        'searchNext'  => fn() => $populated()->searchNext($nulKey),
        'prev'        => fn() => $populated()->prev($nulKey),
        'keys start'  => fn() => $populated()->keys($nulKey, null),
        'keys end'    => fn() => $populated()->keys(null, $nulKey),
        'values'      => fn() => $populated()->values($nulKey, null),
        'toArray'     => fn() => $populated()->toArray($nulKey, null),
        'size'        => fn() => $populated()->size($nulKey, null),
        'slice'       => fn() => $populated()->slice($nulKey, 'zz'),
        'deleteRange' => fn() => $populated()->deleteRange('aa', $nulKey),
        'getAll'      => fn() => $populated()->getAll([$nulKey]),
        'putAll'      => fn() => $populated()->putAll([$nulKey => 1]),
        'fromArray'   => fn() => $judyClass::fromArray($nulType, [$nulKey => 1]),
    ] as $label => $call) {
        check("NUL rejected by $label on type $nulType", $message, $msg($call));
    }
    // Not a write path, so it is guarded on all seven; increment() reports the
    // type restriction first where the type cannot increment at all.
    check("NUL rejected by increment on type $nulType",
        in_array($nulType, [$judyClass::STRING_TO_INT, $judyClass::STRING_TO_INT_HASH], true)
            ? $message
            : 'Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types',
        $msg(fn() => $populated()->increment($nulKey)));

    // The three read/delete offset handlers answer an EMPTY array before they
    // look at the offset, so there the unrepresentable key reads back absent.
    $emptied = new $judyClass($nulType);
    $emptied['gone'] = 1;
    unset($emptied['gone']);
    check("NUL reads absent on empty type $nulType",
        [null, false, 0],
        (function () use ($emptied, $nulKey) {
            $got = $emptied[$nulKey];
            $has = isset($emptied[$nulKey]);
            unset($emptied[$nulKey]);
            return [$got, $has, count($emptied)];
        })());
    // Position is irrelevant, and the empty string is still a fine key.
    foreach (["\x00lead", "trail\x00", "\x00"] as $variant) {
        check("NUL anywhere rejected on type $nulType", $message,
            $msg(function () use ($judyClass, $nulType, $variant) {
                $n = new $judyClass($nulType);
                $n[$variant] = 1;
            }));
    }
    $blank = new $judyClass($nulType);
    $blank[''] = 7;
    check("empty string is a key on type $nulType", [1, [''], 7], [count($blank), $blank->keys(), $blank['']]);
}
// 0x00 is the ONLY rejected byte: 0x80-0xFF store and sort in unsigned order,
// which is what prefix-successor range bounds are carry arithmetic over.
$hi = new $judyClass($judyClass::STRING_TO_MIXED);
foreach (["\x7f", "\x80", "\xfe", "\xff", "ab\xff", "ac"] as $i => $hiKey) {
    $hi[$hiKey] = $i;
}
// 0x80 sorts ABOVE 0x7f, which a signed-char comparison would get backwards.
check('high bytes sort unsigned',
    ['6162ff', '6163', '7f', '80', 'fe', 'ff'],
    array_map('bin2hex', $hi->keys()));
check('high bytes round-trip', [true, 3, "\xff"], [isset($hi["\xff"]), $hi["\xff"], $hi->last()]);
// Everything under the prefix "ab" ends at "ab\xff"; "ac" is the successor.
check('prefix successor bound',
    [['6162ff'], ['6162ff', '6163']],
    [array_map('bin2hex', $hi->keys('ab', "ab\xff")), array_map('bin2hex', $hi->keys('ab', 'ac'))]);
check('high-byte range', 3, $hi->size("\x80", "\xff"));

// ── ArrayAccess offsets are NOT coerced on string-keyed types ──────
// The one place the extension refuses to coerce. Everything else on those
// types casts: putAll([1 => ...]) stores "1", and so do getAll(), fromArray(),
// increment() and the seeks. Both halves are asserted, because "tidying up"
// either direction would break callers written against the extension.
const OFFSET_TYPE_ERROR = 'Judy offset must be of type string for string-based arrays';

$stringKeyed = [
    $judyClass::STRING_TO_INT, $judyClass::STRING_TO_MIXED,
    $judyClass::STRING_TO_MIXED_HASH, $judyClass::STRING_TO_INT_HASH,
    $judyClass::STRING_TO_MIXED_ADAPTIVE, $judyClass::STRING_TO_INT_ADAPTIVE,
    $judyClass::STRING_TO_ENTRY,
];

foreach ($stringKeyed as $sType) {
    $intValued = in_array($sType, [
        $judyClass::STRING_TO_INT, $judyClass::STRING_TO_INT_HASH,
        $judyClass::STRING_TO_INT_ADAPTIVE,
    ], true);
    $v = $intValued ? 1 : 'v';
    $full = function () use ($judyClass, $sType, $v) {
        $n = new $judyClass($sType);
        $n['aa'] = $v;
        $n['zz'] = $v;
        return $n;
    };

    foreach (['int' => 42, 'zero' => 0, 'float' => 1.5, 'true' => true, 'false' => false,
              'null' => null, 'array' => ['x'], 'object' => new stdClass()] as $what => $offset) {
        // Populated: all four handlers reject, before any key coercion.
        throwsWith("offset $what rejected by offsetSet on type $sType", \TypeError::class, OFFSET_TYPE_ERROR,
            function () use ($full, $offset, $v) { $n = $full(); $n[$offset] = $v; });
        throwsWith("offset $what rejected by offsetGet on type $sType", \TypeError::class, OFFSET_TYPE_ERROR,
            fn() => $full()[$offset]);
        throwsWith("offset $what rejected by offsetExists on type $sType", \TypeError::class, OFFSET_TYPE_ERROR,
            fn() => isset($full()[$offset]));
        throwsWith("offset $what rejected by offsetUnset on type $sType", \TypeError::class, OFFSET_TYPE_ERROR,
            function () use ($full, $offset) { $n = $full(); unset($n[$offset]); });

        // Empty: the three read/delete handlers answer before they look at the
        // offset at all — the same reprieve the NUL guard gets — while
        // offsetSet() has no such path and still throws.
        $blank = new $judyClass($sType);
        check("offset $what reads absent on empty type $sType", [null, false, 0],
            (function () use ($blank, $offset) {
                $got = $blank[$offset];
                $has = isset($blank[$offset]);
                unset($blank[$offset]);
                return [$got, $has, count($blank)];
            })());
        throwsWith("offset $what still rejected by offsetSet on empty type $sType",
            \TypeError::class, OFFSET_TYPE_ERROR,
            function () use ($judyClass, $sType, $offset, $v) { $n = new $judyClass($sType); $n[$offset] = $v; });
    }

    // The reprieve holds however the array became empty, not only when it was
    // never written to.
    foreach ([
        'unset'       => function ($n) { unset($n['aa'], $n['zz']); },
        'deleteRange' => function ($n) { $n->deleteRange('a', 'zzz'); },
        'free'        => function ($n) { $n->free(); },
    ] as $how => $drain) {
        $drained = $full();
        $drain($drained);
        check("offset int reads absent after $how on type $sType", [0, null, false],
            [count($drained), $drained[42], isset($drained[42])]);
    }

    // The other half: every non-offset key path still coerces.
    $p = new $judyClass($sType);
    $p->putAll([1 => $v, true => $v]);
    check("putAll coerces keys on type $sType", ['1'], $p->keys());
    check("fromArray coerces keys on type $sType", ['1'],
        $judyClass::fromArray($sType, [1 => $v])->keys());
    $g = $full();
    $g['1'] = $v;
    check("getAll coerces keys on type $sType", ['1' => $v], $g->getAll([1]));
    check("seeks coerce on type $sType", ['aa', 'aa'],
        [$full()->first(1), $full()->searchNext(1)]);
    // increment() is narrower than "int-valued" — the adaptive int type cannot
    // increment at all — so only the two that can are asserted here.
    if (in_array($sType, [$judyClass::STRING_TO_INT, $judyClass::STRING_TO_INT_HASH], true)) {
        $inc = $full();
        $inc->increment(1);
        check("increment coerces on type $sType", ['1', 'aa', 'zz'], $inc->keys());
    }

    // A numeric STRING offset is a string, so it is accepted — which is what
    // makes the rejection a type rule and not a "looks numeric" rule.
    $numeric = $full();
    $numeric['42'] = $v;
    check("numeric string offset accepted on type $sType", [3, $v, true],
        [count($numeric), $numeric['42'], isset($numeric['42'])]);
}

// Integer-keyed types coerce their offsets, as they always did.
foreach ([$judyClass::BITSET, $judyClass::INT_TO_INT,
          $judyClass::INT_TO_MIXED, $judyClass::INT_TO_PACKED] as $iType) {
    $iv = $iType === $judyClass::BITSET ? true : ($iType === $judyClass::INT_TO_INT ? 1 : 'v');
    $ik = new $judyClass($iType);
    $ik['3'] = $iv;
    $ik[4.9] = $iv;
    $ik[true] = $iv;
    check("int-keyed type $iType coerces offsets", [[1, 3, 4], true, true],
        [$ik->keys(), isset($ik[1]), isset($ik['3'])]);
}

// ── slice() bound types ───────────────────────────────────────────
// slice() rejects a non-string bound like keys()/values()/toArray()/size() do,
// with two differences that are asserted rather than assumed: it rejects null
// too (its bounds are required, not "unbounded"), and it has no empty-array
// short circuit in front of it. deleteRange() takes the same two bounds and
// does NOT type-check them, which is the control.
foreach ($stringKeyed as $sType) {
    $intValued = in_array($sType, [
        $judyClass::STRING_TO_INT, $judyClass::STRING_TO_INT_HASH,
        $judyClass::STRING_TO_INT_ADAPTIVE,
    ], true);
    $ranged = function () use ($judyClass, $sType, $intValued) {
        $n = new $judyClass($sType);
        foreach (['aa', 'mm', 'zz'] as $i => $k) {
            $n[$k] = $intValued ? $i : "v$i";
        }
        return $n;
    };
    foreach (['int, int' => [1, 2], 'int, string' => [1, 'zz'], 'string, int' => ['aa', 2],
              'float' => [1.5, 2.5], 'bool' => [true, false], 'array' => [['x'], 'zz'],
              'null, null' => [null, null], 'null, string' => [null, 'zz'],
              'string, null' => ['aa', null]] as $what => [$lo, $hi]) {
        throwsWith("slice($what) rejected on type $sType", \TypeError::class,
            'Judy::slice() expects string arguments for string-keyed arrays',
            fn() => $ranged()->slice($lo, $hi));
        // No empty-array reprieve: the guard runs either way.
        throwsWith("slice($what) rejected on empty type $sType", \TypeError::class,
            'Judy::slice() expects string arguments for string-keyed arrays',
            fn() => (new $judyClass($sType))->slice($lo, $hi));
    }
    // String bounds work, including numeric-looking ones.
    check("slice string bounds on type $sType", [['aa', 'mm'], []],
        [$ranged()->slice('aa', 'mm')->keys(), $ranged()->slice('1', '9')->keys()]);

    // The bounded reads name themselves in the same complaint...
    foreach (['keys', 'values', 'toArray', 'size'] as $method) {
        throwsWith("$method(int, int) rejected on type $sType", \TypeError::class,
            "Judy::$method() expects string arguments for string-keyed arrays",
            fn() => $ranged()->$method(1, 2));
    }
    // ...but they read null as "unbounded", where slice() rejects it.
    $rd = $ranged();
    check("bounded reads accept null bounds on type $sType",
        [['aa', 'mm', 'zz'], 3, 3, 3],
        [$rd->keys(null, null), count($rd->values(null, null)),
         count($rd->toArray(null, null)), $rd->size(null, null)]);

    // The control: deleteRange() coerces its bounds and deletes.
    check("deleteRange coerces bounds on type $sType", [3, []],
        (function () use ($ranged) {
            $n = $ranged();
            $deleted = $n->deleteRange(1, 'zzz');
            return [$deleted, $n->keys()];
        })());
    check("deleteRange int bounds delete nothing on type $sType", [0, ['aa', 'mm', 'zz']],
        (function () use ($ranged) {
            $n = $ranged();
            $deleted = $n->deleteRange(1, 2);
            return [$deleted, $n->keys()];
        })());
}

// Integer-keyed slice takes integer bounds, as always.
$is = new $judyClass($judyClass::INT_TO_MIXED);
foreach ([1, 5, 10] as $i) {
    $is[$i] = "v$i";
}
check('int-keyed slice takes int bounds', [[1, 5], [1, 5]],
    [$is->slice(1, 5)->keys(), $is->slice('1', '5')->keys()]);

// Hash types iterate sorted too (verified against native)
$h = new $judyClass($judyClass::STRING_TO_INT_HASH);
$h['z'] = 26; $h['a'] = 1; $h['m'] = 13;
check('hash keys sorted', ['a', 'm', 'z'], $h->keys());

// Set operations: allowed on BITSET + integer-valued only
$a1 = $judyClass::fromArray($judyClass::INT_TO_INT, [1 => 1, 2 => 2, 3 => 3]);
$a2 = $judyClass::fromArray($judyClass::INT_TO_INT, [3 => 30, 4 => 40]);
check('union', [1 => 1, 2 => 2, 3 => 30, 4 => 40], $a1->union($a2)->toArray());
check('intersect keeps left values', [3 => 3], $a1->intersect($a2)->toArray());
check('diff', [1 => 1, 2 => 2], $a1->diff($a2)->toArray());
check('xor', [1 => 1, 2 => 2, 4 => 40], $a1->xor($a2)->toArray());
throws('setops on MIXED', \Exception::class,
    fn() => (new $judyClass($judyClass::INT_TO_MIXED))->union(new $judyClass($judyClass::INT_TO_MIXED)));
throws('setops cross-type', \Exception::class,
    fn() => (new $judyClass($judyClass::INT_TO_INT))->union(new $judyClass($judyClass::INT_TO_MIXED)));
throws('increment on MIXED', \Exception::class,
    fn() => (new $judyClass($judyClass::INT_TO_MIXED))->increment(1));
throws('populationCount on string', \Exception::class, fn() => $s->populationCount());
throws('nextEmpty string arg', \TypeError::class, fn() => $s->nextEmpty('a'));
throws('invalid ctor', \Exception::class, fn() => new $judyClass(99));

// Serialization
$u = unserialize(serialize($j));
check('serialize roundtrip', [$j->getType(), $j->toArray()], [$u->getType(), $u->toArray()]);
check('json', json_encode($j->toArray()), json_encode($j));
check('equals', true, $j->equals($u));

// ── STRING_TO_ENTRY cache entry and TTL semantics ─────────────────
$e = new $judyClass($judyClass::STRING_TO_ENTRY);
check('STRING_TO_ENTRY getType', $judyClass::STRING_TO_ENTRY, $e->getType());

// Basic set without TTL / flags
$e->set("user:1", ["id" => 1, "name" => "Alice"]);
check('STRING_TO_ENTRY get basic', ["id" => 1, "name" => "Alice"], $e->get("user:1"));
check('STRING_TO_ENTRY getExpiry basic', 0, $e->getExpiry("user:1"));
check('STRING_TO_ENTRY getFlags basic', 0, $e->getFlags("user:1"));

// Set with TTL and flags
$e->set("session:abc", "data_payload", ttl: 3600, flags: 42);
$expiry = null;
$flags = null;
$val = $e->get("session:abc", $expiry, $flags);
check('STRING_TO_ENTRY get with refs', "data_payload", $val);
check('STRING_TO_ENTRY expiry > time', true, $expiry > time());
check('STRING_TO_ENTRY flags == 42', 42, $flags);
check('STRING_TO_ENTRY getExpiry matches', $expiry, $e->getExpiry("session:abc"));
check('STRING_TO_ENTRY getFlags matches', 42, $e->getFlags("session:abc"));

// getEntry
$entry = $e->getEntry("session:abc");
check('STRING_TO_ENTRY getEntry value', "data_payload", $entry["value"] ?? null);
check('STRING_TO_ENTRY getEntry expires_at', $expiry, $entry["expires_at"] ?? null);
check('STRING_TO_ENTRY getEntry flags', 42, $entry["flags"] ?? null);
check('STRING_TO_ENTRY getEntry is_expired', false, $entry["is_expired"] ?? null);

// Non-existent key
check('STRING_TO_ENTRY get non-existent', null, $e->get("non_existent"));
check('STRING_TO_ENTRY getEntry non-existent', null, $e->getEntry("non_existent"));
check('STRING_TO_ENTRY getExpiry non-existent', null, $e->getExpiry("non_existent"));
check('STRING_TO_ENTRY getFlags non-existent', null, $e->getFlags("non_existent"));

// ArrayAccess and Countable support
$ea = new $judyClass($judyClass::STRING_TO_ENTRY);
$ea["k1"] = "val1";
$ea["k2"] = 12345;
$ea["k3"] = ["nested" => true];
check('STRING_TO_ENTRY count', 3, count($ea));
check('STRING_TO_ENTRY isset k1', true, isset($ea["k1"]));
check('STRING_TO_ENTRY isset k2', true, isset($ea["k2"]));
check('STRING_TO_ENTRY isset k4', false, isset($ea["k4"]));
check('STRING_TO_ENTRY read k1', "val1", $ea["k1"]);
check('STRING_TO_ENTRY read k2', 12345, $ea["k2"]);
check('STRING_TO_ENTRY read k3', ["nested" => true], $ea["k3"]);
unset($ea["k2"]);
check('STRING_TO_ENTRY count after unset', 2, count($ea));
check('STRING_TO_ENTRY isset after unset', false, isset($ea["k2"]));
check('STRING_TO_ENTRY read after unset', null, $ea["k2"]);

// TTL expiration and pruneExpired()
$ep = new $judyClass($judyClass::STRING_TO_ENTRY);
$now = 1700000000;
$ep->set("k1", "val1", ttl: 10);
$ep->set("k2", "val2", ttl: 100);
$ep->set("k3", "val3", ttl: 0);
$exp1 = $ep->getExpiry("k1");
$exp2 = $ep->getExpiry("k2");
$exp3 = $ep->getExpiry("k3");
check('STRING_TO_ENTRY exp1 > 0', true, $exp1 > 0);
check('STRING_TO_ENTRY exp2 > exp1', true, $exp2 > $exp1);
check('STRING_TO_ENTRY exp3 == 0', 0, $exp3);
check('STRING_TO_ENTRY count before prune', 3, count($ep));

$pruned = $ep->pruneExpired($exp1 - 5);
check('STRING_TO_ENTRY prune before exp1', 0, $pruned);
check('STRING_TO_ENTRY count after prune 0', 3, count($ep));

$pruned = $ep->pruneExpired($exp1 + 1);
check('STRING_TO_ENTRY prune exp1', 1, $pruned);
check('STRING_TO_ENTRY count after prune 1', 2, count($ep));
check('STRING_TO_ENTRY k1 absent after prune', null, $ep->get("k1"));
check('STRING_TO_ENTRY isset k1 false after prune', false, isset($ep["k1"]));
check('STRING_TO_ENTRY k2 present', "val2", $ep->get("k2"));
check('STRING_TO_ENTRY k3 present', "val3", $ep->get("k3"));

$pruned = $ep->pruneExpired($exp2 + 1);
check('STRING_TO_ENTRY prune exp2', 1, $pruned);
check('STRING_TO_ENTRY count after prune 2', 1, count($ep));
check('STRING_TO_ENTRY k2 absent after prune', null, $ep->get("k2"));
check('STRING_TO_ENTRY k3 still present', "val3", $ep->get("k3"));

// Iterator, navigation and toArray()
$en = new $judyClass($judyClass::STRING_TO_ENTRY);
$en->set("charlie", 300);
$en->set("alice", 100);
$en->set("bob", 200);

$iterOut = [];
foreach ($en as $k => $v) {
    $iterOut[$k] = $v;
}
check('STRING_TO_ENTRY foreach sorted', ["alice" => 100, "bob" => 200, "charlie" => 300], $iterOut);
check('STRING_TO_ENTRY first', "alice", $en->first());
check('STRING_TO_ENTRY searchNext', "bob", $en->searchNext("alice"));
check('STRING_TO_ENTRY last', "charlie", $en->last());
check('STRING_TO_ENTRY prev', "bob", $en->prev("charlie"));
check('STRING_TO_ENTRY toArray', ["alice" => 100, "bob" => 200, "charlie" => 300], $en->toArray());
check('STRING_TO_ENTRY keys', ["alice", "bob", "charlie"], $en->keys());
check('STRING_TO_ENTRY values', [100, 200, 300], $en->values());

// Clone and slice
$es = new $judyClass($judyClass::STRING_TO_ENTRY);
$es->set("a", 1, ttl: 50, flags: 1);
$es->set("b", 2, ttl: 100, flags: 2);
$es->set("c", 3, ttl: 150, flags: 3);

$cloned = clone $es;
check('STRING_TO_ENTRY clone type', $judyClass::STRING_TO_ENTRY, $cloned->getType());
check('STRING_TO_ENTRY clone count', 3, count($cloned));
check('STRING_TO_ENTRY clone get', 2, $cloned->get("b"));
check('STRING_TO_ENTRY clone getFlags', 2, $cloned->getFlags("b"));
check('STRING_TO_ENTRY clone getExpiry', $es->getExpiry("b"), $cloned->getExpiry("b"));

$sliced = $es->slice("a", "b");
check('STRING_TO_ENTRY slice type', $judyClass::STRING_TO_ENTRY, $sliced->getType());
check('STRING_TO_ENTRY slice count', 2, count($sliced));
check('STRING_TO_ENTRY slice keys', ["a", "b"], $sliced->keys());
check('STRING_TO_ENTRY slice getFlags a', 1, $sliced->getFlags("a"));
check('STRING_TO_ENTRY slice getFlags b', 2, $sliced->getFlags("b"));

// Serialization
$es_ser = unserialize(serialize($es));
check('STRING_TO_ENTRY unserialize type', $judyClass::STRING_TO_ENTRY, $es_ser->getType());
check('STRING_TO_ENTRY unserialize count', 3, count($es_ser));
check('STRING_TO_ENTRY unserialize get a', 1, $es_ser["a"]);
check('STRING_TO_ENTRY unserialize get b', 2, $es_ser["b"]);
check('STRING_TO_ENTRY unserialize get c', 3, $es_ser["c"]);

// TypeError when entry methods called on non-STRING_TO_ENTRY
$intJ = new $judyClass($judyClass::INT_TO_INT);
throwsWith('set on INT_TO_INT', \TypeError::class,
    'Judy::set() is only supported for STRING_TO_ENTRY arrays',
    fn() => $intJ->set("key", 123));
throwsWith('get on INT_TO_INT', \TypeError::class,
    'Judy::get() is only supported for STRING_TO_ENTRY arrays',
    fn() => $intJ->get("key"));
throwsWith('pruneExpired on INT_TO_INT', \TypeError::class,
    'Judy::pruneExpired() is only supported for STRING_TO_ENTRY arrays',
    fn() => $intJ->pruneExpired());
throwsWith('getEntry on INT_TO_INT', \TypeError::class,
    'Judy::getEntry() is only supported for STRING_TO_ENTRY arrays',
    fn() => $intJ->getEntry("key"));
throwsWith('getExpiry on INT_TO_INT', \TypeError::class,
    'Judy::getExpiry() is only supported for STRING_TO_ENTRY arrays',
    fn() => $intJ->getExpiry("key"));
throwsWith('getFlags on INT_TO_INT', \TypeError::class,
    'Judy::getFlags() is only supported for STRING_TO_ENTRY arrays',
    fn() => $intJ->getFlags("key"));

// Functional methods on STRING_TO_ENTRY
$ef = new $judyClass($judyClass::STRING_TO_ENTRY);
$ef->set("x", 10);
$ef->set("y", 20);
$filtered = $ef->filter(fn($v, $k) => $v > 15);
check('STRING_TO_ENTRY filter', ["y" => 20], $filtered->toArray());
$mapped = $ef->map(fn($v, $k) => $v * 2);
check('STRING_TO_ENTRY map', ["x" => 20, "y" => 40], $mapped->toArray());

if ($failures === 0) {
    echo "behavior: all checks passed\n";
    exit(0);
}
echo "behavior: $failures failure(s)\n";
exit(1);
