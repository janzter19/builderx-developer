<?php
declare(strict_types=1);

return [
    'name' => '0009_create_builder_form_layout',
    'up' => [
        "CREATE TABLE IF NOT EXISTS builder_form_layout (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            layout_key CHAR(36) NOT NULL UNIQUE,
            form_key CHAR(36) NOT NULL,
            version_key CHAR(36) NULL,
            layout_name VARCHAR(160) NOT NULL,
            layout_type ENUM('FORM','TABLE','DETAIL','PRINT','MOBILE') NOT NULL DEFAULT 'FORM',
            layout_status ENUM('DRAFT','ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'DRAFT',
            layout_schema JSON NOT NULL,
            layout_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_form_layout_name (form_key, layout_name, layout_type),
            INDEX idx_builder_form_layout_form (form_key),
            INDEX idx_builder_form_layout_version (version_key),
            INDEX idx_builder_form_layout_status (layout_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS builder_form_layout',
    ],
];
