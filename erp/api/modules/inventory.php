<?php
/**
 * ERP Module: Складские операции
 * 
 * inventory.list      — остатки по складам
 * inventory.movements — история движений
 * inventory.receive   — приход товара (закупка/возврат)
 * inventory.ship      — расход товара (продажа/списание)
 * inventory.adjust    — корректировка остатка
 * inventory.transfer  — перемещение между складами
 */
class ERP_Inventory {

    /**
     * Текущие остатки
     * ?warehouse_id=1&low_stock_only=1
     */
    public function list(): array {
        $pdo = DB::get();
        $where = ['p.is_active = 1'];
        $params = [];

        if ($whId = param('warehouse_id')) {
            $where[] = 'i.warehouse_id = ?';
            $params[] = (int) $whId;
        }
        if (param('low_stock_only')) {
            $where[] = 'i.quantity <= p.min_stock';
        }

        $whereSQL = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT i.*, p.sku, p.name as product_name, p.unit, p.min_stock,
                   w.name as warehouse_name
            FROM erp_inventory i
            JOIN erp_products p ON p.id = i.product_id
            JOIN erp_warehouses w ON w.id = i.warehouse_id
            WHERE {$whereSQL}
            ORDER BY p.name
        ");
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll()];
    }

    /**
     * История движений товаров
     * ?product_id=5&type=purchase&from=2026-01-01&limit=100
     */
    public function movements(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($pid = param('product_id')) {
            $where[] = 'im.product_id = ?';
            $params[] = (int) $pid;
        }
        if ($type = param('type')) {
            $where[] = 'im.type = ?';
            $params[] = $type;
        }
        if ($from = param('from')) {
            $where[] = 'im.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to = param('to')) {
            $where[] = 'im.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $whereSQL = implode(' AND ', $where);
        $limit = min((int)(param('limit', 100)), 500);

        $stmt = $pdo->prepare("
            SELECT im.*, p.sku, p.name as product_name, w.name as warehouse_name
            FROM erp_inventory_movements im
            JOIN erp_products p ON p.id = im.product_id
            JOIN erp_warehouses w ON w.id = im.warehouse_id
            WHERE {$whereSQL}
            ORDER BY im.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll()];
    }

    /**
     * Приход товара
     * POST: { "product_id": 5, "quantity": 100, "warehouse_id": 1, "unit_price": 150.00, "reason": "Закупка у поставщика X" }
     */
    public function receive(): array {
        return $this->processMovement('purchase', true);
    }

    /**
     * Расход / продажа
     * POST: { "product_id": 5, "quantity": 10, "warehouse_id": 1, "reason": "Заказ Ozon #12345" }
     */
    public function ship(): array {
        return $this->processMovement('sale', false);
    }

    /**
     * Корректировка остатка (инвентаризация)
     * POST: { "product_id": 5, "quantity": 95, "warehouse_id": 1, "reason": "Инвентаризация" }
     * quantity = новый фактический остаток
     */
    public function adjust(): array {
        $input = jsonInput();
        $productId = (int)($input['product_id'] ?? 0);
        $newQty    = (int)($input['quantity'] ?? 0);
        $whId      = (int)($input['warehouse_id'] ?? 1);
        $reason    = $input['reason'] ?? 'Корректировка';

        if (!$productId) errorResponse('product_id required');

        $pdo = DB::get();

        // Текущий остаток
        $current = $pdo->prepare("SELECT quantity FROM erp_inventory WHERE product_id=? AND warehouse_id=?");
        $current->execute([$productId, $whId]);
        $currentQty = (int)($current->fetchColumn() ?: 0);
        $diff = $newQty - $currentQty;

        if ($diff === 0) return ['ok' => true, 'message' => 'Остаток не изменился'];

        $pdo->beginTransaction();
        try {
            // Обновляем остаток
            $pdo->prepare("
                INSERT INTO erp_inventory (product_id, warehouse_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?
            ")->execute([$productId, $whId, $newQty, $newQty]);

            // Движение
            $pdo->prepare("
                INSERT INTO erp_inventory_movements (product_id, warehouse_id, type, quantity, reason)
                VALUES (?, ?, 'adjustment', ?, ?)
            ")->execute([$productId, $whId, $diff, $reason]);

            $pdo->commit();
            return ['ok' => true, 'previous' => $currentQty, 'new' => $newQty, 'diff' => $diff];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Перемещение между складами
     * POST: { "product_id": 5, "quantity": 20, "from_warehouse_id": 1, "to_warehouse_id": 2 }
     */
    public function transfer(): array {
        $input = jsonInput();
        $productId = (int)($input['product_id'] ?? 0);
        $qty       = (int)($input['quantity'] ?? 0);
        $fromWh    = (int)($input['from_warehouse_id'] ?? 0);
        $toWh      = (int)($input['to_warehouse_id'] ?? 0);

        if (!$productId || !$qty || !$fromWh || !$toWh) {
            errorResponse('product_id, quantity, from_warehouse_id, to_warehouse_id required');
        }
        if ($fromWh === $toWh) errorResponse('Склады должны быть разными');
        if ($qty <= 0) errorResponse('quantity must be positive');

        $pdo = DB::get();
        $pdo->beginTransaction();
        try {
            // Проверяем остаток
            $current = $pdo->prepare("SELECT quantity FROM erp_inventory WHERE product_id=? AND warehouse_id=?");
            $current->execute([$productId, $fromWh]);
            $currentQty = (int)($current->fetchColumn() ?: 0);
            if ($currentQty < $qty) errorResponse("Недостаточно на складе: {$currentQty} < {$qty}");

            // Списываем с from
            $pdo->prepare("UPDATE erp_inventory SET quantity = quantity - ? WHERE product_id=? AND warehouse_id=?")
                ->execute([$qty, $productId, $fromWh]);

            // Добавляем на to
            $pdo->prepare("
                INSERT INTO erp_inventory (product_id, warehouse_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ")->execute([$productId, $toWh, $qty, $qty]);

            // Движения
            $reason = "Перемещение со склада #{$fromWh} на склад #{$toWh}";
            $pdo->prepare("INSERT INTO erp_inventory_movements (product_id, warehouse_id, type, quantity, reason) VALUES (?, ?, 'transfer', ?, ?)")
                ->execute([$productId, $fromWh, -$qty, $reason]);
            $pdo->prepare("INSERT INTO erp_inventory_movements (product_id, warehouse_id, type, quantity, reason) VALUES (?, ?, 'transfer', ?, ?)")
                ->execute([$productId, $toWh, $qty, $reason]);

            $pdo->commit();
            return ['ok' => true, 'transferred' => $qty];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Склады
     */
    public function warehouses(): array {
        $pdo = DB::get();
        return ['items' => $pdo->query("SELECT * FROM erp_warehouses WHERE is_active=1 ORDER BY name")->fetchAll()];
    }

    // ── Private ─────────────────────────────────────────

    private function processMovement(string $type, bool $isIncoming): array {
        $input = jsonInput();
        $productId = (int)($input['product_id'] ?? 0);
        $qty       = (int)($input['quantity'] ?? 0);
        $whId      = (int)($input['warehouse_id'] ?? 1);
        $unitPrice = isset($input['unit_price']) ? (float) $input['unit_price'] : null;
        $reason    = $input['reason'] ?? null;
        $docRef    = $input['document_ref'] ?? null;
        $journalId = $input['journal_id'] ?? null;

        if (!$productId || !$qty) errorResponse('product_id and quantity required');
        if ($qty <= 0) errorResponse('quantity must be positive');

        $pdo = DB::get();
        $pdo->beginTransaction();
        try {
            $signedQty = $isIncoming ? $qty : -$qty;

            // Проверяем остаток при списании
            if (!$isIncoming) {
                $current = $pdo->prepare("SELECT quantity FROM erp_inventory WHERE product_id=? AND warehouse_id=?");
                $current->execute([$productId, $whId]);
                $currentQty = (int)($current->fetchColumn() ?: 0);
                if ($currentQty < $qty) {
                    errorResponse("Недостаточно на складе: есть {$currentQty}, нужно {$qty}");
                }
            }

            // Обновляем остаток
            $pdo->prepare("
                INSERT INTO erp_inventory (product_id, warehouse_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ")->execute([$productId, $whId, max(0, $signedQty), $signedQty]);

            // Записываем движение
            $pdo->prepare("
                INSERT INTO erp_inventory_movements (journal_id, product_id, warehouse_id, type, quantity, unit_price, reason, document_ref)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$journalId, $productId, $whId, $type, $signedQty, $unitPrice, $reason, $docRef]);

            $pdo->commit();

            return [
                'ok'       => true,
                'type'     => $type,
                'quantity' => $signedQty,
                'product_id' => $productId,
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
