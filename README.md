<h1 align="center">  cloud-file </h1>

<p align="center"> .</p>

## 介绍

本 sdk 为文件服务 sdk 增强版，提供了更多的功能，更加易用。

内置阿里云、火山云、文件服务的简单调用，只需几行代码即可完成文件的上传、下载、删除等操作。

支持后端直传模式，获取临时凭证后，后端直接上传文件到云存储，减少服务器压力。

可替换的`FilesystemAdapter`配置，自定义更强。

抽离文件服务通用功能到包中，可不依赖文件服务即可使用。

## Flysystem 版本兼容

CloudFile 支持 `league/flysystem: ^2.1.1 || ^3.35.3`，由 Composer 根据应用约束选择一个版本，不会同时安装两个主版本。已有锁文件中的 2.x 不会因为放宽约束自动升级；需要升级的应用应明确要求 3.x，并更新、验证自己的锁文件。

- 新接入及 PHP 8.4 场景推荐选择 Flysystem 3.35.3 或更高的 3.x；保留 2.x 仅用于旧应用兼容。
- Flysystem 2.x 仍受 [CVE-2026-102601](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr) 影响，启用安全阻断的 Composer 可能拒绝安装。双版本支持不表示 2.x 已完成安全修复，也不解决其 PHP 8.4 弃用警告。
- 阿里云和 AWS 适配器保留各自的兼容版本范围，由 Composer 联合求解；应用若固定了适配器版本，也需检查其 Flysystem 要求。
- 自定义 `FilesystemAdapter` 在使用 Flysystem 3 时必须实现 `directoryExists(string $path): bool`。内置 S3 驱动委托底层查询，本地驱动复用继承实现；TOS 和文件服务驱动显式抛出“不支持”异常。
- `FilesystemProxy` 沿用所安装 Flysystem 的 API：2.x 不提供 3.x 新增的目录查询入口。升级后仍需验证应用实际使用的上传、下载和文件操作；第三方存储 SDK 的 PHP 兼容性需分别检查。

## 支持的云

 - 文件服务代理的 阿里云、火山云
 - 阿里云
 - 火山云
- MinIO（兼容 S3 协议）

## 重要功能
- [x] 获取临时凭证
- [x] 上传文件 - 通过临时凭证直传
- [x] 复制文件
- [x] 删除文件
- [x] 批量获取可访问链接
- [x] 获取文件元数据

## 批量标签与文件删除

```php
$filesystem = $cloudFile->get('file_service_test');
$paths = ['mock-org/1001/a.txt', 'mock-org/1001/b.txt'];
$options = ['token' => 'mock-token', 'organization-code' => 'mock-org'];

// 同一份完整标签集应用于所有目标，未提交的旧标签会被移除。
$filesystem->setTags($paths, [
    ['key' => 'verification_state', 'value' => 'pending'],
    ['key' => 'source', 'value' => 'example'],
], $options);

// 清空文件标签，不删除文件；等价于 setTags($paths, [], $options)。
$filesystem->deleteTags($paths, $options);

// 直接删除文件，不依赖标签。
$filesystem->destroy($paths, $options);
```

