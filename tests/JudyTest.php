<?php

namespace Orieg\JudyPolyfill\Tests;

use PHPUnit\Framework\TestCase;
use Orieg\JudyPolyfill\Judy;

class JudyTest extends TestCase
{
    public function testConstructValidTypes(): void
    {
        for ($t = Judy::BITSET; $t <= Judy::STRING_TO_ENTRY; $t++) {
            $j = new Judy($t, true);
            $this->assertSame($t, $j->getType());
            $this->assertFalse($j->isIterationOptimized());

            $jFalse = new Judy($t, false);
            $this->assertSame($t, $jFalse->getType());
            $this->assertFalse($jFalse->isIterationOptimized());
        }
    }

    public function testOptimizeIterationDefault(): void
    {
        $ref = new \ReflectionMethod(Judy::class, '__construct');
        $params = $ref->getParameters();
        $this->assertSame('optimizeIteration', $params[1]->getName());
        $this->assertFalse($params[1]->getDefaultValue());
    }

    public function testConstructBoundaries(): void
    {
        $min = new Judy(Judy::BITSET);
        $this->assertSame(Judy::BITSET, $min->getType());

        $max = new Judy(Judy::STRING_TO_ENTRY);
        $this->assertSame(Judy::STRING_TO_ENTRY, $max->getType());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a valid Judy type');
        new Judy(Judy::BITSET - 1);
    }

    public function testConstructAboveMax(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a valid Judy type');
        new Judy(Judy::STRING_TO_ENTRY + 1);
    }

    public function testDestruct(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $j[1] = 10;
        unset($j);
        $this->assertTrue(true);
    }

    public function testGetTypeAndIsIterationOptimizedReflection(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->assertSame(Judy::INT_TO_INT, $j->getType());
        $this->assertFalse($j->isIterationOptimized());

        $refType = new \ReflectionMethod(Judy::class, 'getType');
        $this->assertTrue($refType->isPublic());
        $this->assertSame('int', (string) $refType->getReturnType());

        $refOpt = new \ReflectionMethod(Judy::class, 'isIterationOptimized');
        $this->assertTrue($refOpt->isPublic());
        $this->assertSame('bool', (string) $refOpt->getReturnType());
    }

    public function testIntToIntOperations(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->assertCount(0, $j);
        $this->assertSame(0, $j->count());
        $this->assertSame(0, $j->size());
        $this->assertNull($j[999]);
        $this->assertFalse(isset($j[999]));

        $j[5] = 50;
        $j[1] = 10;
        $j[300] = 3000;

        $this->assertCount(3, $j);
        $this->assertSame(50, $j[5]);
        $this->assertSame(10, $j[1]);
        $this->assertSame(3000, $j[300]);
        $this->assertTrue(isset($j[5]));
        $this->assertTrue(isset($j[1]));
        $this->assertTrue(isset($j[300]));
        $this->assertFalse(isset($j[999]));

        $this->assertSame([1 => 10, 5 => 50, 300 => 3000], $j->toArray());
        $this->assertSame([1, 5, 300], $j->keys());
        $this->assertSame([10, 50, 3000], $j->values());

        $this->assertSame(1, $j->first());
        $this->assertSame(300, $j->last());
        $this->assertSame(5, $j->first(2));
        $this->assertSame(1, $j->first(1));
        $this->assertSame(300, $j->first(6));
        $this->assertNull($j->first(301));

        $this->assertSame(300, $j->searchNext(5));
        $this->assertSame(5, $j->searchNext(1));
        $this->assertNull($j->searchNext(300));

        $this->assertSame(5, $j->prev(300));
        $this->assertSame(1, $j->prev(5));
        $this->assertNull($j->prev(1));

        $this->assertSame(300, $j->last(300));
        $this->assertSame(5, $j->last(100));
        $this->assertSame(1, $j->last(2));
        $this->assertNull($j->last(0));

        $this->assertSame(1, $j->byCount(1));
        $this->assertSame(5, $j->byCount(2));
        $this->assertSame(300, $j->byCount(3));
        $this->assertNull($j->byCount(0));
        $this->assertNull($j->byCount(-1));
        $this->assertNull($j->byCount(4));

        $this->assertSame(2, $j->size(2, 400));
        $this->assertSame(1, $j->size(1, 4));
        $this->assertSame(0, $j->size(10, 20));
        $this->assertSame(3, $j->size(0, -1));
        $this->assertSame(3, $j->populationCount());
        $this->assertSame(2, $j->populationCount(2, 400));

        $this->assertSame(1, $j->increment(77));
        $this->assertSame(6, $j->increment(77, 5));
        $this->assertSame(10 + 50 + 3000 + 6, $j->sumValues());
        $this->assertSame((10 + 50 + 3000 + 6) / 4.0, $j->averageValues());

        unset($j[77]);
        $this->assertFalse(isset($j[77]));
        $this->assertCount(3, $j);

        $bytes = $j->free();
        $this->assertGreaterThan(0, $bytes);
        $this->assertCount(0, $j);
        $this->assertSame(0, $j->memoryUsage());
        $this->assertNull($j->averageValues());
    }

