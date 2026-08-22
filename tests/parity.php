<?php
/**
 * Parity harness: runs identical scenarios against the native Judy class
 * (ext-judy) and the polyfill, and diffs every result including exceptions.
 *
 * Requires ext-judy to be loaded:
 *   php -d extension=judy tests/parity.php
 *
 * Exit code 0 = full parity on the covered scenarios; 1 = divergence.
 */

require __DIR__ . '/../src/Judy.php';

if (!extension_loaded('judy')) {
    fwrite(STDERR, "ext-judy not loaded; parity comparison needs the native extension.\n");
    fwrite(STDERR, "Run tests/behavior.php for extension-free assertions instead.\n");
    exit(2);
}

const NATIVE = \Judy::class;
const POLY   = \Orieg\JudyPolyfill\Judy::class;

/**
 * The polyfill targets the newest extension API, but CI installs whatever
 * version is RELEASED, and this suite has to stay meaningful against both.
 *
 * The two are never loaded together at runtime — bootstrap.php only defines the
 * polyfill when ext-judy is absent — so this is a development check, not a
 * compatibility shim. Its job is to prove the polyfill matches the extension on
 * everything that extension actually has. Expectations that need a newer
 * extension than the one installed are SKIPPED and named, rather than failing:
 * a red suite that everyone knows to ignore is worse than no suite, and
 * silently dropping the checks would be worse than either.
 *
 * Checks strengthen automatically as soon as CI's extension catches up.
 */
define('EXT_VERSION', \judy_version());

function extAtLeast(string $version): bool
{
    return \version_compare(EXT_VERSION, $version, '>=');
}

/** Method => the extension version that gave it its current signature. */
const SIGNATURE_SINCE = [
    'keys'                 => '2.5.0',   // range arguments (php-judy#96)
    'values'               => '2.5.0',
    'toArray'              => '2.5.0',
    'size'                 => '2.5.0',   // $index_start/$index_end -> $start/$end
    '__construct'          => '2.5.0',   // $optimizeIteration
    'fromArray'            => '2.5.0',
    'isIterationOptimized' => '2.5.0',
    'set'                  => '2.6.0',
    'get'                  => '2.6.0',
    'pruneExpired'         => '2.6.0',
    'getEntry'             => '2.6.0',
    'getExpiry'            => '2.6.0',
    'getFlags'             => '2.6.0',
];

/** Normalize any outcome (value/exception) into a comparable form. */
function capture(callable $fn): mixed
{
    try {
        return normalize($fn());
    } catch (\Throwable $e) {
        // Normalize the polyfill's class name so messages compare equal.
        $msg = str_replace('Orieg\\JudyPolyfill\\Judy', 'Judy', $e->getMessage());
        return 'EX ' . get_class($e) . ': ' . $msg;
    }
}

function normalize(mixed $v): mixed
{
    if ($v instanceof \Judy || $v instanceof \Orieg\JudyPolyfill\Judy) {
        return ['#judy' => $v->getType(), 'data' => $v->toArray()];
    }
    if (is_array($v)) {
        return array_map('normalize', $v);
    }
    return $v;
}

/** Memory numbers differ by design; compare only their type. */
function memShape(mixed $v): string
{
    return is_int($v) ? 'int' : gettype($v);
}

$failures = 0;
$checks   = 0;
$skipped  = 0;

function scenario(string $label, callable $steps, ?string $requires = null): void
{
    global $failures, $checks, $skipped;
    if ($requires !== null && !extAtLeast($requires)) {
        $skipped++;
        echo "SKIP [$label] needs ext-judy >= $requires, have " . EXT_VERSION . "\n";
        return;
    }
    $native = $steps(NATIVE);
    $poly   = $steps(POLY);
    foreach ($native as $step => $expected) {
        $checks++;
        $actual = array_key_exists($step, $poly) ? $poly[$step] : '«missing»';
        if ($expected !== $actual) {
            $failures++;
            echo "DIVERGE [$label :: $step]\n  native:   ", json_encode($expected), "\n  polyfill: ", json_encode($actual), "\n";
        }
    }
}

/* ── Signature parity ────────────────────────────────────────────
 *
 * Behaviour parity cannot catch "the method exists but will not accept those
 * arguments". That gap let the extension add range arguments to keys(), values()
 * and toArray() (php-judy #96) and an $optimizeIteration flag to __construct()
 * and fromArray() while this class kept the older signatures — a fatal for any
 * caller written against the extension, invisible to every scenario below,
 * because a scenario can only call what both classes already accept.
 *
 * A naive comparison is useless here: the polyfill lives in its own namespace,
 * so it must accept its own class and may return static. Those differences are
 * correct and permanent, so they are normalized away rather than reported —
 * otherwise the check cries wolf on ten methods forever and stops being read.
 * What survives normalization is real drift.
 */
function renderSignature(string $class, string $method): string
{
    $r = new \ReflectionMethod($class, $method);

    $params = array_map(static function (\ReflectionParameter $p): string {
        $s = ($p->hasType() ? normalizeType((string) $p->getType()) . ' ' : '') . '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $s .= ' = ' . var_export($p->getDefaultValue(), true);
        } elseif ($p->isVariadic()) {
            $s = '...' . $s;
        }
        return $s;
    }, $r->getParameters());

    return ($r->isStatic() ? 'static ' : '')
        . $method . '(' . implode(', ', $params) . '): '
        . ($r->hasReturnType() ? normalizeType((string) $r->getReturnType()) : 'mixed');
}

/** Collapse the self-type spellings the two implementations legitimately differ on. */
function normalizeType(string $type): string
{
    $parts = explode('|', $type);
    $parts = array_map(static function (string $t): string {
        $t = ltrim($t, '?\\');
        // "static" and "Orieg\JudyPolyfill\Judy" both mean "this Judy class".
        if ($t === 'static' || $t === 'self' || $t === 'Orieg\\JudyPolyfill\\Judy' || $t === 'Judy') {
            return 'Judy';
        }
        return $t;
    }, $parts);
    $parts = array_values(array_unique($parts));
    sort($parts);
    return (str_starts_with($type, '?') ? '?' : '') . implode('|', $parts);
}

function signatureParity(): void
{
    global $failures, $checks, $skipped;

    $publics = static fn(string $c): array => array_map(
        static fn(\ReflectionMethod $m): string => $m->getName(),
        (new \ReflectionClass($c))->getMethods(\ReflectionMethod::IS_PUBLIC)
    );

    $native = $publics(NATIVE);
    $poly   = $publics(POLY);
    sort($native);
    sort($poly);

    $gate = static function (string $m) use (&$skipped): bool {
        $since = SIGNATURE_SINCE[$m] ?? null;
        if ($since !== null && !extAtLeast($since)) {
            $skipped++;
            echo "SKIP [signature :: $m] needs ext-judy >= $since, have " . EXT_VERSION . "\n";
            return false;
        }
        return true;
    };

    foreach (array_diff($native, $poly) as $m) {
        $checks++;
        $failures++;
        echo "DIVERGE [signature :: $m]\n  native:   declared\n  polyfill: «missing»\n";
    }
    // A method the polyfill has and this extension does not is only drift if
    // the extension is new enough to have been expected to declare it.
    foreach (array_diff($poly, $native) as $m) {
        if (!$gate($m)) {
            continue;
        }
        $checks++;
        $failures++;
        echo "DIVERGE [signature :: $m]\n  native:   «absent»\n  polyfill: declared (extra public method)\n";
    }
    foreach (array_intersect($native, $poly) as $m) {
        if (!$gate($m)) {
            continue;
        }
        $checks++;
        $a = renderSignature(NATIVE, $m);
        $b = renderSignature(POLY, $m);
        if ($a !== $b) {
            $failures++;
            echo "DIVERGE [signature :: $m]\n  native:   $a\n  polyfill: $b\n";
        }
    }
}

signatureParity();

$intTypes    = ['BITSET' => 1, 'INT_TO_INT' => 2, 'INT_TO_MIXED' => 3, 'INT_TO_PACKED' => 6];
$stringTypes = ['STRING_TO_INT' => 4, 'STRING_TO_MIXED' => 5, 'STRING_TO_MIXED_HASH' => 7,
                'STRING_TO_INT_HASH' => 8, 'STRING_TO_MIXED_ADAPTIVE' => 9, 'STRING_TO_INT_ADAPTIVE' => 10,
                'STRING_TO_ENTRY' => 11];

/* ── Integer-keyed scenarios ─────────────────────────────────── */

