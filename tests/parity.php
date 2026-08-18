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
                'STRING_TO_INT_HASH' => 8, 'STRING_TO_MIXED_ADAPTIVE' => 9, 'STRING_TO_INT_ADAPTIVE' => 10];

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
    });
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
        $r["$name keys(bounded) types"] = capture(fn() => $types($j->keys('-7', 'user.3')));
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

/** The six string-keyed types, in one place: three scenarios below want them. */
const STRING_KEYED = [
    4  => 'STRING_TO_INT',
    5  => 'STRING_TO_MIXED',
    7  => 'STRING_TO_MIXED_HASH',
    8  => 'STRING_TO_INT_HASH',
    9  => 'STRING_TO_MIXED_ADAPTIVE',
    10 => 'STRING_TO_INT_ADAPTIVE',
];

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

    foreach (STRING_KEYED as $type => $name) {
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

    foreach (STRING_KEYED as $type => $name) {
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
            hex($mk()->slice('a', "a\xff")->keys()),
            $mk()->size('ab', 'ac'),
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

printf("\next-judy %s: %d checks, %d divergences%s\n",
    EXT_VERSION, $checks, $failures,
    $skipped > 0 ? ", $skipped skipped (need a newer extension)" : '');
exit($failures === 0 ? 0 : 1);
