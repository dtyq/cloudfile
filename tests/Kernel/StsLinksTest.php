<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Kernel;

use Dtyq\CloudFile\CloudFile;
use Dtyq\CloudFile\Kernel\AdapterName;
use Dtyq\CloudFile\Kernel\Driver\ExpandInterface;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\FilesystemProxy;
use Dtyq\CloudFile\Kernel\Struct\CredentialPolicy;
use Dtyq\CloudFile\Kernel\Struct\FileLink;
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;
use Dtyq\CloudFile\Kernel\Utils\SimpleUpload;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use League\Flysystem\FilesystemAdapter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * 验证可选 STS 取链的路由、对象身份和凭证寿命。
 *
 * @internal
 * @coversNothing
 */
class StsLinksTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * 每个存储单独装配开关，默认路线保持原样。
     */
    public function testStorageConfigurationIsIsolated(): void
    {
        $config = [
            'adapter' => 'file_service',
            'config' => [
                'host' => 'https://files.example.invalid',
                'platform' => 'tos',
                'key' => 'mock',
            ],
        ];
        $cloudFile = new CloudFile($this->container([
            'storages' => [
                'enabled' => $config + [
                    'prefer_sts_for_links' => true,
                ],
                'default' => $config,
            ],
        ]));
        $property = new ReflectionProperty(FilesystemProxy::class, 'preferStsForLinks');
        self::assertTrue($property->getValue($cloudFile->get('enabled')));
        self::assertFalse($property->getValue($cloudFile->get('default')));
    }

    /**
     * 字符串 false 不能意外开启 STS。
     */
    public function testConfigurationMustBeBoolean(): void
    {
        $cloudFile = new CloudFile($this->container([
            'storages' => [
                'mock' => [
                    'prefer_sts_for_links' => 'false',
                ],
            ],
        ]));
        $this->expectException(CloudFileException::class);
        $cloudFile->get('mock');
    }

    /**
     * STS 分支保留字面量百分号，整批获取一次凭证，返回兼容的下载名和图片链接。
     *
     * @dataProvider platforms
     */
    public function testBatchKeepsOriginalKeys(string $adapter, string $platform): void
    {
        [$filesystem, $expand, $signer] = $this->filesystem($adapter, $platform);
        $deadline = time() + 600;
        $credential = [
            'platform' => $platform,
            'temporary_credential' => [
                'token' => 'mock',
            ],
            'expires' => $deadline,
        ];
        $expand->shouldReceive('getUploadCredential')
            ->once()
            ->withArgs(static function (CredentialPolicy $policy, array $options): bool {
                return $policy->isSts() && $policy->getExpires() === 3600 && ! isset($options['expires']);
            })
            ->andReturn($credential);
        $paths = [
            'demo/30%.png',
            'demo/a%20b.png',
            'demo/a%25b.png',
            'demo/a%2Fb.png',
            'demo/a%%b.png',
        ];
        $image = (new ImageProcessOptions())->resize([
            'width' => 32,
        ]);
        foreach ($paths as $index => $path) {
            $signer->shouldReceive('getPreSignedUrlByCredential')
                ->once()
                ->withArgs(static function (array $received, string $key, array $options) use ($credential, $path, $image, $index): bool {
                    return $received === $credential && $key === $path && $options['method'] === 'GET'
                        && $options['expires'] > 0 && $options['expires'] <= 540 && $options['image'] === $image
                        && ($index !== 0 || ($options['filename'] === '下载30%.png' && $options['download'] === true))
                        && ($index === 0 || ! isset($options['filename']));
                })
                ->andReturn('https://read.example.invalid/mock');
        }
        $links = $filesystem->getLinks($paths, [
            $paths[0] => '下载30%.png',
        ], 86400, [
            'method' => 'PUT',
            'image' => $image,
        ]);
        self::assertSame($paths, array_keys($links));
        foreach ($links as $path => $link) {
            self::assertSame($path, $link->getPath());
            if ($adapter === AdapterName::FILE_SERVICE) {
                self::assertSame($deadline - 60, $link->getExpires());
            } else {
                self::assertGreaterThan(0, $link->getExpires());
                self::assertLessThanOrEqual(540, $link->getExpires());
            }
        }
        self::assertSame('下载30%.png', $links[$paths[0]]->getDownloadName());
    }

    /**
     * 覆盖直接存储及文件服务分派的三个支持平台。
     */
    public function platforms(): array
    {
        return [
            [
                AdapterName::FILE_SERVICE,
                AdapterName::TOS,
            ],
            [
                AdapterName::FILE_SERVICE,
                AdapterName::ALIYUN,
            ],
            [
                AdapterName::FILE_SERVICE,
                AdapterName::MINIO,
            ],
            [
                AdapterName::TOS,
                AdapterName::TOS,
            ],
            [
                AdapterName::ALIYUN,
                AdapterName::ALIYUN,
            ],
            [
                AdapterName::MINIO,
                AdapterName::MINIO,
            ],
        ];
    }

    /**
     * 无需凭证的空批次不应调用外部服务。
     */
    public function testEmptyBatchDoesNotFetchCredentials(): void
    {
        [$filesystem] = $this->filesystem();
        self::assertSame([], $filesystem->getLinks([]));
    }

    /**
     * 单文件短期限原样保留，不使用旧链接缓存或公有读拼接。
     */
    public function testSingleLinkUsesStsBeforePublicRead(): void
    {
        [$filesystem, $expand, $signer] = $this->filesystem();
        $filesystem->setIsPublicRead(true);
        $this->setProperty($filesystem, 'publicDomain', 'https://legacy.example.invalid');
        $expand->shouldReceive('getUploadCredential')
            ->once()
            ->andReturn([
                'platform' => 'tos',
                'temporary_credential' => [],
                'expires' => time() + 600,
            ]);
        $signer->shouldReceive('getPreSignedUrlByCredential')
            ->once()
            ->withArgs(static function (array $credential, string $key, array $options): bool {
                return $key === 'demo/30%.png' && $options['expires'] === 30;
            })
            ->andReturn('https://sts.example.invalid/mock');
        self::assertSame('https://sts.example.invalid/mock', $filesystem->getLink('demo/30%.png', '', 30)->getUrl());
    }

    /**
     * 默认关闭及没有 STS 实现的平台沿用旧取链，不申请临时凭证。
     *
     * @dataProvider legacyModes
     */
    public function testLegacyRouteRemainsAvailable(bool $enabled, string $platform): void
    {
        [$filesystem, $expand] = $this->filesystem(AdapterName::FILE_SERVICE, $platform);
        $filesystem->setPreferStsForLinks($enabled);
        $expected = new FileLink('demo/30%25.txt', 'https://legacy.example.invalid/mock', 300);
        $expand->shouldReceive('getFileLinks')
            ->once()
            ->with([
                'demo/30%25.txt',
            ], [], 300, [])
            ->andReturn([
                'demo/30%25.txt' => $expected,
            ]);
        self::assertSame([
            'demo/30%25.txt' => $expected,
        ], $filesystem->getLinks([
            'demo/30%.txt',
        ], [], 300));
    }

    /**
     * 仅列出有不同路由语义的旧模式。
     */
    public function legacyModes(): array
    {
        return [
            [
                false,
                'tos',
            ],
            [
                true,
                'obs',
            ],
        ];
    }

    /**
     * 签名异常应直接传播，不能回退旧的错误对象链接。
     */
    public function testSigningFailureDoesNotFallBack(): void
    {
        [$filesystem, $expand, $signer] = $this->filesystem();
        $expand->shouldReceive('getUploadCredential')
            ->once()
            ->andReturn([
                'platform' => 'tos',
                'temporary_credential' => [],
                'expires' => time() + 600,
            ]);
        $signer->shouldReceive('getPreSignedUrlByCredential')
            ->once()
            ->andThrow(new RuntimeException('mock failure'));
        $this->expectException(RuntimeException::class);
        $filesystem->getLinks([
            'demo/30%.txt',
        ]);
    }

    /**
     * 不同链接期限复用同一份尚未到期的凭证，而不是重复申请 STS。
     */
    public function testCredentialCacheIsReusedAcrossLinkDurations(): void
    {
        $cached = null;
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')
            ->twice()
            ->andReturnUsing(static function () use (&$cached): mixed {
                return $cached;
            });
        $cache->shouldReceive('set')
            ->once()
            ->andReturnUsing(static function (string $key, array $value, int $ttl) use (&$cached): bool {
                $cached = $value;
                return $ttl > 0;
            });
        [$filesystem, $expand, $signer] = $this->filesystem(AdapterName::FILE_SERVICE, AdapterName::TOS, $cache);
        $expand->shouldReceive('getUploadCredential')
            ->once()
            ->andReturn([
                'platform' => 'tos',
                'temporary_credential' => [],
                'expires' => time() + 600,
            ]);
        $signer->shouldReceive('getPreSignedUrlByCredential')
            ->twice()
            ->andReturn('https://sts.example.invalid/mock');
        foreach ([
            30,
            86400,
        ] as $expires) {
            self::assertSame('demo/30%.txt', $filesystem->getLink('demo/30%.txt', '', $expires)->getPath());
        }
    }

    /**
     * 凭证获取失败不应进入旧链接接口。
     */
    public function testCredentialFailureDoesNotFallBack(): void
    {
        [$filesystem, $expand] = $this->filesystem();
        $expand->shouldReceive('getUploadCredential')
            ->once()
            ->andThrow(new RuntimeException('mock credential failure'));
        $this->expectException(RuntimeException::class);
        $filesystem->getLink('demo/30%.txt');
    }

    /**
     * 实际 TOS 截止时间比外层短时应拒绝已失效的签名。
     */
    public function testExpiredProviderCredentialIsRejected(): void
    {
        [$filesystem, $expand] = $this->filesystem();
        $expand->shouldReceive('getUploadCredential')
            ->once()
            ->andReturn([
                'platform' => 'tos',
                'temporary_credential' => [
                    'credentials' => [
                        'ExpiredTime' => gmdate('c', time() - 1),
                    ],
                ],
                'expires' => time() + 600,
            ]);
        $this->expectException(CloudFileException::class);
        $filesystem->getLinks([
            'demo/30%.txt',
        ]);
    }

    /**
     * 构造不触发真实存储调用的代理和两个职责替身。
     */
    private function filesystem(string $adapter = AdapterName::FILE_SERVICE, string $platform = AdapterName::TOS, ?CacheInterface $cache = null): array
    {
        $filesystem = new FilesystemProxy($this->container([], $cache), AdapterName::FILE_SERVICE, Mockery::mock(FilesystemAdapter::class), [
            'host' => 'https://files.example.invalid',
            'platform' => $platform,
            'key' => 'mock',
        ]);
        $expand = Mockery::mock(ExpandInterface::class);
        $signer = Mockery::mock(SimpleUpload::class);
        $this->setProperty($filesystem, 'expand', $expand);
        $this->setProperty($filesystem, 'adapterName', $adapter);
        $this->setProperty($filesystem, 'simpleUploadInstances', [
            $adapter => $signer,
        ]);
        $filesystem->setPreferStsForLinks(true);
        return [
            $filesystem,
            $expand,
            $signer,
        ];
    }

    /**
     * 使用静默日志，避免测试输出签名或凭证。
     */
    private function container(array $config = [], ?CacheInterface $cache = null): SdkBase
    {
        $container = new Container();
        $container->set(LoggerInterface::class, new NullLogger());
        if ($cache !== null) {
            $container->set(CacheInterface::class, $cache);
        }
        return new SdkBase($container, [
            'sdk_name' => 'cloudfile-test',
            'exception_class' => CloudFileException::class,
            'cloudfile' => $config,
        ]);
    }

    /**
     * 注入职责替身，不加载真实平台配置。
     */
    private function setProperty(object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty($object, $name))->setValue($object, $value);
    }
}
