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

function scenario(string $label, callable $steps): void
{
    global $failures, $checks;
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
    global $failures, $checks;

    $publics = static fn(string $c): array => array_map(
        static fn(\ReflectionMethod $m): string => $m->getName(),
        (new \ReflectionClass($c))->getMethods(\ReflectionMethod::IS_PUBLIC)
    );

    $native = $publics(NATIVE);
    $poly   = $publics(POLY);
    sort($native);
    sort($poly);

    foreach (array_diff($native, $poly) as $m) {
        $checks++;
        $failures++;
        echo "DIVERGE [signature :: $m]\n  native:   declared\n  polyfill: «missing»\n";
    }
    foreach (array_diff($poly, $native) as $m) {
        $checks++;
        $failures++;
        echo "DIVERGE [signature :: $m]\n  native:   «absent»\n  polyfill: declared (extra public method)\n";
    }
    foreach (array_intersect($native, $poly) as $m) {
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
        // What has to agree is that both report an int rather than null.
        $r['memoryUsage shape'] = capture(fn() => memShape($j->memoryUsage()));
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
});

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
    }
    return $r;
});

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
});

echo "\n$checks checks, $failures divergences\n";
exit($failures === 0 ? 0 : 1);
