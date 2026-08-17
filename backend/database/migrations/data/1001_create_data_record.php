<?php
declare(strict_types=1);

return [
    'name' => '1001_create_data_record',
    'up' => [
        "CREATE TABLE IF NOT EXISTS data_record (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_key CHAR(36) NOT NULL UNIQUE,
            form_key CHAR(36) NOT NULL UNIQUE,
            branch_key CHAR(36) NOT NULL,
            project_key CHAR(36) NOT NULL,
            record_table_name VARCHAR(100) NULL UNIQUE,
            record_schema_version INT UNSIGNED NOT NULL DEFAULT 1,
            record_status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'DRAFT',
            schema_hash CHAR(64) NULL,
            schema_snapshot JSON NULL,
            publication_status ENUM('PENDING','CREATING_TABLE','ACTIVE','FAILED') NOT NULL DEFAULT 'PENDING',
            publication_error TEXT NULL,
            server_timestamp TIMESTAMP NULL,
            record_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            record_created_by_key CHAR(36) NULL,
            record_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            record_updated_by_key CHAR(36) NULL,
            record_deleted_at TIMESTAMP NULL,
            record_deleted_by_key CHAR(36) NULL,
            INDEX idx_data_record_form (form_key),
            INDEX idx_data_record_branch_project (branch_key, project_key),
            INDEX idx_data_record_status (record_status),
            INDEX idx_data_record_publication_status (publication_status),
            INDEX idx_data_record_table (record_table_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS data_record',
    ],
];
