<?php
/**
 * ERP Module: Поставки (Supplies / Procurement)
 *
 * supplies.list     — список поставок (с фильтрами)
 * supplies.get      — детали поставки + позиции
 * supplies.create   — создать поставку с позициями
 * supplies.update   — обновить статус/данные
 * supplies.delete   — удалить поставку
 * supplies.stats    — статистика поставок
 * supplies.receive  — перевести в «Получено» + оприходовать товар на склад
 */
class ERP_Supplies {

    /**
     * Список поставок
     * ?status=ordered&q=search&from=2026-01-01&limit=100
     */
    public function list(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($status = param('status')) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        if ($q = param('q')) {
            $where[] = '(s.supplier_name LIKE ? OR s.number LIKE ? OR s.notes LIKE ?)';
            $like = "%{$q}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($from = param('from')) {
            $where[] = 's.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to = param('to')) {
            $where[] = 's.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $whereSQL = implode(' AND ', $where);
        $limit = min((int)(param('limit', 100)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_supplies s WHERE {$whereSQL}");
        $total->execute($params);
        $totalCount = (int) $total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM erp_supply_items si WHERE si.supply_id = s.id) as item_count,
                   (SELECT COALESCE(SUM(si.quantity * si.unit_price), 0) FROM erp_supply_items si WHERE si.supply_id = s.id) as total_amount
            FROM erp_supplies s
            WHERE {$whereSQL}
            ORDER BY s.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $totalCount];
    }

    /**
     * Получить поставку с позициями
     * ?id=5
     */
    public function get(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();

        $stmt = $pdo->prepare("SELECT * FROM erp_supplies WHERE id = ?");
        $stmt->execute([$id]);
        $supply = $stmt->fetch();
        if (!$supply) errorResponse('Supply not found', 404);

        // Позиции
        $items = $pdo->prepare("
            SELECT si.*, p.sku, p.name as product_name, p.unit
            FROM erp_supply_items si
            LEFT JOIN erp_products p ON p.id = si.product_id
            WHERE si.supply_id = ?
            ORDER BY si.id
        ");
        $items->execute([$id]);
        $supply['items'] = $items->fetchAll();
        $supply['total_amount'] = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $supply['items']));

        return $supply;
    }

    /**
     * Создать поставку
     * POST: { supplier_name, number?, status?, expected_date?, notes?, items: [{product_id, quantity, unit_price}] }
     */
    public function create(): array {
        $input = jsonInput();
        $supplierName = trim($input['supplier_name'] ?? '');
        if (!$supplierName) errorResponse('supplier_name required');

        $pdo = DB::get();
        $pdo->beginTransaction();

        try {
            $pdo->prepare("
                INSERT INTO erp_supplies (supplier_name, number, status, expected_date, notes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $supplierName,
                $input['number'] ?? null,
                $input['status'] ?? 'draft',
                $input['expected_date'] ?? null,
                $input['notes'] ?? null,
            ]);

            $supplyId = (int) $pdo->lastInsertId();

            // Позиции
            $items = $input['items'] ?? [];
            if (!empty($items)) {
                $stmt = $pdo->prepare("
                    INSERT INTO erp_supply_items (supply_id, product_id, quantity, unit_price)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($items as $item) {
                    $productId = (int)($item['product_id'] ?? 0);
                    $qty = (int)($item['quantity'] ?? 0);
                    $price = (float)($item['unit_price'] ?? 0);
                    if ($productId && $qty > 0) {
                        $stmt->execute([$supplyId, $productId, $qty, $price]);
                    }
                }
            }

            // Запись в журнал
            $itemCount = count(array_filter($items, fn($i) => ($i['product_id'] ?? 0) > 0 && ($i['quantity'] ?? 0) > 0));
            $pdo->prepare("
                INSERT INTO erp_journal (raw_text, category, source) VALUES (?, 'logistics', 'web')
            ")->execute(["Создана поставка #{$supplyId} от {$supplierName}, {$itemCount} поз."]);

            $pdo->commit();
            return ['ok' => true, 'id' => $supplyId];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Обновить поставку
     * POST: { id, status?, expected_date?, notes?, supplier_name?, number? }
     */
    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? 0);
        if (!$id) errorResponse('id required');

        $pdo = DB::get();

        // Check exists
        $existing = $pdo->prepare("SELECT id, status FROM erp_supplies WHERE id = ?");
        $existing->execute([$id]);
        if (!$existing->fetch()) errorResponse('Supply not found', 404);

        $fields = [];
        $params = [];
        foreach (['supplier_name', 'number', 'status', 'expected_date', 'notes'] as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "{$field} = ?";
                $params[] = $input[$field];
            }
        }

        if (empty($fields)) errorResponse('Nothing to update');
        $params[] = $id;

        $pdo->prepare("UPDATE erp_supplies SET " . implode(', ', $fields) . " WHERE id = ?")
            ->execute($params);

        return ['ok' => true, 'updated' => $id];
    }

    /**
     * Удалить поставку (только draft/cancelled)
     * POST: { id }
     */
    public function delete(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? 0);
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("SELECT status FROM erp_supplies WHERE id = ?");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();

        if (!$status) errorResponse('Supply not found', 404);
        if (!in_array($status, ['draft', 'cancelled'])) {
            errorResponse('Можно удалить только черновик или отменённую поставку');
        }

        $pdo->prepare("DELETE FROM erp_supply_items WHERE supply_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM erp_supplies WHERE id = ?")->execute([$id]);

        return ['ok' => true, 'deleted' => $id];
    }

