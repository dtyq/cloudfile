<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Kernel\Driver;

/**
 * 对象标签的可选扩展能力，避免给既有存储扩展增加必选方法。
 */
interface ObjectTaggingInterface
{
    /**
     * 为一批对象替换同一份完整标签集，空标签集合表示清空。
     *
     * @param array<string> $paths
     * @param array<array{key: string, value: string}> $tags
     */
    public function setTags(array $paths, array $tags, array $options = []): void;
}
