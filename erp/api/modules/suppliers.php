<?php
/**
 * ERP Module: Suppliers — Управление поставщиками
 *
 * suppliers.list       — список поставщиков
 * suppliers.get        — один поставщик (с товарами)
 * suppliers.create     — создать
 * suppliers.update     — обновить
 * suppliers.delete     — удалить (soft)
 * suppliers.stats      — статистика
 */
class ERP_Suppliers {

    public function list(): array {
        $pdo = DB::get();
        $where = ['s.is_active = 1'];
        $params = [];

        if ($q = param('q')) {
            $where[] = '(s.name LIKE ? OR s.alias LIKE ? OR s.synonyms LIKE ? OR s.country LIKE ?)';
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 50)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_suppliers s WHERE {$whereSQL}");
        $total->execute($params);
        $totalCount = (int) $total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM erp_products WHERE supplier_id = s.id AND is_active = 1) as product_count
            FROM erp_suppliers s
            WHERE {$whereSQL}
            ORDER BY s.name ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        return [
            'items'  => $stmt->fetchAll(),
            'total'  => $totalCount,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    public function get(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("SELECT * FROM erp_suppliers WHERE id = ?");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch();
        if (!$supplier) errorResponse('Not found', 404);

        // Товары этого поставщика
        $products = $pdo->prepare("
            SELECT p.id, p.sku, p.name, p.brand, p.sell_price, p.purchase_price,
                   COALESCE(i.quantity, 0) as stock
            FROM erp_products p
            LEFT JOIN erp_inventory i ON i.product_id = p.id
            WHERE p.supplier_id = ? AND p.is_active = 1
            ORDER BY p.name
        ");
        $products->execute([$id]);
        $supplier['products'] = $products->fetchAll();

        return $supplier;
    }

    public function create(): array {
        $input = jsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) errorResponse('name обязательно');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_suppliers (name, alias, synonyms, inn, phone, email, website, country, address, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            trim($input['alias'] ?? '') ?: null,
            trim($input['synonyms'] ?? '') ?: null,
            $input['inn'] ?? null,
            $input['phone'] ?? null,
            $input['email'] ?? null,
            $input['website'] ?? null,
            $input['country'] ?? null,
            $input['address'] ?? null,
            $input['notes'] ?? null,
        ]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['name', 'alias', 'synonyms', 'inn', 'phone', 'email', 'website', 'country', 'address', 'notes', 'is_active'];
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
        $pdo->prepare("UPDATE erp_suppliers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['ok' => true, 'id' => $id];
    }

    public function delete(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_suppliers SET is_active = 0 WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    public function stats(): array {
        $pdo = DB::get();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM erp_suppliers WHERE is_active = 1")->fetchColumn();
        $withProducts = (int) $pdo->query("
            SELECT COUNT(DISTINCT s.id) FROM erp_suppliers s
            INNER JOIN erp_products p ON p.supplier_id = s.id AND p.is_active = 1
            WHERE s.is_active = 1
        ")->fetchColumn();

        return [
            'total_suppliers' => $total,
            'with_products' => $withProducts,
        ];
    }
}
