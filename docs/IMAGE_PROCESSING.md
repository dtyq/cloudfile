# 图片处理功能技术文档

## 概述

本项目提供了统一的图片处理接口，抹平阿里云 OSS 和火山引擎 TOS 等云存储服务商的差异。

**前端开发者请查看：** [IMAGE_PROCESSING_API.md](./IMAGE_PROCESSING_API.md)

## 已实现的功能

### 核心操作
- ✅ **图片缩放** (resize) - 调整图片尺寸
- ✅ **质量调整** (quality) - 压缩图片质量
- ✅ **格式转换** (format) - 转换图片格式
- ✅ **图片旋转** (rotate) - 旋转图片

### 裁剪操作
- ✅ **自定义裁剪** (crop) - 裁剪指定区域
- ✅ **内切圆** (circle) - 圆形裁剪
- ✅ **索引切割** (indexcrop) - 切分图片
- ✅ **圆角矩形** (roundedCorners) - 圆角矩形裁剪

### 效果和颜色
- ✅ **模糊效果** (blur) - 图片模糊
- ✅ **锐化** (sharpen) - 图片锐化
- ✅ **亮度** (bright) - 调整亮度
- ✅ **对比度** (contrast) - 调整对比度
- ✅ **水印** (watermark) - 添加文字或图片水印

### 其他功能
- ✅ **获取信息** (info) - 获取图片元信息
- ✅ **获取主色调** (averageHue) - 获取图片主色调
- ✅ **自适应方向** (autoOrient) - 自动旋转
- ✅ **渐进显示** (interlace) - 渐进式加载

## 使用方法

### 方式一：使用统一的 ImageProcessOptions（推荐）

```php
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;

// 创建图片处理选项
$imageOptions = (new ImageProcessOptions())
    ->resize(['width' => 300, 'height' => 200, 'mode' => 'lfit'])
    ->quality(90)
    ->format('webp');

// 应用到文件链接
$fileLinks = $filesystem->getFileLinks(
    ['path/to/image.jpg'],
    [],
    3600,
    ['image' => $imageOptions]
);
```

### 方式二：旧方式（向下兼容）

```php
// 使用原始处理字符串
$fileLinks = $filesystem->getFileLinks(
    ['path/to/image.jpg'],
    [],
    3600,
    ['image' => ['process' => 'image/resize,w_300/quality,q_90/format,webp']]
);
```

## 参数验证

所有参数都会进行严格的验证，不符合规则的参数会抛出 `InvalidArgumentException` 异常。

### 验证规则

- **quality**: 1-100
- **rotate**: 0-360
- **bright**: -100 到 100
- **contrast**: -100 到 100
- **sharpen**: 0-300
- **circle**: 1-4096
- **roundedCorners**: 1-4096
- **blur.radius**: 1-50
- **blur.sigma**: 1-50
- **resize.width/height**: 1-30000
- **resize.percentage**: 1-1000
- **crop.width/height**: 1-30000
- **watermark.transparency**: 0-100
- **watermark.x/y**: 0-4096
- **watermark.size**: 1-1000
- **format**: 必须是 jpg, jpeg, png, webp, bmp, gif, tiff, heif, avif 之一
- **resize.mode**: 必须是 lfit, mfit, fill, pad, fixed 之一
- **crop.gravity**: 必须是 nw, north, ne, west, center, east, sw, south, se 之一
- **autoOrient/interlace**: 必须是 0 或 1

## 详细参数说明

### 1. 图片缩放 (resize)

```php
$imageOptions->resize([
    'width' => 300,        // 宽度 (1-30000)
    'height' => 200,       // 高度 (1-30000)
    'mode' => 'lfit',      // 模式：lfit|mfit|fill|pad|fixed
    'limit' => 500,        // 长边限制 (1-30000)
    'short' => 200,        // 短边限制 (1-30000)
    'percentage' => 50,    // 百分比缩放 (1-1000)
]);
```

**验证规则：**
- width/height/limit/short: 1-30000
- percentage: 1-1000
- mode: lfit, mfit, fill, pad, fixed

### 2. 质量调整 (quality)

```php
$imageOptions->quality(90);  // 1-100（必须）
```

**验证规则：** 必须在 1-100 之间

### 3. 格式转换 (format)

```php
$imageOptions->format('webp');  // jpg|png|webp|bmp|gif|tiff|heif|avif
```

**验证规则：** 必须是支持的格式之一（不区分大小写）

### 4. 图片旋转 (rotate)

```php
$imageOptions->rotate(90);  // 0-360，顺时针旋转
```

**验证规则：** 必须在 0-360 之间

### 5. 自定义裁剪 (crop)

```php
$imageOptions->crop([
    'x' => 10,          // 起始X坐标 (>=0)
    'y' => 10,          // 起始Y坐标 (>=0)
    'width' => 100,     // 裁剪宽度 (1-30000)
    'height' => 100,    // 裁剪高度 (1-30000)
    'gravity' => 'nw',  // 重心位置（可选）
]);
```

**验证规则：**
- x/y: >= 0
- width/height: 1-30000
- gravity: nw, north, ne, west, center, east, sw, south, se