    /**
     * Статистика поставок
     */
    public function stats(): array {
        $pdo = DB::get();

        $stats = $pdo->query("
            SELECT
                SUM(status IN ('draft','ordered')) as pending,
                SUM(status = 'shipped') as shipped,
                SUM(status = 'received') as received,
                SUM(status = 'cancelled') as cancelled,
                COUNT(*) as total
            FROM erp_supplies
        ")->fetch();

        // Сумма открытых поставок
        $openTotal = $pdo->query("
            SELECT COALESCE(SUM(si.quantity * si.unit_price), 0) as total
            FROM erp_supply_items si
            JOIN erp_supplies s ON s.id = si.supply_id
            WHERE s.status NOT IN ('received', 'cancelled')
        ")->fetchColumn();

        return [
            'pending'   => (int)($stats['pending'] ?? 0),
            'shipped'   => (int)($stats['shipped'] ?? 0),
            'received'  => (int)($stats['received'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0),
            'total'     => (int)($stats['total'] ?? 0),
            'open_total' => (float)$openTotal,
        ];
    }

    /**
     * Получить поставку на склад (статус → received, оприход товаров)
     * POST: { id, warehouse_id? }
     */
    public function receive(): array {
        $input = jsonInput();
        $id   = (int)($input['id'] ?? 0);
        $whId = (int)($input['warehouse_id'] ?? 1);
        if (!$id) errorResponse('id required');

        $pdo = DB::get();

        $supply = $pdo->prepare("SELECT * FROM erp_supplies WHERE id = ?");
        $supply->execute([$id]);
        $supply = $supply->fetch();
        if (!$supply) errorResponse('Supply not found', 404);
        if ($supply['status'] === 'received') errorResponse('Поставка уже получена');
        if ($supply['status'] === 'cancelled') errorResponse('Поставка отменена');

        // Получить позиции
        $items = $pdo->prepare("SELECT * FROM erp_supply_items WHERE supply_id = ?");
        $items->execute([$id]);
        $items = $items->fetchAll();

        $pdo->beginTransaction();
        try {
            // Статус = received
            $pdo->prepare("UPDATE erp_supplies SET status = 'received', received_at = NOW() WHERE id = ?")
                ->execute([$id]);

            // Оприходовать каждый товар
            foreach ($items as $item) {
                $qty = (int) $item['quantity'];
                $pid = (int) $item['product_id'];

                // Обновить остаток
                $pdo->prepare("
                    INSERT INTO erp_inventory (product_id, warehouse_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ")->execute([$pid, $whId, $qty, $qty]);

                // Движение
                $pdo->prepare("
                    INSERT INTO erp_inventory_movements (product_id, warehouse_id, type, quantity, unit_price, reason, document_ref)
                    VALUES (?, ?, 'purchase', ?, ?, ?, ?)
                ")->execute([
                    $pid, $whId, $qty,
                    $item['unit_price'],
                    "Поставка #{$id} от {$supply['supplier_name']}",
                    $supply['number'] ?: "PST-{$id}"
                ]);
            }

            // Журнал
            $total = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
            $pdo->prepare("
                INSERT INTO erp_journal (raw_text, category, source)
                VALUES (?, 'logistics', 'web')
            ")->execute(["Получена поставка #{$id} от {$supply['supplier_name']}, " . count($items) . " поз. на сумму " . number_format($total, 0, '.', ' ') . " ₽"]);

            $pdo->commit();
            return ['ok' => true, 'received' => $id, 'items_count' => count($items)];
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
