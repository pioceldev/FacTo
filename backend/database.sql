CREATE DATABASE IF NOT EXISTS facto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE facto;

-- ============================================================
-- UTILISATEURS & AUTHENTIFICATION
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  company VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'user',           -- 'user' | 'admin'
  logo_path VARCHAR(255) DEFAULT NULL,
  signature LONGTEXT DEFAULT NULL,                    -- image PNG (data URL) signée par l'émetteur
  email_verified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ABONNEMENTS & PAIEMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,                  -- 'Free' | 'Standard' | 'Premium'
  price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  price_yearly DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  description VARCHAR(255) DEFAULT NULL,
  features TEXT DEFAULT NULL,                         -- liste de fonctionnalités (JSON ou texte)
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  plan_id INT DEFAULT NULL,
  plan_name VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) DEFAULT NULL,                   -- montant mensuel facturé
  payment_method VARCHAR(50) DEFAULT NULL,            -- ex: 'carte', 'paypal', 'prelevement'
  status VARCHAR(50) NOT NULL DEFAULT 'active',       -- 'active' | 'pending' | 'cancelled' | 'past_due' | 'trialing'
  next_billing_date DATE DEFAULT NULL,
  renews_at DATE DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  cancelled_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  subscription_id INT DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(50) DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',      -- 'pending' | 'paid' | 'failed' | 'refunded'
  transaction_ref VARCHAR(100) DEFAULT NULL,          -- id transaction FedaPay
  meta VARCHAR(255) DEFAULT NULL,                     -- JSON: plan_id, cycle (en attente de confirmation)
  paid_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CLIENTS, PRODUITS, FOURNISSEURS, STOCK
-- ============================================================

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  company VARCHAR(150) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  zip VARCHAR(20) DEFAULT NULL,
  country VARCHAR(100) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  sku VARCHAR(100) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,        -- taux de TVA ex: 20.00
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(150) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,               -- seuil d'alerte réapprovisionnement
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FACTURES & DEVIS
-- ============================================================

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  client_id INT DEFAULT NULL,
  ref VARCHAR(100) NOT NULL,                          -- ex: FAC-2026-001
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,         -- total HT
  vat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- total TTC
  status VARCHAR(50) NOT NULL DEFAULT 'brouillon',    -- 'brouillon' | 'en_attente' | 'payee' | 'en_retard' | 'annulee'
  due_date DATE NOT NULL,
  issued_at DATETIME DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  product_id INT DEFAULT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,     -- total HT de la ligne
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  client_id INT DEFAULT NULL,
  ref VARCHAR(100) NOT NULL,                          -- ex: DEV-2026-001
  status VARCHAR(50) NOT NULL DEFAULT 'brouillon',    -- 'brouillon' | 'envoye' | 'accepte' | 'refuse' | 'expire'
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,         -- total HT
  vat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- total TTC
  valid_until DATE DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quote_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  product_id INT DEFAULT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DÉPENSES & PAIEMENTS REÇUS
-- ============================================================

CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category VARCHAR(100) DEFAULT NULL,                 -- ex: 'fournitures', 'deplacements', 'logiciels'
  description VARCHAR(255) DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  expense_date DATE NOT NULL,
  receipt_path VARCHAR(255) DEFAULT NULL,             -- scan/photo de la note de frais
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  invoice_id INT DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(50) DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'paid',         -- 'paid' | 'pending' | 'failed' | 'refunded'
  transaction_ref VARCHAR(100) DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  type VARCHAR(50) DEFAULT NULL,                      -- ex: 'paiement', 'stock', 'abonnement', 'systeme'
  link VARCHAR(255) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SUPPORT CLIENT
-- ============================================================

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  subject VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  email VARCHAR(255) NOT NULL,
  category VARCHAR(50) DEFAULT NULL,                  -- ex: 'facturation', 'bug', 'compte'
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',     -- 'low' | 'normal' | 'high'
  status VARCHAR(50) NOT NULL DEFAULT 'open',         -- 'open' | 'in_progress' | 'closed'
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT DEFAULT NULL,                           -- NULL si réponse admin hors session
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- JOURNAL SYSTÈME (administration)
-- ============================================================

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'info',          -- 'info' | 'warning' | 'error'
  message VARCHAR(255) NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DONNÉES INITIALES
-- ============================================================

INSERT INTO plans (name, price_monthly, price_yearly, description, features, is_active, sort_order, created_at) VALUES
('Free', 0.00, 0.00, 'Pour découvrir FacTo', '1 utilisateur;10 factures/mois;Support par e-mail', 1, 1, NOW()),
('Standard', 19.00, 190.00, 'L''essentiel pour gérer votre activité au quotidien', '3 utilisateurs;100 factures/mois;Devis & factures;Support prioritaire', 1, 2, NOW()),
('Premium', 49.00, 490.00, 'La solution complète pour les entreprises exigeantes', 'Utilisateurs illimités;Factures illimitées;Gestion de stock;Rapports avancés;Support dédié', 1, 3, NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Optionnel : données d'exemple (à décommenter après création d'un utilisateur via l'API)
-- INSERT INTO users (name, email, password_hash, company, phone, role, created_at)
-- VALUES ('Admin', 'admin@facto.fr', '<php_password_hash>', 'FacTo', '0123456789', 'admin', NOW());
-- INSERT INTO invoices (user_id, client_id, ref, amount, status, due_date, created_at)
-- VALUES (1, NULL, 'FAC-2026-001', 1200.00, 'payee', '2026-02-15', NOW());
-- INSERT INTO notifications (user_id, title, body, type, is_read, created_at)
-- VALUES (1, 'Nouveau client', 'Vous avez un nouveau client inscrit aujourd''hui.', 'systeme', 0, NOW());
-- INSERT INTO subscriptions (user_id, plan_id, plan_name, price, payment_method, status, next_billing_date, renews_at, created_at)
-- VALUES (1, 2, 'Standard', 19.00, 'carte', 'active', '2026-04-10', '2026-04-10', NOW());