    public function testFreeAndMemoryUsageEstimates(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->assertSame(0, $j->memoryUsage());
        $this->assertSame(0, $j->free());

        $j[1] = 10;
        $this->assertSame(40 + 9, $j->memoryUsage());
        $this->assertSame(49, $j->free());
        $this->assertSame(0, $j->memoryUsage());

        $sMixed = new Judy(Judy::STRING_TO_MIXED);
        $this->assertSame(0, $sMixed->memoryUsage());
        $sMixed['a'] = 'test';
        $this->assertSame(1 + \PHP_INT_SIZE + 16, $sMixed->memoryUsage());

        $sHash = new Judy(Judy::STRING_TO_INT_HASH);
        $sHash['a'] = 100;
        $this->assertSame(2 + \PHP_INT_SIZE, $sHash->memoryUsage());

        $sMixedHash = new Judy(Judy::STRING_TO_MIXED_HASH);
        $sMixedHash['a'] = 'test';
        $this->assertSame(2 + \PHP_INT_SIZE + 16, $sMixedHash->memoryUsage());

        $sEntry = new Judy(Judy::STRING_TO_ENTRY);
        $sEntry->set('a', 'test');
        $this->assertSame(1 + \PHP_INT_SIZE + 24, $sEntry->memoryUsage());
    }

    public function testBitsetSemantics(): void
    {
        $b = new Judy(Judy::BITSET);
        $this->assertNull($b[4]);
        $this->assertFalse(isset($b[4]));
        $this->assertSame(0, $b->memoryUsage());

        $b[9] = true;
        $b[2] = true;
        $b[5] = false;

        $this->assertSame([2, 9], $b->toArray());
        $this->assertFalse($b[4]);
        $this->assertTrue($b[9]);
        $this->assertTrue($b[2]);
        $this->assertSame([2, 9], $b->values());
        $this->assertSame(2, $b->sumValues());
        $this->assertSame(1.0, $b->averageValues());
        $this->assertSame(2, $b->populationCount());

        $this->assertSame([2, 9], $b->toArray(0, 10));
        $this->assertSame([2], $b->toArray(0, 5));
        $this->assertSame([9], $b->toArray(5, 10));

        $bFrom = Judy::fromArray(Judy::BITSET, [4, 7]);
        $this->assertSame([4, 7], $bFrom->toArray());
        $this->assertSame([4, 7], $bFrom->keys());
        $this->assertSame([4, 7], $bFrom->values());

        $bSliced = $bFrom->slice(4, 7);
        $this->assertSame([4, 7], $bSliced->toArray());

        $seen = [];
        $bFrom->forEach(function ($val, $idx) use (&$seen) {
            $seen[$idx] = $val;
        });
        $this->assertSame([4 => true, 7 => true], $seen);

        $filtered = $bFrom->filter(fn($v, $k) => $k > 5);
        $this->assertSame([7], $filtered->toArray());

        $mapped = $bFrom->map(fn($v, $k) => $k === 4 ? true : false);
        $this->assertSame([4], $mapped->toArray());

        $iter = [];
        foreach ($bFrom as $k => $v) {
            $iter[$k] = $v;
        }
        $this->assertSame([4 => true, 7 => true], $iter);
    }

