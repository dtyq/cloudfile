<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Kernel;

use Dtyq\CloudFile\Kernel\Utils\SimpleUpload\AliyunSimpleUpload;
use Dtyq\CloudFile\Kernel\Utils\SimpleUpload\TosSimpleUpload;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use OSS\OssClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tos\TosClient;

/**
 * 用 Mock 官方客户端验证 STS 标签操作与删除确认，不访问真实存储。
 *
 * @internal
 * @coversNothing
 */
class CredentialObjectMutationTest extends TestCase
{
    /**
     * OSS 客户端必须收到会话令牌，标签清空不执行对象删除。
     */
    public function testOssTaggingUsesSessionCredentials(): void
    {
        $sdk = $this->createMock(OssClient::class);
        $sdk->expects(self::once())->method('putObjectTagging')->with('mock-bucket', 'mock-org/1001/a.txt', self::callback(static fn ($config): bool => $config->getTags()[0]->getValue() === 'mock'));
        $sdk->expects(self::once())->method('deleteObjectTagging');
        $sdk->expects(self::never())->method('deleteObject');
        $upload = $this->getMockBuilder(AliyunSimpleUpload::class)->setConstructorArgs([$this->sdk()])->onlyMethods(['createOssClient'])->getMock();
        $upload->method('createOssClient')->willReturnCallback(static function (array $config) use ($sdk): OssClient {
            self::assertSame('mock-session', $config['securityToken']);
            self::assertSame('mock-id', $config['accessKeyId']);
            return $sdk;
        });
        $credential = ['temporary_credential' => ['region' => 'oss-cn-shenzhen', 'bucket' => 'mock-bucket', 'dir' => 'mock-org/1001/', 'access_key_id' => 'mock-id', 'access_key_secret' => 'mock-secret', 'sts_token' => 'mock-session']];
        $upload->setTagsByCredential($credential, ['mock-org/1001/a.txt'], [['key' => 'source', 'value' => 'mock']]);
        $upload->setTagsByCredential($credential, ['mock-org/1001/a.txt'], []);
    }

    /**
     * TOS 客户端必须使用 STS 三字段，按对象调用标签设置和清空接口。
     */
    public function testTosTaggingUsesSessionCredentials(): void
    {
        $sdk = $this->getMockBuilder(TosClient::class)->disableOriginalConstructor()->addMethods(['putObjectTagging', 'deleteObjectTagging', 'deleteObject'])->getMock();
        $sdk->expects(self::once())->method('putObjectTagging')->with(self::callback(static fn ($input): bool => $input->getBucket() === 'mock-bucket' && $input->getKey() === 'mock-org/1001/a.txt' && $input->getTagSet()->getTags()[0]->getValue() === 'mock'));
        $sdk->expects(self::once())->method('deleteObjectTagging');
        $sdk->expects(self::never())->method('deleteObject');
        $upload = $this->getMockBuilder(TosSimpleUpload::class)->setConstructorArgs([$this->sdk()])->onlyMethods(['createTosClient'])->getMock();
        $upload->method('createTosClient')->willReturnCallback(static function (array $config) use ($sdk): TosClient {
            self::assertSame('mock-session', $config['securityToken']);
            self::assertSame('mock-id', $config['ak']);
            return $sdk;
        });
        $credential = ['temporary_credential' => ['region' => 'cn-beijing', 'endpoint' => 'https://tos.example.test', 'bucket' => 'mock-bucket', 'dir' => 'mock-org/1001/', 'credentials' => ['AccessKeyId' => 'mock-id', 'SecretAccessKey' => 'mock-secret', 'SessionToken' => 'mock-session']]];
        $upload->setTagsByCredential($credential, ['mock-org/1001/a.txt'], [['key' => 'source', 'value' => 'mock']]);
        $upload->setTagsByCredential($credential, ['mock-org/1001/a.txt'], []);
    }

    /**
     * OSS 批量删除详细响应只确认部分路径时，不将遗漏目标伪造为成功。
     */
    public function testOssDeletionUsesActualResponse(): void
    {
        $sdk = $this->createMock(OssClient::class);
        $sdk->expects(self::once())->method('deleteObjects')->with('mock-bucket', ['mock-a', 'mock-b'], ['quiet' => false])->willReturn([simplexml_load_string('<Key>mock-a</Key>')]);
        $upload = $this->getMockBuilder(AliyunSimpleUpload::class)->setConstructorArgs([$this->sdk()])->onlyMethods(['createOssClient'])->getMock();
        $upload->method('createOssClient')->willReturn($sdk);
        $credential = ['temporary_credential' => ['region' => 'oss-cn-shenzhen', 'bucket' => 'mock-bucket', 'dir' => 'mock-org/1001/', 'access_key_id' => 'mock-id', 'access_key_secret' => 'mock-secret', 'sts_token' => 'mock-session']];
        $result = $upload->deleteObjectsByCredential($credential, ['mock-a', 'mock-b']);
        self::assertSame(['mock-a'], array_column($result['deleted'], 'key'));
    }

    /**
     * 构造无外部依赖且不输出日志的测试 SDK。
     */
    private function sdk(): SdkBase
    {
        $container = new Container();
        $container->set(LoggerInterface::class, new NullLogger());
        return new SdkBase($container, ['sdk_name' => 'mock-sts']);
    }
}
