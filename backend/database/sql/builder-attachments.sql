CREATE TABLE IF NOT EXISTS builder_attachment (
    x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attachment_key CHAR(36) NOT NULL UNIQUE,
    data_record_key CHAR(36) NOT NULL,
    record_key CHAR(36) NOT NULL,
    form_key CHAR(36) NOT NULL,
    branch_key CHAR(36) NOT NULL,
    project_key CHAR(36) NOT NULL,
    field_key CHAR(36) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(160) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum_sha256 CHAR(64) NULL,
    attachment_status ENUM('ACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_key CHAR(36) NULL,
    deleted_at TIMESTAMP NULL,
    deleted_by_key CHAR(36) NULL,
    INDEX idx_attachment_record (data_record_key, record_key),
    INDEX idx_attachment_form (form_key),
    INDEX idx_attachment_status (attachment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS builder_attachment_download (
    x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    download_key CHAR(36) NOT NULL UNIQUE,
    attachment_key CHAR(36) NOT NULL,
    downloaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    downloaded_by_key CHAR(36) NULL,
    INDEX idx_attachment_download_attachment (attachment_key),
    INDEX idx_attachment_download_user (downloaded_by_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
