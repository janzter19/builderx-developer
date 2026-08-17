<?php
declare(strict_types=1);

return [
    'name' => '0007_create_builder_form',
    'up' => [
        "CREATE TABLE IF NOT EXISTS builder_form (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_key CHAR(36) NOT NULL UNIQUE,
            branch_key CHAR(36) NOT NULL,
            project_key CHAR(36) NOT NULL,
            form_code VARCHAR(80) NOT NULL UNIQUE,
            form_name VARCHAR(160) NOT NULL,
            form_description TEXT NULL,
            form_table_name VARCHAR(100) NULL UNIQUE,
            form_schema_version INT UNSIGNED NOT NULL DEFAULT 1,
            form_status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'DRAFT',
            form_settings JSON NULL,
            server_timestamp TIMESTAMP NULL,
            form_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            form_created_by_key CHAR(36) NULL,
            form_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            form_updated_by_key CHAR(36) NULL,
            form_deleted_at TIMESTAMP NULL,
            form_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_form_branch (branch_key),
            INDEX idx_builder_form_project (project_key),
            INDEX idx_builder_form_status (form_status),
            INDEX idx_builder_form_table (form_table_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS builder_form_version (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            version_key CHAR(36) NOT NULL UNIQUE,
            form_key CHAR(36) NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            version_status ENUM('DRAFT','PUBLISHED','ARCHIVED','DELETED') NOT NULL DEFAULT 'DRAFT',
            schema_snapshot JSON NOT NULL,
            published_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by_key CHAR(36) NULL,
            UNIQUE KEY uq_builder_form_version_number (form_key, version_number),
            INDEX idx_builder_form_version_form (form_key),
            INDEX idx_builder_form_version_status (version_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS builder_form_version',
        'DROP TABLE IF EXISTS builder_form',
    ],
];
