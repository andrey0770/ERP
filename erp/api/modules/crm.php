<?php
/**
 * ERP Module: CRM — Управление контактами и взаимодействиями
 * 
 * crm.contacts         — список контактов
 * crm.contact_get      — один контакт (с историей)
 * crm.contact_create   — создать контакт
 * crm.contact_update   — обновить контакт
 * crm.contact_delete   — удалить (soft)
 * crm.interactions     — история взаимодействий (фильтры)
 * crm.interaction_create — новое взаимодействие
 * crm.interaction_update — обновить
 * crm.search           — поиск по контактам и контрагентам
 * crm.stats            — статистика CRM
 * crm.upcoming         — предстоящие действия (next_action_date)
 */
class ERP_Crm {

    // ═══════════════════════════════════════════════════
    // КОНТАКТЫ
    // ═══════════════════════════════════════════════════

    /**
     * Список контактов
     * ?q=иванов&source=ozon&limit=50&offset=0
     */
    public function contacts(): array {
        $pdo = DB::get();
        $where = ['c.is_active = 1'];
        $params = [];

        if ($q = param('q')) {
            $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $like = "%{$q}%";
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        if ($source = param('source')) {
            $where[] = 'c.source = ?';
            $params[] = $source;
        }
        if ($cpId = param('counterparty_id')) {
            $where[] = 'c.counterparty_id = ?';
            $params[] = (int) $cpId;
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 50)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_contacts c WHERE {$whereSQL}");
        $total->execute($params);
        $totalCount = (int) $total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT c.*, 
                   cp.name as counterparty_name,
                   cp.type as counterparty_type,
                   (SELECT COUNT(*) FROM erp_interactions WHERE contact_id = c.id) as interaction_count,
                   (SELECT MAX(created_at) FROM erp_interactions WHERE contact_id = c.id) as last_interaction
            FROM erp_contacts c
            LEFT JOIN erp_counterparties cp ON cp.id = c.counterparty_id
            WHERE {$whereSQL}
            ORDER BY c.updated_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['tags'] = $r['tags'] ? json_decode($r['tags'], true) : [];
        }

        return [
            'items'  => $rows,
            'total'  => $totalCount,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Один контакт с полной историей взаимодействий
     * ?id=5
     */
    public function contact_get(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            SELECT c.*, cp.name as counterparty_name, cp.type as counterparty_type
            FROM erp_contacts c
            LEFT JOIN erp_counterparties cp ON cp.id = c.counterparty_id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $contact = $stmt->fetch();
        if (!$contact) errorResponse('Not found', 404);

        $contact['tags'] = $contact['tags'] ? json_decode($contact['tags'], true) : [];

        // Последние 50 взаимодействий
        $inter = $pdo->prepare("
            SELECT * FROM erp_interactions 
            WHERE contact_id = ?
            ORDER BY created_at DESC LIMIT 50
        ");
        $inter->execute([$id]);
        $contact['interactions'] = $inter->fetchAll();

        return $contact;
    }

    /**
     * Создать контакт
     * POST: { "first_name": "Иван", "last_name": "Петров", "company": "ООО Ромашка", "phone": "+7...", ... }
     */
    public function contact_create(): array {
        $input = jsonInput();
        $firstName = trim($input['first_name'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        if (!$firstName && !$lastName && !($input['company'] ?? '')) {
            errorResponse('first_name, last_name или company обязательны');
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_contacts 
                (counterparty_id, first_name, last_name, company, position, phone, phone2, email, telegram, whatsapp, address, source, tags, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['counterparty_id'] ?? null,
            $firstName ?: null,
            $lastName ?: null,
            $input['company'] ?? null,
            $input['position'] ?? null,
            $input['phone'] ?? null,
            $input['phone2'] ?? null,
            $input['email'] ?? null,
            $input['telegram'] ?? null,
            $input['whatsapp'] ?? null,
            $input['address'] ?? null,
            $input['source'] ?? null,
            json_encode($input['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            $input['notes'] ?? null,
        ]);

        $id = (int) $pdo->lastInsertId();

        // Если есть первичное взаимодействие — создаём
        if (!empty($input['initial_note'])) {
            $pdo->prepare("
                INSERT INTO erp_interactions (contact_id, type, content, direction)
                VALUES (?, 'note', ?, 'internal')
            ")->execute([$id, $input['initial_note']]);
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Обновить контакт
     */
    public function contact_update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['counterparty_id', 'first_name', 'last_name', 'company', 'position', 'phone', 'phone2', 'email', 'telegram', 'whatsapp', 'address', 'source', 'notes', 'is_active'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "`{$field}` = ?";
                $params[] = $input[$field];
            }
        }
        if (array_key_exists('tags', $input)) {
            $sets[] = "tags = ?";
            $params[] = json_encode($input['tags'], JSON_UNESCAPED_UNICODE);
        }
        if (empty($sets)) errorResponse('No fields to update');
        $params[] = $id;

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_contacts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Soft-удаление контакта
     */
    public function contact_delete(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_contacts SET is_active = 0 WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    // ═══════════════════════════════════════════════════
    // ВЗАИМОДЕЙСТВИЯ
    // ═══════════════════════════════════════════════════

    /**
     * Список взаимодействий с фильтрами
     * ?contact_id=5&type=call&from=2026-01-01&limit=100
     */
    public function interactions(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($cid = param('contact_id')) {
            $where[] = 'i.contact_id = ?';
            $params[] = (int) $cid;
        }
        if ($cpId = param('counterparty_id')) {
            $where[] = 'i.counterparty_id = ?';
            $params[] = (int) $cpId;
        }
        if ($type = param('type')) {
            $where[] = 'i.type = ?';
            $params[] = $type;
        }
        if ($from = param('from')) {
            $where[] = 'i.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to = param('to')) {
            $where[] = 'i.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 100)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_interactions i WHERE {$whereSQL}");
        $total->execute($params);

        $stmt = $pdo->prepare("
            SELECT i.*,
                   CONCAT_WS(' ', c.first_name, c.last_name) as contact_name,
                   c.company as contact_company,
                   cp.name as counterparty_name
            FROM erp_interactions i
            LEFT JOIN erp_contacts c ON c.id = i.contact_id
            LEFT JOIN erp_counterparties cp ON cp.id = i.counterparty_id
            WHERE {$whereSQL}
            ORDER BY i.created_at DESC
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

    /**
     * Создать взаимодействие
     * POST: { "contact_id": 5, "type": "call", "direction": "outgoing", "subject": "Обсуждение заказа", "content": "...", "result": "Договорились", "next_action": "Перезвонить", "next_action_date": "2026-03-10" }
     */
    public function interaction_create(): array {
        $input = jsonInput();
        if (empty($input['contact_id']) && empty($input['counterparty_id'])) {
            errorResponse('contact_id или counterparty_id обязателен');
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_interactions 
                (journal_id, contact_id, counterparty_id, type, direction, subject, content, result, next_action, next_action_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['journal_id'] ?? null,
            $input['contact_id'] ?? null,
            $input['counterparty_id'] ?? null,
            $input['type'] ?? 'note',
            $input['direction'] ?? 'outgoing',
            $input['subject'] ?? null,
            $input['content'] ?? null,
            $input['result'] ?? null,
            $input['next_action'] ?? null,
            $input['next_action_date'] ?? null,
        ]);

        $id = (int) $pdo->lastInsertId();

        // Обновляем updated_at контакта
        if ($input['contact_id'] ?? null) {
            $pdo->prepare("UPDATE erp_contacts SET updated_at = NOW() WHERE id = ?")
                ->execute([$input['contact_id']]);
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Обновить взаимодействие
     */
    public function interaction_update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $allowed = ['type', 'direction', 'subject', 'content', 'result', 'next_action', 'next_action_date'];
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
        $pdo->prepare("UPDATE erp_interactions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['ok' => true, 'id' => $id];
    }

    // ═══════════════════════════════════════════════════
    // ПОИСК, СТАТИСТИКА, UPCOMING
    // ═══════════════════════════════════════════════════

    /**
     * Поиск по контактам + контрагентам
     * ?q=петров
     */
    public function search(): array {
        $q = param('q', '');
        if (mb_strlen($q) < 2) errorResponse('query too short (min 2 chars)');
        $like = "%{$q}%";

        $pdo = DB::get();

        // Контакты
        $contacts = $pdo->prepare("
            SELECT c.id, 'contact' as entity_type,
                   CONCAT_WS(' ', c.first_name, c.last_name) as name,
                   c.company, c.phone, c.email
            FROM erp_contacts c
            WHERE c.is_active = 1 
              AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)
            LIMIT 20
        ");
        $contacts->execute([$like, $like, $like, $like, $like]);

        // Контрагенты
        $cps = $pdo->prepare("
            SELECT id, 'counterparty' as entity_type, name, '' as company, phone, email
            FROM erp_counterparties
            WHERE is_active = 1 AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR inn LIKE ?)
            LIMIT 20
        ");
        $cps->execute([$like, $like, $like, $like]);

        return [
            'contacts'       => $contacts->fetchAll(),
            'counterparties' => $cps->fetchAll(),
            'query'          => $q,
        ];
    }

    /**
     * Предстоящие действия (по next_action_date)
     * ?days=7
     */
    public function upcoming(): array {
        $days = (int)(param('days', 7));
        $pdo = DB::get();

        $stmt = $pdo->prepare("
            SELECT i.*, 
                   CONCAT_WS(' ', c.first_name, c.last_name) as contact_name,
                   c.company as contact_company,
                   c.phone as contact_phone,
                   cp.name as counterparty_name
            FROM erp_interactions i
            LEFT JOIN erp_contacts c ON c.id = i.contact_id
            LEFT JOIN erp_counterparties cp ON cp.id = i.counterparty_id
            WHERE i.next_action IS NOT NULL 
              AND i.next_action_date IS NOT NULL
              AND i.next_action_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY i.next_action_date ASC
            LIMIT 50
        ");
        $stmt->execute([$days]);

        // Просроченные
        $overdue = $pdo->query("
            SELECT i.*, 
                   CONCAT_WS(' ', c.first_name, c.last_name) as contact_name,
                   c.company as contact_company,
                   cp.name as counterparty_name
            FROM erp_interactions i
            LEFT JOIN erp_contacts c ON c.id = i.contact_id
            LEFT JOIN erp_counterparties cp ON cp.id = i.counterparty_id
            WHERE i.next_action IS NOT NULL 
              AND i.next_action_date IS NOT NULL
              AND i.next_action_date < CURDATE()
            ORDER BY i.next_action_date ASC
            LIMIT 20
        ")->fetchAll();

        return [
            'upcoming' => $stmt->fetchAll(),
            'overdue'  => $overdue,
        ];
    }

    /**
     * Статистика CRM
     */
    public function stats(): array {
        $pdo = DB::get();

        $totalContacts = (int) $pdo->query("SELECT COUNT(*) FROM erp_contacts WHERE is_active=1")->fetchColumn();
        $totalInteractions = (int) $pdo->query("SELECT COUNT(*) FROM erp_interactions")->fetchColumn();

        $byType = $pdo->query("
            SELECT type, COUNT(*) as cnt FROM erp_interactions GROUP BY type ORDER BY cnt DESC
        ")->fetchAll();

        $bySource = $pdo->query("
            SELECT COALESCE(source, 'не указан') as source, COUNT(*) as cnt 
            FROM erp_contacts WHERE is_active=1 GROUP BY source ORDER BY cnt DESC
        ")->fetchAll();

        $recentActivity = $pdo->query("
            SELECT DATE(created_at) as day, COUNT(*) as cnt
            FROM erp_interactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY day ORDER BY day
        ")->fetchAll();

        $overdueActions = (int) $pdo->query("
            SELECT COUNT(*) FROM erp_interactions 
            WHERE next_action IS NOT NULL AND next_action_date < CURDATE()
        ")->fetchColumn();

        $totalCounterparties = (int) $pdo->query("SELECT COUNT(*) FROM erp_counterparties WHERE is_active=1")->fetchColumn();

        return [
            'total_contacts'       => $totalContacts,
            'total_counterparties' => $totalCounterparties,
            'total_interactions'   => $totalInteractions,
            'overdue_actions'      => $overdueActions,
            'interactions_by_type' => $byType,
            'contacts_by_source'   => $bySource,
            'recent_activity'      => $recentActivity,
        ];
    }
}
