<?php
/**
 * ERP Module: Общий журнал операций
 * 
 * Центральный лог — каждое действие (транзакция, задача, поступление)
 * сначала попадает сюда, потом AI разбирает и пишет в специализированные таблицы.
 * 
 * journal.list    — список записей (с фильтрами)
 * journal.get     — одна запись
 * journal.create  — новая запись + AI-разбор
 * journal.search  — полнотекстовый поиск
 * journal.stats   — статистика по категориям/датам
 */
class ERP_Journal {

    /**
     * Список записей с пагинацией и фильтрами
     * ?limit=50&offset=0&category=finance&from=2026-01-01&to=2026-03-05
     */
    public function list(): array {
        $pdo = DB::get();
        $where = ['1=1'];
        $params = [];

        if ($cat = param('category')) {
            $where[] = 'category = ?';
            $params[] = $cat;
        }
        if ($from = param('from')) {
            $where[] = 'created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to = param('to')) {
            $where[] = 'created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if ($source = param('source')) {
            $where[] = 'source = ?';
            $params[] = $source;
        }

        $whereSQL = implode(' AND ', $where);
        $limit  = min((int)(param('limit', 50)), 500);
        $offset = max((int)(param('offset', 0)), 0);

        $total = $pdo->prepare("SELECT COUNT(*) FROM erp_journal WHERE {$whereSQL}");
        $total->execute($params);
        $totalCount = (int) $total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, created_at, source, user_name, 
                   LEFT(raw_text, 200) as raw_text_preview, 
                   category, tags
            FROM erp_journal 
            WHERE {$whereSQL} 
            ORDER BY created_at DESC 
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Decode JSON fields
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
     * Одна запись по ID (полная)
     * ?id=123
     */
    public function get(): array {
        $id = (int) param('id');
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("SELECT * FROM erp_journal WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) errorResponse('Not found', 404);

        $row['ai_parsed'] = $row['ai_parsed'] ? json_decode($row['ai_parsed'], true) : null;
        $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];

        return $row;
    }

    /**
     * Создать запись в журнале
     * POST: { "text": "Оплатил поставщику 50000р за бильярдные шары", "source": "web" }
     * 
     * После создания — отправляет на AI-разбор (если настроен)
     */
    public function create(): array {
        $input = jsonInput();
        $text = trim($input['text'] ?? '');
        if (!$text) errorResponse('text is required');

        $source   = $input['source'] ?? 'web';
        $userName = $input['user_name'] ?? null;
        $category = $input['category'] ?? null;
        $tags     = $input['tags'] ?? [];

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_journal (raw_text, source, user_name, category, tags)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $text,
            $source,
            $userName,
            $category,
            json_encode($tags, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) $pdo->lastInsertId();

        // Попытка AI-разбора (не блокирует создание)
        $aiResult = null;
        try {
            require_once __DIR__ . '/ai.php';
            $ai = new ERP_Ai();
            $aiResult = $ai->analyzeJournalEntry($id, $text);
        } catch (Exception $e) {
            // AI недоступен — ок, запись создана
            $aiResult = ['error' => $e->getMessage()];
        }

        return [
            'ok'        => true,
            'id'        => $id,
            'ai_parsed' => $aiResult,
        ];
    }

    /**
     * Полнотекстовый поиск
     * ?q=бильярдные+шары
     */
    public function search(): array {
        $q = param('q', '');
        if (mb_strlen($q) < 2) errorResponse('query too short (min 2 chars)');

        $pdo = DB::get();
        $limit = min((int)(param('limit', 50)), 200);

        $stmt = $pdo->prepare("
            SELECT id, created_at, source, user_name,
                   LEFT(raw_text, 200) as raw_text_preview,
                   category, tags,
                   MATCH(raw_text) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
            FROM erp_journal
            WHERE MATCH(raw_text) AGAINST(? IN NATURAL LANGUAGE MODE)
            ORDER BY relevance DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$q, $q]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['tags'] = $r['tags'] ? json_decode($r['tags'], true) : [];
        }

        return ['items' => $rows, 'query' => $q];
    }

    /**
     * Статистика журнала
     */
    public function stats(): array {
        $pdo = DB::get();

        $byCategory = $pdo->query("
            SELECT COALESCE(category, 'uncategorized') as category, COUNT(*) as cnt
            FROM erp_journal GROUP BY category ORDER BY cnt DESC
        ")->fetchAll();

        $byDay = $pdo->query("
            SELECT DATE(created_at) as day, COUNT(*) as cnt
            FROM erp_journal
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY day ORDER BY day
        ")->fetchAll();

        $bySource = $pdo->query("
            SELECT source, COUNT(*) as cnt
            FROM erp_journal GROUP BY source ORDER BY cnt DESC
        ")->fetchAll();

        return [
            'by_category' => $byCategory,
            'by_day'      => $byDay,
            'by_source'   => $bySource,
            'total'       => (int) $pdo->query("SELECT COUNT(*) FROM erp_journal")->fetchColumn(),
        ];
    }
}
