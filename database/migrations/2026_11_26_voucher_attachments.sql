-- ============================================================================
-- IMS: payment voucher supporting files (/payment-voucher)
-- ============================================================================
-- Invoices, receipts, quotations etc. attached to a voucher so the approver
-- (Senior Finance / HOD) can preview them before signing. Files live in
-- legacy/uploads/voucher_attachments/<voucher id>/ under random names and are
-- served only by legacy/voucher-attachment.php (vouchers.view or the
-- voucher's assigned approver).
-- legacy/includes/voucher-attachments.php creates this table on first use too.
-- Idempotent: safe to run more than once.
-- ============================================================================

CREATE TABLE IF NOT EXISTS payment_voucher_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  voucher_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(120) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pva_voucher (voucher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
