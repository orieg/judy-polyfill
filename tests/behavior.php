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
    // Not a write path, so it is guarded on all six; increment() reports the
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

if ($failures === 0) {
    echo "behavior: all checks passed\n";
    exit(0);
}
echo "behavior: $failures failure(s)\n";
exit(1);
