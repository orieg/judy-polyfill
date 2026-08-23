<?php

namespace Orieg\JudyPolyfill\Tests;

use PHPUnit\Framework\TestCase;
use Orieg\JudyPolyfill\Judy;

class JudyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/Judy.php';
        require_once __DIR__ . '/../src/bootstrap.php';
    }

    public function testConstructValidTypes(): void
    {
        for ($t = Judy::BITSET; $t <= Judy::STRING_TO_ENTRY; $t++) {
            $j = new Judy($t);
            $this->assertSame($t, $j->getType());
            $this->assertFalse($j->isIterationOptimized());
        }
    }

    public function testConstructInvalidType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a valid Judy type');
        new Judy(999);
    }

    public function testConstructInvalidTypeLow(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a valid Judy type');
        new Judy(0);
    }

    public function testDestruct(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $j[1] = 10;
        unset($j);
        $this->assertTrue(true);
    }

    public function testIntToIntOperations(): void
    {
        $j = new Judy(Judy::INT_TO_INT);
        $this->assertCount(0, $j);
        $this->assertSame(0, $j->size());
        $this->assertNull($j[999]);
        $this->assertFalse(isset($j[999]));

        $j[5] = 50;
        $j[1] = 10;
        $j[300] = 3000;

        $this->assertCount(3, $j);
        $this->assertSame(50, $j[5]);
        $this->assertTrue(isset($j[5]));
        $this->assertSame([1 => 10, 5 => 50, 300 => 3000], $j->toArray());
        $this->assertSame([1, 5, 300], $j->keys());
        $this->assertSame([10, 50, 3000], $j->values());

        $this->assertSame(1, $j->first());
        $this->assertSame(300, $j->last());
        $this->assertSame(5, $j->first(2));
        $this->assertSame(300, $j->searchNext(5));
        $this->assertSame(5, $j->prev(300));
        $this->assertNull($j->searchNext(300));
        $this->assertNull($j->prev(1));

        $this->assertSame(1, $j->byCount(1));
        $this->assertSame(5, $j->byCount(2));
        $this->assertSame(300, $j->byCount(3));
        $this->assertNull($j->byCount(0));
        $this->assertNull($j->byCount(4));

        $this->assertSame(2, $j->size(2, 400));
        $this->assertSame(3, $j->size(0, -1));

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
    }

    public function testBitsetSemantics(): void
    {
        $b = new Judy(Judy::BITSET);
        $this->assertNull($b[4]);

        $b[9] = true;
        $b[2] = true;
        $b[5] = false;

        $this->assertSame([2, 9], $b->toArray());
        $this->assertFalse($b[4]);
        $this->assertTrue($b[9]);
        $this->assertSame([2, 9], $b->values());
        $this->assertSame(2, $b->sumValues());
        $this->assertSame(1.0, $b->averageValues());
        $this->assertSame(2, $b->populationCount());

        $bFrom = Judy::fromArray(Judy::BITSET, [4, 7]);
        $this->assertSame([4, 7], $bFrom->toArray());

        $bSliced = $bFrom->slice(4, 7);
        $this->assertSame([4, 7], $bSliced->toArray());
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
        $this->assertSame('aa', $s->prev('zz'));
        $this->assertNull($s->byCount(1));
        $this->assertGreaterThan(0, $s->memoryUsage());

        $this->assertSame(['aa' => 2, 'missing' => null], $s->getAll(['aa', 'missing']));
        $this->assertSame(3, $s->size());
        $this->assertSame(1, $s->size('a', 'b'));
        $this->assertSame(['aa'], $s->keys('a', 'b'));
        $this->assertSame([2], $s->values('a', 'b'));

        $this->assertSame(2, $s->increment('zz'));
        $this->assertSame(13, $s->sumValues());
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
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('keys must not contain embedded null bytes');
            $j[$nulKey] = 1;
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
    }

    public function testStringToEntryTypeErrorsOnOtherTypes(): void
    {
        $intJ = new Judy(Judy::INT_TO_INT);

        $this->expectException(\TypeError::class);
        $intJ->set('key', 123);
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
}