foreach ($intTypes as $name => $type) {
    scenario("int/$name", function (string $class) use ($type, $name) {
        $r = [];
        $j = new $class($type);
        $isBitset = $type === 1;
        $val = fn(int $i) => $isBitset ? true : ($type === 3 || $type === 6 ? "v$i" : $i * 10);

        $r['set/get'] = capture(function () use ($j, $val) {
            $j[5] = $val(5); $j[1] = $val(1); $j[300] = $val(300);
            return [$j[5], $j[1], $j[300], $j[999]];
        });
        $r['isset/unset'] = capture(function () use ($j, $val) {
            $j[7] = $val(7); $has = isset($j[7]); unset($j[7]);
            return [$has, isset($j[7])];
        });
        $r['count/size'] = capture(fn() => [count($j), $j->count(), $j->size()]);
        $r['size ranged'] = capture(fn() => $j->size(2, 400));
        $r['toArray'] = capture(fn() => $j->toArray());
        $r['iterate'] = capture(function () use ($j) {
            $out = [];
            foreach ($j as $k => $v) { $out[] = [$k, $v]; }
            return $out;
        });
        $r['keys/values'] = capture(fn() => [$j->keys(), $j->values()]);
        $r['first/last noarg'] = capture(fn() => [$j->first(), $j->last()]);
        $r['first/last arg'] = capture(fn() => [$j->first(2), $j->last(299), $j->first(301), $j->last(0)]);
        $r['searchNext/prev'] = capture(fn() => [$j->searchNext(1), $j->prev(300), $j->searchNext(300), $j->prev(1)]);
        $r['byCount'] = capture(fn() => [$j->byCount(1), $j->byCount(3), $j->byCount(99)]);
        $r['firstEmpty/nextEmpty'] = capture(fn() => [$j->firstEmpty(), $j->firstEmpty(5), $j->nextEmpty(4)]);
        $r['prevEmpty'] = capture(fn() => $j->prevEmpty(6));
        $r['populationCount'] = capture(fn() => [$j->populationCount(), $j->populationCount(2, 400)]);
        $r['memoryUsage shape'] = capture(fn() => memShape($j->memoryUsage()));
        $r['jsonSerialize'] = capture(fn() => json_encode($j));
        $r['serialize roundtrip'] = capture(function () use ($j) {
            $u = unserialize(serialize($j));
            return [$u->getType(), $u->toArray()];
        });
        $r['slice'] = capture(fn() => $j->slice(1, 6));
        $r['deleteRange'] = capture(function () use ($class, $type, $val) {
            $x = new $class($type);
            $x[1] = $val(1); $x[5] = $val(5); $x[9] = $val(9);
            $n = $x->deleteRange(2, 9);
            return [$n, $x->toArray()];
        });
        $r['forEach args'] = capture(function () use ($j) {
            $seen = [];
            $j->forEach(function ($v, $k) use (&$seen) { $seen[] = [$v, $k]; });
            return $seen;
        });
        $r['filter'] = capture(fn() => $j->filter(fn($v, $k) => $k > 2));
        $r['map'] = capture(fn() => $j->map(fn($v, $k) => $k));
        $r['equals self-copy'] = capture(function () use ($class, $j) {
            $copy = $class::fromArray($j->getType(), $j->toArray());
            return $j->equals($copy);
        });
        $r['free shape'] = capture(fn() => memShape($j->free()));
        $r['empty after free'] = capture(fn() => [count($j), $j->first()]);
        return $r;
    });
}

/* Coercion + arithmetic on INT_TO_INT specifically */
scenario('int/coercion', function (string $class) {
    $r = [];
    $j = new $class(2);
    $r['string key coerces'] = capture(function () use ($j) { $j['abc'] = 1; return $j->toArray(); });
    $r['numeric-string key'] = capture(function () use ($j) { $j['42'] = 5; return $j[42]; });
    $r['float key truncates'] = capture(function () use ($j) { $j[7.9] = 3; return isset($j[7]); });
    $r['string value coerces'] = capture(function () use ($j) { $j[9] = 'xy'; return $j[9]; });
    $r['float value truncates'] = capture(function () use ($j) { $j[11] = 3.9; return $j[11]; });
    $r['bool value'] = capture(function () use ($j) { $j[13] = true; return $j[13]; });
    $r['increment'] = capture(fn() => [$j->increment(42), $j->increment(42, 5), $j->increment(1000, -3)]);
    $r['sum/avg'] = capture(function () use ($class) {
        $x = $class::fromArray(2, [1 => 10, 2 => 20, 3 => 30]);
        return [$x->sumValues(), $x->averageValues()];
    });
    $r['avg empty'] = capture(fn() => (new $class(2))->averageValues());
    return $r;
});

/* Errors on wrong types */
scenario('int/errors', function (string $class) {
    $r = [];
    $r['increment on MIXED'] = capture(fn() => (new $class(3))->increment(1));
    $r['increment on BITSET'] = capture(fn() => (new $class(1))->increment(1));
    $r['increment on PACKED'] = capture(fn() => (new $class(6))->increment(1));
    $r['sumValues on MIXED'] = capture(function () use ($class) {
        $m = new $class(3); $m[1] = 'str'; return $m->sumValues();
    });
    $r['union cross-type'] = capture(function () use ($class) {
        return (new $class(2))->union(new $class(3));
    });
    $r['mergeWith cross-category'] = capture(function () use ($class) {
        $a = new $class(2); $b = new $class(4); $a->mergeWith($b); return 'no-throw';
    });
    $r['invalid ctor type'] = capture(fn() => new $class(99));
    return $r;
});

/* Set operations per int type */
foreach ([1, 2, 3] as $type) {
    scenario("int/setops-$type", function (string $class) use ($type) {
        $mk = function (array $keys) use ($class, $type) {
            $j = new $class($type);
            foreach ($keys as $k) { $j[$k] = $type === 1 ? true : ($type === 3 ? "v$k" : $k); }
            return $j;
        };
        $a = $mk([1, 2, 3, 5]);
        $b = $mk([3, 5, 8]);
        $r = [];
        $r['union'] = capture(fn() => $a->union($b));
        $r['intersect'] = capture(fn() => $a->intersect($b));
        $r['diff'] = capture(fn() => $a->diff($b));
        $r['xor'] = capture(fn() => $a->xor($b));
        $r['mergeWith'] = capture(function () use ($a, $b) { $a->mergeWith($b); return $a->toArray(); });
        $r['equals'] = capture(fn() => [$a->equals($b), $b->equals($b->union($b))]);
        return $r;
    });
}

/* BITSET specifics */
scenario('bitset/specifics', function (string $class) {
    $r = [];
    $r['set false is absent'] = capture(function () use ($class) {
        $b = new $class(1); $b[3] = false; return [isset($b[3]), count($b)];
    });
    $r['toArray is index list'] = capture(function () use ($class) {
        $b = new $class(1); $b[9] = true; $b[2] = true; return $b->toArray();
    });
    $r['fromArray takes indices'] = capture(fn() => $class::fromArray(1, [4, 7])->toArray());
    $r['sumValues is popcount'] = capture(function () use ($class) {
        $b = $class::fromArray(1, [4, 7, 9]); return [$b->sumValues(), $b->averageValues()];
    });
    return $r;
});

/* ── String-keyed scenarios ──────────────────────────────────── */

foreach ($stringTypes as $name => $type) {
    scenario("string/$name", function (string $class) use ($type) {
        $intValued = in_array($type, [4, 8, 10], true);
        $val = fn(string $k) => $intValued ? strlen($k) : "val:$k";
        $r = [];
        $j = new $class($type);
        $r['set/get'] = capture(function () use ($j, $val) {
            $j['zz'] = $val('zz'); $j['aa'] = $val('aa'); $j['mm'] = $val('mm');
            return [$j['aa'], $j['zz'], $j['nope']];
        });
        $r['count'] = capture(fn() => count($j));
        $r['toArray sorted'] = capture(fn() => $j->toArray());
        $r['iterate'] = capture(function () use ($j) {
            $out = [];
            foreach ($j as $k => $v) { $out[] = [$k, $v]; }
            return $out;
        });
        $r['keys/values'] = capture(fn() => [$j->keys(), $j->values()]);
        $r['first/last'] = capture(fn() => [$j->first(), $j->last(), $j->first('b'), $j->last('n')]);
        $r['searchNext/prev'] = capture(fn() => [$j->searchNext('aa'), $j->prev('zz'), $j->searchNext('zz')]);
        $r['byCount null'] = capture(fn() => $j->byCount(1));
        $r['empties null'] = capture(fn() => [$j->firstEmpty(), $j->nextEmpty('a'), $j->lastEmpty(), $j->prevEmpty('z')]);
        // Shape only, as for the integer-keyed types above. The extension's
        // string-keyed figure is its own payload accounting over JudySL/JudyHS;
        // an exact match from a PHP array is neither achievable nor meaningful.
        // What has to agree is that both report an int rather than null — and
        // only from 2.5.0, which is when the extension gained that accounting
        // at all; before it, string-keyed memoryUsage() was null by contract.
        if (extAtLeast('2.5.0')) {
            $r['memoryUsage shape'] = capture(fn() => memShape($j->memoryUsage()));
        }
        $r['numeric-string key'] = capture(function () use ($j, $val) {
            $j['123'] = $val('123');
            return [$j->first(), $j['123'], array_key_exists(123, $j->toArray())];
        });
        $r['slice'] = capture(fn() => $j->slice('a', 'n'));
        $r['deleteRange'] = capture(function () use ($class, $type, $val) {
            $x = new $class($type);
            $x['aa'] = $val('aa'); $x['mm'] = $val('mm'); $x['zz'] = $val('zz');
            return [$x->deleteRange('a', 'n'), $x->toArray()];
        });
        $r['populationCount error'] = capture(fn() => $j->populationCount());
        $r['serialize roundtrip'] = capture(function () use ($j) {
            $u = unserialize(serialize($j));
            return [$u->getType(), $u->toArray()];
        });
        $r['setops'] = capture(function () use ($class, $type, $val) {
            $a = new $class($type); $b = new $class($type);
            foreach (['k1', 'k2', 'k3'] as $k) { $a[$k] = $val($k); }
            foreach (['k3', 'k4'] as $k) { $b[$k] = $val($k); }
            return [$a->union($b), $a->intersect($b), $a->diff($b), $a->xor($b)];
        });
        $r['getAll'] = capture(fn() => $j->getAll(['aa', 'missing']));
        $r['free shape'] = capture(fn() => memShape($j->free()));
        return $r;
    }, requires: $type === 11 ? '2.6.0' : null);
}

