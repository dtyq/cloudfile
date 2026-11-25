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
use GuzzleHttp\Client;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 在隔离随机目录验证百分号文件的实际读取，结束后清理全部测试对象。
 *
 * @group integration
 * @internal
 * @coversNothing
 */
class RealStsLinksTest extends CloudFileBaseTest
{
    private const OPTIONS = [
        'cache' => false,
        'internal_endpoint' => false,
    ];

    /**
     * 真实存储调用需要显式开启。
     */
    public function setUp(): void
    {
        parent::setUp();
        if (getenv('CLOUDFILE_RUN_REAL_TESTS') !== '1') {
            $this->markTestSkipped('设置 CLOUDFILE_RUN_REAL_TESTS=1 后运行');
        }
    }

    /**
     * 不同名称的真实对象必须通过新取链入口读回各自内容。
     *
     * @dataProvider storages
     */
    public function testPercentFilenames(string $storage): void
    {
        $filesystem = null;
        $policy = null;
        $prefix = '';
        $paths = [];
        try {
            $filesystem = $this->createCloudFile()->get($storage);
            $directory = 'cloudfile-sts-links-' . bin2hex(random_bytes(8));
            $policy = new CredentialPolicy([
                'dir' => $directory,
                'expires' => 900,
                'sts' => true,
                'role_session_name' => 'cloudfile-test',
                'sts_type' => 'upload_objects|list_objects|del_objects',
            ]);
            $credential = $filesystem->getUploadTemporaryCredential($policy, self::OPTIONS);
            $prefix = rtrim($credential['temporary_credential']['dir'], '/') . '/';
            self::assertStringContainsString($directory, $prefix);
            self::assertSame(0, $this->objectCount($filesystem, $policy, $prefix));
            $contents = [];
            foreach ([
                '普通.txt',
                '30%.txt',
                'a%%b.txt',
                'a%20b.txt',
                'a b.txt',
                'a%25b.txt',
                'a%b.txt',
                'a%2Fb.txt',
            ] as $filename) {
                $path = $prefix . $filename;
                $paths[] = $path;
                $contents[$path] = 'synthetic-' . bin2hex(random_bytes(12));
                $filesystem->createObjectByCredential($policy, $path, self::OPTIONS + [
                    'content' => $contents[$path],
                ]);
            }
            $links = $filesystem->getLinks($paths, [
                $paths[1] => '下载30%.txt',
            ], 86400, self::OPTIONS);
            self::assertSame($paths, array_keys($links));
            $client = new Client([
                'timeout' => 30,
                'http_errors' => false,
            ]);
            foreach ($links as $path => $link) {
                self::assertSame($path, $link->getPath());
                $response = $client->get($link->getUrl());
                self::assertSame(200, $response->getStatusCode(), '签名链接必须读取成功');
                self::assertSame(hash('sha256', $contents[$path]), hash('sha256', (string) $response->getBody()));
                $ttl = str_starts_with($storage, 'file_service') ? $link->getExpires() - time() : $link->getExpires();
                self::assertGreaterThan(0, $ttl);
                parse_str((string) parse_url($link->getUrl(), PHP_URL_QUERY), $query);
                $signedTtl = (int) ($query['x-oss-expires'] ?? $query['X-Tos-Expires'] ?? 0);
                self::assertGreaterThan(0, $signedTtl);
                self::assertLessThanOrEqual(86400, $signedTtl);
                // 文件服务返回绝对截止时间，读取期间可能已消耗少量签名寿命。
                self::assertLessThanOrEqual($signedTtl, $ttl);
                if (! str_starts_with($storage, 'file_service')) {
                    self::assertSame($signedTtl, $ttl);
                }
            }
            $single = $filesystem->getLink($paths[1], '', 120, self::OPTIONS);
            self::assertSame($paths[1], $single->getPath());
            self::assertSame($contents[$paths[1]], (string) $client->get($single->getUrl())->getBody());
        } catch (Throwable $exception) {
            throw $this->safeFailure($exception);
        } finally {
            if ($filesystem !== null && $policy !== null && $paths !== []) {
                try {
                    $filesystem->deleteObjectsByCredential($policy, $paths, self::OPTIONS);
                    self::assertSame(0, $this->objectCount($filesystem, $policy, $prefix), '隔离测试目录必须清理为空');
                } catch (Throwable $exception) {
                    throw $this->safeFailure($exception);
                }
            }
        }
    }

    /**
     * 复用已有真实配置中的四类存储。
     */
    public function storages(): array
    {
        return [
            [
                'file_service_test',
            ],
            [
                'file_service_tos_test',
            ],
            [
                'aliyun_test',
            ],
            [
                'tos_test',
            ],
        ];
    }

    /**
     * 在内存开启目标开关，不修改真实配置，全部日志保持静默。
     */
    protected function createCloudFile(): CloudFile
    {
        $path = getenv('CLOUDFILE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/storages.json';
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($config['storages'] as &$storage) {
            $storage['prefer_sts_for_links'] = true;
        }
        unset($storage);
        $container = new Container();
        $container->set(LoggerInterface::class, new NullLogger());
        return new CloudFile(new SdkBase($container, [
            'sdk_name' => 'cloudfile-real-test',
            'exception_class' => CloudFileException::class,
            'cloudfile' => $config,
        ]));
    }

    /**
     * 提供基础测试类要求的默认存储，实际验证通过数据集选择。
     */
    protected function getStorageName(): string
    {
        return 'file_service_test';
    }

    /**
     * 只列出本轮随机隔离前缀下的对象。
     */
    private function objectCount(FilesystemProxy $filesystem, CredentialPolicy $policy, string $prefix): int
    {
        $result = $filesystem->listObjectsByCredential($policy, $prefix, self::OPTIONS + [
            'max-keys' => 20,
            'delimiter' => '',
        ]);
        return count($result['objects']);
    }

    /**
     * 错误只保留类型与代码，避免输出请求中的签名和配置。
     */
    private function safeFailure(Throwable $exception): AssertionFailedError
    {
        $line = 0;
        foreach ($exception->getTrace() as $frame) {
            if (($frame['file'] ?? '') === __FILE__) {
                $line = $frame['line'];
                break;
            }
        }
        return new AssertionFailedError('真实 STS 取链验证失败：' . get_class($exception) . '，错误码 ' . $exception->getCode() . '，测试位置 ' . $line);
    }
}
