<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Kernel;

use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\Utils\SimpleUpload\FileServiceSimpleUpload;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 使用占位临时凭证验证真实 SDK 的本地签名，不发起网络请求。
 *
 * @internal
 * @coversNothing
 */
class StsLinkSigningTest extends TestCase
{
    /**
     * 文件服务分派到实际 SDK 后，百分号仍属于原始对象名。
     *
     * @dataProvider platforms
     */
    public function testSdkKeepsLiteralPercent(string $platform): void
    {
        $container = new Container();
        $container->set(LoggerInterface::class, new NullLogger());
        $sdk = new SdkBase($container, [
            'sdk_name' => 'cloudfile-test',
            'exception_class' => CloudFileException::class,
        ]);
        $signer = new FileServiceSimpleUpload($sdk);
        $credential = $this->credential($platform);
        foreach ([
            '普通.png',
            '30%.png',
            'a%%b.png',
            'a%20b.png',
            'a%25b.png',
            'a%2Fb.png',
        ] as $filename) {
            $key = 'demo/' . $filename;
            $url = $signer->getPreSignedUrlByCredential($credential, $key, [
                'expires' => 300,
                'filename' => '下载30%.png',
                'download' => false,
            ]);
            $path = ltrim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
            if ($platform === 'minio') {
                $path = substr($path, strlen('mock-bucket/'));
            }
            self::assertSame($key, $path);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertStringStartsWith('inline;', $query['response-content-disposition']);
            self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('下载30%.png'), $query['response-content-disposition']);
        }
    }

    /**
     * 仅覆盖已有 STS 签名平台。
     */
    public function platforms(): array
    {
        return [
            [
                'aliyun',
            ],
            [
                'tos',
            ],
            [
                'minio',
            ],
        ];
    }

    /**
     * 构造平台真实字段形状的合成临时凭证。
     */
    private function credential(string $platform): array
    {
        $temporary = [
            'region' => $platform === 'aliyun' ? 'oss-cn-hangzhou' : 'cn-beijing',
            'endpoint' => 'https://storage.example.invalid',
            'bucket' => 'mock-bucket',
            'dir' => 'demo/',
            'access_key_id' => 'mock-access-key',
            'access_key_secret' => 'mock-secret-key',
            'sts_token' => 'mock-session-token',
            'credentials' => [
                'AccessKeyId' => 'mock-access-key',
                'SecretAccessKey' => 'mock-secret-key',
                'SessionToken' => 'mock-session-token',
            ],
            'use_path_style_endpoint' => true,
            'version' => 'latest',
        ];
        if ($platform === 'minio') {
            $temporary['credentials'] = [
                'access_key_id' => 'mock-access-key',
                'secret_access_key' => 'mock-secret-key',
                'session_token' => 'mock-session-token',
            ];
        }
        return [
            'platform' => $platform,
            'temporary_credential' => $temporary,
            'expires' => time() + 600,
        ];
    }
}
