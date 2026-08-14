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
        $r['memoryUsage null'] = capture(fn() => $j->memoryUsage());
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

echo "\n$checks checks, $failures divergences\n";
exit($failures === 0 ? 0 : 1);