### 6. 内切圆 (circle)

```php
$imageOptions->circle(100);  // 圆形半径 (1-4096)
```

**验证规则：** 必须在 1-4096 之间

### 7. 圆角矩形 (roundedCorners)

```php
$imageOptions->roundedCorners(30);  // 圆角半径 (1-4096)
```

**验证规则：** 必须在 1-4096 之间

### 8. 索引切割 (indexcrop)

```php
$imageOptions->indexcrop([
    'axis' => 'x',      // 切割轴：x 或 y
    'length' => 100,    // 切割长度 (1-30000)
    'index' => 1,       // 选取索引 (>=0)
]);
```

**验证规则：**
- axis: 必须是 'x' 或 'y'
- length: 1-30000
- index: >= 0

### 9. 模糊效果 (blur)

```php
$imageOptions->blur([
    'radius' => 3,      // 模糊半径 (1-50)
    'sigma' => 2,       // 标准差 (1-50)
]);
```

**验证规则：**
- radius: 1-50
- sigma: 1-50

### 10. 锐化 (sharpen)

```php
$imageOptions->sharpen(100);  // 0-300
```

**验证规则：** 必须在 0-300 之间

### 11. 亮度 (bright)

```php
$imageOptions->bright(50);  // -100 到 100
```

**验证规则：** 必须在 -100 到 100 之间

### 12. 对比度 (contrast)

```php
$imageOptions->contrast(50);  // -100 到 100
```

**验证规则：** 必须在 -100 到 100 之间

### 13. 水印 (watermark)

#### 文字水印
```php
$imageOptions->watermark([
    'type' => 'text',
    'content' => '水印文字',
    'font' => 'wqy-zenhei',
    'size' => 40,           // 字体大小 (1-1000)
    'color' => 'FFFFFF',
    'position' => 'se',     // nw|north|ne|west|center|east|sw|south|se
    'x' => 10,              // X 偏移 (0-4096)
    'y' => 10,              // Y 偏移 (0-4096)
    'transparency' => 80,   // 透明度 (0-100)
]);
```

#### 图片水印
```php
$imageOptions->watermark([
    'type' => 'image',
    'content' => 'watermark/logo.png',  // 水印图片路径
    'position' => 'se',
    'x' => 10,              // X 偏移 (0-4096)
    'y' => 10,              // Y 偏移 (0-4096)
    'transparency' => 80,   // 透明度 (0-100)
]);
```

**验证规则：**
- type: 必须是 'text' 或 'image'
- position: nw, north, ne, west, center, east, sw, south, se
- x/y: 0-4096
- transparency: 0-100
- size (文字): 1-1000

### 14. 获取信息 (info)

```php
$imageOptions->info();  // 获取图片基本信息和 EXIF 信息
```

### 15. 获取主色调 (averageHue)

```php
$imageOptions->averageHue();  // 获取图片主色调
```

### 16. 自适应方向 (autoOrient)

```php
$imageOptions->autoOrient(1);  // 0 或 1
```

**验证规则：** 必须是 0 或 1

### 17. 渐进显示 (interlace)

```php
$imageOptions->interlace(1);  // 0 或 1
```

**验证规则：** 必须是 0 或 1

## 链式调用示例

```php
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;

$imageOptions = (new ImageProcessOptions())
    ->resize(['width' => 800, 'mode' => 'lfit'])
    ->quality(85)
    ->format('webp')
    ->watermark([
        'type' => 'text',
        'content' => '版权所有',
        'position' => 'se',
        'transparency' => 80,
    ])
    ->bright(10)
    ->contrast(5);

$fileLinks = $filesystem->getFileLinks(
    ['path/to/image.jpg'],
    [],
    3600,
    ['image' => $imageOptions]
);
```

## 字符串转换

`ImageProcessOptions` 支持与 JSON 字符串之间的双向转换，便于前端传参和数据存储。

### 从字符串创建 (fromString)

```php
use Dtyq\CloudFile\Kernel\Struct\ImageProcessOptions;

// 从前端接收的 JSON 字符串
$jsonString = '{"resize":{"width":300,"mode":"lfit"},"quality":90,"format":"webp"}';

// 解析为 ImageProcessOptions 对象
$imageOptions = ImageProcessOptions::fromString($jsonString);

// 参数会自动验证，不符合规则会抛出异常
try {
    $imageOptions = ImageProcessOptions::fromString($invalidJson);
} catch (InvalidArgumentException $e) {
    // 处理错误：Invalid JSON string 或参数验证失败
}
```

### 转换为字符串 (toString)

```php
$imageOptions = (new ImageProcessOptions())
    ->resize(['width' => 300, 'mode' => 'lfit'])
    ->quality(90)
    ->format('webp');

// 转换为 JSON 字符串
$jsonString = $imageOptions->toString();
// 结果: {"resize":{"width":300,"mode":"lfit"},"quality":90,"format":"webp"}

// 或使用魔术方法
$jsonString = (string) $imageOptions;
```

### 应用场景

#### 1. 接收前端参数

