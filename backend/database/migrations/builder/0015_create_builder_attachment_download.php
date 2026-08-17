<?php
declare(strict_types=1);

return [
    'name' => '0015_create_builder_attachment_download',
    'up' => [
        "CREATE TABLE IF NOT EXISTS builder_attachment_download (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            download_key CHAR(36) NOT NULL UNIQUE,
            attachment_key CHAR(36) NOT NULL,
            downloaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            downloaded_by_key CHAR(36) NULL,
            INDEX idx_attachment_download_attachment (attachment_key),
            INDEX idx_attachment_download_user (downloaded_by_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS builder_attachment_download',
    ],
];
