-- Amidhara Stone Crusher - Billing
-- Import once:  mysql -u USER -p DBNAME < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS invoice_challans;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS challan_items;
DROP TABLE IF EXISTS challans;
DROP TABLE IF EXISTS customer_prices;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  role          ENUM('admin','biller','viewer') NOT NULL DEFAULT 'biller',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  k VARCHAR(50) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  address      TEXT,
  gstin        VARCHAR(20),
  state_name   VARCHAR(60) NOT NULL DEFAULT 'Gujarat',
  state_code   VARCHAR(4)  NOT NULL DEFAULT '24',
  phone        VARCHAR(30),
  email        VARCHAR(120),
  opening_bal  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  hsn        VARCHAR(12) NOT NULL,
  unit       VARCHAR(12) NOT NULL DEFAULT 'Ton',
  rate       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gst_rate   DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  is_royalty TINYINT(1) NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-customer price override. Auto-written when a challan is saved
-- with a rate different from the remembered one.
CREATE TABLE customer_prices (
  customer_id INT NOT NULL,
  product_id  INT NOT NULL,
  rate        DECIMAL(12,2) NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, product_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE challans (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  challan_no     VARCHAR(30) NOT NULL UNIQUE,
  seq_no         INT NOT NULL,
  fy             VARCHAR(9) NOT NULL,
  challan_date   DATE NOT NULL,
  customer_id    INT NOT NULL,
  kata_chithi_no VARCHAR(40),
  vehicle_no     VARCHAR(30),
  remarks        VARCHAR(255),
  total_qty      DECIMAL(14,4) NOT NULL DEFAULT 0,
  total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  status         ENUM('open','invoiced','cancelled') NOT NULL DEFAULT 'open',
  invoice_id     INT NULL,
  created_by     INT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  UNIQUE KEY uq_ch_no (challan_no),
  INDEX (customer_id, status), INDEX (challan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE challan_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  challan_id   INT NOT NULL,
  product_id   INT NOT NULL,
  product_name VARCHAR(120) NOT NULL,
  hsn          VARCHAR(12) NOT NULL,
  unit         VARCHAR(12) NOT NULL,
  qty          DECIMAL(14,4) NOT NULL,
  rate         DECIMAL(12,2) NOT NULL,
  amount       DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (challan_id) REFERENCES challans(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoices (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no     VARCHAR(40) NOT NULL,
  bill_type      ENUM('gst','nongst') NOT NULL DEFAULT 'gst',
  seq_no         INT NOT NULL,
  fy             VARCHAR(9) NOT NULL,
  invoice_date   DATE NOT NULL,
  customer_id    INT NOT NULL,
  -- buyer snapshot (invoice must never change if master is edited later)
  buyer_name     VARCHAR(150) NOT NULL,
  buyer_address  TEXT,
  buyer_gstin    VARCHAR(20),
  buyer_state    VARCHAR(60),
  buyer_code     VARCHAR(4),
  place_supply   VARCHAR(60),
  delivery_note  VARCHAR(60),
  payment_terms  VARCHAR(60),
  ref_no_date    VARCHAR(60),
  other_ref      VARCHAR(60),
  buyer_order_no VARCHAR(60),
  buyer_order_dt VARCHAR(60),
  dispatch_doc   VARCHAR(60),
  delivery_dt    VARCHAR(60),
  dispatched_via VARCHAR(60),
  destination    VARCHAR(60),
  delivery_terms VARCHAR(120),
  taxable_value  DECIMAL(14,2) NOT NULL DEFAULT 0,
  cgst_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0,
  sgst_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0,
  igst_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0,
  cgst_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  sgst_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  igst_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_qty      DECIMAL(14,4) NOT NULL DEFAULT 0,
  grand_total    DECIMAL(14,2) NOT NULL DEFAULT 0,
  status         ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_by     INT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  UNIQUE KEY uq_series (bill_type, fy, seq_no),
  INDEX (customer_id), INDEX (invoice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoice_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id   INT NOT NULL,
  product_id   INT NULL,
  product_name VARCHAR(120) NOT NULL,
  hsn          VARCHAR(12) NOT NULL,
  unit         VARCHAR(12) NOT NULL,
  qty          DECIMAL(14,4) NOT NULL,
  rate         DECIMAL(12,2) NOT NULL,
  amount       DECIMAL(14,2) NOT NULL,
  line_no      INT NOT NULL DEFAULT 0,
  challan_no   VARCHAR(30) NULL,
  challan_date DATE NULL,
  kata_chithi  VARCHAR(40) NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoice_challans (
  invoice_id INT NOT NULL,
  challan_id INT NOT NULL,
  PRIMARY KEY (invoice_id, challan_id),
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (challan_id) REFERENCES challans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT NOT NULL,
  pay_date     DATE NOT NULL,
  mode         ENUM('cash','bank') NOT NULL DEFAULT 'bank',
  amount       DECIMAL(14,2) NOT NULL,
  ref_no       VARCHAR(60),
  note         VARCHAR(255),
  created_by   INT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX (customer_id, pay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- seed ----------
-- default login: admin / admin123   (change immediately)
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin', '$2y$10$Ys5sV06jkUgkO/U0D9tdSOCUZtfwhZtKfni0k7m7mJTc9d.5I1ijO', 'Administrator', 'admin');

-- Placeholder company identity. Fill these in from Settings after install.
-- Deliberately generic so this schema can start any client's install.
INSERT INTO settings (k,v) VALUES
('company_name','Your Company Name'),
('company_address','Address line 1,\nAddress line 2,\nCity - PIN'),
('company_gstin',''),
('company_state','Gujarat'),
('company_state_code','24'),
('bank_holder',''),
('bank_name',''),
('bank_ac',''),
('bank_ifsc',''),
('bank_swift',''),
('declaration','We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.'),
('gst_prefix','INV/'),
('gst_suffix',''),
('nongst_prefix','INV/C/'),
('nongst_suffix',''),
('challan_prefix','CH/'),
('seq_pad','2'),
('royalty_default','1');

-- The Royalty product is functional infrastructure for GST bills, not sample data:
-- on a GST invoice it auto-adds as the last line at the total material weight.
-- Edit its rate/HSN under Products if needed, but keep the "Royalty" flag on.
INSERT INTO products (name, hsn, unit, rate, gst_rate, is_royalty, sort_order) VALUES
('Royalty','997337','Ton',96.00,5.00,1,99);

-- No material products or customers are seeded. Add your real product list
-- via Products → Add product, and customers via Customers → Add customer.