- 标签设置和清空支持文件服务、OSS、TOS、MinIO/S3 兼容驱动；Local 及未实现标签能力的自定义扩展会明确报不支持。
- 标签格式为最多 10 项的 `key`/`value` 列表，键唯一、非空且最多 128 字符，值最多 256 字符，可为空字符串。空标签集合表示清空全部标签。路径和标签格式错误仍作为本地参数异常抛出。
- 标签设置、清空和文件删除每次最多接收 100 项路径，去重前检查数量，超过上限或列表格式错误直接抛出参数异常，不自动拆批；空列表不发请求。
- 文件服务存储的这三个公开入口通过 STS 直连对应平台，不调用 `/file/tags` 或 `/file/destroy`。标签按需申请 `object_tagging`，删除申请 `del_objects`，执行前整批核对目标是否位于凭证返回的授权目录。凭证始终通过受保护接口获取，`internal=true` 不放宽变更身份隔离。
- 变更凭证范围为当前组织、应用目录，复用既有 PSR 缓存，键包含存储配置、身份选项和权限；临近到期重新申请，`cache=false` 跳过读取。实际减少换证请求需要调用方提供有效的缓存实现，不能使用 NoCache 后仍假定会复用。
- STS 删除只删除存储对象，**不通知文件服务，也不删除其数据库元信息**。
- 文件服务存储的变更入口中，换证或存储操作失败只记日志；本地参数、目录越界错误仍抛出。标签和删除以外的凭证、链接、复制等入口保留原有失败行为。云端变更不自动重试；换证沿用既有有限重试。
- 三个入口为 void：**文件服务存储调用未抛异常不代表操作成功**，应检查日志或独立存储核验。删除后才能更新业务状态的调用方，不能仅凭 `destroy()` 正常返回判断成功。
- OSS/TOS/S3 删除均使用官方批量接口，检查响应中的逐项失败和遗漏目标；直连存储保留抛异常语义，文件服务存储则记录失败日志。标签使用逐对象原生接口，清空标签不删除文件。
- 标签仍按同步语义逐文件调用原生设置/清空接口；未接入异步批处理作业。组织上下文由调用方通过 `organization-code` 传入，不在 SDK 内设置固定组织。
- 仅上述文件服务变更请求采用日志降级；临时凭证、文件链接、复制等仍保留原有失败行为。直接 OSS/TOS/S3 驱动的存储异常仍向上传播。
- 标签整组替换和批量文件操作都不保证原子性；不提供并发合并、历史版本操作或自动清理任务。直接存储驱动需具备相应的对象标签权限。

## 独立获取标签 STS 凭证

`getUploadTemporaryCredential()` 同样支持标签权限，方法名沿用原有接口：

```php
$policy = new \Dtyq\CloudFile\Kernel\Struct\CredentialPolicy([
    'sts' => true,
    'sts_type' => 'object_tagging',
    'dir' => 'example/',
    'expires' => 3600,
]);
$credential = $filesystem->getUploadTemporaryCredential($policy, $options);
```

也可传 `upload_objects|object_tagging` 或 `list_objects|object_tagging` 组合申请。该能力支持文件服务取证以及直连 OSS/TOS/S3 的策略生成；文件服务需先部署支持 `object_tagging` 的版本，云端角色也需具备相应权限。凭证含敏感信息，不应输出到日志。

## 注意事项
如果要使用直连的阿里云、火山云，要先安装相应的FilesystemAdapter，如

```composer
"suggest": {
    "hyperf/logger": "Required to use the Hyperf.",
    "hyperf/di": "Required to use the Hyperf.",
    "hyperf/config": "Required to use the Hyperf.",
    "hyperf/cache": "Required to use the Hyperf.",
    "alibabacloud/sts": "^1.8",
    "aliyuncs/oss-sdk-php": "^2.7",
    "league/flysystem": "^2.0",
    "xxtime/flysystem-aliyun-oss": "^1.6",
    "volcengine/ve-tos-php-sdk": "^2.1",
    "volcengine/volc-sdk-php": "^1.0"
},
```

或者在 config 配置中，增加driver参数，即FilesystemAdapter，由于包之间依赖兼容性问题，可能会有bug，但目前使用文件服务服务的机会比较多，先不管这个了，有问题再改改

## 安装

```shell
$ composer require dtyq/cloudfile -vvv
```

## 配置

