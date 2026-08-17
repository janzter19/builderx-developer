<?php
declare(strict_types=1);

return [
    'name' => '0008_create_builder_form_field',
    'up' => [
        "CREATE TABLE IF NOT EXISTS builder_form_field (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            field_key CHAR(36) NOT NULL UNIQUE,
            form_key CHAR(36) NOT NULL,
            field_code VARCHAR(80) NOT NULL,
            field_name VARCHAR(160) NOT NULL,
            field_label VARCHAR(160) NOT NULL,
            field_type VARCHAR(60) NOT NULL,
            data_type VARCHAR(60) NOT NULL DEFAULT 'string',
            database_column_name VARCHAR(100) NOT NULL,
            field_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            field_status ENUM('DRAFT','ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'DRAFT',
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_unique TINYINT(1) NOT NULL DEFAULT 0,
            is_searchable TINYINT(1) NOT NULL DEFAULT 0,
            is_sortable TINYINT(1) NOT NULL DEFAULT 0,
            default_value TEXT NULL,
            validation_rules JSON NULL,
            option_source JSON NULL,
            formula_expression TEXT NULL,
            field_settings JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_form_field_code (form_key, field_code),
            UNIQUE KEY uq_builder_form_field_column (form_key, database_column_name),
            INDEX idx_builder_form_field_form (form_key),
            INDEX idx_builder_form_field_type (field_type),
            INDEX idx_builder_form_field_status (field_status),
            INDEX idx_builder_form_field_sort (form_key, field_sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS builder_form_field',
    ],
];
