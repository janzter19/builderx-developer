CREATE TABLE IF NOT EXISTS builder_lookup_table (
    x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lookup_table_key CHAR(36) NOT NULL UNIQUE,
    lookup_code VARCHAR(80) NOT NULL UNIQUE,
    lookup_name VARCHAR(160) NOT NULL,
    lookup_description TEXT NULL,
    lookup_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_key CHAR(36) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_key CHAR(36) NULL,
    INDEX idx_builder_lookup_table_status (lookup_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS builder_lookup_option (
    x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lookup_option_key CHAR(36) NOT NULL UNIQUE,
    lookup_table_key CHAR(36) NOT NULL,
    option_value VARCHAR(160) NOT NULL,
    option_label VARCHAR(160) NOT NULL,
    option_color VARCHAR(40) NULL,
    option_icon VARCHAR(80) NULL,
    parent_option_key CHAR(36) NULL,
    option_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    option_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_key CHAR(36) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_key CHAR(36) NULL,
    UNIQUE KEY uq_builder_lookup_option_value (lookup_table_key, option_value),
    INDEX idx_builder_lookup_option_table (lookup_table_key),
    INDEX idx_builder_lookup_option_parent (parent_option_key),
    INDEX idx_builder_lookup_option_status (option_status),
    INDEX idx_builder_lookup_option_sort (lookup_table_key, option_sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
