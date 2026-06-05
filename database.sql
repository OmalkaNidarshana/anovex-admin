-- ============================================================
--  Anovx Technology — Admin Dashboard Database Schema
--  Engine: MySQL 8.0+
--  Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS anovx_admin
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE anovx_admin;

-- ─────────────────────────────────────────
--  USERS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120)  NOT NULL,
  email        VARCHAR(180)  NOT NULL UNIQUE,
  password     VARCHAR(255)  NOT NULL,          -- bcrypt hash
  role         ENUM('admin','manager','staff') NOT NULL DEFAULT 'staff',
  avatar       VARCHAR(255)  DEFAULT NULL,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  last_login   DATETIME      DEFAULT NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin user  (password: admin123)
INSERT INTO users (name, email, password, role) VALUES
  ('Alex Torres', 'admin@anovx.com',
   '$2y$12$yPEkDeV5Kx.p6OQrN3XAaOhEEFp4JX/W3KBz5wH/Ul9pSmZ0fSiJe',
   'admin');

-- ─────────────────────────────────────────
--  CLIENTS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clients (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150)  NOT NULL,
  email        VARCHAR(180)  DEFAULT NULL,
  phone        VARCHAR(30)   DEFAULT NULL,
  address      TEXT          DEFAULT NULL,
  country      VARCHAR(80)   DEFAULT NULL,
  created_by   INT UNSIGNED  DEFAULT NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO clients (name, email) VALUES
  ('Nexus Media Group',  'contact@nexusmedia.com'),
  ('Vertex Labs',        'hello@vertexlabs.io'),
  ('Orion Retail',       'ops@orionretail.com'),
  ('Stellar Finance',    'team@stellarfin.com'),
  ('Apex Solutions',     'info@apexsol.net');

-- ─────────────────────────────────────────
--  PROPOSALS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS proposals (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref_number   VARCHAR(20)   NOT NULL UNIQUE,   -- e.g. PRO-001
  client_id    INT UNSIGNED  NOT NULL,
  title        VARCHAR(200)  NOT NULL,
  description  TEXT          DEFAULT NULL,
  value        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status       ENUM('Pending','Under Review','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  deadline     DATE          DEFAULT NULL,
  progress     TINYINT       NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100),
  created_by   INT UNSIGNED  DEFAULT NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO proposals (ref_number, client_id, title, value, status, deadline, progress, created_by) VALUES
  ('PRO-001', 1, 'Brand Identity Redesign',      18500.00, 'Approved',     '2025-07-30', 65, 1),
  ('PRO-002', 2, 'Cloud Infrastructure Setup',   32000.00, 'Under Review', '2025-08-15', 30, 1),
  ('PRO-003', 3, 'E-commerce Platform Dev',       54000.00, 'Pending',      '2025-09-10', 10, 1),
  ('PRO-004', 4, 'Dashboard Analytics App',       27000.00, 'Approved',     '2025-07-01', 80, 1),
  ('PRO-005', 5, 'Mobile App MVP',                21000.00, 'Rejected',     '2025-06-01',  0, 1);

-- ─────────────────────────────────────────
--  INVOICES
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoices (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(30)   NOT NULL UNIQUE,  -- e.g. INV-2025-041
  client_id      INT UNSIGNED  NOT NULL,
  proposal_id    INT UNSIGNED  DEFAULT NULL,
  subtotal       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_rate       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  tax_amount     DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(subtotal * tax_rate / 100, 2)) STORED,
  total          DECIMAL(12,2) GENERATED ALWAYS AS (subtotal + ROUND(subtotal * tax_rate / 100, 2)) STORED,
  status         ENUM('Draft','Sent','Paid','Overdue','Cancelled') NOT NULL DEFAULT 'Draft',
  issue_date     DATE          NOT NULL,
  due_date       DATE          NOT NULL,
  notes          TEXT          DEFAULT NULL,
  paid_at        DATETIME      DEFAULT NULL,
  created_by     INT UNSIGNED  DEFAULT NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id)   REFERENCES clients(id)   ON DELETE RESTRICT,
  FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────
--  INVOICE LINE ITEMS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoice_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id   INT UNSIGNED  NOT NULL,
  description  VARCHAR(255)  NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount       DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
  sort_order   TINYINT       NOT NULL DEFAULT 0,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO invoices (invoice_number, client_id, subtotal, tax_rate, status, issue_date, due_date, created_by) VALUES
  ('INV-2025-041', 1,  9250.00, 0, 'Paid',    '2025-05-20', '2025-06-20', 1),
  ('INV-2025-042', 4, 13500.00, 0, 'Sent',    '2025-06-01', '2025-07-01', 1),
  ('INV-2025-043', 2,  8000.00, 0, 'Overdue', '2025-04-10', '2025-05-10', 1),
  ('INV-2025-044', 3,  5400.00, 0, 'Draft',   '2025-06-05', '2025-07-05', 1);

INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, sort_order) VALUES
  (1, 'Brand Identity — Phase 1',       1, 9250.00,  1),
  (2, 'Dashboard Analytics — Milestone 1', 1, 13500.00, 1),
  (3, 'Cloud Infrastructure Setup',     1, 8000.00,  1),
  (4, 'E-commerce Platform — Deposit',  1, 5400.00,  1);

-- ─────────────────────────────────────────
--  SESSIONS (server-side token store)
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
  id         VARCHAR(64)  PRIMARY KEY,           -- SHA-256 token
  user_id    INT UNSIGNED NOT NULL,
  ip_address VARCHAR(45)  DEFAULT NULL,
  user_agent TEXT         DEFAULT NULL,
  expires_at DATETIME     NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Auto-clean expired sessions
CREATE EVENT IF NOT EXISTS purge_expired_sessions
  ON SCHEDULE EVERY 1 HOUR
  DO DELETE FROM sessions WHERE expires_at < NOW();

-- ─────────────────────────────────────────
--  USEFUL VIEWS
-- ─────────────────────────────────────────
CREATE OR REPLACE VIEW v_proposals AS
  SELECT
    p.id, p.ref_number, p.title, p.value, p.status,
    p.deadline, p.progress, p.created_at,
    c.name  AS client_name,
    c.email AS client_email,
    u.name  AS created_by_name
  FROM proposals p
  JOIN clients c ON c.id = p.client_id
  LEFT JOIN users u ON u.id = p.created_by;

CREATE OR REPLACE VIEW v_invoices AS
  SELECT
    i.id, i.invoice_number, i.subtotal, i.tax_rate,
    i.tax_amount, i.total, i.status,
    i.issue_date, i.due_date, i.paid_at, i.notes, i.created_at,
    c.name  AS client_name,
    c.email AS client_email,
    p.ref_number AS proposal_ref,
    u.name  AS created_by_name
  FROM invoices i
  JOIN clients c ON c.id = i.client_id
  LEFT JOIN proposals p ON p.id = i.proposal_id
  LEFT JOIN users u ON u.id = i.created_by;