```php
// 控制器中接收前端传来的字符串参数
public function getImageLinks(Request $request)
{
    $paths = $request->input('paths');
    $imageOptionsString = $request->input('options.image');
    
    // 解析字符串为对象
    $imageOptions = ImageProcessOptions::fromString($imageOptionsString);
    
    // 使用对象
    $fileLinks = $filesystem->getFileLinks($paths, [], 3600, [
        'image' => $imageOptions
    ]);
    
    return response()->json(['links' => $fileLinks]);
}
```

#### 2. 缓存配置

```php
use Illuminate\Support\Facades\Cache;

// 缓存常用配置
$thumbnailOptions = (new ImageProcessOptions())
    ->resize(['width' => 200, 'height' => 200, 'mode' => 'fill'])
    ->quality(75)
    ->format('webp');

Cache::put('image.preset.thumbnail', $thumbnailOptions->toString(), 3600);

// 从缓存读取
$cachedString = Cache::get('image.preset.thumbnail');
$imageOptions = ImageProcessOptions::fromString($cachedString);
```

#### 3. 数据库存储

```php
// 存储用户的图片处理偏好
$userPreference = (new ImageProcessOptions())
    ->quality(90)
    ->format('webp')
    ->autoOrient(1);

DB::table('user_preferences')->insert([
    'user_id' => $userId,
    'image_options' => $userPreference->toString(),
]);

// 读取并使用
$row = DB::table('user_preferences')->where('user_id', $userId)->first();
$imageOptions = ImageProcessOptions::fromString($row->image_options);
```

#### 4. 日志和调试

```php
// 记录图片处理参数
Log::info('Processing image', [
    'path' => $imagePath,
    'options' => $imageOptions->toString(),
]);

// 方便的数组转换
$optionsArray = $imageOptions->toArray();
```

### 往返转换

```php
// 创建对象
$original = (new ImageProcessOptions())
    ->resize(['width' => 800, 'mode' => 'lfit'])
    ->quality(85)
    ->format('webp');

// 转换为字符串
$jsonString = $original->toString();

// 从字符串恢复对象
$restored = ImageProcessOptions::fromString($jsonString);

// 两个对象完全相同
assert($original->toArray() === $restored->toArray());
```

### 错误处理

```php
try {
    // JSON 格式错误
    $options = ImageProcessOptions::fromString('not a valid json');
} catch (InvalidArgumentException $e) {
    // 错误信息: Invalid JSON string: Syntax error
}

try {
    // 参数验证失败
    $options = ImageProcessOptions::fromString('{"quality": 150}');
} catch (InvalidArgumentException $e) {
    // 错误信息: quality must be between 1 and 100
}

try {
    // 非数组 JSON
    $options = ImageProcessOptions::fromString('"string value"');
} catch (InvalidArgumentException $e) {
    // 错误信息: JSON string must decode to an array
}
```

## 向下兼容

如果您之前使用的是原始字符串方式，可以继续使用：

```php
// 旧方式仍然支持
$fileLinks = $filesystem->getFileLinks(
    ['path/to/image.jpg'],
    [],
    3600,
    ['image' => ['process' => 'image/resize,w_300/quality,q_90']]
);

// 或者使用 raw 方法
$imageOptions = (new ImageProcessOptions())
    ->raw('image/resize,w_300/quality,q_90/format,webp');
```

## 注意事项

1. **图片格式限制**：原图仅支持 JPG、PNG、BMP、GIF、WebP、TIFF、HEIC、AVIF
2. **图片大小限制**：原图大小不能超过 20 MB
3. **图片尺寸限制**：原图高或宽不能超过 30,000 px，且总像素不能超过 2.5 亿 px
4. **处理顺序**：多个处理操作按照调用顺序依次执行
5. **服务商差异**：虽然接口统一，但各服务商的具体实现可能有细微差异，请参考官方文档

## 参考文档

- [阿里云 OSS 图片处理文档](https://help.aliyun.com/zh/oss/user-guide/overview-17/)
- [火山引擎 TOS 图片处理文档](https://www.volcengine.com/docs/6349/153623)

## 架构说明

### 核心组件

1. **ImageProcessOptions** - 统一的图片处理选项类
   - 提供链式调用 API
   - 包含完整的参数验证逻辑
2. **ImageProcessInterface** - 图片处理器接口
   - `buildProcessString()` - 构建服务商特定的处理字符串
   - `getParameterName()` - 获取参数名称（如 'x-oss-process'）
3. **OSSImageProcessor** - 阿里云 OSS 图片处理器实现
4. **TOSImageProcessor** - 火山引擎 TOS 图片处理器实现

### 工作流程

```
用户代码
  ↓
ImageProcessOptions (统一参数)
  ↓
ImageProcessor (转换器)
  ↓
服务商特定的处理字符串
  ↓
云存储 API
```

## 扩展性

如需支持其他云存储服务商，只需：

1. 实现 `ImageProcessInterface` 接口
2. 在对应的 Expand 类中集成处理器
3. 保持与现有接口的一致性

## 前端文档

如果您是前端开发者，请参考 [IMAGE_PROCESSING_API.md](./IMAGE_PROCESSING_API.md) 文档，该文档提供了更适合前端使用的 API 说明和示例。
