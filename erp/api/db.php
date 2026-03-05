<?php
/**
 * ERP System — Database connection + auto-migration
 * 
 * Подключение к MySQL, создание таблиц при первом запуске.
 * Версионирование через таблицу migrations.
 */

class DB {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            $cfg = require __DIR__ . '/config.php';
            $db = $cfg['db'];
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
            self::$pdo = new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Запустить все непримёненные миграции
     */
    public static function migrate(): array {
        $pdo = self::get();

        // Таблица миграций
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(50) NOT NULL UNIQUE,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $applied = $pdo->query("SELECT version FROM erp_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $results = [];

        foreach (self::getMigrations() as $version => $sql) {
            if (in_array($version, $applied)) continue;
            try {
                $pdo->exec($sql);
                $pdo->prepare("INSERT INTO erp_migrations (version) VALUES (?)")->execute([$version]);
                $results[] = "✓ {$version}";
            } catch (PDOException $e) {
                $results[] = "✗ {$version}: " . $e->getMessage();
                break; // стоп при ошибке
            }
        }

        return $results ?: ['Все миграции применены'];
    }

    /**
     * Словарь миграций: версия => SQL
     */
    private static function getMigrations(): array {
        return [
            // ── v001: Общий журнал операций ─────────────────
            'v001_journal' => "
                CREATE TABLE IF NOT EXISTS erp_journal (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
                    source ENUM('web','telegram','api','import','system') NOT NULL DEFAULT 'web',
                    user_name VARCHAR(100) DEFAULT NULL,
                    raw_text TEXT NOT NULL COMMENT 'Исходный текст записи',
                    ai_parsed JSON DEFAULT NULL COMMENT 'Разбор от AI',
                    category VARCHAR(50) DEFAULT NULL COMMENT 'finance|inventory|task|logistics|note',
                    tags JSON DEFAULT NULL,
                    INDEX idx_created (created_at),
                    INDEX idx_category (category),
                    FULLTEXT idx_raw (raw_text)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v002: Финансовые счета ──────────────────────
            'v002_finance_accounts' => "
                CREATE TABLE IF NOT EXISTS erp_finance_accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    type ENUM('cash','bank','card','crypto','other') NOT NULL DEFAULT 'bank',
                    currency CHAR(3) NOT NULL DEFAULT 'RUB',
                    balance DECIMAL(15,2) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v003: Финансовые транзакции ──────────────────
            'v003_finance_transactions' => "
                CREATE TABLE IF NOT EXISTS erp_finance_transactions (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    journal_id BIGINT DEFAULT NULL,
                    date DATE NOT NULL,
                    type ENUM('income','expense','transfer') NOT NULL,
                    amount DECIMAL(15,2) NOT NULL,
                    currency CHAR(3) NOT NULL DEFAULT 'RUB',
                    account_id INT DEFAULT NULL,
                    to_account_id INT DEFAULT NULL COMMENT 'Для transfer',
                    category VARCHAR(100) DEFAULT NULL,
                    subcategory VARCHAR(100) DEFAULT NULL,
                    counterparty VARCHAR(200) DEFAULT NULL,
                    description TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (journal_id) REFERENCES erp_journal(id) ON DELETE SET NULL,
                    FOREIGN KEY (account_id) REFERENCES erp_finance_accounts(id),
                    FOREIGN KEY (to_account_id) REFERENCES erp_finance_accounts(id),
                    INDEX idx_date (date),
                    INDEX idx_type (type),
                    INDEX idx_category (category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v004: Категории товаров ─────────────────────
            'v004_product_categories' => "
                CREATE TABLE IF NOT EXISTS erp_product_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    parent_id INT DEFAULT NULL,
                    name VARCHAR(200) NOT NULL,
                    slug VARCHAR(100) DEFAULT NULL,
                    FOREIGN KEY (parent_id) REFERENCES erp_product_categories(id) ON DELETE SET NULL,
                    UNIQUE KEY uk_slug (slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v005: Товары ────────────────────────────────
            'v005_products' => "
                CREATE TABLE IF NOT EXISTS erp_products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sku VARCHAR(50) NOT NULL COMMENT 'Артикул',
                    name VARCHAR(300) NOT NULL,
                    barcode VARCHAR(50) DEFAULT NULL,
                    category_id INT DEFAULT NULL,
                    unit ENUM('шт','кг','м','л','уп','компл') NOT NULL DEFAULT 'шт',
                    purchase_price DECIMAL(12,2) DEFAULT NULL COMMENT 'Закупочная цена',
                    sell_price DECIMAL(12,2) DEFAULT NULL COMMENT 'Цена продажи',
                    min_stock INT DEFAULT 0 COMMENT 'Мин. остаток (алерт)',
                    ozon_product_id VARCHAR(50) DEFAULT NULL,
                    ozon_sku VARCHAR(50) DEFAULT NULL,
                    image_url VARCHAR(500) DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_sku (sku),
                    INDEX idx_barcode (barcode),
                    FOREIGN KEY (category_id) REFERENCES erp_product_categories(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v006: Склады ────────────────────────────────
            'v006_warehouses' => "
                CREATE TABLE IF NOT EXISTS erp_warehouses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    address VARCHAR(300) DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    UNIQUE KEY uk_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v007: Складские остатки ─────────────────────
            'v007_inventory' => "
                CREATE TABLE IF NOT EXISTS erp_inventory (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    warehouse_id INT NOT NULL DEFAULT 1,
                    quantity INT NOT NULL DEFAULT 0,
                    reserved INT NOT NULL DEFAULT 0 COMMENT 'Зарезервировано (заказы)',
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_product_wh (product_id, warehouse_id),
                    FOREIGN KEY (product_id) REFERENCES erp_products(id) ON DELETE CASCADE,
                    FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v008: Движение товаров ──────────────────────
            'v008_inventory_movements' => "
                CREATE TABLE IF NOT EXISTS erp_inventory_movements (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    journal_id BIGINT DEFAULT NULL,
                    product_id INT NOT NULL,
                    warehouse_id INT NOT NULL DEFAULT 1,
                    type ENUM('purchase','sale','return','adjustment','transfer','writeoff') NOT NULL,
                    quantity INT NOT NULL COMMENT 'Положительное = приход, отрицательное = расход',
                    unit_price DECIMAL(12,2) DEFAULT NULL,
                    reason VARCHAR(300) DEFAULT NULL,
                    document_ref VARCHAR(100) DEFAULT NULL COMMENT 'Номер накладной/заказа',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (journal_id) REFERENCES erp_journal(id) ON DELETE SET NULL,
                    FOREIGN KEY (product_id) REFERENCES erp_products(id),
                    FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses(id),
                    INDEX idx_product (product_id),
                    INDEX idx_type (type),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v009: Контрагенты (поставщики/покупатели) ───
            'v009_counterparties' => "
                CREATE TABLE IF NOT EXISTS erp_counterparties (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(200) NOT NULL,
                    type ENUM('supplier','customer','both') NOT NULL DEFAULT 'both',
                    inn VARCHAR(12) DEFAULT NULL,
                    phone VARCHAR(20) DEFAULT NULL,
                    email VARCHAR(100) DEFAULT NULL,
                    address VARCHAR(300) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v010: Задачи ────────────────────────────────
            'v010_tasks' => "
                CREATE TABLE IF NOT EXISTS erp_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    journal_id BIGINT DEFAULT NULL,
                    title VARCHAR(300) NOT NULL,
                    description TEXT DEFAULT NULL,
                    status ENUM('todo','in_progress','done','cancelled') NOT NULL DEFAULT 'todo',
                    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                    assignee VARCHAR(100) DEFAULT NULL,
                    due_date DATE DEFAULT NULL,
                    completed_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (journal_id) REFERENCES erp_journal(id) ON DELETE SET NULL,
                    INDEX idx_status (status),
                    INDEX idx_due (due_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v011: Начальные данные ──────────────────────
            'v011_seed_data' => "
                INSERT IGNORE INTO erp_finance_accounts (name, type, currency) VALUES
                    ('Наличные', 'cash', 'RUB'),
                    ('Расчётный счёт', 'bank', 'RUB'),
                    ('Тинькофф', 'card', 'RUB');

                INSERT IGNORE INTO erp_warehouses (name, address) VALUES
                    ('Основной склад', ''),
                    ('Ozon FBS', 'Склад Ozon FBS');
            ",

            // ── v012: CRM — Контакты ────────────────────────
            'v012_crm_contacts' => "
                CREATE TABLE IF NOT EXISTS erp_contacts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    counterparty_id INT DEFAULT NULL,
                    first_name VARCHAR(100) DEFAULT NULL,
                    last_name VARCHAR(100) DEFAULT NULL,
                    company VARCHAR(200) DEFAULT NULL,
                    position VARCHAR(100) DEFAULT NULL COMMENT 'Должность',
                    phone VARCHAR(20) DEFAULT NULL,
                    phone2 VARCHAR(20) DEFAULT NULL,
                    email VARCHAR(100) DEFAULT NULL,
                    telegram VARCHAR(100) DEFAULT NULL,
                    whatsapp VARCHAR(20) DEFAULT NULL,
                    address VARCHAR(300) DEFAULT NULL,
                    source VARCHAR(50) DEFAULT NULL COMMENT 'Откуда: ozon, сайт, звонок, рекомендация...',
                    tags JSON DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (counterparty_id) REFERENCES erp_counterparties(id) ON DELETE SET NULL,
                    INDEX idx_company (company),
                    INDEX idx_phone (phone),
                    INDEX idx_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v013: CRM — Взаимодействия ──────────────────
            'v013_crm_interactions' => "
                CREATE TABLE IF NOT EXISTS erp_interactions (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    journal_id BIGINT DEFAULT NULL,
                    contact_id INT DEFAULT NULL,
                    counterparty_id INT DEFAULT NULL,
                    type ENUM('call','email','meeting','message','note','order','delivery','complaint','other') NOT NULL DEFAULT 'note',
                    direction ENUM('incoming','outgoing','internal') DEFAULT 'outgoing',
                    subject VARCHAR(300) DEFAULT NULL,
                    content TEXT DEFAULT NULL,
                    result VARCHAR(300) DEFAULT NULL COMMENT 'Итог: договорились, отказ, перезвон...',
                    next_action VARCHAR(300) DEFAULT NULL COMMENT 'Следующий шаг',
                    next_action_date DATE DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (journal_id) REFERENCES erp_journal(id) ON DELETE SET NULL,
                    FOREIGN KEY (contact_id) REFERENCES erp_contacts(id) ON DELETE SET NULL,
                    FOREIGN KEY (counterparty_id) REFERENCES erp_counterparties(id) ON DELETE SET NULL,
                    INDEX idx_contact (contact_id),
                    INDEX idx_counterparty (counterparty_id),
                    INDEX idx_type (type),
                    INDEX idx_created (created_at),
                    INDEX idx_next_action (next_action_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v014: Поставки (Supplies) ───────────────────
            'v014_supplies' => "
                CREATE TABLE IF NOT EXISTS erp_supplies (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    number VARCHAR(50) DEFAULT NULL COMMENT 'Номер поставки',
                    supplier_name VARCHAR(200) NOT NULL,
                    counterparty_id INT DEFAULT NULL,
                    status ENUM('draft','ordered','shipped','partial','received','cancelled') NOT NULL DEFAULT 'draft',
                    expected_date DATE DEFAULT NULL,
                    received_at DATETIME DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (counterparty_id) REFERENCES erp_counterparties(id) ON DELETE SET NULL,
                    INDEX idx_status (status),
                    INDEX idx_supplier (supplier_name),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v015: Позиции поставок ──────────────────────
            'v015_supply_items' => "
                CREATE TABLE IF NOT EXISTS erp_supply_items (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    supply_id INT NOT NULL,
                    product_id INT NOT NULL,
                    quantity INT NOT NULL DEFAULT 1,
                    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                    received_quantity INT NOT NULL DEFAULT 0 COMMENT 'Фактически принятое кол-во',
                    FOREIGN KEY (supply_id) REFERENCES erp_supplies(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES erp_products(id),
                    INDEX idx_supply (supply_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v016: Сделки (Deals / Pipeline) ─────────────
            'v016_deals' => "
                CREATE TABLE IF NOT EXISTS erp_deals (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(300) NOT NULL,
                    contact_id INT DEFAULT NULL,
                    counterparty_id INT DEFAULT NULL,
                    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                    stage ENUM('new','qualifying','proposal','negotiation','won','lost') NOT NULL DEFAULT 'new',
                    assignee VARCHAR(100) DEFAULT NULL,
                    description TEXT DEFAULT NULL,
                    lost_reason VARCHAR(300) DEFAULT NULL,
                    won_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (contact_id) REFERENCES erp_contacts(id) ON DELETE SET NULL,
                    FOREIGN KEY (counterparty_id) REFERENCES erp_counterparties(id) ON DELETE SET NULL,
                    INDEX idx_stage (stage),
                    INDEX idx_contact (contact_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
        ];
    }
}
