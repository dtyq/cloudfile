<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Kernel;

use Aws\S3\S3Client;
use Dtyq\CloudFile\Kernel\Driver\ExpandInterface;
use Dtyq\CloudFile\Kernel\Driver\OSS\OSSExpand;
use Dtyq\CloudFile\Kernel\Driver\S3\S3Expand;
use Dtyq\CloudFile\Kernel\Driver\TOS\TOSExpand;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\FilesystemProxy;
use InvalidArgumentException;
use OSS\OssClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tos\TosClient;

/**
 * 验证直接存储驱动的原生标签调用，不使用真实账号或对象。
 *
 * @internal
 * @coversNothing
 */
class ObjectTaggingTest extends TestCase
{
    /**
     * 验证 OSS 标签写入与清空批量使用相同标签集，不删除对象。
     */
    public function testOssTagsAndClearingUseNativeSdk(): void
    {
        $expand = (new ReflectionClass(OSSExpand::class))->newInstanceWithoutConstructor();
        $client = $this->createMock(OssClient::class);
        $this->inject($expand, 'client', $client);
        $this->inject($expand, 'bucket', 'mock-bucket');
        $client->expects(self::exactly(2))->method('putObjectTagging')->with('mock-bucket', self::anything(), self::callback(static function ($config): bool {
            $tags = $config->getTags();
            return count($tags) === 2 && $tags[0]->getKey() === 'source' && $tags[0]->getValue() === 'mock' && $tags[1]->getValue() === '';
        }));
        $client->expects(self::exactly(2))->method('deleteObjectTagging')->with('mock-bucket', self::anything());
        $client->expects(self::never())->method('deleteObject');
        $paths = ['mock-a.txt', 'mock-b.txt'];
        $expand->setTags($paths, [['key' => 'source', 'value' => 'mock'], ['key' => 'empty', 'value' => '']]);
        $expand->setTags($paths, []);
    }

    /**
     * 验证 TOS 请求模型绑定 bucket、目标与完整标签集，清空不删文件。
     */
    public function testTosTagsAndClearingUseNativeSdk(): void
    {
        $expand = (new ReflectionClass(TOSExpand::class))->newInstanceWithoutConstructor();
        $client = $this->getMockBuilder(TosClient::class)->disableOriginalConstructor()->addMethods(['putObjectTagging', 'deleteObjectTagging', 'deleteObject'])->getMock();
        $this->inject($expand, 'client', $client);
        $this->inject($expand, 'config', ['bucket' => 'mock-bucket']);
        $client->expects(self::exactly(2))->method('putObjectTagging')->with(self::callback(static function ($input): bool {
            $tags = $input->getTagSet()->getTags();
            return $input->getBucket() === 'mock-bucket' && in_array($input->getKey(), ['mock-a.txt', 'mock-b.txt'], true) && $tags[0]->getKey() === 'source' && $tags[0]->getValue() === 'mock';
        }));
        $client->expects(self::exactly(2))->method('deleteObjectTagging')->with(self::callback(static fn ($input): bool => $input->getBucket() === 'mock-bucket'));
        $client->expects(self::never())->method('deleteObject');
        $paths = ['mock-a.txt', 'mock-b.txt'];
        $expand->setTags($paths, [['key' => 'source', 'value' => 'mock']]);
        $expand->setTags($paths, []);
    }

    /**
     * 验证 S3 兼容存储使用标准大小写字段和原生标签删除调用。
     */
    public function testS3TagsAndClearingUseNativeSdk(): void
    {
        $expand = (new ReflectionClass(S3Expand::class))->newInstanceWithoutConstructor();
        $client = $this->getMockBuilder(S3Client::class)->disableOriginalConstructor()->addMethods(['putObjectTagging', 'deleteObjectTagging', 'deleteObject'])->getMock();
        $this->inject($expand, 'client', $client);
        $this->inject($expand, 'config', ['bucket' => 'mock-bucket']);
        $client->expects(self::exactly(2))->method('putObjectTagging')->with(self::callback(static fn (array $input): bool => $input['Bucket'] === 'mock-bucket' && $input['Tagging']['TagSet'] === [['Key' => 'source', 'Value' => 'mock']]));
        $client->expects(self::exactly(2))->method('deleteObjectTagging')->with(self::callback(static fn (array $input): bool => ! isset($input['Tagging'])));
        $client->expects(self::never())->method('deleteObject');
        $paths = ['mock-a.txt', 'mock-b.txt'];
        $expand->setTags($paths, [['key' => 'source', 'value' => 'mock']]);
        $expand->setTags($paths, []);
    }

    /**
     * 验证直接驱动继续传播存储失败，不受文件服务降级策略影响。
     */
    public function testDirectDriverFailureStillPropagates(): void
    {
        $expand = (new ReflectionClass(OSSExpand::class))->newInstanceWithoutConstructor();
        $client = $this->createMock(OssClient::class);
        $this->inject($expand, 'client', $client);
        $this->inject($expand, 'bucket', 'mock-bucket');
        $client->method('putObjectTagging')->willThrowException(new RuntimeException('mock provider failure', 403));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $expand->setTags(['mock.txt'], [['key' => 'source', 'value' => 'mock']]);
    }

    /**
     * 验证没有标签能力的外部扩展保留既有接口，标签调用明确拒绝。
     */
    public function testUnsupportedExpandIsRejected(): void
    {
        $proxy = (new ReflectionClass(FilesystemProxy::class))->newInstanceWithoutConstructor();
        $expand = $this->createMock(ExpandInterface::class);
        $this->inject($proxy, 'expand', $expand);
        $this->inject($proxy, 'adapterName', 'local');
        $this->expectException(CloudFileException::class);
        $proxy->setTags(['mock.txt'], []);
    }

    /**
     * 所有直连驱动的标签调用均在访问 SDK 前拒绝超过 100 项的输入。
     *
     * @dataProvider taggingDrivers
     */
    public function testTagsRejectOverLimit(string $driver): void
    {
        $expand = (new ReflectionClass($driver))->newInstanceWithoutConstructor();
        $this->expectException(InvalidArgumentException::class);
        $expand->setTags(array_fill(0, 101, 'mock.txt'), []);
    }

    /**
     * 提供具有标签能力的直连驱动。
     */
    public function taggingDrivers(): array
    {
        return [[OSSExpand::class], [TOSExpand::class], [S3Expand::class]];
    }

    /**
     * 公共入口在去重与驱动访问前校验上限，避免重复路径绕过限制。
     *
     * @dataProvider mutationMethods
     */
    public function testProxyRejectsOverLimit(string $method): void
    {
        $proxy = (new ReflectionClass(FilesystemProxy::class))->newInstanceWithoutConstructor();
        $this->expectException(InvalidArgumentException::class);
        $paths = array_fill(0, 101, 'mock.txt');
        if ($method === 'setTags') {
            $proxy->setTags($paths, []);
        } else {
            $proxy->{$method}($paths);
        }
    }

    /**
     * 提供统一受路径数量限制的公开入口。
     */
    public function mutationMethods(): array
    {
        return [['setTags'], ['deleteTags'], ['destroy']];
    }

    /**
     * 向跳过构造的扩展注入 Mock 客户端与合成配置。
     */
    private function inject(object $target, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