/* increment on string int-valued types */
scenario('string/increment', function (string $class) {
    $r = [];
    foreach ([4 => 'STRING_TO_INT', 8 => 'STRING_TO_INT_HASH'] as $type => $name) {
        $r["increment $name"] = capture(function () use ($class, $type) {
            $j = new $class($type);
            return [$j->increment('counter'), $j->increment('counter', 10)];
        });
    }
    $r['increment adaptive throws'] = capture(fn() => (new $class(10))->increment('k'));
    return $r;
});

/* ── Numeric-looking keys on string-keyed types ──────────────────
 *
 * A PHP array cannot hold the string key "42" — the engine coerces canonical
 * decimal integers in PHP_INT range. The polyfill stores rows in a PHP array,
 * so anything derived from array_keys() leaks int(42) where the extension,
 * which builds its list with add_next_index_string(), returns "42".
 *
 * This shipped in v2.5.0 because the suite tested numeric keys and tested
 * keys(), but never together: the keys/values scenarios all used
 * fruit-shaped keys. Types are asserted explicitly here rather than relying
 * on value comparison, since "42" and 42 compare loosely equal and would
 * otherwise slip through.
 *
 * toArray() is the deliberate exception — it returns a PHP array, so BOTH
 * sides coerce and agreeing on int keys there is correct behaviour, not drift.
 */
scenario('string/numeric-key types', function (string $class) {
    $r = [];
    $keys = ['42', '-7', '07', '0', '9223372036854775807', 'user.3'];
    foreach ([4 => 'STRING_TO_INT', 7 => 'STRING_TO_MIXED_HASH', 10 => 'STRING_TO_INT_ADAPTIVE'] as $type => $name) {
        $j = new $class($type);
        foreach ($keys as $i => $k) {
            $j[$k] = $i;
        }
        $types = fn(array $a) => implode(',', array_map('gettype', $a));

        $r["$name keys() types"] = capture(fn() => $types($j->keys()));
        $r["$name foreach key types"] = capture(function () use ($j) {
            $out = [];
            foreach ($j as $k => $v) { $out[] = gettype($k); }
            return implode(',', $out);
        });
        $r["$name seek types"] = capture(fn() => $types(array_filter(
            [$j->first(), $j->last(), $j->searchNext('0'), $j->prev('user.3')],
            fn($x) => $x !== null
        )));
        /* Round-trip: every key keys() hands back must be a usable offset. */
        $r["$name keys() round-trip"] = capture(function () use ($j) {
            foreach ($j->keys() as $k) {
                if (!isset($j[$k])) { return "not set: " . var_export($k, true); }
            }
            return 'all keys round-trip';
        });
        /* toArray() coerces on both sides — pinned so nobody "fixes" it. */
        $r["$name toArray() coerces"] = capture(fn() => $types(array_keys($j->toArray())));
    }
    return $r;
});

/* The same key-type check through a BOUNDED keys(), split out because the
   range arguments arrived in ext-judy 2.5.0 (php-judy#96): before that keys()
   took none, and calling it with two compares this class against a method that
   cannot accept the call. */
scenario('string/numeric-key types, bounded', function (string $class) {
    $r = [];
    $keys = ['42', '-7', '07', '0', '9223372036854775807', 'user.3'];
    foreach ([4 => 'STRING_TO_INT', 7 => 'STRING_TO_MIXED_HASH', 10 => 'STRING_TO_INT_ADAPTIVE'] as $type => $name) {
        $j = new $class($type);
        foreach ($keys as $i => $k) {
            $j[$k] = $i;
        }
        $types = fn(array $a) => implode(',', array_map('gettype', $a));
        $r["$name keys(bounded) types"] = capture(fn() => $types($j->keys('-7', 'user.3')));
    }
    return $r;
}, requires: '2.5.0');

function stringKeyedTypes(): array
{
    $types = [
        4  => 'STRING_TO_INT',
        5  => 'STRING_TO_MIXED',
        7  => 'STRING_TO_MIXED_HASH',
        8  => 'STRING_TO_INT_HASH',
        9  => 'STRING_TO_MIXED_ADAPTIVE',
        10 => 'STRING_TO_INT_ADAPTIVE',
    ];
    if (extAtLeast('2.6.0')) {
        $types[11] = 'STRING_TO_ENTRY';
    }
    return $types;
}

/** Hex-render anything key-shaped, so binary keys survive the diff printer. */
function hex(mixed $v): mixed
{
    if (is_array($v)) {
        return array_map('hex', $v);
    }
    return is_string($v) ? bin2hex($v) : $v;
}

/** Hex-render an array's KEYS (the values are already printable). */
function hexKeys(array $a): array
{
    return array_map('hex', array_keys($a));
}

/* ── Embedded NUL bytes in string keys (ext-judy 2.5.1) ──────────
 *
 * A PHP array is binary-safe, so the polyfill would happily store "ab\0cd" as
 * a key distinct from "ab". The extension cannot: JudySL indexes NUL-TERMINATED
 * C strings by construction, and the hash and adaptive types share that trie
 * for every seek and range. So 2.5.1 rejects the key outright (php-judy #117 /
 * PR #119), where before it the trie types truncated at the NUL — collapsing
 * two distinct keys into one and destroying a value — and the hash/adaptive
 * types guarded only writes while every ordered read still truncated.
 *
 * The polyfill has to throw to match. It is the less "correct" data structure
 * and the only correct polyfill: code written against this class has to run
 * against the extension.
 *
 * This scenario exists because the suite had NO \x00 in it anywhere, and was
 * green through the whole divergence. That is the fourth key-space bug in this
 * ecosystem to hide behind a corpus of only realistic keys, so the checks below
 * are deliberately exhaustive rather than representative: every method that
 * takes a key or a bound, on every string-keyed type, plus the orderings where
 * the guard does NOT fire.
 */
