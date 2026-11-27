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
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;
use Dtyq\CloudFile\Tests\CloudFileBaseTest;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use GuzzleHttp\Client;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
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
     * 公开链接和追加缓存标记后的链接都应读回原对象，图片处理及短期元数据不应破坏匿名访问。
     *
     * @dataProvider publicStorages
     */
    public function testPublicLinks(string $storage): void
    {
        if ($storage === '') {
            $this->markTestSkipped('真实公开桶配置缺失，不能通过修改 SDK 标记使私有桶支持匿名访问');
        }
        $filesystem = null;
        $policy = null;
        $prefix = '';
        $paths = [];
        try {
            $filesystem = $this->createCloudFile()->get($storage);
            $directory = 'cloudfile-public-links-' . bin2hex(random_bytes(8));
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
                'fragment.txt',
                'fragment.txt#part',
                'query.txt',
                'query.txt?tag=1&x=2',
            ] as $filename) {
                $contents[$prefix . $filename] = 'synthetic-' . bin2hex(random_bytes(12));
            }
            // 固定的 16×8 合成 PNG，不读取已有业务图片。
            $imagePath = $prefix . 'preview%#?.png';
            $contents[$imagePath] = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAICAIAAAB/FOjAAAAAE0lEQVR4nGPQarhAEmIY1UALDQBq+L0BjDSJFQAAAABJRU5ErkJggg==', true);
            foreach ($contents as $path => $content) {
                $paths[] = $path;
                $filesystem->createObjectByCredential($policy, $path, self::OPTIONS + [
                    'content' => $content,
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
                $this->assertUnsignedUrl($link->getUrl());
                foreach (['', 'swCache=slides-template-image-v2'] as $marker) {
                    $url = $link->getUrl();
                    if ($marker !== '') {
                        $url .= (str_contains($url, '?') ? '&' : '?') . $marker;
                    }
                    self::assertSame(hash('sha256', $contents[$path]), hash('sha256', $this->readContent($client, $url)));
                }
                $ttl = str_starts_with($storage, 'file_service') ? $link->getExpires() - time() : $link->getExpires();
                self::assertGreaterThan(86300, $ttl);
            }
            $image = $filesystem->getLink($imagePath, '', 86400, self::OPTIONS + [
                'image' => (new ImageProcessOptions())->resize([
                    'width' => 8,
                ]),
            ]);
            $this->assertUnsignedUrl($image->getUrl());
            foreach (['', '&swCache=featured-slides-template-image-v2'] as $marker) {
                $dimensions = getimagesizefromstring($this->readContent($client, $image->getUrl() . $marker));
                self::assertIsArray($dimensions);
                self::assertSame(8, $dimensions[0]);
                self::assertSame(4, $dimensions[1]);
            }
            $short = $filesystem->getLink($paths[1], '', 1, self::OPTIONS);
            $this->assertUnsignedUrl($short->getUrl());
            sleep(2);
            self::assertSame($contents[$paths[1]], $this->readContent($client, $short->getUrl()));
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
     * 只选择外部配置明确声明的公开存储，不修改真实桶权限或私有测试存储的公开读标记。
     */
    public function publicStorages(): array
    {
        $path = getenv('CLOUDFILE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/storages.json';
        $storages = [];
        if (getenv('CLOUDFILE_RUN_REAL_TESTS') === '1' && is_file($path)) {
            $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($config['storages'] as $name => $storage) {
                if (($storage['public_read'] ?? false) === true) {
                    $storages[] = [$name];
                }
            }
        }
        return $storages ?: [['']];
    }

    /**
     * 公开 URL 不允许残留鉴权及需要签名的响应头覆盖字段，断言不输出 URL 原文。
     */
    private function assertUnsignedUrl(string $url): void
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (array_keys($query) as $name) {
            self::assertDoesNotMatchRegularExpression('/signature|credential|token|access.key|expires|algorithm|signedheaders|additional.headers|^x.(tos|oss|amz).date$|^response-/i', $name);
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
     * 只读取当前测试对象，失败仅通过状态码报告，不暴露签名或响应正文。
     */
    private function readContent(Client $client, string $url): string
    {
        $response = $client->get($url);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('测试对象读取失败', $response->getStatusCode());
        }
        return (string) $response->getBody();
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
