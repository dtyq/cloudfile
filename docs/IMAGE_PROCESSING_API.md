# 图片处理参数说明

## 字符串格式

前端通过 URL Query 格式传递参数：

```
resize=w:300,h:200,m:lfit&quality=90&format=webp
```

### 格式规则

- **简单参数：** `quality=90&format=webp`
- **对象参数：** `resize=w:300,h:200,m:lfit` (用逗号分隔多个键值对)
- **多个参数：** 用 `&` 连接

## 参数列表

### 1. resize - 图片缩放

```
resize=w:300,h:200,m:lfit
```

| 缩写 | 完整名称 | 类型 | 范围 | 说明 |
|------|----------|------|------|------|
| w | width | number | 1-30000 | 目标宽度 |
| h | height | number | 1-30000 | 目标高度 |
| m | mode | string | lfit/mfit/fill/pad/fixed | 缩放模式 |
| l | limit | number | 1-30000 | 长边限制 |
| s | short | number | 1-30000 | 短边限制 |
| p | percentage | number | 1-1000 | 百分比缩放 |

**缩放模式：**
- `lfit`: 等比缩放，限制在宽高内（默认）
- `mfit`: 等比缩放，延伸出宽高外
- `fill`: 固定宽高，裁剪居中
- `pad`: 固定宽高，填充留白
- `fixed`: 强制固定宽高

**示例：**
```
resize=w:800,m:lfit          # 宽度800，等比缩放
resize=w:200,h:200,m:fill    # 200x200，裁剪
resize=p:50                  # 缩小到50%
```

---

### 2. quality - 图片质量

```
quality=90
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | 1-100 | 质量值，100最高 |

**建议值：** 85-90（高质量）、75-85（普通）、60-75（缩略图）

---

### 3. format - 格式转换

```
format=webp
```

| 类型 | 可选值 |
|------|--------|
| string | jpg, jpeg, png, webp, bmp, gif, tiff, heif, avif |

**推荐：** `webp`（最佳压缩）、`png`（需要透明背景）

---

### 4. rotate - 旋转

```
rotate=90
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | 0-360 | 顺时针旋转角度 |

---

### 5. crop - 裁剪

```
crop=x:10,y:10,w:100,h:100,g:center
```

| 缩写 | 完整名称 | 类型 | 范围 | 说明 |
|------|----------|------|------|------|
| x | x | number | ≥0 | 起始X坐标 |
| y | y | number | ≥0 | 起始Y坐标 |
| w | width | number | 1-30000 | 裁剪宽度 |
| h | height | number | 1-30000 | 裁剪高度 |
| g | gravity | string | nw/north/ne/west/center/east/sw/south/se | 重心位置 |

**示例：**
```
crop=w:300,h:300,g:center    # 从中心裁剪300x300
crop=x:100,y:100,w:500,h:500 # 从(100,100)裁剪500x500
```

---

### 6. circle - 圆形裁剪

```
circle=75
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | 1-4096 | 圆形半径 |

**用途：** 圆形头像  
**建议：** 配合 `format=png` 使用（透明背景）

**示例：**
```
resize=w:150,h:150,m:fill&circle=75&format=png
```

---

### 7. roundedCorners - 圆角

```
roundedCorners=20
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | 1-4096 | 圆角半径 |

---

### 8. indexcrop - 索引切割

```
indexcrop=a:x,l:200,i:1
```

| 缩写 | 完整名称 | 类型 | 范围 | 说明 |
|------|----------|------|------|------|
| a | axis | string | x, y | 切割方向 |
| l | length | number | 1-30000 | 每块长度 |
| i | index | number | ≥0 | 选取第几块 |

---

### 9. watermark - 水印

```
watermark=t:text,c:Logo,p:se,tr:80
```