scenario('string/embedded-NUL keys', function (string $class) {
    $nul = "ab\x00cd";
    $r = [];

    foreach (stringKeyedTypes() as $type => $name) {
        $intValued = in_array($type, [4, 8, 10], true);
        $val = fn(string $k) => $intValued ? strlen($k) : "v:$k";
        /* A fresh populated array per check: many of these mutate, and the
           partial-application ones have to start from a known state. */
        $mk = function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach (['aa', 'mm', 'zz'] as $k) {
                $j[$k] = $val($k);
            }
            return $j;
        };
        $empty = fn() => new $class($type);

        /* Writes. The message names the type — except on the two adaptive
           types, which share one wording because the extension raises theirs
           from the shared adaptive layer. That asymmetry is the point of
           comparing messages rather than just "did it throw". */
        $r["$name offsetSet"] = capture(function () use ($mk, $nul, $val) {
            $j = $mk();
            $j[$nul] = $val($nul);
            return $j->count();
        });
        $r["$name increment"] = capture(fn() => $mk()->increment($nul));
        $r["$name fromArray"] = capture(fn() => $class::fromArray($type, [$nul => 1])->count());
        /* putAll applies entries until it hits the bad key and then throws, so
           the surviving keys are part of the contract, not just the message. */
        $r["$name putAll partial"] = capture(function () use ($mk, $nul, $val) {
            $j = $mk();
            try {
                $j->putAll(['ok' => $val('ok'), $nul => $val('x'), 'later' => $val('later')]);
            } catch (\Throwable $e) {
                return [str_replace('Orieg\\JudyPolyfill\\Judy', 'Judy', $e->getMessage()), $j->keys()];
            }
            return ['no throw', $j->keys()];
        });

        /* Reads and deletes on a POPULATED array. */
        $r["$name offsetGet"] = capture(fn() => $mk()[$nul]);
        $r["$name offsetExists"] = capture(fn() => isset($mk()[$nul]));
        $r["$name offsetUnset"] = capture(function () use ($mk, $nul) {
            $j = $mk();
            unset($j[$nul]);
            return $j->count();
        });
        $r["$name getAll"] = capture(fn() => $mk()->getAll(['aa', $nul]));

        /* The one place the guard does NOT fire: the three read/delete offset
           handlers answer an EMPTY array before they look at the offset. It
           holds however the array became empty, so all three routes are pinned
           — a polyfill that put the check in its key-coercion helper and left
           it there would throw here, and that is exactly the shape of mistake
           this scenario is for. */
        $r["$name offsetGet on empty"] = capture(fn() => $empty()[$nul]);
        $r["$name offsetExists on empty"] = capture(fn() => isset($empty()[$nul]));
        $r["$name offsetUnset on empty"] = capture(function () use ($empty, $nul) {
            $j = $empty();
            unset($j[$nul]);
            return $j->count();
        });
        $r["$name offsetGet on emptied"] = capture(function () use ($mk, $nul) {
            $j = $mk();
            $j->deleteRange('a', 'zzz');
            return [$j->count(), $j[$nul]];
        });
        $r["$name offsetGet after free"] = capture(function () use ($mk, $nul) {
            $j = $mk();
            $j->free();
            return $j[$nul];
        });
        /* Seeks do NOT get that reprieve: they throw even with nothing to find. */
        $r["$name first on empty"] = capture(fn() => $empty()->first($nul));
        $r["$name keys on empty"] = capture(fn() => $empty()->keys($nul, null));

        /* Navigation. */
        $r["$name first"] = capture(fn() => $mk()->first($nul));
        $r["$name last"] = capture(fn() => $mk()->last($nul));
        $r["$name searchNext"] = capture(fn() => $mk()->searchNext($nul));
        $r["$name prev"] = capture(fn() => $mk()->prev($nul));

        /* Every bounded read, on BOTH bounds — the pre-2.5.1 extension guarded
           writes and let the ordered reads truncate, so "the write path throws"
           is not enough to call this fixed. */
        foreach ([
            'keys'        => fn($j, $lo, $hi) => hex($j->keys($lo, $hi)),
            'values'      => fn($j, $lo, $hi) => $j->values($lo, $hi),
            'toArray'     => fn($j, $lo, $hi) => hexKeys($j->toArray($lo, $hi)),
            'size'        => fn($j, $lo, $hi) => $j->size($lo, $hi),
            'slice'       => fn($j, $lo, $hi) => hex($j->slice($lo, $hi)->keys()),
            'deleteRange' => fn($j, $lo, $hi) => $j->deleteRange($lo, $hi),
        ] as $method => $call) {
            $r["$name $method start"] = capture(fn() => $call($mk(), $nul, 'zz'));
            $r["$name $method end"] = capture(fn() => $call($mk(), 'aa', $nul));
        }

        /* Position is irrelevant — "embedded" is the extension's wording, not
           its rule. A key that is nothing but a NUL is rejected too. */
        foreach (['leading' => "\x00ab", 'trailing' => "ab\x00", 'only' => "\x00",
                  'double' => "a\x00b\x00c"] as $where => $key) {
            $r["$name NUL $where"] = capture(function () use ($mk, $key, $val) {
                $j = $mk();
                $j[$key] = $val('x');
                return $j->count();
            });
        }
        /* The empty string is NOT a NUL and remains a perfectly good key. */
        $r["$name empty string key"] = capture(function () use ($mk, $val) {
            $j = $mk();
            $j[''] = $val('');
            return [$j->count(), hex($j->keys()), $j[''], isset($j[''])];
        });
        /* Only the KEY is constrained. Values stay binary-safe. */
        $r["$name NUL in value"] = capture(function () use ($mk, $type) {
            if (in_array($type, [4, 8, 10], true)) {
                return 'int-valued';   // nothing to store a NUL in
            }
            $j = $mk();
            $j['k'] = "v\x00w";
            return hex($j['k']);
        });
    }

    /* Which complaint wins when a call is wrong twice over. The bounded reads
       reject a non-string bound on EITHER side before inspecting the bytes of
       either; slice() checks the bytes of both bounds first. Opposite orders,
       both pinned, because a shared helper would quietly unify them. */
    $j = new $class(5);
    $j['aa'] = 'v';
    $r['keys(NUL, int) is the TypeError'] = capture(fn() => $j->keys($nul, 1));
    $r['keys(int, NUL) is the TypeError'] = capture(fn() => $j->keys(1, $nul));
    $r['size(NUL, int) is the TypeError'] = capture(fn() => $j->size($nul, 1));
    $r['slice(int, NUL) is the Exception'] = capture(fn() => $j->slice(1, $nul));
    $r['slice(NUL, int) is the Exception'] = capture(fn() => $j->slice($nul, 1));

    /* Integer-keyed types are untouched: the offset is cast to an int, so the
       bytes after the cast are nobody's business. */
    foreach ([1 => 'BITSET', 2 => 'INT_TO_INT', 3 => 'INT_TO_MIXED', 6 => 'INT_TO_PACKED'] as $type => $name) {
        $r["int/$name ignores NUL"] = capture(function () use ($class, $type, $nul) {
            $k = new $class($type);
            $k[$nul] = $type === 1 ? true : 1;
            return [$k->count(), $k->keys()];
        });
    }

    return $r;
}, requires: '2.5.1');

/* ── High bytes in string keys ───────────────────────────────────
 *
 * The other half of the same corpus gap, and the half that must NOT change:
 * 0x00 is the only byte the extension rejects. Everything from 0x01 to 0xFF is
 * stored, compared and ordered as an UNSIGNED byte — which is load-bearing,
 * because prefix-successor range bounds ("ab" .. "ac") are carry arithmetic
 * over exactly those bytes, and 0xFF is where the carry happens.
 *
 * A signed-char comparison anywhere on either side would put 0x80-0xFF below
 * 0x7F and pass every test written with fruit-shaped keys. This scenario is
 * expected to be green BEFORE and AFTER the NUL change: it is the negative
 * control on the rejection, proving it was aimed at one byte and not at
 * "non-ASCII keys".
 */
scenario('string/high-byte keys', function (string $class) {
    $r = [];
    $corpus = ["\x01", "\x7f", "\x80", "\xc3\xa9", "\xfe", "\xff", "a\xffb", "ab\xff", "ac", "\xff\xff"];

    foreach (stringKeyedTypes() as $type => $name) {
        $intValued = in_array($type, [4, 8, 10], true);
        $mk = function () use ($class, $type, $corpus, $intValued) {
            $j = new $class($type);
            foreach ($corpus as $i => $k) {
                $j[$k] = $intValued ? $i : "v$i";
            }
            return $j;
        };

        $r["$name stores and orders"] = capture(fn() => [
            $mk()->count(), hex($mk()->keys()), $mk()->values(),
        ]);
        $r["$name round-trips every key"] = capture(function () use ($mk, $corpus) {
            $j = $mk();
            $out = [];
            foreach ($corpus as $k) {
                $out[] = [bin2hex($k), isset($j[$k]), $j[$k]];
            }
            return $out;
        });
        $r["$name iterate"] = capture(function () use ($mk) {
            $out = [];
            foreach ($mk() as $k => $v) {
                $out[] = [bin2hex($k), $v];
            }
            return $out;
        });
        /* 0x80 sorts ABOVE 0x7f, not below it — the signed-char trap. */
        $r["$name unsigned nav"] = capture(fn() => hex([
            $mk()->first(), $mk()->last(),
            $mk()->first("\x80"), $mk()->last("\x80"),
            $mk()->searchNext("\x7f"), $mk()->prev("\xff"),
        ]));
        /* slice() has taken bounds since 2.4.0, so the prefix-successor carry
           is checkable here; the keys()/values()/toArray()/size() half of it
           needs 2.5.0 and lives in the gated sibling scenario below. */
        $r["$name prefix successor via slice"] = capture(fn() => [
            hex($mk()->slice('a', "a\xff")->keys()),
            hex($mk()->slice('ab', 'ac')->keys()),
        ]);
        $r["$name unset high byte"] = capture(function () use ($mk) {
            $j = $mk();
            unset($j["\xff"]);
            return [$j->count(), isset($j["\xff"]), isset($j["\xff\xff"])];
        });
        $r["$name deleteRange high bytes"] = capture(function () use ($mk) {
            $j = $mk();
            $n = $j->deleteRange("\x80", "\xfe");
            return [$n, hex($j->keys())];
        });
        $r["$name getAll"] = capture(fn() => hexKeys($mk()->getAll(["\xff", "a\xffb", "\xfd absent"])));
    }
    return $r;
});

