<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Kernel\Utils;

use InvalidArgumentException;

/**
 * 统一文件标签与删除的路径上限、校验和去重规则。
 */
final class FileMutationPaths
{
    private const MAX_FILES = 100;

    /**
     * 与文件服务一致，在去重前限制最多 100 项，空集合保持无操作。
     *
     * @param array<string> $paths
     * @return array<string>
     */
    public static function normalize(array $paths): array
    {
        if (! array_is_list($paths) || count($paths) > self::MAX_FILES) {
            throw new InvalidArgumentException('file_paths 必须是最多 100 项的路径列表');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                throw new InvalidArgumentException('文件路径必须是非空字符串');
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * 整批确认对象处于 STS 返回的授权目录内，拒绝路径歧义与越界目标。
     *
     * @param array<string> $paths
     */
    public static function assertWithinDirectory(array $paths, string $directory): void
    {
        if ($directory === '' || ! str_ends_with($directory, '/')) {
            throw new InvalidArgumentException('STS 缺少有效的授权目录');
        }
        foreach ($paths as $path) {
            if (strlen($path) > 1024 || ! str_starts_with($path, $directory) || preg_match('/[\\\\\x00-\x1f\x7f]|%[0-9a-f]{2}/i', $path)) {
                throw new InvalidArgumentException('文件路径超出 STS 授权目录或格式无效');
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException('文件路径不能包含空段或相对目录');
                }
            }
        }
    }
}