```php
$configs = [
    'storages' => [
        // 文件服务配置示例
        'file_service_test' => [
            'adapter' => 'file_service',
            'config' => [
                // 文件服务地址
                'host' => 'xxx',
                // 文件服务的 platform
                'platform' => 'xxx',
                // 文件服务的 key
                'key' => 'xxx',
            ],
        ],
        // 阿里云配置示例
        'aliyun_test' => [
            'adapter' => 'aliyun',
            'config' => [
                'accessId' => 'xxx',
                'accessSecret' => 'xxx',
                'bucket' => 'xxx',
                'endpoint' => 'xxx',
                'role_arn' => 'xxx',
            ],
        ],
        // 火山云配置示例
        'tos_test' => [
            'adapter' => 'tos',
            'config' => [
                'region' => 'xxx',
                'endpoint' => 'xxx',
                'ak' => 'xxx',
                'sk' => 'xxx',
                'bucket' => 'xxx',
                'trn' => 'xxx',
            ],
        ],
        // MinIO 直连配置示例（底层复用 S3 协议实现）
        'minio_test' => [
            'adapter' => 'minio',
            'config' => [
                'region' => 'us-east-1',
                'endpoint' => 'http://localhost:9000', // MinIO 服务地址
                'accessKey' => 'xxx',
                'secretKey' => 'xxx',
                'bucket' => 'xxx',
                'use_path_style_endpoint' => true, // MinIO 必须为 true
                'version' => 'latest',
                'role_arn' => 'xxx', // 可选，用于 STS 临时凭证
            ],
        ],
    ],
];

$container = new SdkContainer([
    // sdk 基本配置
    'sdk_name' => 'easy_file_sdk',
    'exception_class' => CloudFileException::class,·
    // cloudfile 配置
    'cloudfile' => $configs,
]);

$cloudFile = new CloudFile($container);
```

## 文件服务特殊性
因为要请求文件服务，是需要动态 token 和 organization-code 的，这里需要放到 options 参数中，**所有**文件服务的请求，都需要带上，如下

```php
$filesystem = $cloudFile->get('file_service_test');

$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
    'cache' => false, // 根据需要设置，建议 false，方便调试
];

```

## 使用

### 获取临时凭证

```php
$filesystem = $cloudFile->get('file_service_test');

$credentialPolicy = new CredentialPolicy([
    'sts' => false,
    'roleSessionName' => 'test',
]);
$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
$data = $filesystem->getUploadTemporaryCredential($credentialPolicy, $options);
```

### 上传文件 - 通过临时凭证直传
上传完成后，记得查看`$uploadFile->getKey()`，来获取上传后的文件实际路径（因为文件服务会拼接 组织/应用 前缀）

```php
$filesystem = $cloudFile->get('file_service_test');

$credentialPolicy = new CredentialPolicy([
    'sts' => false,
]);

$realPath = __DIR__ . '/../test.txt';

$uploadFile = new UploadFile($realPath, 'easy-file');
$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
$filesystem->uploadByCredential($uploadFile, $credentialPolicy, $options);
```

### 上传 base64 data URI

`UploadFile` 也接受 `data:image/<type>;base64,...` 形式的图片，默认支持 png、jpeg、jpg、gif、webp。

SVG 可以携带脚本，默认拒绝。业务对可信来源（例如随代码发布的内置图标）需要上传 SVG data URI 时，在读取文件信息或上传之前显式开启：

```php
$uploadFile = new UploadFile($svgDataUri, 'default-files', 'icon.svg');
$uploadFile->setAllowSvgDataUri(true);
$filesystem->uploadByCredential($uploadFile, $credentialPolicy, $options);
```

- 开关只能由业务代码决定，不能来自请求参数、文件名或 MIME。
- 开启后只放行格式解析，不清洗 SVG 内容；展示与访问侧的隔离仍由业务负责。
- 本地路径和 URL 上传不受此开关影响。
- 解码后按实际内容复核：SVG 内容只能以 `image/svg+xml` 声明，不能以位图声明绕过开关。

### 复制文件

```php
$filesystem = $cloudFile->get('file_service_test');

$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
// 复制文件成功后，要获取这个 path 结果才是真实地址，因为文件服务会有权限处理
$path = $filesystem->duplicate('easy-file/test.txt', 'easy-file/test-copy.txt', $options);
```