    public function testStringToIntAndNavigation(): void
    {
        $s = new Judy(Judy::STRING_TO_INT);
        $this->assertSame(0, $s->memoryUsage());

        $s['zz'] = 1;
        $s['aa'] = 2;
        $s['123'] = 9;

        $this->assertSame('123', $s->first());
        $this->assertSame('zz', $s->last());
        $this->assertSame('zz', $s->searchNext('aa'));
        $this->assertSame('aa', $s->searchNext('123'));
        $this->assertNull($s->searchNext('zz'));
        $this->assertSame('aa', $s->prev('zz'));
        $this->assertSame('123', $s->prev('aa'));
        $this->assertNull($s->prev('123'));

        $this->assertNull($s->byCount(1));
        $this->assertGreaterThan(0, $s->memoryUsage());

        $this->assertSame(['aa' => 2, 'missing' => null], $s->getAll(['aa', 'missing']));
        $this->assertSame(3, $s->size());
        $this->assertSame(1, $s->size('a', 'b'));
        $this->assertSame(['aa'], $s->keys('a', 'b'));
        $this->assertSame([2], $s->values('a', 'b'));
        $this->assertSame(['aa' => 2], $s->toArray('a', 'b'));

        $this->assertSame(2, $s->increment('zz'));
        $this->assertSame(13, $s->sumValues());
        $this->assertEquals(13 / 3.0, $s->averageValues());
    }

    public function testStringHashTypesMemoryUsage(): void
    {
        $h1 = new Judy(Judy::STRING_TO_INT_HASH);
        $h1['abc'] = 123;
        $this->assertGreaterThan(0, $h1->memoryUsage());

        $h2 = new Judy(Judy::STRING_TO_MIXED_HASH);
        $h2['abc'] = 'def';
        $this->assertGreaterThan(0, $h2->memoryUsage());

        $ad1 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
        $ad1['abc'] = 456;
        $this->assertGreaterThan(0, $ad1->memoryUsage());

        $ad2 = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
        $ad2['abc'] = ['foo' => 'bar'];
        $this->assertGreaterThan(0, $ad2->memoryUsage());
    }

    public function testStringNulByteRejection(): void
    {
        $nulKey = "ab\x00cd";
        $types = [
            Judy::STRING_TO_INT,
            Judy::STRING_TO_MIXED,
            Judy::STRING_TO_MIXED_HASH,
            Judy::STRING_TO_INT_HASH,
            Judy::STRING_TO_MIXED_ADAPTIVE,
            Judy::STRING_TO_INT_ADAPTIVE,
            Judy::STRING_TO_ENTRY,
        ];

        foreach ($types as $type) {
            $j = new Judy($type);
            $thrown = false;
            try {
                $j[$nulKey] = 1;
            } catch (\Exception $e) {
                $thrown = true;
                $this->assertStringContainsString('keys must not contain embedded null bytes', $e->getMessage());
            }
            $this->assertTrue($thrown, "Expected NUL byte rejection on type $type");
        }
    }

    public function testStringOffsetTypeError(): void
    {
        $s = new Judy(Judy::STRING_TO_INT);
        $s['init'] = 1;
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Judy offset must be of type string for string-based arrays');
        $val = $s[123];
    }

    public function testEmptySeekMethods(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->assertSame(0, $j->firstEmpty());
        $this->assertSame(0, $j->firstEmpty(0));
        $this->assertSame(5, $j->firstEmpty(5));
        $this->assertSame(-1, $j->lastEmpty());
        $this->assertSame(5, $j->nextEmpty(4));
        $this->assertSame(3, $j->prevEmpty(4));

        $j[0] = 100;
        $this->assertSame(1, $j->firstEmpty());
        $this->assertSame(1, $j->nextEmpty(0));

        $j[-1] = 200;
        $this->assertNull($j->nextEmpty(-1));

        $j[0] = 1;
        $this->assertSame(1, $j->firstEmpty(0));
        $this->assertNull($j->prevEmpty(0));

        $s = new Judy(Judy::STRING_TO_INT);
        $this->assertNull($s->firstEmpty());
        $this->assertNull($s->lastEmpty());
        $this->assertNull($s->nextEmpty(1));
        $this->assertNull($s->prevEmpty(1));
    }

    public function testEmptySeekTypeError(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->expectException(\TypeError::class);
        $j->firstEmpty('invalid');
    }

    public function testSetOperations(): void
    {
        $a = new Judy(Judy::INT_TO_INT);
        $a[1] = 10;
        $a[2] = 20;

        $b = new Judy(Judy::INT_TO_INT);
        $b[2] = 22;
        $b[3] = 30;

        $union = $a->union($b);
        $this->assertSame([1 => 10, 2 => 22, 3 => 30], $union->toArray());

        $intersect = $a->intersect($b);
        $this->assertSame([2 => 20], $intersect->toArray());

        $diff = $a->diff($b);
        $this->assertSame([1 => 10], $diff->toArray());

        $xor = $a->xor($b);
        $this->assertSame([1 => 10, 3 => 30], $xor->toArray());

        $this->assertFalse($a->equals($b));
        $aCopy = clone $a;
        $this->assertTrue($a->equals($aCopy));

        $a->mergeWith($b);
        $this->assertSame([1 => 10, 2 => 22, 3 => 30], $a->toArray());
    }

