<?php
require_once __DIR__ . '/counterparties.php';

/**
 * ERP Module: Финансы
 * 
 * finance.list          — транзакции (с фильтрами)
 * finance.get           — одна транзакция
 * finance.create        — новая транзакция
 * finance.update        — редактировать
 * finance.delete        — удалить
 * finance.accounts      — список счетов
 * finance.account_create — новый счёт
 * finance.summary       — сводка (доходы/расходы/баланс)
 * finance.cashflow      — денежный поток по дням
 * finance.link          — связать транзакции (перевод)
 * finance.unlink        — разъединить транзакции
 */
class ERP_Finance {

    public function list(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($type = param('type')) {
            $where[] = 'ft.type = ?';
            $params[] = $type;
        }
        if ($cat = param('category')) {
            $where[] = 'ft.category = ?';
            $params[] = $cat;
        }
        if ($accId = param('account_id')) {
            $where[] = '(ft.account_id = ? OR ft.to_account_id = ?)';
            $params[] = (int) $accId;
            $params[] = (int) $accId;
        }
        if ($from = param('from')) {
            $where[] = 'ft.date >= ?';
            $params[] = $from;
        }
        if ($to = param('to')) {
            $where[] = 'ft.date <= ?';
            $params[] = $to;
        }
        if ($q = param('q')) {
            $where[] = '(ft.description LIKE ? OR ft.counterparty LIKE ? OR ft.category LIKE ?)';
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 100)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_finance_transactions ft WHERE {$whereSQL}");
        $total->execute($params);

        $stmt = $pdo->prepare("
            SELECT ft.*, 
                   fa.name as account_name,
                   fa2.name as to_account_name,
                   cp.name as counterparty_name,
                   cp.alias as counterparty_alias
            FROM erp_finance_transactions ft
            LEFT JOIN erp_finance_accounts fa ON fa.id = ft.account_id
            LEFT JOIN erp_finance_accounts fa2 ON fa2.id = ft.to_account_id
            LEFT JOIN erp_counterparties cp ON cp.id = ft.counterparty_id
            WHERE {$whereSQL}
            ORDER BY ft.date DESC, ft.id DESC
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
            SELECT ft.*, fa.name as account_name, fa2.name as to_account_name
            FROM erp_finance_transactions ft
            LEFT JOIN erp_finance_accounts fa ON fa.id = ft.account_id
            LEFT JOIN erp_finance_accounts fa2 ON fa2.id = ft.to_account_id
            WHERE ft.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Not found', 404);

        return $row;
    }

    public function create(): array {
        $input = jsonInput();
        $type   = $input['type'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $date   = $input['date'] ?? date('Y-m-d');

        if (!in_array($type, ['income', 'expense', 'transfer'])) {
            errorResponse('type must be income/expense/transfer');
        }
        if ($amount <= 0) errorResponse('amount must be positive');

        $pdo = DB::get();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO erp_finance_transactions 
                    (journal_id, date, type, amount, currency, account_id, to_account_id, dest_amount, dest_currency, category, subcategory, counterparty, counterparty_id, description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $input['journal_id'] ?? null,
                $date,
                $type,
                $amount,
                $input['currency'] ?? 'RUB',
                $input['account_id'] ?? null,
                $input['to_account_id'] ?? null,
                $input['dest_amount'] ?? null,
                $input['dest_currency'] ?? null,
                $input['category'] ?? null,
                $input['subcategory'] ?? null,
                $input['counterparty'] ?? null,
                $input['counterparty_id'] ?? null,
                $input['description'] ?? null,
            ]);
            $id = (int) $pdo->lastInsertId();

            // Обновляем балансы счетов
            $this->updateAccountBalance($pdo, $type, $amount, $input);

            // Обновляем баланс контрагента
            $cpId = (int)($input['counterparty_id'] ?? 0);
            if ($cpId) ERP_Counterparties::recalcBalance($pdo, $cpId);

            $pdo->commit();
            return ['ok' => true, 'id' => $id];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['date', 'type', 'amount', 'currency', 'account_id', 'to_account_id', 'dest_amount', 'dest_currency', 'category', 'subcategory', 'counterparty', 'counterparty_id', 'description', 'linked_id'];
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
        // Запоминаем старый counterparty_id
        $oldCp = $pdo->prepare("SELECT counterparty_id FROM erp_finance_transactions WHERE id = ?");
        $oldCp->execute([$id]);
        $oldCpId = (int) ($oldCp->fetchColumn() ?: 0);

        $pdo->prepare("UPDATE erp_finance_transactions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        // Пересчитываем баланс контрагента (старого и нового)
        $newCpId = (int)($input['counterparty_id'] ?? $oldCpId);
        if ($oldCpId) ERP_Counterparties::recalcBalance($pdo, $oldCpId);
        if ($newCpId && $newCpId !== $oldCpId) ERP_Counterparties::recalcBalance($pdo, $newCpId);

        return ['ok' => true, 'id' => $id];
    }

    public function delete(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        // Запоминаем counterparty_id перед удалением
        $old = $pdo->prepare("SELECT counterparty_id FROM erp_finance_transactions WHERE id = ?");
        $old->execute([$id]);
        $cpId = (int) ($old->fetchColumn() ?: 0);

        $pdo->prepare("DELETE FROM erp_finance_transactions WHERE id = ?")->execute([$id]);

        // Пересчитываем баланс контрагента
        if ($cpId) ERP_Counterparties::recalcBalance($pdo, $cpId);

        return ['ok' => true];
    }

    /**
     * Список финансовых счетов
     */
    public function accounts(): array {
        $pdo = DB::get();
        $rows = $pdo->query("
            SELECT fa.*, 
                   (SELECT COUNT(*) FROM erp_finance_transactions WHERE account_id = fa.id) as tx_count
            FROM erp_finance_accounts fa 
            WHERE fa.is_active = 1 
            ORDER BY fa.name
        ")->fetchAll();

        return ['items' => $rows];
    }

    /**
     * Создать счёт
     */
    public function account_create(): array {
        $input = jsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) errorResponse('name required');

        $pdo = DB::get();
        $pdo->prepare("INSERT INTO erp_finance_accounts (name, type, currency, balance) VALUES (?, ?, ?, ?)")
            ->execute([
                $name,
                $input['type'] ?? 'bank',
                $input['currency'] ?? 'RUB',
                $input['balance'] ?? 0,
            ]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Сводка: доходы, расходы, баланс за период
     * ?from=2026-01-01&to=2026-03-05
     */
    public function summary(): array {
        $pdo = DB::get();
        $from = param('from', date('Y-m-01'));
        $to   = param('to', date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT type, 
                   SUM(amount) as total_amount,
                   COUNT(*) as count
            FROM erp_finance_transactions
            WHERE date BETWEEN ? AND ?
            GROUP BY type
        ");
        $stmt->execute([$from, $to]);
        $byType = [];
        foreach ($stmt->fetchAll() as $row) {
            $byType[$row['type']] = ['amount' => (float) $row['total_amount'], 'count' => (int) $row['count']];
        }

        $income  = $byType['income']['amount'] ?? 0;
        $expense = $byType['expense']['amount'] ?? 0;

        // Топ категорий расходов
        $topExpenses = $pdo->prepare("
            SELECT category, SUM(amount) as total, COUNT(*) as cnt
            FROM erp_finance_transactions
            WHERE type = 'expense' AND date BETWEEN ? AND ? AND category IS NOT NULL
            GROUP BY category
            ORDER BY total DESC
            LIMIT 10
        ");
        $topExpenses->execute([$from, $to]);

        // Балансы счетов
        $accounts = $pdo->query("SELECT name, balance, currency FROM erp_finance_accounts WHERE is_active=1")->fetchAll();

        return [
            'period'       => ['from' => $from, 'to' => $to],
            'income'       => $income,
            'expense'      => $expense,
            'profit'       => $income - $expense,
            'by_type'      => $byType,
            'top_expenses' => $topExpenses->fetchAll(),
            'accounts'     => $accounts,
        ];
    }

    /**
     * Денежный поток по дням
     * ?from=2026-01-01&to=2026-03-05
     */
    public function cashflow(): array {
        $pdo = DB::get();
        $from = param('from', date('Y-m-d', strtotime('-30 days')));
        $to   = param('to', date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT date,
                   SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as income,
                   SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as expense
            FROM erp_finance_transactions
            WHERE date BETWEEN ? AND ?
            GROUP BY date
            ORDER BY date
        ");
        $stmt->execute([$from, $to]);

        return ['period' => ['from' => $from, 'to' => $to], 'items' => $stmt->fetchAll()];
    }

    /**
     * Связать транзакции в группу (перевод)
     * POST {ids: [1, 2]} — массив ID транзакций для связывания
     */
    public function link(): array {
        $input = jsonInput();
        $ids = $input['ids'] ?? [];
        if (count($ids) < 2) errorResponse('Need at least 2 transaction IDs');
        $ids = array_map('intval', $ids);

        // linked_id = минимальный ID в группе
        $linkedId = min($ids);
        $pdo = DB::get();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE erp_finance_transactions SET linked_id = ? WHERE id IN ({$placeholders})")
            ->execute(array_merge([$linkedId], $ids));

        return ['ok' => true, 'linked_id' => $linkedId];
    }

    /**
     * Разъединить транзакцию из группы
     */
    public function unlink(): array {
        $id = (int)(param('id') ?: (jsonInput()['id'] ?? 0));
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_finance_transactions SET linked_id = NULL WHERE id = ?")->execute([$id]);

        return ['ok' => true];
    }

    // ── Private ─────────────────────────────────────────

    private function updateAccountBalance(PDO $pdo, string $type, float $amount, array $input): void {
        $accId   = $input['account_id'] ?? null;
        $toAccId = $input['to_account_id'] ?? null;

        if ($type === 'income' && $accId) {
            $pdo->prepare("UPDATE erp_finance_accounts SET balance = balance + ? WHERE id = ?")
                ->execute([$amount, $accId]);
        } elseif ($type === 'expense' && $accId) {
            $pdo->prepare("UPDATE erp_finance_accounts SET balance = balance - ? WHERE id = ?")
                ->execute([$amount, $accId]);
        } elseif ($type === 'transfer') {
            // Списываем source amount с источника
            if ($accId) {
                $pdo->prepare("UPDATE erp_finance_accounts SET balance = balance - ? WHERE id = ?")
                    ->execute([$amount, $accId]);
            }
            // Зачисляем dest_amount (или amount если нет) на назначение
            if ($toAccId) {
                $destAmount = (float)($input['dest_amount'] ?? $amount);
                $pdo->prepare("UPDATE erp_finance_accounts SET balance = balance + ? WHERE id = ?")
                    ->execute([$destAmount, $toAccId]);
            }
        }
    }
}