### 删除文件

```php
$filesystem = $cloudFile->get('file_service_test');

$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
$filesystem->destroy('easy-file/test.txt', $options);
```

### 批量获取可访问链接
> 请求文件服务时，不检测是否存在，直接返回链接
```php
$filesystem = $cloudFile->get('file_service_test');

$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
$list = $filesystem->getLinks([
    'easy-file/file-service.txt',
    'easy-file/test.txt',
], [], 7200, $options);
```

### 获取文件元数据

```php
$filesystem = $cloudFile->get('file_service_test');

$options = [
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
$list = $filesystem->getMetas([
    'easy-file/file-service.txt',
    'easy-file/test.txt'], $options);
```
## Hyperf 快捷使用

### 发布配置文件
```shell
$ php bin/hyperf.php vendor:publish dtyq/cloudfile
```

### 使用
```php
// 这里可以在构造中注入 CloudFileFactory
$cloudFile = \Hyperf\Support\make(CloudFileFactory::class)->create();

$filesystem = $cloudFile->get('file_service');

$options = [
    // 这里的动态 token 需要自行传入
    'token' => 'xxx',
    'organization-code' => 'xxx',
];
$list = $filesystem->getLinks([
    'easy-file/file-service.txt',
    'easy-file/test.txt',
], [], 7200, $options);

$link = $list[0]->getUrl();
```

### Hyperf 中使用 MinIO
```php
// 在 .env 中配置
MINIO_ENDPOINT=http://localhost:9000
MINIO_REGION=us-east-1
MINIO_ACCESS_KEY=your-access-key
MINIO_SECRET_KEY=your-secret-key
MINIO_BUCKET=your-bucket

// 在代码中使用
$cloudFile = \Hyperf\Support\make(CloudFileFactory::class)->create();
$filesystem = $cloudFile->get('minio');

// 上传文件
$uploadFile = new UploadFile('/path/to/file.txt', 'my-folder');
$credentialPolicy = new CredentialPolicy(['sts' => false]);
$filesystem->uploadByCredential($uploadFile, $credentialPolicy);

// 获取文件链接
$links = $filesystem->getLinks(['my-folder/file.txt'], [], 3600);
```

## MinIO 直连使用说明

### 配置要点
MinIO 是兼容 AWS S3 协议的对象存储，但对外配置仍使用 `adapter=minio`。使用时需要注意：
- `use_path_style_endpoint` 必须设置为 `true`
- `endpoint` 设置为 MinIO 服务地址（如 `http://localhost:9000`）
- `sts=false` 时，返回与 `file_service + platform=minio` 对齐的表单直传 credential，核心字段包括 `signature`、`policy`、`fields`
- `sts=true` 时，返回与 `file_service + platform=minio` 对齐的 STS credential，核心字段包括 `sts_token`、`access_key_id`、`access_key_secret`
- `uploadByCredential()` 固定走 `sts=false`
- `uploadByChunks()`、`appendUploadByCredential()`、`getPreSignedUrlByCredential()`、`listObjectsByCredential()` 等 SDK 能力固定走 `sts=true`

### 基本使用示例

```php
$filesystem = $cloudFile->get('minio_test');

// 获取临时凭证
$credentialPolicy = new CredentialPolicy([
    'sts' => false, // 返回 MinIO 表单直传 credential，字段语义与 file_service/minio 保持一致
    'roleSessionName' => 'test',
]);
$credential = $filesystem->getUploadTemporaryCredential($credentialPolicy);

// 上传文件
$realPath = __DIR__ . '/test.txt';
$uploadFile = new UploadFile($realPath, 'my-folder');
$filesystem->uploadByCredential($uploadFile, $credentialPolicy);

// 分片上传 / 预签名 URL 等 SDK 场景需要 STS 凭证
$stsPolicy = new CredentialPolicy([
    'sts' => true,
    'roleSessionName' => 'test',
]);
$stsCredential = $filesystem->getUploadTemporaryCredential($stsPolicy);

// 获取文件链接
$links = $filesystem->getLinks(['my-folder/test.txt'], [], 3600);
$downloadUrl = $links[0]->getUrl();

// 复制文件
$newPath = $filesystem->duplicate('my-folder/test.txt', 'my-folder/test-copy.txt');

// 删除文件
$filesystem->destroy('my-folder/test.txt');
```

