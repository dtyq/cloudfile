<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Kernel;

use Aws\Result;
use Aws\S3\S3Client;
use Dtyq\CloudFile\Kernel\Driver\ExpandInterface;
use Dtyq\CloudFile\Kernel\Driver\OSS\OSSExpand;
use Dtyq\CloudFile\Kernel\Driver\S3\S3Expand;
use Dtyq\CloudFile\Kernel\Driver\TOS\TOSExpand;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use InvalidArgumentException;
use OSS\Http\ResponseCore;
use OSS\OssClient;
use OSS\Result\DeleteObjectsResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tos\Model\Deleted;
use Tos\Model\DeleteError;
use Tos\Model\DeleteMultiObjectsInput;
use Tos\Model\DeleteMultiObjectsOutput;
use Tos\Model\RequestInfo;
use Tos\TosClient;

/**
 * 验证直连驱动的原生批量删除，仅使用 Mock SDK 和合成路径。
 *
 * @internal
 * @coversNothing
 */
class NativeBatchDeletionTest extends TestCase
{
    /**
     * 验证 100 项输入去重后使用一次原生批量请求。
     *
     * @dataProvider providers
     */
    public function testNativeBatchLimitAndDeduplication(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn (array $paths) => $this->result($provider, $paths));
        $paths = array_map(static fn (int $index): string => 'mock-org/1001/' . $index . '.txt', range(1, 99));
        $expand->destroy([...$paths, $paths[0]]);
        self::assertSame([99], array_map('count', $requests));
        self::assertSame($paths, array_merge(...$requests));
    }

    /**
     * 超过上限时整批拒绝，重复路径也计入输入数量。
     *
     * @dataProvider providers
     */
    public function testOverLimitDoesNotCallSdk(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn (array $paths) => $this->result($provider, $paths));
        $this->expectException(InvalidArgumentException::class);
        try {
            $expand->destroy(array_fill(0, 101, 'mock.txt'));
        } finally {
            self::assertSame([], $requests);
        }
    }

    /**
     * 空集合不访问 SDK。
     *
     * @dataProvider providers
     */
    public function testEmptyPathsDoNotCallSdk(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn (array $paths) => $this->result($provider, $paths));
        $expand->destroy([]);
        self::assertSame([], $requests);
    }

    /**
     * 尾部非法路径必须在首批存储请求之前发现。
     *
     * @dataProvider providers
     */
    public function testAllPathsAreValidatedBeforeFirstBatch(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn (array $paths) => $this->result($provider, $paths));
        $paths = array_map(static fn (int $index): string => 'mock-' . $index, range(1, 99));
        $this->expectException(InvalidArgumentException::class);
        try {
            $expand->destroy([...$paths, '']);
        } finally {
            self::assertSame([], $requests);
        }
    }

    /**
     * HTTP 成功仍可能有逐项失败，必须抛出异常。
     *
     * @dataProvider providers
     */
    public function testPartialFailureThrows(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn (array $paths) => $this->result($provider, array_slice($paths, 0, -1), [end($paths)]));
        $paths = array_map(static fn (int $index): string => 'mock-' . $index, range(1, 100));
        $this->expectException(CloudFileException::class);
        try {
            $expand->destroy($paths);
        } finally {
            self::assertCount(1, $requests);
        }
    }

    /**
     * 缺失成功响应不能按请求级成功处理。
     *
     * @dataProvider providers
     */
    public function testMissingConfirmationFails(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn () => $this->result($provider, []));
        $this->expectException(CloudFileException::class);
        $expand->destroy(['mock-a', 'mock-b']);
    }

    /**
     * 重复成功项不能掩盖遗漏目标，不能只比较返回数量。
     *
     * @dataProvider providers
     */
    public function testDuplicateSuccessDoesNotHideMissingPath(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn () => $this->result($provider, ['mock-a', 'mock-a']));
        $this->expectException(CloudFileException::class);
        $expand->destroy(['mock-a', 'mock-b']);
    }

    /**
     * 非请求成功项不能替代本次未确认成功的目标。
     *
     * @dataProvider providers
     */
    public function testUnrequestedSuccessDoesNotHideMissingPath(string $provider): void
    {
        $requests = [];
        $expand = $this->expand($provider, $requests, fn () => $this->result($provider, ['mock-a', 'outside']));
        $this->expectException(CloudFileException::class);
        $expand->destroy(['mock-a', 'mock-b']);
    }

    /**
     * 原生异常保持对象和错误码，不重试。
     *
     * @dataProvider providers
     */
    public function testRequestFailurePreservesOriginalException(string $provider): void
    {
        $requests = [];
        $failure = new RuntimeException('mock-provider-failure', 503);
        $expand = $this->expand($provider, $requests, static function () use ($failure): void { throw $failure; });
        try {
            $expand->destroy(array_map(static fn (int $index): string => 'mock-' . $index, range(1, 100)));
            self::fail('原生失败必须传播');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertCount(1, $requests);
        }
    }

    /**
     * 所有直连驱动使用相同的批量完成语义。
     */
    public function providers(): array
    {
        return [['oss'], ['tos'], ['s3']];
    }

    /**
     * TOS 和 S3 的显式失败项优先于成功清单，不能只检查遗漏。
     */
    public function testExplicitErrorsFailEvenWhenDeletedListIsComplete(): void
    {
        foreach (['tos', 's3'] as $provider) {
            $requests = [];
            $expand = $this->expand($provider, $requests, fn () => $this->result($provider, ['mock-a'], ['mock-a']));
            try {
                $expand->destroy(['mock-a']);
                self::fail('显式失败不能当作成功');
            } catch (CloudFileException $exception) {
                self::assertSame('批量删除未全部成功', $exception->getMessage());
            }
        }
    }

    /**
     * S3 失败项即使缺少 Key，也不能因字段提取丢失而被当作成功。
     */
    public function testS3MalformedErrorIsNotIgnored(): void
    {
        $requests = [];
        $expand = $this->expand('s3', $requests, static fn () => new Result([
            'Deleted' => [['Key' => 'mock-a']],
            'Errors' => [['Code' => 'AccessDenied']],
        ]));
        $this->expectException(CloudFileException::class);
        $expand->destroy(['mock-a']);
    }

    /**
     * 注入 Mock SDK 并核对平台请求结构，不初始化真实客户端。
     */
    private function expand(string $provider, array &$requests, callable $reply): ExpandInterface
    {
        $classes = ['oss' => OSSExpand::class, 'tos' => TOSExpand::class, 's3' => S3Expand::class];
        $expand = (new ReflectionClass($classes[$provider]))->newInstanceWithoutConstructor();
        if ($provider === 'oss') {
            $client = $this->createMock(OssClient::class);
            $client->method('deleteObjects')->willReturnCallback(static function ($bucket, $paths, $options) use (&$requests, $reply) {
                self::assertSame('mock-bucket', $bucket);
                self::assertSame(['quiet' => false], $options);
                $requests[] = $paths;
                return $reply($paths);
            });
            $this->inject($expand, 'bucket', 'mock-bucket');
        } elseif ($provider === 'tos') {
            $client = $this->getMockBuilder(TosClient::class)->disableOriginalConstructor()->addMethods(['deleteMultiObjects', 'deleteObject'])->getMock();
            $client->method('deleteMultiObjects')->willReturnCallback(static function (DeleteMultiObjectsInput $input) use (&$requests, $reply) {
                self::assertSame('mock-bucket', $input->getBucket());
                self::assertFalse($input->isQuiet());
                $paths = array_map(static fn ($object): string => $object->getKey(), $input->getObjects());
                $requests[] = $paths;
                return $reply($paths);
            });
            $this->inject($expand, 'config', ['bucket' => 'mock-bucket']);
        } else {
            $client = $this->getMockBuilder(S3Client::class)->disableOriginalConstructor()->addMethods(['deleteObjects', 'deleteObject'])->getMock();
            $client->method('deleteObjects')->willReturnCallback(static function (array $input) use (&$requests, $reply) {
                self::assertSame('mock-bucket', $input['Bucket']);
                self::assertFalse($input['Delete']['Quiet']);
                self::assertSame(['Bucket', 'Delete'], array_keys($input));
                $paths = array_column($input['Delete']['Objects'], 'Key');
                $requests[] = $paths;
                return $reply($paths);
            });
            $this->inject($expand, 'config', ['bucket' => 'mock-bucket']);
        }
        $client->expects(self::never())->method('deleteObject');
        $this->inject($expand, 'client', $client);
        return $expand;
    }

    /**
     * 构造真实 SDK 响应模型，OSS 特别使用实际 XML 解析器。
     */
    private function result(string $provider, array $deleted, array $failed = [])
    {
        if ($provider === 'oss') {
            $xml = '<DeleteResult>';
            foreach ($deleted as $path) {
                $xml .= '<Deleted><Key>' . htmlspecialchars($path, ENT_XML1) . '</Key></Deleted>';
            }
            foreach ($failed as $path) {
                $xml .= '<Error><Key>' . htmlspecialchars($path, ENT_XML1) . '</Key><Code>AccessDenied</Code></Error>';
            }
            return (new DeleteObjectsResult(new ResponseCore([], $xml . '</DeleteResult>', 200)))->getData();
        }
        if ($provider === 'tos') {
            $info = new RequestInfo();
            return new DeleteMultiObjectsOutput($info, array_map(static fn (string $path) => new Deleted($path), $deleted), array_map(static fn (string $path) => new DeleteError($path, '', 'AccessDenied', 'mock-denied'), $failed));
        }
        return new Result([
            'Deleted' => array_map(static fn (string $path): array => ['Key' => $path], $deleted),
            'Errors' => array_map(static fn (string $path): array => ['Key' => $path, 'Code' => 'AccessDenied'], $failed),
        ]);
    }

    /**
     * 为隔离测试注入属性，不启动容器。
     * @param mixed $value
     */
    private function inject(object $target, string $name, $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
