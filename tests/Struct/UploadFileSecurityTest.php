<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Struct;

use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\Struct\UploadFile;
use Dtyq\CloudFile\Kernel\Utils\RemoteDownloadSecurityConfig;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class UploadFileSecurityTest extends TestCase
{
    // 1x1 无损 webp 合成样例
    private const WEBP_BASE64 = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    // 1x1 矩形 SVG 合成样例
    private const SVG_CONTENT = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>';

    protected function tearDown(): void
    {
        RemoteDownloadSecurityConfig::reset();
    }

    /**
     * 校验 UploadFile 在实际读取前会拒绝本地流包装器。
     */
    public function testRejectsLocalStreamWrappersBeforeReading(): void
    {
        $this->expectException(CloudFileException::class);

        new UploadFile('php://temp', 'safe-test');
    }

    /**
     * 校验被识别为 URL 的危险协议也会在下载边界被拒绝。
     */
    public function testRejectsUnsafeUrlSchemesDuringRemoteDownload(): void
    {
        RemoteDownloadSecurityConfig::configure([
            'enabled' => true,
        ]);
        $uploadFile = new UploadFile('file://127.0.0.1/etc/passwd', 'safe-test');

        $this->expectException(CloudFileException::class);

        $uploadFile->getRealPath();
    }

    /**
     * 校验 base64 图片仍按本地临时文件处理，避免修复 SSRF 时破坏历史能力。
     */
    public function testKeepsBase64ImageUploadSupport(): void
    {
        $uploadFile = new UploadFile(
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lK3Q5wAAAABJRU5ErkJggg==',
            'safe-test',
            '',
            false
        );

        $realPath = $uploadFile->getRealPath();

        $this->assertFileExists($realPath);
        $this->assertSame('image/png', $uploadFile->getMimeType());

        $uploadFile->release();
    }

    /**
     * 校验 webp data URI 与 png 等位图一样按 base64 图片上传。
     */
    public function testAcceptsWebpBase64Image(): void
    {
        $uploadFile = new UploadFile('data:image/webp;base64,' . self::WEBP_BASE64, 'safe-test', '', false);

        $realPath = $uploadFile->getRealPath();

        $this->assertFileExists($realPath);
        $this->assertSame('image/webp', $uploadFile->getMimeType());

        $uploadFile->release();
    }

    /**
     * 校验未开启开关时 SVG data URI 在解析时被拒绝，且构造阶段不再误报本地文件不存在。
     */
    public function testRejectsSvgDataUriByDefault(): void
    {
        $uploadFile = new UploadFile($this->svgDataUri(), 'safe-test', 'icon.svg', false);

        $this->expectException(CloudFileException::class);
        $this->expectExceptionMessage('SVG data URI is not allowed');

        $uploadFile->getRealPath();
    }

    /**
     * 校验业务显式开启后可以上传 SVG data URI。
     */
    public function testAcceptsSvgDataUriWhenAllowed(): void
    {
        $uploadFile = new UploadFile($this->svgDataUri(), 'safe-test', 'icon.svg', false);
        $uploadFile->setAllowSvgDataUri(true);

        $realPath = $uploadFile->getRealPath();

        $this->assertFileExists($realPath);
        $this->assertSame('image/svg+xml', $uploadFile->getMimeType());
        $this->assertSame('icon.svg', $uploadFile->getName());

        $uploadFile->release();
    }

    /**
     * 校验 SVG 内容以位图声明时被拒绝，开关不能被位图声明绕过。
     */
    public function testRejectsSvgContentDeclaredAsBitmap(): void
    {
        $uploadFile = new UploadFile('data:image/png;base64,' . base64_encode(self::SVG_CONTENT), 'safe-test', '', false);

        $this->expectException(CloudFileException::class);
        $this->expectExceptionMessage('Data URI content does not match its declared image type');

        $uploadFile->getRealPath();
    }

    /**
     * 校验开启开关后，声明为 SVG 但内容不是 SVG 时仍被拒绝。
     */
    public function testRejectsBitmapContentDeclaredAsSvg(): void
    {
        $uploadFile = new UploadFile('data:image/svg+xml;base64,' . self::WEBP_BASE64, 'safe-test', '', false);
        $uploadFile->setAllowSvgDataUri(true);

        $this->expectException(CloudFileException::class);
        $this->expectExceptionMessage('Data URI content does not match its declared image type');

        $uploadFile->getRealPath();
    }

    /**
     * 校验不支持的 data URI 给出明确错误，且错误信息不回显载荷。
     */
    public function testRejectsUnsupportedDataUriWithoutPayload(): void
    {
        $uploadFile = new UploadFile('data:text/plain;base64,' . base64_encode('payload-marker'), 'safe-test', '', false);

        try {
            $uploadFile->getRealPath();
            $this->fail('Unsupported data URI should be rejected');
        } catch (CloudFileException $exception) {
            $this->assertSame('Unsupported data URI type', $exception->getMessage());
            $this->assertStringNotContainsString(base64_encode('payload-marker'), $exception->getMessage());
        }
    }

    /**
     * 校验文件已解析后不能再修改开关，避免误以为设置生效。
     */
    public function testRejectsSvgPermissionChangeAfterResolved(): void
    {
        $uploadFile = new UploadFile('data:image/webp;base64,' . self::WEBP_BASE64, 'safe-test', '', false);
        $uploadFile->getRealPath();

        try {
            $this->expectException(CloudFileException::class);
            $uploadFile->setAllowSvgDataUri(true);
        } finally {
            $uploadFile->release();
        }
    }

    private function svgDataUri(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::SVG_CONTENT);
    }
}