/* The bounded half of the same corpus: unsigned byte order as seen through
   keys()/values()/toArray()/size() range arguments, which arrived in ext-judy
   2.5.0 (php-judy#96) and so cannot be asked of an older extension. The
   ordering itself is already pinned ungated above, by the full-array keys()
   and by slice(); this adds the range bounds that are carry arithmetic over
   exactly those bytes. */
scenario('string/high-byte keys, bounded', function (string $class) {
    $r = [];
    $corpus = ["\x01", "\x7f", "\x80", "\xc3\xa9", "\xfe", "\xff", "a\xffb", "ab\xff", "ac", "\xff\xff"];

    foreach (stringKeyedTypes() as $type => $name) {
        $intValued = in_array($type, [4, 8, 10], true);
        $mk = function () use ($class, $type, $corpus, $intValued) {
            $j = new $class($type);
            foreach ($corpus as $i => $k) {
                $j[$k] = $intValued ? $i : "v$i";
            }
            return $j;
        };
        $r["$name high-byte ranges"] = capture(fn() => [
            hex($mk()->keys("\x80", "\xff")),
            hex($mk()->keys("\x01", "\x7f")),
            $mk()->values("\x80", "\xff"),
            hexKeys($mk()->toArray("\x80", "\xff")),
            $mk()->size("\x80", "\xff"),
            $mk()->size("\x01", "\x7f"),
        ]);
        /* Prefix-successor carry: everything under the prefix "ab" is
           [ab, ab\xff...] and stops before "ac". */
        $r["$name prefix successor"] = capture(fn() => [
            hex($mk()->keys('ab', "ab\xff")),
            hex($mk()->keys('ab', 'ac')),
            $mk()->size('ab', 'ac'),
        ]);
    }
    return $r;
}, requires: '2.5.0');

/* ── ArrayAccess offset types on string-keyed arrays ─────────────
 *
 * The extension refuses to coerce an ArrayAccess offset: on a string-keyed
 * array $j[42] is a TypeError, not the key "42". Nothing else on those types
 * is strict — putAll([1 => 'x']) stores the key "1" without complaint, and so
 * do getAll(), fromArray(), increment() and every seek — so this is a property
 * of the offset SYNTAX, not of string keys, and a polyfill backed by a PHP
 * array coerces everywhere by default and is wrong in exactly one of those
 * places. The asymmetry is the whole scenario: both halves are pinned, so
 * "fixing" either direction shows up here.
 *
 * The empty-array reprieve is pinned too. Like the NUL guard, the type guard
 * sits BEHIND the null-array short circuit in the three read/delete handlers,
 * so on an empty array $j[42] answers absent instead of throwing — and it holds
 * however the array became empty. offsetSet() has no such path and always
 * throws. That ordering is what makes this a four-handler scenario rather than
 * one check: a polyfill that put the guard in its key-coercion helper would be
 * right on offsetSet() and wrong on the other three.
 *
 * Both guards live in the same macro natively (CHECK_ARRAY_AND_ARG_TYPE), type
 * first and bytes second, so a non-string offset never reaches the NUL check.
 */
scenario('string/offset types', function (string $class) {
    $r = [];
    /* Every non-string offset PHP can hand an ArrayAccess handler. null is
       here because it is NOT special to the extension — see the append note
       at the bottom for the one case userland cannot reproduce. */
    $offsets = [
        'int'    => 42,
        'zero'   => 0,
        'float'  => 1.5,
        'true'   => true,
        'false'  => false,
        'null'   => null,
        'array'  => ['x'],
        'object' => new stdClass(),
    ];

    foreach (stringKeyedTypes() as $type => $name) {
        $intValued = in_array($type, [4, 8, 10], true);
        $val = fn(string $k) => $intValued ? strlen($k) : "v:$k";
        $mk = function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach (['aa', 'zz'] as $k) {
                $j[$k] = $val($k);
            }
            return $j;
        };
        $empty = fn() => new $class($type);

        foreach ($offsets as $label => $offset) {
            /* Populated: all four handlers reject. */
            $r["$name set[$label]"] = capture(function () use ($mk, $offset, $intValued) {
                $j = $mk();
                $j[$offset] = $intValued ? 1 : 'v';
                return [$j->count(), $j->keys()];
            });
            $r["$name get[$label]"] = capture(fn() => $mk()[$offset]);
            $r["$name exists[$label]"] = capture(fn() => isset($mk()[$offset]));
            $r["$name unset[$label]"] = capture(function () use ($mk, $offset) {
                $j = $mk();
                unset($j[$offset]);
                return $j->count();
            });

            /* Empty: the three read/delete handlers answer before they look,
               offsetSet() still throws. */
            $r["$name get[$label] on empty"] = capture(fn() => $empty()[$offset]);
            $r["$name exists[$label] on empty"] = capture(fn() => isset($empty()[$offset]));
            $r["$name unset[$label] on empty"] = capture(function () use ($empty, $offset) {
                $j = $empty();
                unset($j[$offset]);
                return $j->count();
            });
            $r["$name set[$label] on empty"] = capture(function () use ($empty, $offset, $intValued) {
                $j = $empty();
                $j[$offset] = $intValued ? 1 : 'v';
                return $j->count();
            });
        }

        /* The reprieve holds however the array became empty, not just on one
           that was never written to. */
        foreach ([
            'unset'       => function ($j) { unset($j['aa'], $j['zz']); },
            'deleteRange' => function ($j) { $j->deleteRange('a', 'zzz'); },
            'free'        => function ($j) { $j->free(); },
        ] as $how => $drain) {
            $r["$name get[int] after $how"] = capture(function () use ($mk, $drain) {
                $j = $mk();
                $drain($j);
                return [$j->count(), $j[42], isset($j[42])];
            });
        }

        /* The other half of the asymmetry: everything that is NOT an offset
           coerces, and must keep coercing. */
        $r["$name putAll coerces"] = capture(function () use ($class, $type, $intValued) {
            $j = new $class($type);
            $j->putAll([1 => $intValued ? 9 : 'x', true => $intValued ? 8 : 'y']);
            return [$j->keys(), $j->toArray()];
        });
        $r["$name getAll coerces"] = capture(function () use ($mk, $intValued) {
            $j = $mk();
            $j['1'] = $intValued ? 9 : 'x';
            return $j->getAll([1, 'aa']);
        });
        $r["$name fromArray coerces"] = capture(fn() => $class::fromArray($type, [1 => $intValued ? 9 : 'x'])->keys());
        $r["$name increment coerces"] = capture(function () use ($mk) {
            $j = $mk();
            $j->increment(1);
            return $j->keys();
        });
        $r["$name seeks coerce"] = capture(fn() => [
            $mk()->first(1), $mk()->last(1), $mk()->searchNext(1), $mk()->prev(1),
        ]);
        /* A numeric STRING offset is a string and is accepted, which is what
           makes the int rejection a type rule rather than a "looks numeric"
           rule. */
        $r["$name numeric string offset"] = capture(function () use ($mk, $intValued) {
            $j = $mk();
            $j['42'] = $intValued ? 1 : 'v';
            return [$j->count(), $j->keys(), $j['42'], isset($j['42'])];
        });
        /* The empty string is a string. */
        $r["$name empty string offset"] = capture(function () use ($mk, $intValued) {
            $j = $mk();
            $j[''] = $intValued ? 1 : 'v';
            return [$j->count(), $j[''], isset($j[''])];
        });
    }

    /* Integer-keyed types coerce their offsets, as before — the strictness is
       the string types' alone. */
    foreach ([1 => 'BITSET', 2 => 'INT_TO_INT', 3 => 'INT_TO_MIXED', 6 => 'INT_TO_PACKED'] as $type => $name) {
        $r["int/$name coerces offsets"] = capture(function () use ($class, $type) {
            $j = new $class($type);
            $v = fn() => $type === 1 ? true : ($type === 2 ? 1 : 'v');
            $j['3'] = $v();
            $j[4.9] = $v();
            $j[true] = $v();
            return [$j->count(), $j->keys(), isset($j['3']), isset($j[1])];
        });
    }

    /* NOT covered, and deliberately: $j[] = 1. The extension answers append
       with its own "values cannot be set without specifying a key" Exception,
       but PHP hands offsetSet() a null offset for BOTH $j[] = 1 and
       $j[null] = 1, so no userland class can tell them apart. This polyfill
       reports the TypeError for both; the README lists it. */

    return $r;
});

