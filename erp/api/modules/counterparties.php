<?php
/**
 * ERP Module: Контрагенты (Counterparties)
 *
 * counterparties.list    — список контрагентов (с фильтрами, балансами)
 * counterparties.get     — один контрагент (с товарами, транзакциями)
 * counterparties.create  — создать
 * counterparties.update  — обновить
 * counterparties.delete  — удалить (soft)
 * counterparties.stats   — статистика
 */
class ERP_Counterparties {

    public function list(): array {
        $pdo = DB::get();
        $where = ['c.is_active = 1'];
        $params = [];

        if ($type = param('type')) {
            $where[] = 'c.type = ?';
            $params[] = $type;
        }
        if ($q = param('q')) {
            $where[] = '(c.name LIKE ? OR c.alias LIKE ? OR c.synonyms LIKE ? OR c.inn LIKE ?)';
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        if ($country = param('country')) {
            $where[] = 'c.country = ?';
            $params[] = $country;
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 50)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_counterparties c WHERE {$whereSQL}");
        $total->execute($params);
        $totalCount = (int) $total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM erp_products WHERE counterparty_id = c.id AND is_active = 1) as product_count
            FROM erp_counterparties c
            WHERE {$whereSQL}
            ORDER BY c.name ASC
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
        $stmt = $pdo->prepare("SELECT * FROM erp_counterparties WHERE id = ?");
        $stmt->execute([$id]);
        $cp = $stmt->fetch();
        if (!$cp) errorResponse('Not found', 404);

        // Товары контрагента
        $products = $pdo->prepare("
            SELECT p.id, p.sku, p.alias, p.short_name, p.name, p.brand, p.product_code, p.article, p.supplier, p.supplier_product_name, p.sell_price, p.purchase_price, p.image_url,
                   COALESCE((SELECT SUM(i.quantity) FROM erp_inventory i WHERE i.product_id = p.id), 0) as stock
            FROM erp_products p
            WHERE p.counterparty_id = ? AND p.is_active = 1
            ORDER BY p.name
        ");
        $products->execute([$id]);
        $cp['products'] = $products->fetchAll();

        // Финансовые транзакции
        $txns = $pdo->prepare("
            SELECT ft.id, ft.date, ft.type, ft.amount, ft.currency, ft.category, ft.description,
                   fa.name as account_name
            FROM erp_finance_transactions ft
            LEFT JOIN erp_finance_accounts fa ON fa.id = ft.account_id
            WHERE ft.counterparty_id = ?
            ORDER BY ft.date DESC
            LIMIT 50
        ");
        $txns->execute([$id]);
        $cp['transactions'] = $txns->fetchAll();

        $cp['balance'] = (float) ($cp['balance'] ?? 0);

        // Поставки
        $supplies = $pdo->prepare("
            SELECT id, number, status, created_at,
                   (SELECT COALESCE(SUM(si.quantity * si.unit_price), 0) FROM erp_supply_items si WHERE si.supply_id = s.id) as total_amount
            FROM erp_supplies s WHERE s.counterparty_id = ?
            ORDER BY s.created_at DESC LIMIT 20
        ");
        $supplies->execute([$id]);
        $cp['supplies'] = $supplies->fetchAll();

        return $cp;
    }

    public function create(): array {
        $input = jsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) errorResponse('name обязательно');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_counterparties (name, type, alias, inn, phone, email, website, country, address, notes, synonyms, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $input['type'] ?? 'both',
            trim($input['alias'] ?? '') ?: null,
            $input['inn'] ?? null,
            $input['phone'] ?? null,
            $input['email'] ?? null,
            $input['website'] ?? null,
            $input['country'] ?? null,
            $input['address'] ?? null,
            $input['notes'] ?? null,
            trim($input['synonyms'] ?? '') ?: null,
            $input['currency'] ?? 'RUB',
        ]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['name', 'type', 'alias', 'synonyms', 'inn', 'phone', 'email', 'website', 'country', 'address', 'notes', 'currency', 'is_active'];
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
        $pdo->prepare("UPDATE erp_counterparties SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['ok' => true, 'id' => $id];
    }

    public function delete(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_counterparties SET is_active = 0 WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    public function stats(): array {
        $pdo = DB::get();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM erp_counterparties WHERE is_active = 1")->fetchColumn();
        $byType = $pdo->query("
            SELECT type, COUNT(*) as cnt FROM erp_counterparties WHERE is_active = 1 GROUP BY type
        ")->fetchAll();
        $withProducts = (int) $pdo->query("
            SELECT COUNT(DISTINCT c.id) FROM erp_counterparties c
            INNER JOIN erp_products p ON p.counterparty_id = c.id AND p.is_active = 1
            WHERE c.is_active = 1
        ")->fetchColumn();
        $totalBalance = (float) $pdo->query("
            SELECT COALESCE(SUM(balance), 0) FROM erp_counterparties WHERE is_active = 1
        ")->fetchColumn();

        return [
            'total' => $total,
            'by_type' => $byType,
            'with_products' => $withProducts,
            'total_balance' => $totalBalance,
        ];
    }

    /**
     * Пересчитать баланс контрагента из транзакций
     */
    public static function recalcBalance(PDO $pdo, int $counterpartyId): void {
        if (!$counterpartyId) return;
        $stmt = $pdo->prepare("
            SELECT COALESCE(
                SUM(CASE WHEN ft.type = 'expense' THEN ft.amount ELSE 0 END) -
                SUM(CASE WHEN ft.type = 'income' THEN ft.amount ELSE 0 END), 0)
            FROM erp_finance_transactions ft WHERE ft.counterparty_id = ? AND ft.status = 'confirmed'
        ");
        $stmt->execute([$counterpartyId]);
        $balance = $stmt->fetchColumn();
        $pdo->prepare("UPDATE erp_counterparties SET balance = ? WHERE id = ?")->execute([$balance, $counterpartyId]);
    }

    /**
     * Пересчитать все балансы (API endpoint)
     */
    public function recalc_balances(): array {
        $pdo = DB::get();

        // Убеждаемся что колонка balance существует
        $check = $pdo->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'erp_counterparties'
              AND COLUMN_NAME = 'balance'
        ");
        if ((int) $check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE erp_counterparties ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        }

        // Сначала обнуляем все
        $pdo->exec("UPDATE erp_counterparties SET balance = 0");
        // Обновляем через JOIN
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
        $total = (int) $pdo->query("SELECT COUNT(*) FROM erp_counterparties WHERE balance != 0")->fetchColumn();
        return ['ok' => true, 'updated' => $total];
    }
}
