CREATE TABLE IF NOT EXISTS builder_record_grid_view (
    x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_key CHAR(36) NOT NULL UNIQUE,
    form_key CHAR(36) NOT NULL,
    view_name VARCHAR(160) NOT NULL,
    view_schema JSON NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    view_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_key CHAR(36) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_key CHAR(36) NULL,
    UNIQUE KEY uq_builder_record_grid_view_name (form_key, view_name),
    INDEX idx_builder_record_grid_view_form (form_key),
    INDEX idx_builder_record_grid_view_default (form_key, is_default),
    INDEX idx_builder_record_grid_view_status (view_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
