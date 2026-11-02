<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Kernel\Struct;

use InvalidArgumentException;

/**
 * 校验对象标签的完整集合，统一文件服务与直接存储驱动的输入格式。
 */
class ObjectTagSet
{
    private array $tags;

    /**
     * 校验标签列表、唯一键、长度与各存储平台共同支持的字符范围。
     *
     * @param array<array{key: string, value: string}> $tags
     */
    public function __construct(array $tags)
    {
        if (! array_is_list($tags) || count($tags) > 10) {
            throw new InvalidArgumentException('tags 必须是最多 10 项的标签列表');
        }
        $keys = [];
        foreach ($tags as $tag) {
            if (! is_array($tag) || count($tag) !== 2 || ! isset($tag['key'], $tag['value'])
                || ! is_string($tag['key']) || ! is_string($tag['value'])
                || ! preg_match('/^[\p{L}\p{N} +\-=._:\/]{1,128}\z/u', $tag['key'])
                || ! preg_match('/^[\p{L}\p{N} +\-=._:\/]{0,256}\z/u', $tag['value'])
                || in_array($tag['key'], $keys, true)) {
                throw new InvalidArgumentException('标签键值格式错误、超过长度限制或键重复');
            }
            $keys[] = $tag['key'];
        }
        $this->tags = $tags;
    }

    /**
     * 返回标签列表，保留原有大小写及空字符串值。
     *
     * @return array<array{key: string, value: string}>
     */
    public function toArray(): array
    {
        return $this->tags;
    }
}