/* ── slice() bound types ─────────────────────────────────────────
 *
 * slice() rejects a non-string bound on a string-keyed array exactly as
 * keys()/values()/toArray()/size() do, with its own method name in the message
 * — but with two differences that a shared helper would quietly erase, so both
 * are pinned:
 *
 *   1. ORDER. slice() checks the BYTES of both bounds before it minds their
 *      types, the opposite of the bounded reads. slice(1, "a\0b") is therefore
 *      the NUL Exception while keys(1, "a\0b") is the TypeError. (Those two
 *      orderings are pinned in the embedded-NUL scenario, which is where the
 *      \0 corpus lives; this one covers the types.)
 *   2. NULL. The bounded reads default their bounds to null and read it as
 *      "unbounded". slice() takes two REQUIRED arguments and rejects null like
 *      any other non-string, so slice(null, null) throws where keys(null, null)
 *      returns everything.
 *
 * And the guard is unconditional: unlike the offset handlers, slice() has no
 * empty-array short circuit in front of it, so an empty array throws too.
 *
 * deleteRange() is the control. It takes the same two bounds and does NOT
 * type-check them — deleteRange(1, 'z') coerces and deletes — so it is pinned
 * alongside to keep a well-meaning refactor from giving it the check as well.
 */
scenario('string/slice bound types', function (string $class) {
    $r = [];
    $bounds = [
        'int, int'       => [1, 2],
        'int, string'    => [1, 'zz'],
        'string, int'    => ['aa', 2],
        'float, float'   => [1.5, 2.5],
        'bool, bool'     => [true, false],
        'null, string'   => [null, 'zz'],
        'string, null'   => ['aa', null],
        'null, null'     => [null, null],
        'array, string'  => [['x'], 'zz'],
        'object, string' => [new stdClass(), 'zz'],
        'string, string' => ['aa', 'zz'],
        'numeric string' => ['1', '9'],
    ];

    /* deleteRange() is the control below, but only over the bounds PHP can
       COERCE. An array or object bound never reaches its Judy-level handling:
       the extension declares a string parameter there, so the engine rejects
       it first with its own "Argument #1 ($start) must be of type string,
       array given". The polyfill takes mixed and converts, which diverges —
       but that is a separate, pre-existing gap shared by first(), last(),
       searchNext(), prev() and deleteRange() alike, about PHP's coercion
       rules and not about these two type guards. Pinning it here would put an
       unrelated failure inside this scenario.

       The null bounds are left out for a duller reason: the extension declares
       deleteRange()'s parameters non-nullable, so passing null there is a
       deprecation on every call and would bury the suite's output in notices.
       Whether null is a bound or "unbounded" is already settled above, by
       slice() rejecting it and keys()/values()/toArray()/size() accepting it. */
    $coercible = array_diff_key($bounds, array_flip([
        'array, string', 'object, string', 'null, string', 'string, null', 'null, null',
    ]));

    foreach (stringKeyedTypes() as $type => $name) {
        $intValued = in_array($type, [4, 8, 10], true);
        $mk = function () use ($class, $type, $intValued) {
            $j = new $class($type);
            foreach (['aa', 'mm', 'zz'] as $i => $k) {
                $j[$k] = $intValued ? $i : "v$i";
            }
            return $j;
        };
        $empty = fn() => new $class($type);

        foreach ($bounds as $label => [$lo, $hi]) {
            $r["$name slice($label)"] = capture(fn() => $mk()->slice($lo, $hi)->keys());
            /* No empty-array reprieve here: the type guard runs either way. */
            $r["$name slice($label) on empty"] = capture(fn() => $empty()->slice($lo, $hi)->keys());
        }

        /* The control: same bounds, no type check, coerced instead. */
        foreach ($coercible as $label => [$lo, $hi]) {
            $r["$name deleteRange($label)"] = capture(function () use ($mk, $lo, $hi) {
                $j = $mk();
                $n = $j->deleteRange($lo, $hi);
                return [$n, $j->keys()];
            });
        }
    }

    /* Integer-keyed types take integer bounds, as always. */
    foreach ([1 => 'BITSET', 2 => 'INT_TO_INT', 3 => 'INT_TO_MIXED', 6 => 'INT_TO_PACKED'] as $type => $name) {
        $r["int/$name slice takes ints"] = capture(function () use ($class, $type) {
            $j = new $class($type);
            foreach ([1, 5, 10] as $i) {
                $j[$i] = $type === 1 ? true : ($type === 2 ? $i : "v$i");
            }
            return [$j->slice(1, 5)->keys(), $j->slice('1', '5')->keys(), $j->deleteRange(1, 5)];
        });
    }

    return $r;
});

/* ── slice() vs the bounded reads on bound types ─────────────────
 *
 * The other half of the slice() comparison, split out for one reason: it CALLS
 * keys()/values()/toArray()/size() with range arguments, and those arrived in
 * ext-judy 2.5.0 (php-judy#96). Before that keys(), values() and toArray()
 * took no arguments at all and size() ignored the pair, so running these
 * against an older extension compares this class against a method that cannot
 * accept the call — an ArgumentCountError, which says nothing about bound
 * types. slice() itself needs no gate: it has type-checked its bounds since
 * 2.4.0, when it was added, which is why it stays in the ungated scenario.
 *
 * What is pinned here is the pair of differences between slice() and the four
 * bounded reads:
 *
 *   - They reject the same non-string bounds, each naming ITSELF in the
 *     message — which is why these compare messages and not just "did it
 *     throw".
 *   - They accept null on BOTH sides, reading it as "unbounded", where
 *     slice() rejects it because its two bounds are required.
 */
scenario('string/slice vs bounded reads', function (string $class) {
    $r = [];
    foreach (stringKeyedTypes() as $type => $name) {
        $intValued = in_array($type, [4, 8, 10], true);
        $mk = function () use ($class, $type, $intValued) {
            $j = new $class($type);
            foreach (['aa', 'mm', 'zz'] as $i => $k) {
                $j[$k] = $intValued ? $i : "v$i";
            }
            return $j;
        };
        foreach ([
            'keys'    => fn($j, $lo, $hi) => $j->keys($lo, $hi),
            'values'  => fn($j, $lo, $hi) => $j->values($lo, $hi),
            'toArray' => fn($j, $lo, $hi) => $j->toArray($lo, $hi),
            'size'    => fn($j, $lo, $hi) => $j->size($lo, $hi),
        ] as $method => $call) {
            $r["$name $method(int, int)"] = capture(fn() => $call($mk(), 1, 2));
            $r["$name $method(null, null)"] = capture(fn() => $call($mk(), null, null));
            /* slice() rejects the null pair the bounded reads accept. */
            $r["$name slice vs $method on null"] = capture(fn() => $mk()->slice(null, null));
        }
    }
    return $r;
}, requires: '2.5.0');

/* ── Bounded keys()/values()/toArray() ───────────────────────────
 *
 * The range arguments the signature check now pins also have to behave the
 * same. The interesting cases are not the ordinary spans but the edges, and one
 * of them is easy to get wrong in a PHP reimplementation: the extension casts
 * integer bounds to Word_t, so -1 is the maximum bound (size(0, -1) means
 * "everything") and every other negative likewise lands above any representable
 * key — which makes keys(-5, 10) empty rather than "the low keys".
 */
scenario('range/int', function (string $class) {
    $r = [];
    foreach ([1 => 'BITSET', 2 => 'INT_TO_INT', 3 => 'INT_TO_MIXED', 6 => 'INT_TO_PACKED'] as $type => $name) {
        $j = new $class($type);
        foreach ([1, 5, 10, 15, 1000] as $i) {
            $j[$i] = $type === 1 ? true : ($type === 2 ? $i * 10 : "v$i");
        }
        foreach ([
            'span'            => [5, 15],
            'single'          => [5, 5],
            'in a gap'        => [6, 9],
            'past the end'    => [2000, 3000],
            'inverted'        => [15, 5],
            'unbounded end'   => [10, null],
            'unbounded start' => [null, 10],
            'both unbounded'  => [null, null],
            'minus one end'   => [0, -1],
            'negative start'  => [-5, 10],
        ] as $label => [$lo, $hi]) {
            $r["$name $label"] = capture(fn() => [
                $j->keys($lo, $hi),
                $j->values($lo, $hi),
                $j->toArray($lo, $hi),
            ]);
        }
        $r["$name size/populationCount"] = capture(fn() => [
            $j->size(5, 15), $j->populationCount(5, 15),
            $j->size(0, -1), $j->populationCount(0, -1),
        ]);
    }
    return $r;
}, requires: '2.5.0');

