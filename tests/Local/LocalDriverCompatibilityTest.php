<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\CloudFile\Tests\Local;

use Dtyq\CloudFile\CloudFile;
use Dtyq\CloudFile\Kernel\Exceptions\CloudFileException;
use Dtyq\CloudFile\Kernel\FilesystemProxy;
use Dtyq\CloudFile\Tests\Container;
use Dtyq\SdkBase\SdkBase;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * 使用隔离目录验证本地驱动经 CloudFile 入口的跨版本兼容。
 * @internal
 * @coversNothing
 */
class LocalDriverCompatibilityTest extends TestCase
{
    private string $root;

    private FilesystemProxy $filesystem;

    /**
     * 构造仅使用临时目录和占位域名的完整本地存储入口。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cloudfile-compat-' . bin2hex(random_bytes(8));
        $cloudFile = new CloudFile(new SdkBase(new Container(), [
            'sdk_name' => 'mock-cloudfile-compat',
            'exception_class' => CloudFileException::class,
            'cloudfile' => [
                'storages' => [
                    'local' => [
                        'adapter' => 'local',
                        'config' => [
                            'root' => $this->root,
                            'read_host' => 'https://read.example.test',
                            'write_host' => 'https://write.example.test',
                        ],
                    ],
                ],
            ],
        ]));
        $this->filesystem = $cloudFile->get('local');
    }

    /**
     * 无论断言是否成功，都清理本用例拥有的文件与目录。
     */
    protected function tearDown(): void
    {
        try {
            $this->filesystem->deleteDirectory('mock-directory');
            rmdir($this->root);
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 验证已有文件操作通过父类规范化路径并保持读取和删除行为。
     */
    public function testExistingFileOperations(): void
    {
        $this->filesystem->write('mock-directory/./sample.txt', 'mock-content');

        $this->assertTrue($this->filesystem->fileExists('mock-directory/sample.txt'));
        $this->assertSame('mock-content', $this->filesystem->read('mock-directory/sample.txt'));

        $this->filesystem->delete('mock-directory/sample.txt');
        $this->assertFalse($this->filesystem->fileExists('mock-directory/sample.txt'));
    }

    /**
     * 仅在父类提供目录查询入口时验证目录创建前后的结果。
     */
    public function testDirectoryQueryThroughProxy(): void
    {
        if (! method_exists(Filesystem::class, 'directoryExists')) {
            $this->markTestSkipped('Flysystem 2 不提供目录查询入口，文件操作兼容由独立用例验证。');
        }

        $this->assertFalse($this->filesystem->directoryExists('mock-directory'));
        $this->filesystem->createDirectory('mock-directory');
        $this->assertTrue($this->filesystem->directoryExists('mock-directory/./'));
        $this->filesystem->deleteDirectory('mock-directory');
        $this->assertFalse($this->filesystem->directoryExists('mock-directory'));
    }
}
