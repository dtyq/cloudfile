<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Integration;

use Dtyq\CloudFile\CloudFile;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\FilesystemProxy;
use Dtyq\CloudFile\Kernel\Struct\CredentialPolicy;
use Dtyq\CloudFile\Tests\CloudFileBaseTest;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 使用真实配置分别测试标签和删除，仅操作随机生成的文件。
 *
 * @group integration
 * @internal
 * @coversNothing
 */
class RealFileMutationTest extends CloudFileBaseTest
{
    private const OPTIONS = ['cache' => false, 'internal_endpoint' => false];

    private string $storageName = 'file_service_test';

    private object $logger;

    /**
     * 真实写入需要显式开启。
     */
    public function setUp(): void
    {
        parent::setUp();
        if (getenv('CLOUDFILE_RUN_REAL_TESTS') !== '1') {
            $this->markTestSkipped('设置 CLOUDFILE_RUN_REAL_TESTS=1 后运行');
        }
    }

    /**
     * 设置、清空标签，文件应保持存在。
     *
     * @dataProvider storages
     */
    public function testTags(string $storage): void
    {
        [$filesystem, $policy, $prefix, $paths] = $this->fixture($storage);
        try {
            $this->createFiles($filesystem, $policy, $paths);
            $filesystem->setTags($paths, [['key' => 'verification', 'value' => 'synthetic']], self::OPTIONS);
            $filesystem->deleteTags($paths, self::OPTIONS);
            $this->assertSame(0, $this->logger->warnings, '标签请求不应出现失败日志');
            $this->assertSame(2, $this->objectCount($filesystem, $policy, $prefix));
        } catch (Throwable $exception) {
            throw $this->safeFailure($exception);
        } finally {
            $this->cleanup($filesystem, $policy, $prefix, $paths);
        }
    }

    /**
     * 批量删除后文件不存在，重复删除也应完成。
     *
     * @dataProvider storages
     */
    public function testDestroy(string $storage): void
    {
        [$filesystem, $policy, $prefix, $paths] = $this->fixture($storage);
        try {
            $this->createFiles($filesystem, $policy, $paths);
            $filesystem->destroy($paths, self::OPTIONS);
            $filesystem->destroy($paths, self::OPTIONS);
            $this->assertSame(0, $this->logger->warnings, '删除请求不应出现失败日志');
            $this->assertSame(0, $this->objectCount($filesystem, $policy, $prefix));
        } catch (Throwable $exception) {
            throw $this->safeFailure($exception);
        } finally {
            $this->cleanup($filesystem, $policy, $prefix, $paths);
        }
    }

    /**
     * 复用已有四个真实测试存储。
     */
    public function storages(): array
    {
        return [['file_service_test'], ['file_service_tos_test'], ['aliyun_test'], ['tos_test']];
    }

    /**
     * 沿用基础类的 SDK 创建方式，外部配置只读，日志不输出凭证。
     */
    protected function createCloudFile(): CloudFile
    {
        $path = getenv('CLOUDFILE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/storages.json';
        $configs = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->logger = new class extends NullLogger {
            public int $warnings = 0;

            /**
             * 文件服务只记日志不抛异常，测试通过计数判断请求失败。
             * @param mixed $level
             * @param mixed $message
             */
            public function log($level, $message, array $context = []): void
            {
                if ($level === 'warning') {
                    ++$this->warnings;
                }
            }
        };
        $container = new Container();
        $container->set(LoggerInterface::class, $this->logger);
        return new CloudFile(new SdkBase($container, ['sdk_name' => 'cloudfile-real-test', 'exception_class' => CloudFileException::class, 'cloudfile' => $configs]));
    }

    /**
     * 返回当前数据集的存储名。
     */
    protected function getStorageName(): string
    {
        return $this->storageName;
    }

    /**
     * 只准备随机目录与路径，调用方在 try/finally 内创建文件。
     */
    private function fixture(string $storage): array
    {
        $this->storageName = $storage;
        try {
            $filesystem = $this->createCloudFile()->get($storage);
            $dir = 'cloudfile-real-' . bin2hex(random_bytes(8));
            $policy = new CredentialPolicy(['dir' => $dir, 'expires' => 900, 'sts' => true, 'role_session_name' => 'cloudfile-test', 'sts_type' => 'upload_objects|list_objects|del_objects']);
            $credential = $filesystem->getUploadTemporaryCredential($policy, self::OPTIONS);
            $prefix = rtrim($credential['temporary_credential']['dir'], '/') . '/';
            $this->assertTrue(str_contains($prefix, $dir));
            $this->assertSame(0, $this->objectCount($filesystem, $policy, $prefix));
            return [$filesystem, $policy, $prefix, [$prefix . 'test.txt', $prefix . 'zero.txt']];
        } catch (Throwable $exception) {
            throw $this->safeFailure($exception);
        }
    }

    /**
     * 创建一个普通对象和一个零字节对象。
     */
    private function createFiles(FilesystemProxy $filesystem, CredentialPolicy $policy, array $paths): void
    {
        foreach ($paths as $index => $path) {
            $filesystem->createObjectByCredential($policy, $path, self::OPTIONS + ['content' => $index === 0 ? 'test' : '']);
        }
    }

    /**
     * 测试结束时仅清理本轮文件，并确认目录为空。
     */
    private function cleanup(FilesystemProxy $filesystem, CredentialPolicy $policy, string $prefix, array $paths): void
    {
        try {
            $filesystem->deleteObjectsByCredential($policy, $paths, self::OPTIONS);
            $this->assertSame(0, $this->objectCount($filesystem, $policy, $prefix));
        } catch (Throwable $exception) {
            throw $this->safeFailure($exception);
        }
    }

    /**
     * 只查询本轮随机目录，避免读取已有业务文件。
     */
    private function objectCount(FilesystemProxy $filesystem, CredentialPolicy $policy, string $prefix): int
    {
        $result = $filesystem->listObjectsByCredential($policy, $prefix, self::OPTIONS + ['max-keys' => 10, 'delimiter' => '']);
        return count($result['objects']);
    }

    /**
     * 保留断言结果，不输出 SDK 原始错误中的配置与凭证。
     */
    private function safeFailure(Throwable $exception): AssertionFailedError
    {
        return $exception instanceof AssertionFailedError ? $exception : new AssertionFailedError('真实调用失败：' . get_class($exception) . '，错误码 ' . $exception->getCode());
    }
}