scenario('range/string', function (string $class) {
    $r = [];
    foreach ([4 => 'STRING_TO_INT', 5 => 'STRING_TO_MIXED', 7 => 'STRING_TO_MIXED_HASH',
              8 => 'STRING_TO_INT_HASH', 9 => 'STRING_TO_MIXED_ADAPTIVE',
              10 => 'STRING_TO_INT_ADAPTIVE'] as $type => $name) {
        $j = new $class($type);
        $intValued = \in_array($type, [4, 8, 10], true);
        foreach (['aa', 'apple', 'apricot_long', 'banana', 'blackcurrant', 'cherry', 'zz'] as $i => $k) {
            $j[$k] = $intValued ? $i : "v$i";
        }
        foreach ([
            'span'            => ['b', 'c'],
            'bounds are keys' => ['apple', 'cherry'],
            'single'          => ['aa', 'aa'],
            'prefix is not a prefix match' => ['bb', 'bl'],
            'prefix successor' => ['bb', 'bm'],
            'inverted'        => ['c', 'b'],
            'past the end'    => ['zzz', 'zzzz'],
            'unbounded end'   => ['b', null],
            'unbounded start' => [null, 'b'],
            'both unbounded'  => [null, null],
        ] as $label => [$lo, $hi]) {
            $r["$name $label"] = capture(fn() => [
                $j->keys($lo, $hi),
                $j->values($lo, $hi),
                $j->toArray($lo, $hi),
            ]);
        }
        // A bounded read must equal the same range copied and read whole.
        $r["$name matches slice"] = capture(fn() => [
            $j->keys('b', 'c') === $j->slice('b', 'c')->keys(),
            $j->toArray('b', 'c') === $j->slice('b', 'c')->toArray(),
        ]);
        // Non-string bounds are a TypeError on string-keyed arrays.
        $r["$name rejects int bounds"] = capture(fn() => $j->keys(1, 2));

        /* size() on string keys is the behaviour change 2.5.0 actually shipped:
         * before it, string bounds were accepted, ignored, and the whole-array
         * count came back. Covering only keys()/values()/toArray() above would
         * leave that regression free to come back unnoticed, so size() is
         * pinned against the same ranges — and against count(), which must stay
         * the unbounded answer. */
        $r["$name size bounded"] = capture(fn() => [
            $j->size('b', 'c'),
            $j->size('apple', 'cherry'),
            $j->size('aa', 'aa'),
            $j->size('bb', 'bl'),
            $j->size('c', 'b'),          // inverted: empty
            $j->size('zzz', 'zzzz'),     // past the end: empty
            $j->size('b', null),
            $j->size(null, 'b'),
        ]);
        $r["$name size unbounded equals count"] = capture(fn() => [
            $j->size(), $j->count(), $j->size() === $j->count(),
        ]);
        // A bounded size() must agree with the keys() it is counting.
        $r["$name size agrees with keys"] = capture(fn() => [
            $j->size('b', 'c') === \count($j->keys('b', 'c')),
            $j->size(null, 'b') === \count($j->keys(null, 'b')),
        ]);
        $r["$name size rejects int bounds"] = capture(fn() => $j->size(1, 2));
    }
    return $r;
}, requires: '2.5.0');

/* populationCount() stays integer-keyed only, bounds or no bounds.
 *
 * size() and populationCount() look interchangeable and are not: the second is
 * specifically a read of libJudy's population cache, and JudySL/JudyHS have no
 * such cache, so there is nothing for it to read. size() is expected to gain
 * string bounds (php-judy#105); populationCount() is deliberately not.
 *
 * Worth pinning here because the polyfill routes size(), keys(), values() and
 * toArray() through one shared range helper, and it would be an easy accident
 * to let populationCount() reach it too and start answering where the extension
 * throws. */
scenario('populationCount stays integer-keyed', function (string $class) {
    $r = [];
    foreach ([4 => 'STRING_TO_INT', 5 => 'STRING_TO_MIXED', 7 => 'STRING_TO_MIXED_HASH',
              8 => 'STRING_TO_INT_HASH', 9 => 'STRING_TO_MIXED_ADAPTIVE',
              10 => 'STRING_TO_INT_ADAPTIVE'] as $type => $name) {
        $j = new $class($type);
        $j['aa'] = 1;
        $j['bb'] = 2;
        $r["$name bounded"] = capture(fn() => $j->populationCount('a', 'c'));
        $r["$name unbounded"] = capture(fn() => $j->populationCount());
    }
    return $r;
});

/* ── Negative integer keys (ext-judy 2.5.0) ──────────────────────
 *
 * Integer keys are unsigned machine words, so a negative PHP int addresses the
 * TOP of the key space: -1 is the largest key there is, and the whole negative
 * half sorts above PHP_INT_MAX. Before 2.5.0 the extension discarded negative
 * offsets and appended instead, so this was unreachable and the divergence here
 * was invisible — a PHP array orders those keys signed, putting -1 first.
 *
 * Everything below follows from the ordering: keys()/toArray() order,
 * first()/last(), the empty-slot scans, and the range bounds. -1 as an upper
 * bound is the maximum, which is exactly why size(0, -1) means "everything".
 */
scenario('negative keys', function (string $class) {
    $r = [];
    foreach ([1 => 'BITSET', 2 => 'INT_TO_INT', 3 => 'INT_TO_MIXED', 6 => 'INT_TO_PACKED'] as $type => $name) {
        $val = fn(int $i) => $type === 1 ? true : ($type === 2 ? $i : "v$i");

        $r["$name round trip"] = capture(function () use ($class, $type, $val) {
            $j = new $class($type);
            $j[-1] = $val(1);
            $j[PHP_INT_MIN] = $val(2);
            return [$j->count(), isset($j[-1]), $j[-1], isset($j[0]), $j[PHP_INT_MIN]];
        });

        $r["$name unsigned order"] = capture(function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach ([5, 1, -1, -2, PHP_INT_MAX, PHP_INT_MIN, 0] as $k) {
                $j[$k] = $val($k);
            }
            return [$j->keys(), $j->toArray(), $j->values()];
        });

        $r["$name first/last/nav"] = capture(function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach ([5, -1, -2, 0] as $k) {
                $j[$k] = $val($k);
            }
            return [$j->first(), $j->last(), $j->searchNext(5), $j->prev(-1), $j->byCount(1), $j->byCount(4)];
        });

        $r["$name ranges"] = capture(function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach ([5, 1, -1, -2, PHP_INT_MAX] as $k) {
                $j[$k] = $val($k);
            }
            return [
                $j->keys(0, -1),        // 0 .. unsigned max: everything
                $j->keys(-2, -1),       // just the negative pair
                $j->keys(0, 100),       // excludes the negatives
                $j->keys(-1, -1),
                $j->keys(-5, 10),       // start above end: empty
                $j->size(0, -1),
                $j->populationCount(0, -1),
                $j->populationCount(-2, -1),
            ];
        });

        $r["$name iteration order"] = capture(function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach ([3, -1, 0] as $k) {
                $j[$k] = $val($k);
            }
            $out = [];
            foreach ($j as $k => $v) {
                $out[] = $k;
            }
            return $out;
        });

        $r["$name deleteRange/slice"] = capture(function () use ($class, $type, $val) {
            $j = new $class($type);
            foreach ([5, 1, -1, -2] as $k) {
                $j[$k] = $val($k);
            }
            $sliced = $j->slice(-2, -1)->keys();
            $deleted = $j->deleteRange(-2, -1);
            return [$sliced, $deleted, $j->keys()];
        });
    }

    // Empty-slot scans are unsigned too: the first absent key at or above -2.
    $r['empties around the top'] = capture(function () use ($class) {
        $j = new $class(2);
        $j[-1] = 1;
        $j[-3] = 3;
        return [$j->firstEmpty(-3), $j->nextEmpty(-3), $j->lastEmpty(), $j->prevEmpty(-1)];
    });

    return $r;
}, requires: '2.5.0');

/* The flag the extension added alongside: accepted everywhere, honoured only
   natively, and isIterationOptimized() is what makes the difference visible. */
scenario('optimizeIteration accepted', function (string $class) {
    $r = [];
    foreach ([2 => 'INT_TO_INT', 4 => 'STRING_TO_INT', 8 => 'STRING_TO_INT_HASH'] as $type => $name) {
        $r["$name constructs with flag"] = capture(function () use ($class, $type) {
            $j = new $class($type, true);
            $j[$type === 2 ? 5 : 'aa'] = 1;
            return [$j->count(), is_bool($j->isIterationOptimized())];
        });
        $r["$name fromArray with flag"] = capture(function () use ($class, $type) {
            $data = $type === 2 ? [1 => 10, 2 => 20] : ['aa' => 1, 'bb' => 2];
            $j = $class::fromArray($type, $data, true);
            return [$j->toArray(), is_bool($j->isIterationOptimized())];
        });
    }
    return $r;
}, requires: '2.5.0');

