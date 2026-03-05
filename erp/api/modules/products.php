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
            $where[] = 'p.category_id = ?';
            $params[] = (int) $cat;
        }
        if ($q = param('q')) {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 100)), 1000);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_products p WHERE {$whereSQL}");
        $total->execute($params);

        $stmt = $pdo->prepare("
            SELECT p.*, 
                   pc.name as category_name,
                   COALESCE(i.quantity, 0) as stock,
                   COALESCE(i.reserved, 0) as reserved
            FROM erp_products p
            LEFT JOIN erp_product_categories pc ON pc.id = p.category_id
            LEFT JOIN erp_inventory i ON i.product_id = p.id AND i.warehouse_id = 1
            WHERE {$whereSQL}
            ORDER BY p.name
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
            INSERT INTO erp_products (sku, name, barcode, category_id, unit, purchase_price, sell_price, min_stock, ozon_product_id, ozon_sku, image_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sku,
            $name,
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

        $allowed = ['name', 'sku', 'barcode', 'category_id', 'unit', 'purchase_price', 'sell_price', 'min_stock', 'ozon_product_id', 'ozon_sku', 'image_url', 'is_active'];
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
     * Дерево категорий
     */
    public function categories(): array {
        $pdo = DB::get();
        $cats = $pdo->query("SELECT * FROM erp_product_categories ORDER BY name")->fetchAll();
        return ['items' => $cats];
    }

    /**
     * Создать категорию
     */
    public function category_create(): array {
        $input = jsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) errorResponse('name required');

        $pdo = DB::get();
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
        $pdo->prepare("INSERT INTO erp_product_categories (name, slug, parent_id) VALUES (?, ?, ?)")
            ->execute([$name, $slug, $input['parent_id'] ?? null]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
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
     * Массовый импорт товаров
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
                    // Update
                    $pdo->prepare("UPDATE erp_products SET name=?, barcode=?, purchase_price=?, sell_price=? WHERE id=?")
                        ->execute([$name, $item['barcode'] ?? null, $item['purchase_price'] ?? null, $item['sell_price'] ?? null, $existingId]);
                    $updated++;
                } else {
                    // Insert
                    $pdo->prepare("INSERT INTO erp_products (sku, name, barcode, purchase_price, sell_price) VALUES (?,?,?,?,?)")
                        ->execute([$sku, $name, $item['barcode'] ?? null, $item['purchase_price'] ?? null, $item['sell_price'] ?? null]);
                    $created++;
                }
            } catch (PDOException $e) {
                $errors[] = "Row {$i} ({$sku}): " . $e->getMessage();
            }
        }

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
    }
}
