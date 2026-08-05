-- ============================================================
-- Sugar Demand Prediction System - Mbeya Markets
-- Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS sugar_demand_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sugar_demand_db;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'trader', 'supplier') DEFAULT 'trader',
    avatar VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    reset_token VARCHAR(255) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- SUGAR SALES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS sugar_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date DATE NOT NULL,
    quantity_kg DECIMAL(10,2) NOT NULL,
    price_per_kg DECIMAL(10,2) NOT NULL,
    total_revenue DECIMAL(12,2) GENERATED ALWAYS AS (quantity_kg * price_per_kg) STORED,
    market_location VARCHAR(100) DEFAULT 'Mbeya Central Market',
    sugar_type ENUM('white_refined', 'brown', 'raw') DEFAULT 'brown',
    supplier_name VARCHAR(100),
    season ENUM('dry', 'wet', 'harvest', 'planting') DEFAULT 'dry',
    is_holiday TINYINT(1) DEFAULT 0,
    notes TEXT,
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sale_date (sale_date),
    INDEX idx_season (season)
);

-- ============================================================
-- PREDICTIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS predictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prediction_date DATE NOT NULL,
    target_date DATE NOT NULL,
    previous_sales DECIMAL(10,2) NOT NULL,
    input_price DECIMAL(10,2) NOT NULL,
    input_season ENUM('dry', 'wet', 'harvest', 'planting') NOT NULL,
    is_holiday TINYINT(1) DEFAULT 0,
    predicted_demand DECIMAL(10,2) NOT NULL,
    confidence_level DECIMAL(5,2) NOT NULL COMMENT 'Percentage 0-100',
    model_used VARCHAR(50) DEFAULT 'linear_regression',
    lower_bound DECIMAL(10,2),
    upper_bound DECIMAL(10,2),
    actual_demand DECIMAL(10,2) DEFAULT NULL COMMENT 'Filled after actual data arrives',
    accuracy_score DECIMAL(5,2) DEFAULT NULL,
    predicted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (predicted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_target_date (target_date),
    INDEX idx_prediction_date (prediction_date)
);

-- ============================================================
-- RECOMMENDATIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prediction_id INT NOT NULL,
    recommended_stock DECIMAL(10,2) NOT NULL,
    safety_buffer_pct DECIMAL(5,2) DEFAULT 15.00,
    risk_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    shortage_alert TINYINT(1) DEFAULT 0,
    overstock_warning TINYINT(1) DEFAULT 0,
    current_stock DECIMAL(10,2),
    stock_gap DECIMAL(10,2) GENERATED ALWAYS AS (recommended_stock - current_stock) STORED,
    action_required VARCHAR(255),
    valid_from DATE NOT NULL,
    valid_until DATE NOT NULL,
    is_acknowledged TINYINT(1) DEFAULT 0,
    acknowledged_by INT DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prediction_id) REFERENCES predictions(id) ON DELETE CASCADE,
    FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- REPORTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(150) NOT NULL,
    report_type ENUM('monthly', 'yearly', 'custom', 'demand_comparison') DEFAULT 'monthly',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_sales DECIMAL(12,2),
    total_revenue DECIMAL(14,2),
    avg_price DECIMAL(10,2),
    peak_demand_date DATE,
    peak_demand_qty DECIMAL(10,2),
    low_demand_date DATE,
    low_demand_qty DECIMAL(10,2),
    avg_prediction_accuracy DECIMAL(5,2),
    file_path VARCHAR(255) DEFAULT NULL,
    generated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- NOTIFICATIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Default admin user (password: admin123)
INSERT INTO users (username, email, password, full_name, role) VALUES
('admin', 'admin@mbeya-sugar.co.tz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin'),
('trader1', 'trader1@mbeya-sugar.co.tz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Mwenda', 'trader'),
('supplier1', 'supplier1@mbeya-sugar.co.tz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Fatuma Mlowezi', 'supplier');

-- Sample sugar sales data (last 12 months)
INSERT INTO sugar_sales (sale_date, quantity_kg, price_per_kg, market_location, sugar_type, supplier_name, season, is_holiday, recorded_by) VALUES
('2025-06-01', 4500.00, 1.85, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'dry', 0, 1),
('2025-06-08', 4200.00, 1.85, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'dry', 0, 1),
('2025-06-15', 5100.00, 1.80, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'dry', 0, 1),
('2025-07-01', 4800.00, 1.80, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'dry', 0, 1),
('2025-07-15', 5300.00, 1.75, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'dry', 0, 1),
('2025-08-01', 5600.00, 1.75, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'harvest', 0, 1),
('2025-08-15', 6200.00, 1.70, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'harvest', 0, 1),
('2025-09-01', 6800.00, 1.70, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'harvest', 0, 1),
('2025-09-15', 7200.00, 1.65, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'harvest', 1, 1),
('2025-10-01', 7500.00, 1.65, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'harvest', 0, 1),
('2025-10-15', 6900.00, 1.70, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'harvest', 0, 1),
('2025-11-01', 6400.00, 1.75, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'wet', 0, 1),
('2025-11-15', 5800.00, 1.80, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'wet', 0, 1),
('2025-12-01', 7800.00, 1.80, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'wet', 1, 1),
('2025-12-15', 8500.00, 1.85, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'wet', 1, 1),
('2026-01-01', 7200.00, 1.85, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'wet', 1, 1),
('2026-01-15', 6500.00, 1.90, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'wet', 0, 1),
('2026-02-01', 5900.00, 1.90, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'wet', 0, 1),
('2026-02-15', 5400.00, 1.95, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'wet', 0, 1),
('2026-03-01', 5100.00, 1.95, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'planting', 0, 1),
('2026-03-15', 4800.00, 2.00, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'planting', 0, 1),
('2026-04-01', 4600.00, 2.00, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'planting', 0, 1),
('2026-04-15', 4400.00, 2.05, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'planting', 1, 1),
('2026-05-01', 4900.00, 2.05, 'Mbeya Central Market', 'white_refined', 'TanzaniaSweet Ltd', 'dry', 0, 1),
('2026-05-15', 5200.00, 2.00, 'Mbeya Central Market', 'white_refined', 'Kilombero Sugar', 'dry', 0, 1);

-- Sample predictions
INSERT INTO predictions (prediction_date, target_date, previous_sales, input_price, input_season, is_holiday, predicted_demand, confidence_level, model_used, lower_bound, upper_bound, predicted_by) VALUES
('2026-05-01', '2026-06-01', 4900.00, 2.00, 'dry', 0, 5350.00, 87.50, 'linear_regression', 4815.00, 5885.00, 1),
('2026-05-05', '2026-06-15', 5200.00, 2.00, 'dry', 0, 5600.00, 85.20, 'linear_regression', 5040.00, 6160.00, 2),
('2026-05-10', '2026-07-01', 5200.00, 1.95, 'dry', 0, 6100.00, 82.10, 'linear_regression', 5490.00, 6710.00, 1);

-- Sample recommendations
INSERT INTO recommendations (prediction_id, recommended_stock, safety_buffer_pct, risk_level, shortage_alert, overstock_warning, current_stock, action_required, valid_from, valid_until) VALUES
(1, 6152.50, 15.00, 'low', 0, 0, 7000.00, 'Maintain current stock levels', '2026-05-01', '2026-06-01'),
(2, 6440.00, 15.00, 'medium', 0, 0, 6200.00, 'Consider ordering 240 kg additional stock', '2026-05-05', '2026-06-15'),
(3, 7015.00, 15.00, 'high', 1, 0, 5500.00, 'URGENT: Order at least 1515 kg immediately', '2026-05-10', '2026-07-01');
