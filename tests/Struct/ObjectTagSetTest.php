<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Struct;

use Dtyq\CloudFile\Kernel\Struct\ObjectTagSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 验证完整对象标签集的结构、字符和边界，不依赖存储 SDK。
 *
 * @internal
 * @coversNothing
 */
class ObjectTagSetTest extends TestCase
{
    /**
     * 验证大小写、空值、中文和完整清空均保留原意。
     */
    public function testTagValuesArePreserved(): void
    {
        $tags = [['key' => 'Source', 'value' => ''], ['key' => 'source', 'value' => '合成/标签']];
        self::assertSame($tags, (new ObjectTagSet($tags))->toArray());
        self::assertSame([], (new ObjectTagSet([]))->toArray());
        $maximum = [['key' => str_repeat('合', 128), 'value' => str_repeat('成', 256)]];
        self::assertSame($maximum, (new ObjectTagSet($maximum))->toArray());
    }

    /**
     * 验证非法输入在任何远端写入之前拒绝。
     * @dataProvider invalidTags
     */
    public function testInvalidTagSetsAreRejected(array $tags): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ObjectTagSet($tags);
    }

    /**
     * 提供错误结构、重复键、非法字符和长度超限集合。
     */
    public function invalidTags(): array
    {
        return [
            [[['key' => '', 'value' => 'mock']]],
            [[['key' => 'mock', 'value' => null]]],
            [[['key' => 'mock', 'value' => 1]]],
            [[['key' => 'mock', 'value' => '<invalid>']]],
            [[['key' => 'mock', 'value' => "invalid\n"]]],
            [[['key' => 'mock', 'value' => 'value', 'extra' => 'field']]],
            [[['key' => str_repeat('合', 129), 'value' => 'mock']]],
            [[['key' => 'mock', 'value' => str_repeat('成', 257)]]],
            [[['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']]],
            [array_fill(0, 11, ['key' => 'mock', 'value' => 'value'])],
            [['mock' => ['key' => 'mock', 'value' => 'value']]],
        ];
    }
}