    public function testSetOperationsTypeMismatch(): void
    {
        $a = new Judy(Judy::INT_TO_INT);
        $b = new Judy(Judy::INT_TO_MIXED);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Both Judy arrays must be the same type');
        $a->union($b);
    }

    public function testSetOperationsUnsupportedType(): void
    {
        $a = new Judy(Judy::STRING_TO_MIXED);
        $b = new Judy(Judy::STRING_TO_MIXED);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Set operations are only supported on BITSET and integer-valued arrays');
        $a->union($b);
    }

    public function testMergeWithIncompatibleCategories(): void
    {
        $a = new Judy(Judy::INT_TO_INT);
        $b = new Judy(Judy::STRING_TO_INT);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot merge Judy arrays with incompatible key types');
        $a->mergeWith($b);
    }

    public function testFunctionalMethods(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $j[1] = 10;
        $j[2] = 20;
        $j[3] = 30;

        $seen = [];
        $j->forEach(function ($v, $k) use (&$seen) {
            $seen[$k] = $v;
        });
        $this->assertSame([1 => 10, 2 => 20, 3 => 30], $seen);

        $filtered = $j->filter(fn($v, $k) => $v > 15);
        $this->assertSame([2 => 20, 3 => 30], $filtered->toArray());

        $mapped = $j->map(fn($v, $k) => $v * 2);
        $this->assertSame([1 => 20, 2 => 40, 3 => 60], $mapped->toArray());

        $iter = [];
        foreach ($j as $k => $v) {
            $iter[$k] = $v;
        }
        $this->assertSame([1 => 10, 2 => 20, 3 => 30], $iter);
    }

    public function testStringToEntryCacheMethods(): void
    {
        $e = new Judy(Judy::STRING_TO_ENTRY);
        $e->set('session', ['user_id' => 42], ttl: 3600, flags: 1);

        $this->assertTrue(isset($e['session']));
        $this->assertSame(['user_id' => 42], $e['session']);
        $this->assertSame(1, $e->getFlags('session'));
        $this->assertGreaterThan(\time(), $e->getExpiry('session'));

        $entry = $e->getEntry('session');
        $this->assertNotNull($entry);
        $this->assertSame(['user_id' => 42], $entry['value']);
        $this->assertSame(1, $entry['flags']);
        $this->assertFalse($entry['is_expired']);

        $expVal = null;
        $expFlags = null;
        $got = $e->get('session', $expVal, $expFlags);
        $this->assertSame(['user_id' => 42], $got);
        $this->assertGreaterThan(\time(), $expVal);
        $this->assertSame(1, $expFlags);

        $this->assertSame(0, $e->pruneExpired());

        $e->set('expired_key', 'val', ttl: -10);
        $this->assertFalse(isset($e['expired_key']));
        $this->assertNull($e['expired_key']);
        $this->assertNull($e->get('expired_key'));
        $this->assertSame(1, $e->pruneExpired());

        $cloned = clone $e;
        $this->assertSame(Judy::STRING_TO_ENTRY, $cloned->getType());
        $this->assertSame(['user_id' => 42], $cloned->get('session'));

        $filtered = $e->filter(fn($v, $k) => $k === 'session');
        $this->assertSame(['session' => ['user_id' => 42]], $filtered->toArray());

        $mapped = $e->map(fn($v, $k) => 'new_value');
        $this->assertSame(['session' => 'new_value'], $mapped->toArray());

        $e['simple'] = 'val';
        $this->assertSame('val', $e['simple']);
        $this->assertSame(0, $e->getExpiry('simple'));
        $this->assertSame(0, $e->getFlags('simple'));
    }

    public function testStringToEntryTypeErrorsOnOtherTypes(): void
    {
        $intJ = new Judy(Judy::INT_TO_INT);

        $methods = [
            fn() => $intJ->set('key', 123),
            fn() => $intJ->get('key'),
            fn() => $intJ->pruneExpired(),
            fn() => $intJ->getEntry('key'),
            fn() => $intJ->getExpiry('key'),
            fn() => $intJ->getFlags('key'),
        ];

        foreach ($methods as $fn) {
            $thrown = false;
            try {
                $fn();
            } catch (\TypeError $e) {
                $thrown = true;
                $this->assertStringContainsString('is only supported for STRING_TO_ENTRY arrays', $e->getMessage());
            }
            $this->assertTrue($thrown);
        }
    }

