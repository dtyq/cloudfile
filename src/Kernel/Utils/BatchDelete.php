<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Kernel\Utils;

use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;

/**
 * 统一原生同步批量删除的完成判定。
 */
final class BatchDelete
{
    /**
     * 详细响应必须确认所有请求目标成功，失败项及遗漏项均不能当作成功。
     *
     * @param array<string> $requested
     * @param array<string> $deleted
     * @param array<mixed> $failed
     */
    public static function assertSucceeded(array $requested, array $deleted, array $failed = []): void
    {
        if ($failed !== [] || array_diff($requested, $deleted) !== []) {
            throw new CloudFileException('批量删除未全部成功');
        }
    }
}
