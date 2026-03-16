<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */
return [
    'storages' => [
        'file_service' => [
            'adapter' => 'file_service',
            'config' => [
                'host' => '',
                'platform' => '',
                'key' => '',
            ],
        ],
        'aliyun' => [
            'adapter' => 'aliyun',
            'config' => [
                'accessId' => '',
                'accessSecret' => '',
                'bucket' => '',
                'endpoint' => '',
                'role_arn' => '',
            ],
        ],
        'tos' => [
            'adapter' => 'tos',
            'config' => [
                'region' => '',
                'endpoint' => '',
                'ak' => '',
                'sk' => '',
                'bucket' => '',
                'trn' => '',
            ],
        ],
        'minio' => [
            'adapter' => 'minio',
            'config' => [
                // MinIO 服务地址，如：http://localhost:9000
                'endpoint' => '',
                // 区域，默认 us-east-1
                'region' => '',
                // Access Key
                'accessKey' => '',
                // Secret Key
                'secretKey' => '',
                // 存储桶名称
                'bucket' => '',
                // MinIO 必须使用 path-style 访问
                'use_path_style_endpoint' => true,
                // SDK 版本
                'version' => 'latest',
                // 可选：STS 能力（分片上传、预签名 URL、对象管理等）依赖的 Role ARN
                'role_arn' => '',
                // 可选：STS 服务端点（如果与主服务不同）
                'sts_endpoint' => '',
            ],
            // 可选：是否公开读
            'public_read' => false,
            // 可选：默认选项
            'options' => [],
        ],
    ],
];