    public function testSliceAndDeleteRange(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $j[10] = 100;
        $j[20] = 200;
        $j[30] = 300;
        $j[40] = 400;

        $sliced = $j->slice(20, 30);
        $this->assertSame([20 => 200, 30 => 300], $sliced->toArray());

        $deleted = $j->deleteRange(20, 30);
        $this->assertSame(2, $deleted);
        $this->assertSame([10 => 100, 40 => 400], $j->toArray());
    }

    public function testSerializationAndJson(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $j[1] = 10;
        $j[2] = 20;

        $serialized = serialize($j);
        /** @var Judy $unserialized */
        $unserialized = unserialize($serialized);

        $this->assertSame(Judy::INT_TO_INT, $unserialized->getType());
        $this->assertSame([1 => 10, 2 => 20], $unserialized->toArray());
        $this->assertSame(json_encode([1 => 10, 2 => 20]), json_encode($j));
    }

    public function testInvalidUnserialize(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid serialized Judy data');
        $j->__unserialize(['type' => 'invalid']);
    }

    public function testGlobalFunctions(): void
    {
        $this->assertSame(Judy::POLYFILL_VERSION, judy_version());

        $jInt = new Judy(Judy::INT_TO_INT);
        $this->assertSame(Judy::INT_TO_INT, judy_type($jInt));
        $this->assertSame(-1, judy_type(['not', 'a', 'judy']));
    }

    public function testUnsignedKeyOrdering(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $j[-1] = 100;
        $j[0] = 200;
        $j[PHP_INT_MAX] = 300;

        $keys = $j->keys();
        $this->assertSame([0, PHP_INT_MAX, -1], $keys);
        $this->assertSame(0, $j->first());
        $this->assertSame(-1, $j->last());
    }

    public function testHighByteOrdering(): void
    {
        $hi = new Judy(Judy::STRING_TO_MIXED);
        $hi["\x7f"] = 1;
        $hi["\x80"] = 2;
        $hi["\xff"] = 3;

        $keys = $hi->keys();
        $this->assertSame(["\x7f", "\x80", "\xff"], $keys);
        $this->assertSame("\x7f", $hi->first());
        $this->assertSame("\xff", $hi->last());
    }

    public function testFromArrayWithOptimizeIteration(): void
    {
        $j = Judy::fromArray(Judy::INT_TO_INT, [1 => 10], true);
        $this->assertSame([1 => 10], $j->toArray());

        $ref = new \ReflectionMethod(Judy::class, 'fromArray');
        $params = $ref->getParameters();
        $this->assertFalse($params[2]->getDefaultValue());
    }

    /**
     * Requiring the Composer autoloader must NOT compile src/Judy.php.
     *
     * This is what makes mutation testing possible. Infection swaps the file in
     * at include time, and vendor/bin/phpunit requires vendor/autoload.php to
     * boot itself — so anything that loads the class from the autoloader puts
     * the ORIGINAL code in memory before Infection can intercept it. That is
     * not a visible failure: the suite stays green for every mutant and MSI
     * reports 0%, which reads like "the tests are worthless" rather than "the
     * measurement is broken". It cost a nightly-failure issue to find once.
     *
     * A subprocess is the only place to observe this: by the time any test in
     * this class runs, the class is loaded by definition.
     */
    public function testAutoloaderDoesNotEagerlyLoadTheClass(): void
    {
        $autoload = \dirname(__DIR__) . '/vendor/autoload.php';
        $this->assertFileExists($autoload);

        $code = 'require ' . \var_export($autoload, true) . ';'
            . ' echo class_exists(' . \var_export(Judy::class, true) . ', false) ? "EAGER" : "LAZY";';

        $output = \shell_exec(\escapeshellarg(PHP_BINARY) . ' -r ' . \escapeshellarg($code) . ' 2>&1');

        $this->assertSame(
            'LAZY',
            \trim((string) $output),
            'src/bootstrap.php must not force the polyfill class to load from the autoloader; '
            . 'doing so silently reduces Infection MSI to 0%.'
        );
    }
}
