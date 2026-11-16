<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Kernel;

use Dtyq\CloudFile\Kernel\Driver\FileService\FileServiceDriver;
use Dtyq\CloudFile\Kernel\Driver\S3\S3Driver;
use Dtyq\CloudFile\Kernel\Driver\TOS\TOSDriver;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * 验证目录查询的驱动委托及明确不支持的行为，不访问真实存储。
 * @internal
 * @coversNothing
 */
class DriverDirectoryExistsTest extends TestCase
{
    /**
     * 验证目录存在与不存在时都透传原路径和查询结果。
     * @dataProvider directoryResults
     */
    public function testS3DelegatesDirectoryQuery(bool $exists): void
    {
        $adapter = $this->createMock(AwsS3V3Adapter::class);
        $adapter->expects($this->once())
            ->method('directoryExists')
            ->with('mock-directory/nested/')
            ->willReturn($exists);

        $this->assertSame($exists, $this->s3Driver($adapter)->directoryExists('mock-directory/nested/'));
    }

    /**
     * 提供目录查询的两种布尔结果。
     */
    public static function directoryResults(): array
    {
        return [
            'exists' => [true],
            'missing' => [false],
        ];
    }

    /**
     * 验证查询异常保持原实例，不被转换为目录不存在。
     */
    public function testS3PropagatesDirectoryQueryFailure(): void
    {
        $failure = new RuntimeException('mock directory query failure');
        $adapter = $this->createMock(AwsS3V3Adapter::class);
        $adapter->expects($this->once())
            ->method('directoryExists')
            ->with('mock-directory')
            ->willThrowException($failure);

        try {
            $this->s3Driver($adapter)->directoryExists('mock-directory');
            $this->fail('查询异常必须透传。');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * 验证不支持目录的驱动无需创建客户端即可明确失败。
     * @dataProvider unsupportedDrivers
     */
    public function testUnsupportedDirectoryQuery(string $driverClass): void
    {
        $driver = (new ReflectionClass($driverClass))->newInstanceWithoutConstructor();

        $this->expectException(CloudFileException::class);
        $this->expectExceptionMessage('暂不支持');
        $driver->directoryExists('mock-directory');
    }

    /**
     * 提供当前不支持目录查询的内置驱动。
     */
    public static function unsupportedDrivers(): array
    {
        return [
            'tos' => [TOSDriver::class],
            'file-service' => [FileServiceDriver::class],
        ];
    }

    /**
     * 注入 Mock 适配器并跳过真实 S3 客户端构造。
     */
    private function s3Driver(AwsS3V3Adapter $adapter): S3Driver
    {
        $driver = (new ReflectionClass(S3Driver::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(S3Driver::class, 'adapter');
        $property->setAccessible(true);
        $property->setValue($driver, $adapter);
        return $driver;
    }
}