| 缩写 | 完整名称 | 类型 | 范围 | 说明 |
|------|----------|------|------|------|
| t | type | string | text, image | 水印类型 |
| c | content | string | - | 文字内容或图片路径 |
| p | position | string | nw/north/ne/west/center/east/sw/south/se | 水印位置 |
| x | x | number | 0-4096 | 水平偏移 |
| y | y | number | 0-4096 | 垂直偏移 |
| tr | transparency | number | 0-100 | 透明度 |
| s | size | number | 1-1000 | 字体大小（文字） |
| co | color | string | - | 字体颜色（文字） |
| f | font | string | - | 字体名称（文字） |

**文字水印示例：**
```
watermark=t:text,c:版权所有,p:se,s:24,co:FFFFFF,tr:80
```

**图片水印示例：**
```
watermark=t:image,c:logo.png,p:se,tr:70
```

---

### 10. blur - 模糊

```
blur=r:10,s:5
```

| 缩写 | 完整名称 | 类型 | 范围 | 说明 |
|------|----------|------|------|------|
| r | radius | number | 1-50 | 模糊半径 |
| s | sigma | number | 1-50 | 标准差 |

**建议值：** 轻度 `r:3,s:2` / 中度 `r:10,s:5` / 重度 `r:30,s:20`

---

### 11. sharpen - 锐化

```
sharpen=100
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | 0-300 | 锐化强度 |

---

### 12. bright - 亮度

```
bright=30
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | -100 ~ 100 | 负数变暗，正数变亮 |

---

### 13. contrast - 对比度

```
contrast=20
```

| 类型 | 范围 | 说明 |
|------|------|------|
| number | -100 ~ 100 | 负数降低，正数增强 |

---

### 14. info - 获取图片信息

```
info=1
```

| 类型 | 可选值 | 说明 |
|------|--------|------|
| number | 0, 1 | 是否获取图片信息 |

**返回：** 格式、宽高、文件大小、EXIF信息

---

### 15. averageHue - 主色调

```
averageHue=1
```

| 类型 | 可选值 | 说明 |
|------|--------|------|
| number | 0, 1 | 是否获取主色调 |

---

### 16. autoOrient - 自适应方向

```
autoOrient=1
```

| 类型 | 可选值 | 说明 |
|------|--------|------|
| number | 0, 1 | 根据EXIF自动旋转 |

**推荐：** 处理用户上传的照片时启用

---

### 17. interlace - 渐进显示

```
interlace=1
```

| 类型 | 可选值 | 说明 |
|------|--------|------|
| number | 0, 1 | 是否启用渐进加载 |

**用途：** 大图片由模糊到清晰显示

---

### 18. raw - 原始字符串

```
raw=image/resize,w_300/quality,q_90
```

| 类型 | 说明 |
|------|------|
| string | 云服务商原始处理字符串 |

**注意：** 设置后其他参数会被忽略

---

## 常用场景

### 缩略图
```
resize=w:200,h:200,m:fill&quality=80&format=webp
```

### 圆形头像
```
resize=w:150,h:150,m:fill&circle=75&format=png
```

### 商品图（圆角）
```
resize=w:800,m:lfit&quality=85&roundedCorners=20&format=webp
```

### 文章配图（带水印）
```
resize=w:800,m:lfit&quality=85&watermark=t:text,c:版权所有,p:se,tr:80&format=webp
```

### 大图预览（渐进加载）
```
resize=l:1920,m:lfit&quality=90&autoOrient=1&interlace=1
```

---

## 使用限制

### 原图要求
- **格式：** JPG、PNG、BMP、GIF、WebP、TIFF、HEIC、AVIF
- **大小：** ≤ 20 MB
- **尺寸：** 宽或高 ≤ 30,000 px，总像素 ≤ 2.5 亿

### 错误处理

参数不符合要求时会返回错误：

```json
{
  "success": false,
  "error": "quality must be between 1 and 100"
}
```


## 参考文档

- [阿里云 OSS 图片处理文档](https://help.aliyun.com/zh/oss/user-guide/overview-17/)
- [火山引擎 TOS 图片处理文档](https://www.volcengine.com/docs/6349/153623)