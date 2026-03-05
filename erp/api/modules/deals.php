<?php
/**
 * ERP Module: Сделки (Deals / Sales Pipeline)
 * 
 * deals.list    — список сделок
 * deals.get     — деталь сделки
 * deals.create  — новая сделка
 * deals.update  — обновить сделку
 * deals.delete  — удалить сделку
 * deals.stats   — статистика воронки
 */
class ERP_Deals {

    /**
     * Список сделок
     * ?stage=new&q=поиск&limit=100
     */
    public function list(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($stage = param('stage')) {
            $where[] = 'd.stage = ?';
            $params[] = $stage;
        }
        if ($q = param('q')) {
            $where[] = '(d.title LIKE ? OR d.description LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ?)';
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $whereSQL = implode(' AND ', $where);
        $limit = min((int)(param('limit', 100)), 500);

        $stmt = $pdo->prepare("
            SELECT d.*,
                   COALESCE(CONCAT_WS(' ', c.first_name, c.last_name), '') as contact_name,
                   c.company
            FROM erp_deals d
            LEFT JOIN erp_contacts c ON c.id = d.contact_id
            WHERE {$whereSQL}
            ORDER BY FIELD(d.stage, 'new', 'qualifying', 'proposal', 'negotiation', 'won', 'lost'), d.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_deals d LEFT JOIN erp_contacts c ON c.id = d.contact_id WHERE {$whereSQL}");
        $total->execute($params);

        return ['items' => $items, 'total' => (int)$total->fetchColumn()];
    }

    /**
     * Получить сделку по ID
     */
    public function get(): array {
        $id = (int)param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            SELECT d.*,
                   COALESCE(CONCAT_WS(' ', c.first_name, c.last_name), '') as contact_name,
                   c.company, c.phone as contact_phone, c.email as contact_email
            FROM erp_deals d
            LEFT JOIN erp_contacts c ON c.id = d.contact_id
            WHERE d.id = ?
        ");
        $stmt->execute([$id]);
        $deal = $stmt->fetch();
        if (!$deal) errorResponse('Deal not found', 404);

        return $deal;
    }

    /**
     * Создать сделку
     * POST: { title, contact_id, amount, stage, assignee, description }
     */
    public function create(): array {
        $input = jsonInput();
        $title = trim($input['title'] ?? '');
        if (!$title) errorResponse('title required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_deals (title, contact_id, amount, stage, assignee, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $title,
            ($input['contact_id'] ?? null) ?: null,
            (float)($input['amount'] ?? 0),
            $input['stage'] ?? 'new',
            $input['assignee'] ?? null,
            $input['description'] ?? null,
        ]);

        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }

    /**
     * Обновить сделку
     * POST: { id, stage?, amount?, title?, assignee?, description?, won_at?, lost_reason? }
     */
    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? 0);
        if (!$id) errorResponse('id required');

        $fields = [];
        $params = [];
        $allowed = ['title', 'contact_id', 'amount', 'stage', 'assignee', 'description', 'lost_reason'];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }

        // Auto-set won_at / closed
        if (isset($input['stage'])) {
            if ($input['stage'] === 'won') {
                $fields[] = 'won_at = NOW()';
            } elseif ($input['stage'] === 'lost') {
                $fields[] = 'won_at = NULL';
            }
        }

        if (empty($fields)) errorResponse('No fields to update');
        $params[] = $id;

        $pdo = DB::get();
        $sql = "UPDATE erp_deals SET " . implode(', ', $fields) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);

        return ['ok' => true];
    }

    /**
     * Удалить сделку
     */
    public function delete(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("DELETE FROM erp_deals WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    /**
     * Статистика воронки
     */
    public function stats(): array {
        $pdo = DB::get();

        $byStage = $pdo->query("
            SELECT stage, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
            FROM erp_deals
            GROUP BY stage
        ")->fetchAll();

        $stats = ['new' => 0, 'qualifying' => 0, 'proposal' => 0, 'negotiation' => 0, 'won' => 0, 'lost' => 0, 'pipeline_amount' => 0, 'total' => 0];
        $inProgress = 0;

        foreach ($byStage as $row) {
            $stats[$row['stage']] = (int)$row['cnt'];
            $stats['total'] += (int)$row['cnt'];
            if (!in_array($row['stage'], ['won', 'lost'])) {
                $stats['pipeline_amount'] += (float)$row['total'];
                $inProgress += (int)$row['cnt'];
            }
        }
        $stats['in_progress'] = $inProgress;

        return $stats;
    }
}