/* ── The two conformance fixes in ext-judy 2.6.0 ─────────────────
 *
 * 2.6.0 shipped no new API, but it did make the extension conform to two
 * contracts its own stub had always declared — and in both cases the
 * behaviour it moved to is the one the polyfill already had. These are the
 * only observable-behaviour changes between 2.5.2 and 2.6.0, so they are
 * gated: against an older extension the polyfill is *correct* and the
 * extension diverges, which is not a polyfill defect to fail on.
 *
 *   1. offsetSet()/offsetUnset() called as methods returned a bool, though
 *      Judy.stub.php declares both void (php-judy 3b35162). The bool was not
 *      merely undeclared but wrong: it reported the helper's SUCCESS/FAILURE,
 *      which tracks whether the backing array was allocated yet, not whether
 *      anything was unset. The $j[$k] = $v and unset($j[$k]) operator paths
 *      always discarded it, so only the explicit method calls were affected.
 *
 *   2. Judy::__construct() ran zpp only when given arguments, so `new Judy()`
 *      silently produced a type-0 object instead of raising for the required
 *      $type its arginfo has always declared (php-judy 1f14974).
 */
scenario('2.6.0 conformance: void offsets, required $type', function (string $class) {
    $r = [];

    /* All ten types, across every branch the unset helper distinguishes:
       an unallocated array, an absent key, and a present one. */
    foreach ([1 => 'BITSET', 2 => 'INT_TO_INT', 3 => 'INT_TO_MIXED', 4 => 'STRING_TO_INT',
              5 => 'STRING_TO_MIXED', 6 => 'STRING_TO_INT_HASH', 7 => 'STRING_TO_MIXED_HASH',
              8 => 'STRING_TO_INT_ADAPTIVE', 9 => 'STRING_TO_MIXED_ADAPTIVE'] as $type => $name) {
        $intKeyed = $type <= 3;
        $r["$name offset methods return null"] = capture(function () use ($class, $type, $intKeyed) {
            $key = $intKeyed ? 7 : 'kk';
            $fresh = new $class($type);
            $onUnallocated = $fresh->offsetUnset($key);      // nothing allocated yet
            $j = new $class($type);
            $set = $j->offsetSet($key, $type === 1 ? true : 1);
            $overwrite = $j->offsetSet($key, $type === 1 ? true : 2);
            $onAbsent = $j->offsetUnset($intKeyed ? 99 : 'zz');
            $onPresent = $j->offsetUnset($key);
            return [$onUnallocated, $set, $overwrite, $onAbsent, $onPresent];
        });
    }

    /* offsetExists/offsetGet are typed _IS_BOOL and IS_MIXED respectively and
       were never part of this mismatch — pin them so a future "make it all
       void" sweep cannot quietly take them along. */
    $r['offsetExists/offsetGet keep their types'] = capture(function () use ($class) {
        $j = new $class(2);
        $j[3] = 30;
        return [$j->offsetExists(3), $j->offsetExists(4), $j->offsetGet(3)];
    });

    /* $type is required. The exception CLASS is the contract; the message is
       not comparable — the engine words zpp failures differently for internal
       and userland functions, so only the class is asserted here. */
    $r['zero-arg construction raises'] = (function () use ($class) {
        try {
            new $class();
            return 'no exception';
        } catch (\Throwable $e) {
            return get_class($e);
        }
    })();

    return $r;
}, requires: '2.6.0');

/* ── STRING_TO_ENTRY cache entry and TTL methods (ext-judy 2.6.0) ── */
scenario('string/STRING_TO_ENTRY cache entry methods', function (string $class) {
    $r = [];
    $j = new $class(11);

    $r['type'] = capture(fn() => $j->getType());

    $r['basic set/get'] = capture(function () use ($j) {
        $j->set("user:1", ["id" => 1, "name" => "Alice"]);
        return [
            $j->get("user:1"),
            $j->getExpiry("user:1"),
            $j->getFlags("user:1"),
        ];
    });

    $r['set with ttl and flags'] = capture(function () use ($j) {
        $j->set("session:abc", "data_payload", ttl: 3600, flags: 42);
        $exp = null;
        $flags = null;
        $val = $j->get("session:abc", $exp, $flags);
        return [
            $val,
            is_int($exp) && $exp > time(),
            $flags,
            $j->getExpiry("session:abc") === $exp,
            $j->getFlags("session:abc") === 42,
        ];
    });

    $r['getEntry'] = capture(function () use ($j) {
        $entry = $j->getEntry("session:abc");
        return [
            $entry["value"] ?? null,
            is_int($entry["expires_at"] ?? null),
            $entry["flags"] ?? null,
            $entry["is_expired"] ?? null,
        ];
    });

    $r['non-existent keys'] = capture(function () use ($j) {
        return [
            $j->get("non_existent"),
            $j->getEntry("non_existent"),
            $j->getExpiry("non_existent"),
            $j->getFlags("non_existent"),
        ];
    });

    $r['arrayaccess'] = capture(function () use ($class) {
        $x = new $class(11);
        $x["k1"] = "val1";
        $x["k2"] = 12345;
        $x["k3"] = ["nested" => true];
        $c1 = count($x);
        $h1 = isset($x["k1"]);
        $h2 = isset($x["k2"]);
        $h4 = isset($x["k4"]);
        $v1 = $x["k1"];
        $v2 = $x["k2"];
        $v3 = $x["k3"];
        unset($x["k2"]);
        $c2 = count($x);
        $h2_after = isset($x["k2"]);
        $v2_after = $x["k2"];
        return [$c1, $h1, $h2, $h4, $v1, $v2, $v3, $c2, $h2_after, $v2_after];
    });

    $r['pruneExpired'] = capture(function () use ($class) {
        $x = new $class(11);
        $now = 1700000000;
        $x->set("k1", "val1", ttl: 10);
        $x->set("k2", "val2", ttl: 100);
        $x->set("k3", "val3", ttl: 0);
        $exp1 = $x->getExpiry("k1");
        $exp2 = $x->getExpiry("k2");

        $p0 = $x->pruneExpired($exp1 - 5);
        $c0 = count($x);
        $p1 = $x->pruneExpired($exp1 + 1);
        $c1 = count($x);
        $k1_val = $x->get("k1");
        $k1_has = isset($x["k1"]);
        $k2_val = $x->get("k2");
        $k3_val = $x->get("k3");
        $p2 = $x->pruneExpired($exp2 + 1);
        $c2 = count($x);
        $k2_after = $x->get("k2");
        $k3_after = $x->get("k3");
        return [
            $p0, $c0, $p1, $c1, $k1_val, $k1_has, $k2_val, $k3_val,
            $p2, $c2, $k2_after, $k3_after
        ];
    });

    $r['navigation and bulk'] = capture(function () use ($class) {
        $x = new $class(11);
        $x->set("charlie", 300);
        $x->set("alice", 100);
        $x->set("bob", 200);
        $iter = [];
        foreach ($x as $k => $v) {
            $iter[$k] = $v;
        }
        return [
            $iter,
            $x->first(),
            $x->searchNext("alice"),
            $x->last(),
            $x->prev("charlie"),
            $x->toArray(),
            $x->keys(),
            $x->values(),
        ];
    });

    $r['slice and clone'] = capture(function () use ($class) {
        $x = new $class(11);
        $x->set("a", 1, ttl: 50, flags: 1);
        $x->set("b", 2, ttl: 100, flags: 2);
        $x->set("c", 3, ttl: 150, flags: 3);
        $cloned = clone $x;
        $sliced = $x->slice("a", "b");
        return [
            $cloned->getType(), count($cloned), $cloned->get("b"), $cloned->getFlags("b"),
            $sliced->getType(), count($sliced), $sliced->keys(), $sliced->getFlags("a"), $sliced->getFlags("b"),
        ];
    });

    $r['type errors on other types'] = capture(function () use ($class) {
        $intJ = new $class(2);
        $errs = [];
        foreach ([
            'set'          => fn() => $intJ->set("key", 123),
            'get'          => fn() => $intJ->get("key"),
            'pruneExpired' => fn() => $intJ->pruneExpired(),
            'getEntry'     => fn() => $intJ->getEntry("key"),
            'getExpiry'    => fn() => $intJ->getExpiry("key"),
            'getFlags'     => fn() => $intJ->getFlags("key"),
        ] as $method => $fn) {
            try {
                $fn();
                $errs[$method] = 'no exception';
            } catch (\Throwable $e) {
                $errs[$method] = get_class($e) . ': ' . $e->getMessage();
            }
        }
        return $errs;
    });

    return $r;
}, requires: '2.6.0');

printf("\next-judy %s: %d checks, %d divergences%s\n",
    EXT_VERSION, $checks, $failures,
    $skipped > 0 ? ", $skipped skipped (need a newer extension)" : '');
exit($failures === 0 ? 0 : 1);
