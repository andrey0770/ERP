<?php
/**
 * ERP Module: Товары и Складские остатки
 * 
 * products.list       — каталог товаров
 * products.get        — один товар (с остатками)
 * products.create     — добавить товар
 * products.update     — редактировать товар
 * products.delete     — удалить (soft: is_active=0)
 * products.categories — дерево категорий
 * products.import     — массовый импорт (CSV/JSON)
 * products.low_stock  — товары с остатком ниже минимума
 */
class ERP_Products {

    public function list(): array {
        $pdo = DB::get();
        $where = ['p.is_active = 1'];
        $params = [];

        if ($cat = param('category_id')) {
            // Collect this category + all descendants
            $catId = (int) $cat;
            $allCats = $pdo->query("SELECT id, parent_id FROM erp_product_categories")->fetchAll();
            $catMap = [];
            foreach ($allCats as $c) $catMap[$c['id']] = $c['parent_id'];
            $ids = [$catId];
            $queue = [$catId];
            while ($queue) {
                $pid = array_shift($queue);
                foreach ($catMap as $cid => $par) {
                    if ($par == $pid && !in_array($cid, $ids)) {
                        $ids[] = $cid;
                        $queue[] = $cid;
                    }
                }
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "p.category_id IN ({$placeholders})";
            $params = array_merge($params, $ids);
        }
        if ($q = param('q')) {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.alias LIKE ?)';
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        if ($fsku = param('filter_sku')) {
            $where[] = 'p.sku LIKE ?';
            $params[] = "%{$fsku}%";
        }
        if ($fname = param('filter_name')) {
            $where[] = 'p.name LIKE ?';
            $params[] = "%{$fname}%";
        }
        if (param('has_image')) {
            $where[] = "p.image_url IS NOT NULL AND p.image_url != ''";
        }
        if (param('no_image')) {
            $where[] = "(p.image_url IS NULL OR p.image_url = '')";
        }
        if (param('in_stock')) {
            $where[] = 'COALESCE(i.quantity, 0) > 0';
        }
        if (param('zero_stock')) {
            $where[] = 'COALESCE(i.quantity, 0) = 0';
        }
        if ($brand = param('brand')) {
            $brands = explode(',', $brand);
            $placeholders = implode(',', array_fill(0, count($brands), '?'));
            $where[] = "p.brand IN ({$placeholders})";
            $params = array_merge($params, $brands);
        }
        if ($source = param('marketplace_source')) {
            $sources = explode(',', $source);
            $placeholders = implode(',', array_fill(0, count($sources), '?'));
            $where[] = "p.marketplace_source IN ({$placeholders})";
            $params = array_merge($params, $sources);
        }
        if ($supplier = param('supplier')) {
            $suppliers = explode(',', $supplier);
            $placeholders = implode(',', array_fill(0, count($suppliers), '?'));
            $where[] = "p.supplier IN ({$placeholders})";
            $params = array_merge($params, $suppliers);
        }
        if ($cueType = param('cue_type')) {
            $types = explode(',', $cueType);
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $where[] = "p.cue_type IN ({$placeholders})";
            $params = array_merge($params, $types);
        }
        if ($cueParts = param('cue_parts')) {
            $parts = explode(',', $cueParts);
            $placeholders = implode(',', array_fill(0, count($parts), '?'));
            $where[] = "p.cue_parts IN ({$placeholders})";
            $params = array_merge($params, array_map('intval', $parts));
        }
        if ($cueMat = param('cue_material')) {
            $mats = explode(',', $cueMat);
            $placeholders = implode(',', array_fill(0, count($mats), '?'));
            $where[] = "p.cue_material IN ({$placeholders})";
            $params = array_merge($params, $mats);
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 100)), 1000);
        $offset = max((int)(param('offset', 0)), 0);

