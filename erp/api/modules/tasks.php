<?php
/**
 * ERP Module: Задачи
 * 
 * tasks.list    — список задач (с фильтрами)
 * tasks.get     — одна задача
 * tasks.create  — создать
 * tasks.update  — обновить
 * tasks.done    — отметить выполненной
 * tasks.stats   — статистика
 */
class ERP_Tasks {

    public function list(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($status = param('status')) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($priority = param('priority')) {
            $where[] = 'priority = ?';
            $params[] = $priority;
        }
        if ($assignee = param('assignee')) {
            $where[] = 'assignee = ?';
            $params[] = $assignee;
        }
        $overdue = param('overdue');
        if ($overdue) {
            $where[] = "due_date < CURDATE() AND status NOT IN ('done','cancelled')";
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 100)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_tasks WHERE {$whereSQL}");
        $total->execute($params);

        $stmt = $pdo->prepare("
            SELECT * FROM erp_tasks
            WHERE {$whereSQL}
            ORDER BY 
                FIELD(priority, 'urgent', 'high', 'normal', 'low'),
                CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,
                due_date ASC,
                created_at DESC
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
        $stmt = $pdo->prepare("SELECT * FROM erp_tasks WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Not found', 404);

        return $row;
    }

    public function create(): array {
        $input = jsonInput();
        $title = trim($input['title'] ?? '');
        if (!$title) errorResponse('title required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_tasks (journal_id, title, description, status, priority, assignee, due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['journal_id'] ?? null,
            $title,
            $input['description'] ?? null,
            $input['status'] ?? 'todo',
            $input['priority'] ?? 'normal',
            $input['assignee'] ?? null,
            $input['due_date'] ?? null,
        ]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['title', 'description', 'status', 'priority', 'assignee', 'due_date'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "`{$field}` = ?";
                $params[] = $input[$field];
            }
        }

        // Авто-проставляем completed_at
        if (($input['status'] ?? '') === 'done') {
            $sets[] = 'completed_at = NOW()';
        }

        if (empty($sets)) errorResponse('No fields to update');
        $params[] = $id;

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_tasks SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Быстрое завершение задачи
     * ?id=5  или POST { "id": 5 }
     */
    public function done(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_tasks SET status = 'done', completed_at = NOW() WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Статистика задач
     */
    public function stats(): array {
        $pdo = DB::get();

        $byStatus = $pdo->query("
            SELECT status, COUNT(*) as cnt FROM erp_tasks GROUP BY status
        ")->fetchAll();

        $byPriority = $pdo->query("
            SELECT priority, COUNT(*) as cnt FROM erp_tasks 
            WHERE status NOT IN ('done','cancelled') 
            GROUP BY priority
        ")->fetchAll();

        $overdue = (int) $pdo->query("
            SELECT COUNT(*) FROM erp_tasks 
            WHERE due_date < CURDATE() AND status NOT IN ('done','cancelled')
        ")->fetchColumn();

        $dueToday = (int) $pdo->query("
            SELECT COUNT(*) FROM erp_tasks 
            WHERE due_date = CURDATE() AND status NOT IN ('done','cancelled')
        ")->fetchColumn();

        return [
            'by_status'   => $byStatus,
            'by_priority' => $byPriority,
            'overdue'     => $overdue,
            'due_today'   => $dueToday,
        ];
    }
}