### MinIO 与 AWS S3 的区别
- MinIO 默认使用 path-style 访问（`http://endpoint/bucket/key`）
- AWS S3 默认使用 virtual-hosted-style 访问（`http://bucket.endpoint/key`）
- 通过设置 `use_path_style_endpoint => true` 可统一使用 path-style
- `cloudfile` 对外暴露的是 `minio` 适配器，内部复用的是 S3 协议实现
- 直连 `minio` 与 `file_service + platform=minio` 共享同一套 credential 语义，避免两套接入心智
```

## 真实标签与删除集成测试

`tests/Integration/RealFileMutationTest.php` 沿用 `CloudFileBaseTest`，读取真实 `storages.json`，分别运行 `testTags` 和 `testDestroy`。每个测试只创建随机目录中的合成文件，并在 `finally` 中清理；文件服务请求失败会通过日志计数让测试失败。

```bash
CLOUDFILE_RUN_REAL_TESTS=1 \
CLOUDFILE_TEST_CONFIG=/absolute/path/to/storages.json \
vendor/bin/phpunit tests/Integration/RealFileMutationTest.php
```

默认不执行真实写入。配置只读，不复制或输出凭证；覆盖文件服务 OSS/TOS、直连 OSS/TOS。

## 可选 STS 读取链接

在目标 storage 上设置 `prefer_sts_for_links`，让 `getLinks()` 和 `getLink()` 优先使用临时凭证本地签名：

```php
'storages' => [
    'file_service' => [
        'adapter' => 'file_service',
        'prefer_sts_for_links' => true,
        'config' => [
            'host' => 'https://files.example.com',
            'platform' => 'tos',
            'key' => 'your-storage-key',
        ],
    ],
],
```

- 默认 `false`，仅接受布尔值；每个 storage 独立控制，关闭时保留原有取链行为。
- 支持 OSS、TOS、MinIO，以及这些平台的 `file_service` 存储。需要已有可用的 STS 角色配置；LOCAL 和尚无 STS 签名实现的 OBS 保留原路线。
- 开启后，整批文件复用一份凭证，不调用文件服务链接接口。原始对象 key 不做百分号替换，返回数组键与 `FileLink.path` 保持原值。
- 支持的平台申请凭证或签名失败时抛异常，不静默回退旧取链。
- 复用现有图片处理和下载名参数，签名请求固定为 GET。
- 新入口申请一小时临时凭证，并复用已有凭证缓存。链接实际 TTL 取请求时长与凭证剩余寿命减 60 秒的较小值；请求一天不代表链接仍能存活一天。缓存凭证接近到期时，链接可能更短。
- `FileLink.expires` 保留既有适配器口径：直接存储为实际 TTL 秒数，文件服务为实际截止时间戳。
- 此开关优先于 `public_read`，返回有时效的存储 endpoint 签名链接，不保证原无签名公共域名、CDN 或 `use_cdn` 路线等价；开启前确认客户端可达该 endpoint。
- `getMetas()` 等其他读取能力不受此开关控制。

`tests/Integration/RealStsLinksTest.php` 使用外部配置，仅在随机目录创建合成文件，验证含 `%`、`%20`、`%25`、`%2F` 的近似文件名分别读回正确内容，最后删除并确认目录为空：

```bash
CLOUDFILE_RUN_REAL_TESTS=1 \
CLOUDFILE_TEST_CONFIG=/absolute/path/to/storages.json \
vendor/bin/phpunit tests/Integration/RealStsLinksTest.php
```
