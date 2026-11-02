<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\FileService;

use Dtyq\CloudFile\Kernel\AdapterName;
use Dtyq\CloudFile\Kernel\Driver\FileService\FileServiceApi;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\FilesystemProxy;
use Dtyq\CloudFile\Kernel\Struct\CredentialPolicy;
use Dtyq\CloudFile\Kernel\Utils\SimpleUpload;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use League\Flysystem\FilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * 验证文件服务的变更请求尽力执行，其余请求仍保留原有失败语义。
 *
 * @internal
 * @coversNothing
 */
class FileServiceMutationTest extends TestCase
{
    private array $history = [];

    private array $warnings = [];

    /**
     * 调用方可通过公开入口独立申请标签 STS 或与上传权限组合申请。
     *
     * @dataProvider taggingCredentialTypes
     */
    public function testPublicTaggingCredentialCanBeRequested(string $type): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse()]);
        $policy = new CredentialPolicy(['sts' => true, 'sts_type' => $type, 'dir' => 'mock-child/', 'expires' => 3600]);
        $credential = $filesystem->getUploadTemporaryCredential($policy);

        self::assertSame('tos', $credential['platform']);
        self::assertSame('mock-org/1001/', $credential['temporary_credential']['dir']);
        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertSame('/file/temporary-credential', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame($type, $body['policy']['sts_type']);
        self::assertTrue($body['policy']['sts']);
        self::assertSame('mock-child/', $body['policy']['dir']);
        self::assertSame(3600, $body['policy']['expires']);
    }

    /**
     * 提供标签凭证的独立权限与组合权限表达式。
     */
    public function taggingCredentialTypes(): array
    {
        return [['object_tagging'], ['upload_objects|object_tagging']];
    }

    /**
     * 公开入口仅换取 STS，并复用相同身份和权限的缓存，不调用标签或删除接口。
     */
    public function testPublicMutationsUseCachedSts(): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse(), $this->credentialResponse()]);
        $filesystem->setOptions(['token' => 'mock-token', 'organization-code' => 'mock-org', 'internal' => true]);
        $paths = ['mock-org/1001/a.txt', 'mock-org/1001/b.txt'];
        $storage = $this->createMock(SimpleUpload::class);
        $storage->expects(self::exactly(2))->method('setTagsByCredential')->with(self::anything(), $paths, self::anything());
        $storage->expects(self::once())->method('deleteObjectsByCredential')->with(self::anything(), $paths, self::callback(static fn (array $options): bool => $options['quiet'] === false))->willReturn(['deleted' => [['key' => $paths[0]], ['key' => $paths[1]]], 'errors' => []]);
        $this->injectStorage($filesystem, $storage);
        $filesystem->setTags([...$paths, $paths[0]], [['key' => 'source', 'value' => 'mock']]);
        $filesystem->deleteTags($paths);
        $filesystem->destroy($paths);
        self::assertCount(2, $this->history);
        foreach ($this->history as $index => $transaction) {
            $request = $transaction['request'];
            self::assertSame('/file/temporary-credential', $request->getUri()->getPath());
            self::assertSame('mock-org', $request->getHeaderLine('organization-code'));
            self::assertSame('mock-token', $request->getHeaderLine('token'));
            $body = json_decode((string) $request->getBody(), true);
            self::assertTrue($body['policy']['sts']);
            self::assertSame($index === 0 ? 'object_tagging' : 'del_objects', $body['policy']['sts_type']);
        }
        self::assertSame([], $this->warnings);
    }

    /**
     * 凭证与云端操作失败在公开变更入口只记脱敏日志。
     */
    public function testStsMutationFailureIsLogged(): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse()]);
        $storage = $this->createMock(SimpleUpload::class);
        $storage->method('setTagsByCredential')->willThrowException(new RuntimeException('mock-sensitive-value'));
        $this->injectStorage($filesystem, $storage);
        $filesystem->setTags(['mock-org/1001/a.txt'], []);
        self::assertCount(1, $this->warnings);
        self::assertStringNotContainsString('mock-sensitive-value', json_encode($this->warnings));
    }

    /**
     * 删除响应缺失或部分失败也必须记录失败，不能假定全部成功。
     */
    public function testPartialStsDeletionIsLogged(): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse()]);
        $storage = $this->createMock(SimpleUpload::class);
        $storage->method('deleteObjectsByCredential')->willReturn(['deleted' => [], 'errors' => []]);
        $this->injectStorage($filesystem, $storage);
        $filesystem->destroy(['mock-org/1001/a.txt']);
        self::assertCount(1, $this->warnings);
    }

    /**
     * 混合越权路径在任何对象变更前整批拒绝。
     */
    public function testStsScopeIsValidatedBeforeMutation(): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse()]);
        $storage = $this->createMock(SimpleUpload::class);
        $storage->expects(self::never())->method('deleteObjectsByCredential');
        $this->injectStorage($filesystem, $storage);
        $this->expectException(InvalidArgumentException::class);
        $filesystem->destroy(['mock-org/1001/a.txt', 'other-org/1001/b.txt']);
    }

    /**
     * 同一实例更换身份后不能复用前一个身份的凭证。
     */
    public function testStsCacheIsIsolatedByIdentity(): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse(), $this->credentialResponse()]);
        $storage = $this->createMock(SimpleUpload::class);
        $this->injectStorage($filesystem, $storage);
        $filesystem->setTags(['mock-org/1001/a.txt'], [], ['token' => 'mock-first']);
        $filesystem->setTags(['mock-org/1001/a.txt'], [], ['token' => 'mock-second']);
        self::assertCount(2, $this->history);
    }

    /**
     * 凭证申请失败只在变更入口降级，不调用任何存储操作。
     */
    public function testCredentialFailureIsLoggedWithoutMutation(): void
    {
        $response = new Response(200, [], '{"code":4300,"message":"mock failure"}');
        $filesystem = $this->filesystem([$response, $response, $response]);
        $storage = $this->createMock(SimpleUpload::class);
        $storage->expects(self::never())->method('setTagsByCredential');
        $this->injectStorage($filesystem, $storage);
        $filesystem->setTags(['mock-org/1001/a.txt'], []);
        self::assertCount(1, $this->warnings);
        self::assertCount(3, $this->history);
    }

    /**
     * 凭证即将过期时重新申请，不能继续复用缓存。
     */
    public function testExpiringStsIsRefreshed(): void
    {
        $filesystem = $this->filesystem([$this->credentialResponse(time() + 30), $this->credentialResponse()]);
        $storage = $this->createMock(SimpleUpload::class);
        $storage->expects(self::exactly(2))->method('setTagsByCredential');
        $this->injectStorage($filesystem, $storage);
        $filesystem->setTags(['mock-org/1001/a.txt'], []);
        $filesystem->setTags(['mock-org/1001/a.txt'], []);
        self::assertCount(2, $this->history);
    }

    /**
     * 验证最多 100 项只发送一次请求，远端失败仍仅记录日志。
     * @dataProvider operations
     */
    public function testFailedMutationAtLimitIsLogged(string $operation): void
    {
        $api = new FileServiceApi($this->sdk([new Response(200, [], '{"code":9999,"message":"mock failure"}'), $this->success(), $this->success()]), $this->config());
        $paths = array_map(static fn (int $index): string => 'mock-org/1001/' . $index . '.txt', range(0, 99));
        $this->mutate($api, $operation, $paths);
        self::assertCount(1, $this->history);
        $batches = array_map(static fn (array $transaction): array => json_decode((string) $transaction['request']->getBody(), true)['file_paths'], $this->history);
        self::assertSame([100], array_map('count', $batches));
        self::assertSame($paths, array_merge(...$batches));
        self::assertCount(1, $this->warnings);
        self::assertSame($operation, $this->warnings[0]['context']['operation']);
        self::assertSame($this->history[0]['request']->getHeaderLine('request-id'), $this->warnings[0]['context']['request_id']);
        self::assertSame(100, $this->warnings[0]['context']['item_count']);
    }

    /**
     * 超过 100 项在本地拒绝，不发送请求也不当作远端失败吞掉。
     * @dataProvider operations
     */
    public function testOverLimitIsRejectedBeforeRequest(string $operation): void
    {
        $api = new FileServiceApi($this->sdk([]), $this->config());
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->mutate($api, $operation, array_fill(0, 101, 'mock.txt'));
        } finally {
            self::assertSame([], $this->history);
            self::assertSame([], $this->warnings);
        }
    }

    /**
     * 验证变更请求各类失败均只记日志，日志不携带路径、凭证和完整响应。
     * @dataProvider requestFailures
     */
    public function testMutationFailuresDoNotEscape(string $operation, string $failure): void
    {
        $responses = [
            'business' => new Response(200, [], '{"code":9999,"message":"mock-sensitive-response","data":{"token":"mock-sensitive-response"}}'),
            'http' => new Response(503, [], 'mock-sensitive-response'),
            'json' => new Response(200, [], 'mock-sensitive-response'),
            'scalar' => new Response(200, [], '1'),
            'data' => new Response(200, [], '{"code":1000,"data":"mock-sensitive-response"}'),
            'transport' => new RuntimeException('mock-sensitive-response'),
        ];
        $api = new FileServiceApi($this->sdk([$responses[$failure]]), $this->config());
        $this->mutate($api, $operation, ['mock-org/1001/private-name.txt']);
        self::assertCount(1, $this->history);
        self::assertCount(1, $this->warnings);
        $log = json_encode($this->warnings, JSON_THROW_ON_ERROR);
        foreach (['mock-sensitive-response', 'private-name.txt', 'mock-token'] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
        self::assertSame($operation, $this->warnings[0]['context']['operation']);
    }

    /**
     * 提供业务拒绝、HTTP 错误、无效 JSON、错误结构及传输异常。
     */
    public function requestFailures(): array
    {
        $cases = [];
        foreach (['tags', 'destroy'] as $operation) {
            foreach (['business', 'http', 'json', 'scalar', 'data', 'transport'] as $failure) {
                $cases[$operation . '-' . $failure] = [$operation, $failure];
            }
        }
        return $cases;
    }

    /**
     * 验证缺少身份时变更仅记日志，且不发出 HTTP 请求。
     * @dataProvider operations
     */
    public function testMissingTokenOnlyLogsMutationFailure(string $operation): void
    {
        $api = new FileServiceApi($this->sdk([]), $this->config());
        $this->mutate($api, $operation, ['mock.txt'], []);
        self::assertSame([], $this->history);
        self::assertCount(1, $this->warnings);
        self::assertSame(401, $this->warnings[0]['context']['error_code']);
    }

    /**
     * 提供标签和物理删除两个尽力执行操作。
     */
    public function operations(): array
    {
        return [['tags'], ['destroy']];
    }

    /**
     * 验证临时凭证、读取、复制等请求不会被变更降级逻辑吞掉。
     * @dataProvider strictOperations
     */
    public function testOtherOperationsKeepThrowing(string $operation): void
    {
        $api = new FileServiceApi($this->sdk([new Response(200, [], '{"code":4300,"message":"mock strict failure"}')]), $this->config());
        try {
            $options = ['token' => 'mock-token'];
            match ($operation) {
                'credentials' => $api->getTemporaryCredential(new CredentialPolicy(), $options),
                'links' => $api->getUrls(['mock.txt'], [], 60, $options),
                'show' => $api->show(['mock.txt'], $options),
                'copy' => $api->copy('mock-source.txt', 'mock-target.txt', $options),
                'pre-signed' => $api->getPreSignedUrls(['mock.txt'], 60, $options),
            };
            self::fail('非变更请求必须保留失败异常');
        } catch (CloudFileException $exception) {
            self::assertSame(4300, $exception->getCode());
        }
        self::assertSame([], $this->warnings);
    }

    /**
     * 提供必须继续抛出原有请求错误的操作。
     */
    public function strictOperations(): array
    {
        return [['credentials'], ['links'], ['show'], ['copy'], ['pre-signed']];
    }

    /**
     * 验证凭证请求的内部路由保持原样。
     */
    public function testCredentialInternalRouteIsUnchanged(): void
    {
        $api = new FileServiceApi($this->sdk([$this->success()]), $this->config());
        $api->getTemporaryCredential(new CredentialPolicy(), ['token' => 'mock-token', 'internal' => true]);
        self::assertSame('/file/temporary-credential/internal', $this->history[0]['request']->getUri()->getPath());
    }

    /**
     * 验证空目标不请求服务，标签参数错误仍属于本地参数异常。
     */
    public function testEmptyTargetsAndInvalidTagsDoNotSendRequests(): void
    {
        $filesystem = $this->filesystem([]);
        $filesystem->setTags([], []);
        $filesystem->deleteTags([]);
        $filesystem->destroy([]);
        self::assertSame([], $this->history);
        $this->expectException(InvalidArgumentException::class);
        $filesystem->setTags(['mock.txt'], [['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']]);
    }

    /**
     * 创建仅使用 Mock HTTP 与日志的 SDK，不访问网络或真实身份。
     */
    private function sdk(array $responses): SdkBase
    {
        $this->history = [];
        $this->warnings = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
            $this->warnings[] = ['message' => $message, 'context' => $context];
        });
        $values = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key, mixed $default = null) use (&$values): mixed { return $values[$key] ?? $default; });
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$values): bool {
            $values[$key] = $value;
            return true;
        });
        $container = new Container();
        $container->set(CacheInterface::class, $cache);
        $container->set(ClientInterface::class, new Client(['handler' => $stack]));
        $container->set(LoggerInterface::class, $logger);
        return new SdkBase($container, ['sdk_name' => 'cloudfile-mock', 'exception_class' => CloudFileException::class]);
    }

    /**
     * 注入 Mock 存储，测试不连接真实云端。
     */
    private function injectStorage(FilesystemProxy $filesystem, SimpleUpload $storage): void
    {
        (new ReflectionProperty($filesystem, 'simpleUploadInstances'))->setValue($filesystem, [AdapterName::FILE_SERVICE => $storage]);
    }

    /**
     * 返回有效但完全合成的限定目录凭证响应。
     */
    private function credentialResponse(?int $expires = null): Response
    {
        return new Response(200, [], json_encode(['code' => 1000, 'data' => ['platform' => 'tos', 'expires' => $expires ?? time() + 3600, 'temporary_credential' => ['dir' => 'mock-org/1001/']]]));
    }

    /**
     * 返回合成文件服务配置。
     */
    private function config(): array
    {
        return ['host' => 'https://file.example.test', 'platform' => 'tos', 'key' => 'mock-bucket-key'];
    }

    /**
     * 构造成功业务响应，不以 HTTP 200 代替业务成功。
     */
    private function success(): Response
    {
        return new Response(200, [], '{"code":1000,"data":[]}');
    }

    /**
     * 创建文件服务代理，使用 Mock Flysystem 适配器。
     */
    private function filesystem(array $responses): FilesystemProxy
    {
        $filesystem = new FilesystemProxy($this->sdk($responses), AdapterName::FILE_SERVICE, $this->createMock(FilesystemAdapter::class), $this->config());
        $filesystem->setOptions(['token' => 'mock-token', 'organization-code' => 'mock-org']);
        return $filesystem;
    }

    /**
     * 统一触发被测请求，保持测试的合成身份与完整标签格式。
     */
    private function mutate(FileServiceApi $api, string $operation, array $paths, array $options = ['token' => 'mock-token']): void
    {
        if ($operation === 'tags') {
            $api->setTags($paths, [['key' => 'verification_state', 'value' => 'mock']], $options);
        } else {
            $api->destroy($paths, $options);
        }
    }
}
