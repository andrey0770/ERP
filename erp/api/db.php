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
                if (is_callable($sql)) {
                    $sql($pdo);
                } else {
                    $pdo->exec($sql);
                }
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

            // ── v017: Marketplace fields for products ────────
            'v017_marketplace_fields' => "
                ALTER TABLE erp_products
                    ADD COLUMN IF NOT EXISTS ya_market_sku VARCHAR(50) DEFAULT NULL AFTER ozon_sku,
                    ADD COLUMN IF NOT EXISTS ya_offer_id VARCHAR(100) DEFAULT NULL AFTER ya_market_sku,
                    ADD COLUMN IF NOT EXISTS marketplace_source VARCHAR(30) DEFAULT NULL COMMENT 'ozon_kp, ozon_bsh, yandex_market' AFTER ya_offer_id,
                    ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL AFTER name,
                    ADD COLUMN IF NOT EXISTS brand VARCHAR(200) DEFAULT NULL AFTER description,
                    ADD COLUMN IF NOT EXISTS weight DECIMAL(10,3) DEFAULT NULL COMMENT 'Вес в кг' AFTER brand,
                    ADD COLUMN IF NOT EXISTS images JSON DEFAULT NULL COMMENT 'Массив URL картинок' AFTER image_url
            ",

            // ── v018: Supplier field for products ──────────
            'v018_product_supplier' => "
                ALTER TABLE erp_products
                    ADD COLUMN IF NOT EXISTS supplier VARCHAR(200) DEFAULT NULL AFTER brand
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

            // ── v019: Справочник поставщиков ────────────────
            'v019_suppliers' => "
                CREATE TABLE IF NOT EXISTS erp_suppliers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(200) NOT NULL,
                    alias VARCHAR(100) DEFAULT NULL COMMENT 'Сокращённое название',
                    inn VARCHAR(12) DEFAULT NULL,
                    phone VARCHAR(20) DEFAULT NULL,
                    email VARCHAR(100) DEFAULT NULL,
                    website VARCHAR(200) DEFAULT NULL,
                    country VARCHAR(100) DEFAULT NULL,
                    address VARCHAR(300) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_name (name),
                    INDEX idx_alias (alias)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v020: Связь товаров с поставщиками ──────────
            'v020_product_supplier_id' => "
                ALTER TABLE erp_products
                    ADD COLUMN IF NOT EXISTS supplier_id INT DEFAULT NULL AFTER supplier,
                    ADD CONSTRAINT fk_product_supplier FOREIGN KEY (supplier_id) REFERENCES erp_suppliers(id) ON DELETE SET NULL
            ",

            // ── v021: Атрибуты киев ─────────────────────────
            'v021_cue_attributes' => "
                ALTER TABLE erp_products
                    ADD COLUMN IF NOT EXISTS cue_type VARCHAR(30) DEFAULT NULL COMMENT 'Тип кия: пирамида/пул/снукер/укороченный/удлинённый/древко',
                    ADD COLUMN IF NOT EXISTS cue_parts TINYINT DEFAULT NULL COMMENT 'Количество частей: 1, 2+',
                    ADD COLUMN IF NOT EXISTS cue_material VARCHAR(30) DEFAULT NULL COMMENT 'Материал: клён/рамин/композит'
            ",

            // ── v022: Синонимы поставщиков ───────────────────
            'v022_supplier_synonyms' => "
                ALTER TABLE erp_suppliers
                    ADD COLUMN IF NOT EXISTS synonyms TEXT DEFAULT NULL COMMENT 'Синонимы через запятую для поиска и сопоставления'
            ",

            // ── v023: Расширение складов ────────────────────
            'v023_warehouses_extend' => "
                ALTER TABLE erp_warehouses
                    ADD COLUMN IF NOT EXISTS type ENUM('regular','transit') NOT NULL DEFAULT 'regular' COMMENT 'Тип: обычный или транзитный (в пути)',
                    ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL COMMENT 'Родительский склад (для подскладов)',
                    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS notes VARCHAR(500) DEFAULT NULL
            ",
            // ── v024: Краткое название товара ───────────────
            'v024_product_alias' => "
                ALTER TABLE erp_products
                    ADD COLUMN IF NOT EXISTS alias VARCHAR(50) DEFAULT NULL COMMENT 'Краткое название для сборки/поиска',
                    ADD INDEX idx_alias (alias)
            ",
            // ── v025: Объединение поставщиков в контрагенты ──
            'v025_counterparties_merge' => function($pdo) {
                // 1. Расширяем таблицу контрагентов полями из поставщиков
                $pdo->exec("ALTER TABLE erp_counterparties
                    ADD COLUMN IF NOT EXISTS alias VARCHAR(100) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS website VARCHAR(200) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS synonyms TEXT DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS currency CHAR(3) DEFAULT 'RUB'");

                // 2. Переносим поставщиков в контрагенты
                $pdo->exec("INSERT INTO erp_counterparties (name, type, alias, inn, phone, email, website, country, address, notes, synonyms)
                    SELECT name, 'supplier', alias, inn, phone, email, website, country, address, notes, synonyms
                    FROM erp_suppliers WHERE is_active = 1
                    ON DUPLICATE KEY UPDATE
                        type = IF(type = 'customer', 'both', type),
                        alias = VALUES(alias), website = VALUES(website),
                        country = VALUES(country), synonyms = VALUES(synonyms)");

                // 3. Добавляем counterparty_id в товары
                $pdo->exec("ALTER TABLE erp_products
                    ADD COLUMN IF NOT EXISTS counterparty_id INT DEFAULT NULL");

                // 4. Маппим supplier_id → counterparty_id по имени
                $pdo->exec("UPDATE erp_products p
                    JOIN erp_suppliers s ON p.supplier_id = s.id
                    JOIN erp_counterparties c ON c.name = s.name
                    SET p.counterparty_id = c.id
                    WHERE p.supplier_id IS NOT NULL");

                // 5. Добавляем counterparty_id в финансовые транзакции
                $pdo->exec("ALTER TABLE erp_finance_transactions
                    ADD COLUMN IF NOT EXISTS counterparty_id INT DEFAULT NULL");

                // 6. Маппим текстовое поле counterparty → counterparty_id
                $pdo->exec("UPDATE erp_finance_transactions ft
                    JOIN erp_counterparties c ON ft.counterparty = c.name OR ft.counterparty = c.alias
                    SET ft.counterparty_id = c.id
                    WHERE ft.counterparty IS NOT NULL AND ft.counterparty_id IS NULL");

                // 7. Обновляем counterparty_id в поставках
                $pdo->exec("UPDATE erp_supplies s
                    JOIN erp_counterparties c ON s.supplier_name = c.name OR s.supplier_name = c.alias
                    SET s.counterparty_id = c.id
                    WHERE s.counterparty_id IS NULL");
            },

            // ── v026: Заметки к задачам ────────────────────
            'v026_task_notes' => "
                CREATE TABLE IF NOT EXISTS erp_task_notes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    content TEXT NOT NULL,
                    author VARCHAR(100) DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES erp_tasks(id) ON DELETE CASCADE,
                    INDEX idx_task (task_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            // ── v027: Поле balance в контрагентах ──────────
            'v027_counterparty_balance' => function($pdo) {
                $pdo->exec("ALTER TABLE erp_counterparties
                    ADD COLUMN IF NOT EXISTS balance DECIMAL(12,2) NOT NULL DEFAULT 0.00");

                // Пересчитываем балансы из финансовых транзакций
                $pdo->exec("UPDATE erp_counterparties c SET c.balance = (
                    SELECT COALESCE(
                        SUM(CASE WHEN ft.type = 'expense' THEN ft.amount ELSE 0 END) -
                        SUM(CASE WHEN ft.type = 'income' THEN ft.amount ELSE 0 END), 0)
                    FROM erp_finance_transactions ft WHERE ft.counterparty_id = c.id
                )");
            },

            // ── v028: Поле balance (повтор для MySQL совместимости) ──
            'v028_counterparty_balance_fix' => function($pdo) {
                // Проверяем наличие колонки через INFORMATION_SCHEMA
                $check = $pdo->query("
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'erp_counterparties'
                      AND COLUMN_NAME = 'balance'
                ");
                if ((int) $check->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE erp_counterparties ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00");
                }

                // Пересчёт балансов
                $pdo->exec("UPDATE erp_counterparties c
                    JOIN (
                        SELECT ft.counterparty_id,
                               COALESCE(SUM(CASE WHEN ft.type = 'expense' THEN ft.amount ELSE 0 END) -
                                        SUM(CASE WHEN ft.type = 'income' THEN ft.amount ELSE 0 END), 0) as calc_balance
                        FROM erp_finance_transactions ft
                        WHERE ft.counterparty_id IS NOT NULL
                        GROUP BY ft.counterparty_id
                    ) b ON b.counterparty_id = c.id
                    SET c.balance = b.calc_balance");
            },

            // ── v029: KL Logistics контрагент + переименование счетов ──
            'v029_kl_logistics_accounts' => function($pdo) {
                // Создаём KL Logistics как контрагента
                $check = $pdo->query("SELECT id FROM erp_counterparties WHERE name = 'KL Logistics'")->fetchColumn();
                if (!$check) {
                    $pdo->exec("INSERT INTO erp_counterparties (name, type, alias, country, phone, notes, currency)
                        VALUES ('KL Logistics', 'supplier', 'KL', 'Китай', '13533331440',
                                'Логистическая компания в Китае. Наш код: KL157. Тел: 13533331440, 15303645057, 13039943064',
                                'CNY')");
                    // Привязываем CRM контакт KL Logistics к контрагенту
                    $cpId = (int) $pdo->lastInsertId();
                    $pdo->exec("UPDATE erp_contacts SET counterparty_id = {$cpId} WHERE company = 'KL Logistics' AND counterparty_id IS NULL");
                }

                // Переименовываем счета
                $pdo->exec("UPDATE erp_finance_accounts SET name = 'CNY' WHERE name = 'WeChat CNY'");
                $pdo->exec("UPDATE erp_finance_accounts SET name = 'USDT' WHERE name = 'USDT кошелёк'");
            },

            // ── v030: linked_id для группировки связанных транзакций ──
            'v030_linked_transactions' => function($pdo) {
                $exists = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_finance_transactions' AND COLUMN_NAME = 'linked_id'")->fetchColumn();
                if (!$exists) {
                    $pdo->exec("ALTER TABLE erp_finance_transactions ADD COLUMN linked_id INT DEFAULT NULL");
                    $pdo->exec("CREATE INDEX idx_ft_linked ON erp_finance_transactions(linked_id)");
                }
                // Связываем существующие пары переводов
                // tx1 (расход Тинькофф) + tx2 (приход USDT) — покупка крипты
                $pdo->exec("UPDATE erp_finance_transactions SET linked_id = 1 WHERE id IN (1, 2)");
                // tx3 (расход USDT) + tx4 (приход CNY) — обмен крипты на юани
                $pdo->exec("UPDATE erp_finance_transactions SET linked_id = 3 WHERE id IN (3, 4)");
            },

            // ── v031: dest_amount + dest_currency для мультивалютных переводов ──
            'v031_transfer_dest_amount' => function($pdo) {
                $check = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_finance_transactions' AND COLUMN_NAME = 'dest_amount'")->fetchColumn();
                if (!$check) {
                    $pdo->exec("ALTER TABLE erp_finance_transactions ADD COLUMN dest_amount DECIMAL(15,2) DEFAULT NULL");
                    $pdo->exec("ALTER TABLE erp_finance_transactions ADD COLUMN dest_currency CHAR(3) DEFAULT NULL");
                }

                // Конвертируем 5 старых income/expense пар в 3 правильные записи:
                // Было: tx1(expense RUB) tx2(income USD) tx3(expense USD) tx4(income CNY) tx5(expense CNY)
                // Станет: tx1=transfer Тинькофф→USDT, tx3=transfer USDT→CNY, tx5=expense CNY→Condy

                // tx1: expense 122473.48 RUB Тинькофф → transfer Тинькофф→USDT (dest: 1530.92 USD)
                $pdo->exec("UPDATE erp_finance_transactions SET 
                    type='transfer', to_account_id=7, dest_amount=1530.92, dest_currency='USD',
                    counterparty='Слава', description='Обмен RUB→USDT через Славу, курс ~80 руб/USDT'
                    WHERE id = 1");

                // tx2: удаляем (поглощена tx1)
                $pdo->exec("DELETE FROM erp_finance_transactions WHERE id = 2");

                // tx3: expense 1530.92 USD USDT → transfer USDT→CNY (dest: 10502.45 CNY)
                $pdo->exec("UPDATE erp_finance_transactions SET 
                    type='transfer', to_account_id=8, dest_amount=10502.45, dest_currency='CNY',
                    counterparty='Nadex', description='Обмен USDT→CNY через Nadex, курс 1 USDT = 6.8568 CNY'
                    WHERE id = 3");

                // tx4: удаляем (поглощена tx3)
                $pdo->exec("DELETE FROM erp_finance_transactions WHERE id = 4");

                // tx5: оставляем как expense, но описание подправим
                $pdo->exec("UPDATE erp_finance_transactions SET 
                    description='Оплата Condy, заказ 230919RC01 (KL157). 125 киёв x 84 CNY + переплата 2.45 CNY'
                    WHERE id = 5");

                // Убираем linked_id (теперь каждая запись самодостаточна)
                $pdo->exec("UPDATE erp_finance_transactions SET linked_id = NULL WHERE id IN (1, 3, 5)");

                // Связываем всю цепочку: обмен RUB→USDT + обмен USDT→CNY + оплата Condy
                $pdo->exec("UPDATE erp_finance_transactions SET linked_id = 1 WHERE id IN (1, 3, 5)");

                // Пересчитываем балансы счетов с нуля
                $pdo->exec("UPDATE erp_finance_accounts SET balance = 0");
                // Тинькофф: -122473.48 (transfer source)
                $pdo->exec("UPDATE erp_finance_accounts SET balance = -122473.48 WHERE id = 3");
                // USDT: +1530.92 (from tx1) - 1530.92 (from tx3) = 0
                $pdo->exec("UPDATE erp_finance_accounts SET balance = 0 WHERE id = 7");
                // CNY: +10502.45 (from tx3) - 10502.45 (from tx5) = 0
                $pdo->exec("UPDATE erp_finance_accounts SET balance = 0 WHERE id = 8");
            },
        ];
    }
}
