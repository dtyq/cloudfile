<?php

declare(strict_types=1);

namespace Dtyq\CloudFile\Tests\FileService;

use Dtyq\CloudFile\Kernel\AdapterName;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\FilesystemProxy;
use Dtyq\CloudFile\Kernel\Struct\FileLink;
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\FilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;

/**
 * 通过公开入口校验固定原始路径协议，全部 HTTP 响应均为合成数据。
 */
class FileServiceRawReadTest extends TestCase
{
    private array $history = [];

    /**
     * 提供普通与内部路由。
     */
    public static function routes(): array
    {
        return [[false], [true]];
    }

    /**
     * 下载名、返回索引及元信息使用原始 key，调用方不能把协议切回旧模式。
     * @dataProvider routes
     */
    public function testReadRequestsAlwaysUseRawKeys(bool $internal): void
    {
        $paths = ['mock-org/1001/a b.png', 'mock-org/1001/a%20b.png', 'mock-org/1001/a%25%2F+#?.png'];
        $deadline = time() + 86400;
        $links = array_map(static fn ($path) => ['file_path' => $path, 'url' => 'https://mock.invalid/' . rawurlencode($path), 'expires' => $deadline, 'download_name' => $path === $paths[1] ? '下载 %20.png' : ''], $paths);
        $metas = array_map(static fn ($path) => ['name' => basename($path), 'file_path' => $path, 'metadata' => ['path' => $path, 'file_size' => 17]], $paths);
        $filesystem = $this->filesystem([$this->response($links), $this->response($metas)]);
        $options = ['internal' => $internal, 'cache' => false, 'file_path_mode' => 'legacy', 'image' => (new ImageProcessOptions())->resize(['width' => 8])];
        $result = $filesystem->getLinks($paths, [$paths[1] => '下载 %20.png'], 86400, $options);
        self::assertSame($paths, array_keys($result));
        foreach ($result as $path => $link) {
            self::assertSame($path, $link->getPath());
        }
        self::assertSame('下载 %20.png', $result[$paths[1]]->getDownloadName());
        self::assertSame($deadline, $result[$paths[1]]->getExpires());
        $metadata = $filesystem->getMetas($paths, $options);
        self::assertSame($paths, array_keys($metadata));
        foreach ($metadata as $path => $meta) {
            self::assertSame($path, $meta->getPath());
            self::assertSame($path, $meta->getFileAttributes()->path());
        }
        self::assertCount(2, $this->history);
        foreach ($this->history as $index => $transaction) {
            $request = $transaction['request'];
            self::assertSame(($index === 0 ? '/file/links/path' : '/file/show') . ($internal ? '/internal' : ''), $request->getUri()->getPath());
            $body = json_decode((string) $request->getBody(), true);
            self::assertSame('raw', $body['file_path_mode'] ?? null);
            self::assertSame($paths, $body['file_paths']);
            if ($index === 0) {
                self::assertSame([$paths[1] => '下载 %20.png'], $body['download_names']);
                self::assertSame(86400, $body['expires']);
                self::assertSame([['type' => 'resize', 'params' => ['w' => 8]]], $body['options']['image']);
            }
        }
    }

    /**
     * 公开读复用 SDK 编码，不因域名已缓存而拼出错误 key 或丢失图片参数。
     */
    public function testPublicReadsKeepEncodedKeysAndImageProcessing(): void
    {
        $paths = ['mock-org/1001/a%20+#?.png', 'mock-org/1001/另一个%2F.png'];
        $responses = [];
        $expected = [];
        foreach ($paths as $path) {
            $url = 'https://objects.mock.invalid/' . str_replace('%2F', '/', rawurlencode($path));
            $expected[$path] = $url . '?x-tos-process=image%2Fresize%2Cw_8';
            $responses[] = $this->response([['file_path' => $path, 'url' => $expected[$path] . '&X-Tos-Signature=mock&X-Tos-Security-Token=mock&X-Tos-Expires=600&response-content-disposition=attachment', 'expires' => time() + 86400, 'download_name' => '下载.png']]);
        }
        $filesystem = $this->filesystem($responses, true);
        (new ReflectionProperty($filesystem, 'publicDomain'))->setValue($filesystem, 'https://old.mock.invalid');
        foreach ($paths as $index => $path) {
            $link = $filesystem->getLink($path, $index === 0 ? '' : '下载.png', 86400, ['cache' => false, 'image' => (new ImageProcessOptions())->resize(['width' => 8])]);
            self::assertSame($path, $link->getPath());
            self::assertSame($expected[$path], $link->getUrl());
            self::assertSame($path, rawurldecode(ltrim((string) parse_url($link->getUrl(), PHP_URL_PATH), '/')));
        }
        self::assertCount(2, $this->history);
        foreach ($this->history as $transaction) {
            $body = json_decode((string) $transaction['request']->getBody(), true);
            self::assertSame('raw', $body['file_path_mode']);
            self::assertSame([['type' => 'resize', 'params' => ['w' => 8]]], $body['options']['image']);
        }
    }

    /**
     * 原始模式不能读取历史路径缓存，后续相同调用仍可复用新链接缓存。
     */
    public function testRawCacheDoesNotReuseLegacyObjectLinks(): void
    {
        $path = 'mock-org/1001/a%20b.txt';
        $stored = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $key) use (&$stored) {
            if (strpos($key, '_raw_') === false) {
                return new FileLink('mock-org/1001/a b.txt', 'https://old.mock.invalid/wrong-object', time() + 300);
            }
            return $stored[$key] ?? null;
        });
        $cache->expects(self::once())->method('set')->willReturnCallback(static function (string $key, $link, $ttl) use (&$stored): bool {
            self::assertMatchesRegularExpression('/_raw_[a-f0-9]{32}$/', $key);
            $stored[$key] = $link;
            return true;
        });
        $filesystem = $this->filesystem([$this->response([['file_path' => $path, 'url' => 'https://new.mock.invalid/correct-object', 'expires' => time() + 3600]])], false, $cache);
        foreach ([1, 2] as $unused) {
            $link = $filesystem->getLink($path);
            self::assertSame($path, $link->getPath());
            self::assertSame('https://new.mock.invalid/correct-object', $link->getUrl());
        }
        self::assertCount(1, $this->history);
    }

    /**
     * 装配 Mock HTTP、合成身份及可选隔离缓存，不调用真实服务。
     */
    private function filesystem(array $responses, bool $public = false, ?CacheInterface $cache = null): FilesystemProxy
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $container = new Container();
        $container->set(ClientInterface::class, new Client(['handler' => $stack]));
        $container->set(LoggerInterface::class, new NullLogger());
        if ($cache !== null) {
            $container->set(CacheInterface::class, $cache);
        }
        $sdk = new SdkBase($container, ['sdk_name' => 'raw-read-mock', 'exception_class' => CloudFileException::class]);
        $filesystem = new FilesystemProxy($sdk, AdapterName::FILE_SERVICE, $this->createMock(FilesystemAdapter::class), ['host' => 'https://files.mock.invalid', 'platform' => 'tos', 'key' => 'mock-bucket']);
        $filesystem->setIsPublicRead($public);
        $filesystem->setOptions(['token' => 'mock-token', 'organization-code' => 'mock-org']);
        return $filesystem;
    }

    /**
     * 构造合成业务成功响应。
     */
    private function response(array $data): Response
    {
        return new Response(200, [], json_encode(['code' => 1000, 'data' => $data]));
    }
}