        // Sort
        $sortMap = [
            'name' => 'p.name ASC',
            'sku' => 'p.sku ASC',
            'price_asc' => 'COALESCE(p.sell_price, 999999999) ASC',
            'price_desc' => 'p.sell_price DESC',
            'newest' => 'p.id DESC',
            'stock_desc' => 'COALESCE(i.quantity, 0) DESC',
            'stock_asc' => 'COALESCE(i.quantity, 0) ASC',
            'brand' => 'COALESCE(p.brand, "яяя") ASC, p.name ASC',
            'supplier' => 'COALESCE(p.supplier, "яяя") ASC, p.name ASC',
            'alias' => 'COALESCE(p.alias, "яяя") ASC, p.name ASC',
        ];
        $sort = $sortMap[param('sort', 'name')] ?? 'p.name ASC';

        $total = $pdo->prepare("
            SELECT COUNT(*) FROM erp_products p 
            LEFT JOIN erp_inventory i ON i.product_id = p.id AND i.warehouse_id = 1
            WHERE {$whereSQL}
        ");
        $total->execute($params);

        $stmt = $pdo->prepare("
            SELECT p.*, 
                   pc.name as category_name,
                   COALESCE(i.quantity, 0) as stock,
                   COALESCE(i.reserved, 0) as reserved,
                   sup.alias as supplier_alias,
                   cp.alias as counterparty_alias,
                   cp.name as counterparty_name
            FROM erp_products p
            LEFT JOIN erp_product_categories pc ON pc.id = p.category_id
            LEFT JOIN erp_inventory i ON i.product_id = p.id AND i.warehouse_id = 1
            LEFT JOIN erp_suppliers sup ON sup.id = p.supplier_id
            LEFT JOIN erp_counterparties cp ON cp.id = p.counterparty_id
            WHERE {$whereSQL}
            ORDER BY {$sort}
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        return [
            'items'  => $stmt->fetchAll(),
            'total'  => (int) $total->fetchColumn(),
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    public function get(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            SELECT p.*, pc.name as category_name
            FROM erp_products p
            LEFT JOIN erp_product_categories pc ON pc.id = p.category_id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) errorResponse('Not found', 404);

        // Остатки по складам
        $inv = $pdo->prepare("
            SELECT i.*, w.name as warehouse_name
            FROM erp_inventory i
            JOIN erp_warehouses w ON w.id = i.warehouse_id
            WHERE i.product_id = ?
        ");
        $inv->execute([$id]);
        $product['inventory'] = $inv->fetchAll();

        // Последние движения
        $mov = $pdo->prepare("
            SELECT im.*, w.name as warehouse_name
            FROM erp_inventory_movements im
            JOIN erp_warehouses w ON w.id = im.warehouse_id
            WHERE im.product_id = ?
            ORDER BY im.created_at DESC LIMIT 20
        ");
        $mov->execute([$id]);
        $product['movements'] = $mov->fetchAll();

        return $product;
    }

    public function create(): array {
        $input = jsonInput();
        $sku  = trim($input['sku'] ?? '');
        $name = trim($input['name'] ?? '');
        if (!$sku || !$name) errorResponse('sku and name required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_products (sku, name, alias, barcode, category_id, unit, purchase_price, sell_price, min_stock, ozon_product_id, ozon_sku, image_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sku,
            $name,
            trim($input['alias'] ?? '') ?: null,
            $input['barcode'] ?? null,
            $input['category_id'] ?? null,
            $input['unit'] ?? 'шт',
            $input['purchase_price'] ?? null,
            $input['sell_price'] ?? null,
            $input['min_stock'] ?? 0,
            $input['ozon_product_id'] ?? null,
            $input['ozon_sku'] ?? null,
            $input['image_url'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();

        // Создаём запись остатков на основных складах
        $warehouses = $pdo->query("SELECT id FROM erp_warehouses WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($warehouses as $whId) {
            $pdo->prepare("INSERT IGNORE INTO erp_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, 0)")
                ->execute([$id, $whId]);
        }

        return ['ok' => true, 'id' => $id];
    }

    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['name', 'sku', 'alias', 'short_name', 'product_code', 'article', 'barcode', 'brand', 'category_id', 'unit', 'purchase_price', 'sell_price', 'min_stock', 'ozon_product_id', 'ozon_sku', 'image_url', 'is_active', 'supplier', 'supplier_product_name', 'supplier_id', 'counterparty_id', 'cue_type', 'cue_parts', 'cue_material'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "`{$field}` = ?";
                $params[] = $input[$field];
            }
        }
        if (empty($sets)) errorResponse('No fields to update');
        $params[] = $id;

        $pdo = DB::get();
        $sql = "UPDATE erp_products SET " . implode(', ', $sets) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);

        return ['ok' => true, 'id' => $id];
    }

    public function delete(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_products SET is_active = 0 WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    /**
     * Массовое перемещение товаров в категорию
     */
    public function bulk_move(): array {
        $input = jsonInput();
        $ids = $input['ids'] ?? [];
        $categoryId = $input['category_id'] ?? null;
        if (empty($ids)) errorResponse('ids required');

        $pdo = DB::get();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$categoryId], array_map('intval', $ids));
        $pdo->prepare("UPDATE erp_products SET category_id = ? WHERE id IN ({$placeholders})")
            ->execute($params);

        return ['ok' => true, 'moved' => count($ids)];
    }

    /**
     * Дерево категорий
     */
    public function categories(): array {
        $pdo = DB::get();
        // Get all categories with direct product count
        $cats = $pdo->query("
            SELECT c.*, COUNT(p.id) as direct_count 
            FROM erp_product_categories c
            LEFT JOIN erp_products p ON p.category_id = c.id AND p.is_active = 1
            GROUP BY c.id
            ORDER BY c.name
        ")->fetchAll();

        // Build parent→children map and accumulate counts up the tree
        $byId = [];
        foreach ($cats as &$c) {
            $c['count'] = (int) $c['direct_count'];
            $byId[$c['id']] = &$c;
        }
        unset($c);
        // Walk each category, propagate its direct_count to all ancestors
        foreach ($byId as &$c) {
            $dc = (int) $c['direct_count'];
            if ($dc > 0) {
                $pid = $c['parent_id'];
                while ($pid && isset($byId[$pid])) {
                    $byId[$pid]['count'] += $dc;
                    $pid = $byId[$pid]['parent_id'];
                }
            }
        }
        unset($c);
        // Remove helper field
        $result = [];
        foreach ($byId as $c) {
            unset($c['direct_count']);
            $result[] = $c;
        }
        usort($result, fn($a, $b) => $a['id'] <=> $b['id']);
        return ['items' => $result];
    }

    /**
     * Создать категорию
     */
    public function category_create(): array {
        $input = jsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) errorResponse('name required');

        $pdo = DB::get();
        $parentId = $input['parent_id'] ?? null;

        // Cyrillic transliteration
        $tr = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
            'ж'=>'zh','з'=>'z','и'=>'i','й'=>'j','к'=>'k','л'=>'l','м'=>'m',
            'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
            'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $lower = mb_strtolower($name);
        $transliterated = strtr($lower, $tr);
        $baseSlug = trim(preg_replace('/[^a-z0-9]+/', '-', $transliterated), '-');
        if (!$baseSlug) $baseSlug = 'cat';
        $slug = $parentId ? $baseSlug . '-' . $parentId : $baseSlug;

        // Ensure unique — append counter if needed
        $origSlug = $slug;
        $counter = 1;
        while (true) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM erp_product_categories WHERE slug = ?");
            $st->execute([$slug]);
            if ($st->fetchColumn() == 0) break;
            $slug = $origSlug . '-' . (++$counter);
        }
        $pdo->prepare("INSERT INTO erp_product_categories (name, slug, parent_id) VALUES (?, ?, ?)")
            ->execute([$name, $slug, $parentId]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Переименовать категорию
     */
    public function category_update(): array {
        $input = jsonInput();
        $id = (int) ($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if (!$id || !$name) errorResponse('id and name required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_product_categories SET name = ? WHERE id = ?")->execute([$name, $id]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Удалить все категории (для переимпорта)
     */
    public function categories_clear(): array {
        $pdo = DB::get();
        $pdo->exec("UPDATE erp_products SET category_id = NULL");
        $pdo->exec("DELETE FROM erp_product_categories");
        $pdo->exec("ALTER TABLE erp_product_categories AUTO_INCREMENT = 1");
        return ['ok' => true];
    }

    /**
     * Автоматическое распределение товаров по категориям
     * На основе ключевых слов в названии товара
     */
    public function auto_categorize(): array {
        $pdo = DB::get();

        // Keyword → leaf category_id mapping (листовые категории — самый нижний уровень)
        $rules = [
            // Бильярд → Аксессуары для киев → Мел (6)
            ['cat' => 6,  'kw' => ['мел для', 'мел бильярд', 'мелок']],
            // Бильярд → Аксессуары для киев → Наклейки (7)
            ['cat' => 7,  'kw' => ['наклейк', 'tip']],
            // Бильярд → Аксессуары → Перчатки (3)
            ['cat' => 3,  'kw' => ['перчатк']],
            // Бильярд → Аксессуары → Треугольники (4)
            ['cat' => 4,  'kw' => ['треугольник']],
            // Бильярд → Кии → Пул (10)
            ['cat' => 10, 'kw' => ['кий для пула', 'кий пул']],
            // Бильярд → Кии → Русский бильярд (11)
            ['cat' => 11, 'kw' => ['кий для русского', 'бильярдный кий']],
            // Бильярд → Кии → Снукер (12)
            ['cat' => 12, 'kw' => ['кий для снукера', 'кий снукер']],
            // Бильярд → Шары → Пул (29)
            ['cat' => 29, 'kw' => ['шар для пула', 'шары для пула', 'бильярдный шар', 'набор шаров']],
            // Бильярд → Шары → Русский бильярд (30)
            ['cat' => 30, 'kw' => ['шар для русского']],
            // Бильярд → Шары → Снукер (31)
            ['cat' => 31, 'kw' => ['шар для снукера', 'шары для снукера']],
            // Бильярд → Шары → Тренировочные (32)
            ['cat' => 32, 'kw' => ['тренировочн']],
            // Бильярд → Столы → Бильярдные столы (21)
            ['cat' => 21, 'kw' => ['бильярдный стол', 'стол бильярдн', 'стол для бильярд', 'стол для пул']],
            // Бильярд → Сукно и покрытия → Сукно (23)
            ['cat' => 23, 'kw' => ['сукно']],
            // Бильярд → Освещение → Светильники (17)
            ['cat' => 17, 'kw' => ['светильник', 'лампа для бильярд']],
            // Бильярд → Комплектующие столов → Лузы (14)
            ['cat' => 14, 'kw' => ['лузы', 'луза']],
            // Бильярд → Комплектующие столов → Резина для бортов (15)
            ['cat' => 15, 'kw' => ['резина для борт', 'бортовая резина']],
            // Бильярд → Чехлы и тубусы → Тубусы и футляры (25)
            ['cat' => 25, 'kw' => ['тубус', 'футляр для к']],
            // Бильярд → Чехлы и тубусы → Чехлы для столов (26)
            ['cat' => 26, 'kw' => ['чехол для стол', 'чехлы для стол', 'покрыв']],
            // General billiard keywords (catch-all → Бильярд → Прочее → Аксессуары (19))
            ['cat' => 19, 'kw' => ['бильярд', 'кий ', 'кия ', 'пул ', 'снукер', 'киев', 'древко', 'мост бильярд', 'полка для к', 'стойка для к', 'машинка для к']],
            // Дартс → Дротики → Дротики (37)
            ['cat' => 37, 'kw' => ['дротик']],
            // Дартс → Аксессуары → Прочее (35)
            ['cat' => 35, 'kw' => ['дартс', 'мишень']],
            // Игровые столы → Аэрохоккей → Аэрохоккей (40)
            ['cat' => 40, 'kw' => ['аэрохоккей']],
            // Игровые столы → Настольный футбол → Кикер (42)
            ['cat' => 42, 'kw' => ['кикер', 'настольный футбол']],
            // Настольный теннис → Ракетки → Ракетки (45)
            ['cat' => 45, 'kw' => ['ракетк', 'теннисн']],
            // Настольный теннис → Столы → Теннисные столы (47)
            ['cat' => 47, 'kw' => ['теннисный стол', 'стол для тенниса', 'стол для пинг']],
            // Покер → Карты → Карты (50)
            ['cat' => 50, 'kw' => ['карты для покер', 'покерные карт', 'игральн']],
            // Покер → Наборы → Наборы для покера (52)
            ['cat' => 52, 'kw' => ['набор для покера', 'покерный набор']],
            // Покер → Фишки → Фишки (54)
            ['cat' => 54, 'kw' => ['фишк', 'покер']],
            // Тренажеры (60)
            ['cat' => 60, 'kw' => ['тренажер']],
        ];

        $products = $pdo->query("SELECT id, name FROM erp_products WHERE is_active = 1 AND category_id IS NULL")->fetchAll();
        $updated = 0;
        $fallback = 57; // Прочее → Без категории → Прочее

        foreach ($products as $p) {
            $pName = mb_strtolower($p['name']);
            $matched = false;
            foreach ($rules as $rule) {
                foreach ($rule['kw'] as $kw) {
                    if (mb_strpos($pName, $kw) !== false) {
                        $pdo->prepare("UPDATE erp_products SET category_id = ? WHERE id = ?")->execute([$rule['cat'], $p['id']]);
                        $updated++;
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (!$matched) {
                $pdo->prepare("UPDATE erp_products SET category_id = ? WHERE id = ?")->execute([$fallback, $p['id']]);
                $updated++;
            }
        }

        return ['ok' => true, 'updated' => $updated, 'total' => count($products)];
    }

    /**
     * Meta-данные каталога (бренды, источники)
     */
    public function meta(): array {
        $pdo = DB::get();
        $brands = $pdo->query("SELECT DISTINCT brand FROM erp_products WHERE is_active=1 AND brand IS NOT NULL AND brand != '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
        $sources = $pdo->query("SELECT DISTINCT marketplace_source FROM erp_products WHERE is_active=1 AND marketplace_source IS NOT NULL AND marketplace_source != '' ORDER BY marketplace_source")->fetchAll(PDO::FETCH_COLUMN);
        $suppliers = $pdo->query("SELECT DISTINCT supplier FROM erp_products WHERE is_active=1 AND supplier IS NOT NULL AND supplier != '' ORDER BY supplier")->fetchAll(PDO::FETCH_COLUMN);
        $suppliersList = $pdo->query("SELECT id, name, alias FROM erp_suppliers WHERE is_active=1 ORDER BY name")->fetchAll();
        return ['brands' => $brands, 'sources' => $sources, 'suppliers' => $suppliers, 'suppliers_list' => $suppliersList];
    }

    /**
     * Товары с низким остатком
     */
    public function low_stock(): array {
        $pdo = DB::get();
        $stmt = $pdo->query("
            SELECT p.id, p.sku, p.name, p.min_stock,
                   COALESCE(i.quantity, 0) as stock,
                   COALESCE(i.reserved, 0) as reserved
            FROM erp_products p
            LEFT JOIN erp_inventory i ON i.product_id = p.id AND i.warehouse_id = 1
            WHERE p.is_active = 1
              AND COALESCE(i.quantity, 0) <= p.min_stock
            ORDER BY (COALESCE(i.quantity, 0) - p.min_stock) ASC
        ");

        return ['items' => $stmt->fetchAll()];
    }

    /**
     * Синхронизация остатков из МойСклад
     * POST: { "items": [{ "sku": "2304", "quantity": 310, "reserve": 0 }, ...] }
     */
    public function stock_sync(): array {
        $input = jsonInput();
        $items = $input['items'] ?? [];
        if (empty($items)) errorResponse('items array required');

        $pdo = DB::get();
        $whId = (int)($input['warehouse_id'] ?? 1);
        $updated = 0;
        $not_found = [];

        $stmtFind = $pdo->prepare("SELECT id FROM erp_products WHERE sku = ? AND is_active = 1");
        $stmtUpsert = $pdo->prepare("
            INSERT INTO erp_inventory (product_id, warehouse_id, quantity, reserved)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), reserved = VALUES(reserved)
        ");

        foreach ($items as $item) {
            $sku = trim($item['sku'] ?? '');
            if (!$sku) continue;

            $stmtFind->execute([$sku]);
            $productId = $stmtFind->fetchColumn();
            if (!$productId) {
                $not_found[] = $sku;
                continue;
            }

            $qty = (float)($item['quantity'] ?? 0);
            $res = (float)($item['reserve'] ?? 0);
            $stmtUpsert->execute([$productId, $whId, $qty, $res]);
            $updated++;
        }

        return ['ok' => true, 'updated' => $updated, 'not_found' => $not_found];
    }

    /**
     * Массовый импорт товаров (с полной поддержкой marketplace полей)
     * POST: { "products": [{ "sku": "...", "name": "...", ... }, ...] }
     */
    public function import(): array {
        $input = jsonInput();
        $items = $input['products'] ?? [];
        if (empty($items)) errorResponse('products array required');

        $pdo = DB::get();
        $created = 0;
        $updated = 0;
        $errors = [];

        $fields = ['name','barcode','category_id','unit','purchase_price','sell_price',
                   'min_stock','ozon_product_id','ozon_sku','ya_market_sku','ya_offer_id',
                   'marketplace_source','description','brand','supplier','weight','image_url','images'];

        foreach ($items as $i => $item) {
            $sku = trim($item['sku'] ?? '');
            $name = trim($item['name'] ?? '');
            if (!$sku || !$name) {
                $errors[] = "Row {$i}: sku and name required";
                continue;
            }

            try {
                $existing = $pdo->prepare("SELECT id FROM erp_products WHERE sku = ?");
                $existing->execute([$sku]);
                $existingId = $existing->fetchColumn();

                if ($existingId) {
                    // Update — все переданные поля
                    $sets = ['name = ?'];
                    $params = [$name];
                    foreach ($fields as $f) {
                        if ($f === 'name') continue;
                        if (array_key_exists($f, $item)) {
                            $val = $item[$f];
                            if ($f === 'images' && is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                            $sets[] = "`{$f}` = ?";
                            $params[] = $val;
                        }
                    }
                    $params[] = $existingId;
                    $pdo->prepare("UPDATE erp_products SET " . implode(', ', $sets) . " WHERE id = ?")
                        ->execute($params);
                    $updated++;
                } else {
                    // Insert — все переданные поля
                    $cols = ['sku', 'name'];
                    $vals = [$sku, $name];
                    foreach ($fields as $f) {
                        if ($f === 'name') continue;
                        if (array_key_exists($f, $item)) {
                            $cols[] = $f;
                            $val = $item[$f];
                            if ($f === 'images' && is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                            $vals[] = $val;
                        }
                    }
                    $placeholders = implode(',', array_fill(0, count($cols), '?'));
                    $colsSQL = implode(',', array_map(fn($c) => "`{$c}`", $cols));
                    $pdo->prepare("INSERT INTO erp_products ({$colsSQL}) VALUES ({$placeholders})")
                        ->execute($vals);
                    $created++;
                }
            } catch (PDOException $e) {
                $errors[] = "Row {$i} ({$sku}): " . $e->getMessage();
            }
        }

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * Автозаполнение атрибутов киев по названиям
     * Анализирует названия, заполняет cue_type, cue_parts, cue_material
     * POST: { "category_id": 1, "dry_run": false }
     */
    public function auto_fill_cue(): array {
        $input = jsonInput();
        $catId = (int)($input['category_id'] ?? param('category_id', 0));
        if (!$catId) errorResponse('category_id required');
        $dryRun = (bool)($input['dry_run'] ?? false);

        $pdo = DB::get();

        // Collect category + descendants
        $allCats = $pdo->query("SELECT id, parent_id FROM erp_product_categories")->fetchAll();
        $catMap = [];
        foreach ($allCats as $c) $catMap[$c['id']] = $c['parent_id'];
        $ids = [$catId];
        $queue = [$catId];
        while ($queue) {
            $pid = array_shift($queue);
            foreach ($catMap as $cid => $par) {
                if ($par == $pid && !in_array($cid, $ids)) { $ids[] = $cid; $queue[] = $cid; }
            }
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $products = $pdo->prepare("SELECT id, name, cue_type, cue_parts, cue_material FROM erp_products WHERE is_active=1 AND category_id IN ({$placeholders})");
        $products->execute($ids);
        $products = $products->fetchAll();

        // Rules for type detection
        $typeRules = [
            'снукер'      => ['снукер', 'snooker'],
            'пул'         => ['для пула', 'пул '],
            'пирамида'    => ['русского', 'русский бильярд'],
            'укороченный' => ['укороченн'],
            'древко'      => ['древко'],
        ];
        // Rules for parts
        $parts2Rules = ['2-составн', 'разборн', '3/4'];
        $parts1Rules = ['цельн', '1-составн'];
        // Rules for material
        $materialRules = [
            'рамин'    => ['ramin', 'рамин'],
            'клён'     => ['maple', 'crown', 'клён', 'клен'],
            'композит' => ['compositor', 'композит', 'карбонов'],
        ];

        $updated = 0;
        $skipped = 0;
        $unidentified = [];
        $results = [];

        $stmt = $pdo->prepare("UPDATE erp_products SET cue_type=?, cue_parts=?, cue_material=? WHERE id=?");

        foreach ($products as $p) {
            $name = mb_strtolower($p['name']);
            $type = null; $parts = null; $material = null;

            // Detect type
            foreach ($typeRules as $val => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($name, $kw) !== false) { $type = $val; break 2; }
                }
            }

            // Detect parts
            foreach ($parts2Rules as $kw) {
                if (mb_strpos($name, $kw) !== false) { $parts = 2; break; }
            }
            if (!$parts) {
                foreach ($parts1Rules as $kw) {
                    if (mb_strpos($name, $kw) !== false) { $parts = 1; break; }
                }
            }

            // Detect material
            foreach ($materialRules as $val => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($name, $kw) !== false) { $material = $val; break 2; }
                }
            }

            // Astro = клён (maple wood cues)
            if (!$material && mb_strpos($name, 'astro') !== false) $material = 'клён';
            // Player without Compositor = рамин (default affordable wood)
            if (!$material && mb_strpos($name, 'player') !== false) $material = 'рамин';
            // Тафгай карбоновый already matched by 'карбонов' → композит

            $hasChanges = ($type !== null || $parts !== null || $material !== null);
            $noTypeInfo = ($type === null && $parts === null && $material === null);

            if ($noTypeInfo) {
                $unidentified[] = ['id' => $p['id'], 'name' => $p['name']];
                $skipped++;
                continue;
            }

            $results[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'cue_type' => $type,
                'cue_parts' => $parts,
                'cue_material' => $material,
            ];

            if (!$dryRun) {
                // Only update fields that were detected (keep existing values for nulls)
                $sets = [];
                $params = [];
                if ($type !== null) { $sets[] = 'cue_type=?'; $params[] = $type; }
                if ($parts !== null) { $sets[] = 'cue_parts=?'; $params[] = $parts; }
                if ($material !== null) { $sets[] = 'cue_material=?'; $params[] = $material; }
                $params[] = $p['id'];
                $pdo->prepare("UPDATE erp_products SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
                $updated++;
            }
        }

        return [
            'ok' => true,
            'total' => count($products),
            'updated' => $updated,
            'skipped' => $skipped,
            'unidentified' => $unidentified,
            'results' => $results,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Массовое обновление атрибутов товаров
     * POST: { "ids": [1,2,3], "fields": { "cue_type": "пул", "cue_parts": 2 } }
     */
    public function bulk_update_attr(): array {
        $input = jsonInput();
        $ids = $input['ids'] ?? [];
        $fields = $input['fields'] ?? [];
        if (empty($ids)) errorResponse('ids required');
        if (empty($fields)) errorResponse('fields required');

        $allowed = ['cue_type', 'cue_parts', 'cue_material'];
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed)) continue;
            $sets[] = "`{$k}` = ?";
            $params[] = $v;
        }
        if (empty($sets)) errorResponse('No valid fields');

        $pdo = DB::get();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, array_map('intval', $ids));
        $sql = "UPDATE erp_products SET " . implode(', ', $sets) . " WHERE id IN ({$placeholders})";
        $pdo->prepare($sql)->execute($params);

        return ['ok' => true, 'updated' => count($ids)];
    }
}
