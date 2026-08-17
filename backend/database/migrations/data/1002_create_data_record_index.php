<?php
declare(strict_types=1);

return [
    'name' => '1002_create_data_record_index',
    'up' => [
        "CREATE TABLE IF NOT EXISTS data_record_index (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            index_key CHAR(36) NOT NULL UNIQUE,
            record_key CHAR(36) NOT NULL UNIQUE,
            data_record_key CHAR(36) NOT NULL,
            form_key CHAR(36) NOT NULL,
            branch_key CHAR(36) NOT NULL,
            project_key CHAR(36) NOT NULL,
            record_table_name VARCHAR(100) NOT NULL,
            display_value VARCHAR(255) NULL,
            document_number VARCHAR(100) NULL,
            record_status VARCHAR(30) NOT NULL,
            search_text TEXT NULL,
            direct_url VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_index_data_record (data_record_key),
            INDEX idx_index_form_status (form_key, record_status),
            INDEX idx_index_branch_project (branch_key, project_key),
            INDEX idx_index_document_number (document_number),
            INDEX idx_index_updated_at (updated_at),
            FULLTEXT INDEX ft_index_search_text (search_text)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS data_record_index',
    ],
];
