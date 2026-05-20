CREATE TABLE IF NOT EXISTS document_pdf_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  version_number INT NOT NULL,
  pdf_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_size BIGINT NULL,
  checksum_sha256 CHAR(64) NULL,
  generated_by INT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_pdf_versions_number (document_id, version_number),
  KEY idx_document_pdf_versions_document (document_id),
  KEY idx_document_pdf_versions_current (document_id, is_current),
  KEY idx_document_pdf_versions_generated_by (generated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
