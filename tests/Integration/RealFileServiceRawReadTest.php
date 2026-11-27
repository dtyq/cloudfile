<?php

declare(strict_types=1);

namespace Dtyq\CloudFile\Tests\Integration;

use Dtyq\CloudFile\CloudFile;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Tos\Model\DeleteObjectInput;
use Tos\Model\ListObjectsInput;
use Tos\TosClient;

/**
 * 通过 CloudFile 公开方法验收已部署文件服务，仅操作随机前缀内的合成对象。
 * @group integration
 */
class RealFileServiceRawReadTest extends TestCase
{
    /**
     * 仅枚举授权的测试存储，不读取配置或发出请求。
     */
    public static function storages(): array
    {
        return [
            ['file_service_private_test', 'tos_private_test'],
            ['file_service_public_test', 'tos_public_test'],
            ['file_service_sandbox_test', 'tos_sandbox_test'],
        ];
    }

    /**
     * 未显式启用真实调用时跳过，不读取任何凭证。
     */
    protected function setUp(): void
    {
        if (getenv('CLOUDFILE_RUN_REAL_FILE_SERVICE_TESTS') !== '1') {
            self::markTestSkipped('需显式启用文件服务真实读取验收');
        }
    }

    /**
     * 验证关闭 STS 优先时的原始 key、下载名、元信息、公开图片及内部路由。
     * @dataProvider storages
     */
    public function testRawReadThroughCloudFile(string $storage, string $nativeStorage): void
    {
        $configPath = getenv('CLOUDFILE_TEST_CONFIG');
        if (! $configPath || ! is_file($configPath)) {
            self::markTestSkipped('需提供授权的文件服务及原生桶配置');
        }
        $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        $service = $config['storages'][$storage];
        self::assertSame(false, $service['prefer_sts_for_links']);
        self::assertSame('https', parse_url($service['config']['host'], PHP_URL_SCHEME));
        $native = $config['storages'][$nativeStorage]['config'];
        $public = (bool) ($service['public_read'] ?? false);
        $httpOptions = ['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false, 'allow_redirects' => false];
        if (getenv('CLOUDFILE_HTTP_DIRECT') === '1') {
            $httpOptions['proxy'] = '';
        }
        $http = new Client($httpOptions);
        $container = new Container();
        $container->set(ClientInterface::class, $http);
        $container->set(LoggerInterface::class, new NullLogger());
        $cloudFile = new CloudFile(new SdkBase($container, ['sdk_name' => 'cloudfile-raw-http', 'exception_class' => CloudFileException::class, 'cloudfile' => $config]));
        $filesystem = $cloudFile->get($storage);
        $nativeClient = new TosClient($native);
        $directory = 'cloudfile-http-raw-' . bin2hex(random_bytes(12));
        $contents = [];
        foreach (['plain.txt', 'a b.txt', 'a%20b.txt', 'a%2520b.txt', 'a%b.txt', 'a%25b.txt', 'a/b.txt', 'a%2Fb.txt', 'a%2fb.txt', '中文 +#?&=.txt', 'a\\b.txt', 'nested//a.txt'] as $name) {
            $contents[$directory . '/' . $name] = 'synthetic-' . bin2hex(random_bytes(16));
        }
        $imageName = $directory . '/preview%20+#?.png';
        $contents[$imageName] = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAICAIAAAB/FOjAAAAAE0lEQVR4nGPQarhAEmIY1UALDQBq+L0BjDSJFQAAAABJRU5ErkJggg==', true);
        $paths = [];
        $prefix = '';
        $phase = 'upload_links';
        try {
            $uploads = $filesystem->getPreSignedUrls(array_keys($contents), 600);
            self::assertSame(array_keys($contents), array_keys($uploads));
            $fixtures = [];
            foreach ($uploads as $name => $upload) {
                self::assertStringStartsWith($native['bucket'] . '.', (string) parse_url($upload->getUrl(), PHP_URL_HOST));
                self::assertSame($upload->getPath(), rawurldecode(ltrim((string) parse_url($upload->getUrl(), PHP_URL_PATH), '/')));
                $offset = strpos($upload->getPath(), '/' . $directory . '/');
                self::assertNotFalse($offset);
                $prefix = substr($upload->getPath(), 0, $offset) . '/' . $directory . '/';
                $fixtures[$upload->getPath()] = $contents[$name];
            }
            self::assertCount(0, $nativeClient->listObjects(new ListObjectsInput($native['bucket'], 1, $prefix))->getContents());
            $phase = 'upload';
            foreach ($uploads as $name => $upload) {
                $paths[] = $upload->getPath();
                self::assertSame(200, $http->put($upload->getUrl(), ['headers' => $upload->getHeaders(), 'body' => $contents[$name]])->getStatusCode());
            }
            foreach ([false, true] as $internal) {
                $phase = $internal ? 'internal_read' : 'read';
                $options = ['cache' => false, 'internal' => $internal, 'internal_endpoint' => false];
                $links = $filesystem->getLinks($paths, [$paths[2] => '下载 %20.txt'], 86400, $options);
                self::assertSame($paths, array_keys($links));
                foreach ($links as $path => $link) {
                    self::assertSame($path, $link->getPath());
                    self::assertSame($path, rawurldecode(ltrim((string) parse_url($link->getUrl(), PHP_URL_PATH), '/')));
                    self::assertGreaterThan(time() + 86300, $link->getExpires());
                    parse_str((string) parse_url($link->getUrl(), PHP_URL_QUERY), $query);
                    self::assertArrayNotHasKey('X-Tos-Security-Token', $query);
                    if ($public) {
                        self::assertSame([], $query);
                    } else {
                        self::assertSame('86400', $query['X-Tos-Expires']);
                    }
                    $response = $http->get($link->getUrl());
                    self::assertSame(200, $response->getStatusCode());
                    self::assertSame(hash('sha256', $fixtures[$path]), hash('sha256', (string) $response->getBody()));
                    if ($path === $paths[2]) {
                        self::assertSame('下载 %20.txt', $link->getDownloadName());
                        if (! $public) {
                            self::assertStringContainsString(rawurlencode('下载 %20.txt'), $response->getHeaderLine('Content-Disposition'));
                        }
                    }
                }
                $metas = $filesystem->getMetas($paths, $options);
                self::assertSame($paths, array_keys($metas));
                foreach ($metas as $path => $meta) {
                    self::assertSame($path, $meta->getPath());
                    self::assertSame($path, $meta->getFileAttributes()->path());
                    self::assertSame(strlen($fixtures[$path]), $meta->getFileAttributes()->fileSize());
                }
                $phase = 'image';
                $image = $filesystem->getLink($uploads[$imageName]->getPath(), '', 86400, $options + ['image' => (new ImageProcessOptions())->resize(['width' => 8])]);
                foreach ($public ? ['', '&swCache=slides-template-image-v2'] : [''] as $marker) {
                    $response = $http->get($image->getUrl() . $marker);
                    self::assertSame(200, $response->getStatusCode());
                    $size = getimagesizefromstring((string) $response->getBody());
                    self::assertIsArray($size);
                    self::assertSame([8, 4], [$size[0], $size[1]]);
                }
            }
        } catch (Throwable $exception) {
            // 原始异常可能带有请求头或签名 URL，只记录阶段、异常类型和状态码。
            self::fail('CloudFile 真实验收失败：' . $phase . '，类型 ' . get_class($exception) . '，状态码 ' . (int) $exception->getCode());
        } finally {
            $failed = 0;
            foreach ($paths as $path) {
                try {
                    $nativeClient->deleteObject(new DeleteObjectInput($native['bucket'], $path));
                } catch (Throwable $exception) {
                    ++$failed;
                }
            }
            self::assertSame(0, $failed, '本轮合成对象应全部删除');
            if ($prefix !== '') {
                try {
                    self::assertCount(0, $nativeClient->listObjects(new ListObjectsInput($native['bucket'], 1, $prefix))->getContents());
                } catch (Throwable $exception) {
                    throw new RuntimeException('随机前缀清理结果无法确认');
                }
            }
        }
    }
}
