<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Kernel\Struct;

use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\Utils\EasyFileTools;
use Dtyq\CloudFile\Kernel\Utils\MimeTypes;
use Dtyq\CloudFile\Kernel\Utils\SafeRemoteFileDownloader;

class UploadFile
{
    private bool $isRemote = false;

    private string $remoteUrl = '';

    private string $name;

    private string $dir;

    private string $realPath;

    private string $mimeType;

    private int $size;

    private bool $rename = true;

    private string $key = '';

    private bool $allowSvgDataUri = false;

    public function __construct(string $realPath, string $dir = '', string $name = '', bool $rename = true)
    {
        $this->dir = $dir;
        $this->name = $name;
        // data URI 一律延迟到解析时，由下载器按格式和 allowSvgDataUri 判断，便于构造后再设置开关
        if (EasyFileTools::isUrl($realPath) || EasyFileTools::isDataUri($realPath)) {
            $this->isRemote = true;
            $this->remoteUrl = $realPath;
            $this->rename = $rename;
            return;
        }
        $this->assertPlainLocalPath($realPath);
        if (! is_file($realPath)) {
            throw new CloudFileException(sprintf('File not exists: %s', $realPath));
        }
        $this->realPath = $realPath;
        $this->size = filesize($realPath);
        $options = pathinfo($realPath);

        $this->name = $name ?: $options['basename'];

        $this->rename = $rename;
        $rename && $this->rename();
    }

    /**
     * 设置是否允许以 SVG data URI 上传，默认不允许。
     *
     * 只应由业务代码对可信来源显式开启，不能由请求参数、文件名或 MIME 决定。
     * 开启后只放行格式解析，不清洗 SVG 内容；本地路径和 URL 上传不受此开关影响。
     * 必须在读取 MIME、名称、大小、路径或上传之前调用。
     */
    public function setAllowSvgDataUri(bool $allowSvgDataUri): void
    {
        if ($this->isRemote && isset($this->realPath)) {
            throw new CloudFileException('SVG data URI permission must be set before the file is resolved');
        }
        $this->allowSvgDataUri = $allowSvgDataUri;
    }

    public function getKeyPath(): string
    {
        $prefix = '';
        if (! empty($this->dir)) {
            $prefix .= rtrim($this->dir, '/') . '/';
        }

        $prefix .= $this->name;
        return $prefix;
    }

    public function setDir(string $dir): void
    {
        $this->dir = $dir;
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function getMimeType(): string
    {
        if (empty($this->mimeType)) {
            if ($this->isRemote) {
                $this->downloadRemoteUrl();
            } else {
                $this->mimeType = mime_content_type($this->realPath);
            }
        }
        return $this->mimeType;
    }

    public function getName(): string
    {
        if (empty($this->name) && $this->isRemote) {
            $this->downloadRemoteUrl();
        }
        return $this->name;
    }

    public function getRealPath(): string
    {
        if (empty($this->realPath) && $this->isRemote) {
            $this->downloadRemoteUrl();
        }
        return $this->realPath;
    }

    public function rename(): void
    {
        $this->name = uniqid() . '.' . pathinfo($this->name, PATHINFO_EXTENSION);
    }

    public function release(): void
    {
        if ($this->isRemote) {
            @unlink($this->realPath);
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getExt(): string
    {
        return MimeTypes::getExtension($this->getMimeType());
    }

    public function getSize(): int
    {
        if (empty($this->realPath) && $this->isRemote) {
            $this->downloadRemoteUrl();
        }
        return $this->size;
    }

    private function downloadRemoteUrl(): void
    {
        if (isset($this->realPath)) {
            return;
        }

        $downloadedFile = (new SafeRemoteFileDownloader())->download($this->remoteUrl, $this->allowSvgDataUri);
        $this->realPath = $downloadedFile->getRealPath();
        $this->size = $downloadedFile->getSize();
        $this->mimeType = $downloadedFile->getMimeType();

        if (empty($this->name)) {
            $this->name = $downloadedFile->getName();
        }

        // 判断 name 中是否具有文件后缀，如果没有，则使用 mime_type 生成一个
        if (empty(pathinfo($this->name, PATHINFO_EXTENSION))) {
            $this->name = $this->name . '.' . MimeTypes::getExtension($this->mimeType);
        }

        $this->rename && $this->rename();
    }

    /**
     * 校验本地文件路径不能携带流包装器，避免 file://、php:// 等绕过远程下载安全边界。
     */
    private function assertPlainLocalPath(string $realPath): void
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $realPath) === 1) {
            throw new CloudFileException(sprintf('Unsupported file path wrapper: %s', $realPath));
        }
    }
}
