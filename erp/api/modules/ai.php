<?php
/**
 * ERP Module: AI-ассистент (серверная часть)
 * 
 * ai.context  — схема БД + контекст для AI (вызывается фронтом при загрузке)
 * ai.query    — выполнить SELECT-запрос от AI
 * ai.execute  — выполнить подтверждённый план (INSERT/UPDATE/DELETE)
 * ai.schema   — вернуть схему БД (отладка)
 */
class ERP_Ai {

    private const ALLOWED_TABLES = [
        'erp_journal', 'erp_finance_accounts', 'erp_finance_transactions',
        'erp_product_categories', 'erp_products', 'erp_warehouses',
        'erp_inventory', 'erp_inventory_movements', 'erp_counterparties',
        'erp_tasks', 'erp_task_notes', 'erp_contacts', 'erp_interactions',
        'erp_deals', 'erp_supplies', 'erp_supply_items', 'erp_attachments',
    ];

    /**
     * GET — возвращает системный промпт (схема + контекст + правила)
     * Фронт вызывает при загрузке страницы AI и кеширует
     */
    public function context(): array {
        $schema = $this->getDbSchema();
        $context = $this->getQuickContext();
        $systemPrompt = $this->buildSystemPrompt($schema, $context);
        return ['ok' => true, 'system_prompt' => $systemPrompt];
    }

    /**
     * POST { "queries": [{"sql":"SELECT ...","params":[]}] }
     * Выполнить SELECT-запросы от AI
     */
    public function query(): array {
        $input = jsonInput();
        $queries = $input['queries'] ?? [];
        if (empty($queries)) errorResponse('queries required');

        $pdo = DB::get();
        $results = [];

        foreach ($queries as $q) {
            $sql = $q['sql'] ?? '';
            $params = $q['params'] ?? [];

            if (!preg_match('/^\s*SELECT\b/i', $sql)) {
                $results[] = ['description' => $q['description'] ?? '', 'error' => 'Только SELECT разрешён'];
                continue;
            }
            if (!$this->validateSql($sql)) {
                $results[] = ['description' => $q['description'] ?? '', 'error' => 'Недопустимая таблица'];
                continue;
            }

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                $results[] = [
                    'description' => $q['description'] ?? '',
                    'columns' => $rows ? array_keys($rows[0]) : [],
                    'rows' => $rows,
                    'count' => count($rows),
                ];
            } catch (PDOException $e) {
                $results[] = ['description' => $q['description'] ?? '', 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * POST { "operations": [{"sql":"INSERT ...","params":[]}] }
     * Выполнить подтверждённый план
     */
    public function execute(): array {
        $input = jsonInput();
        $operations = $input['operations'] ?? [];
        if (empty($operations)) errorResponse('operations required');

        $pdo = DB::get();
        $results = [];

        $pdo->beginTransaction();
        try {
            foreach ($operations as $op) {
                $sql = $op['sql'] ?? '';
                $params = $op['params'] ?? [];

                if (!$this->validateSql($sql)) {
                    throw new Exception("SQL заблокирован: недопустимые таблицы");
                }
                if (preg_match('/^\s*(DROP|TRUNCATE|GRANT|REVOKE)/i', $sql)) {
                    throw new Exception("DDL запрещён: " . substr($sql, 0, 50));
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results[] = [
                    'sql' => $sql,
                    'affected_rows' => $stmt->rowCount(),
                    'last_id' => $pdo->lastInsertId() ?: null,
                ];
            }
            $pdo->commit();
            return ['ok' => true, 'type' => 'executed', 'results' => $results, 'message' => 'Выполнено: ' . count($results) . ' операций'];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['ok' => false, 'type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    public function schema(): array {
        return ['ok' => true, 'schema' => $this->getDbSchema()];
    }

    // ── Private ─────────────────────────────────────────

    private function buildSystemPrompt(array $schema, array $context): string {
        $schemaText = '';
        foreach ($schema as $table => $columns) {
            $cols = [];
            foreach ($columns as $col) {
                $c = $col['name'];
                $t = $col['type'];
                if ($col['key'] === 'PRI') $c = '*' . $c;
                $cols[] = "{$c} {$t}";
            }
            $schemaText .= "{$table}(" . implode(', ', $cols) . ")\n";
        }
        $contextJSON = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Ты — AI-ассистент ERP-системы бильярдного бизнеса billiarder.ru.
Ты работаешь с MySQL базой данных. Всегда отвечай по-русски.
Ты умный, точный и лаконичный помощник. Ты знаешь SQL отлично.

== СХЕМА БАЗЫ ДАННЫХ ==
{$schemaText}
== ТЕКУЩИЙ КОНТЕКСТ ==
{$contextJSON}

== БИЗНЕС-ПРАВИЛА ==

Финансы (erp_finance_transactions):
- type='income': доход (деньги пришли в бизнес от клиента)
- type='expense': расход (деньги ушли из бизнеса поставщику/за услуги)
- type='transfer': перевод между СВОИМИ счетами. Это ОДНА запись, НЕ пара income+expense!
  account_id = откуда, to_account_id = куда
  amount + currency = сумма СПИСАНИЯ
  dest_amount + dest_currency = сумма ЗАЧИСЛЕНИЯ (если валюты разные)
- linked_id связывает цепочки операций (обмен RUB→USDT, потом USDT→CNY, потом оплата CNY→поставщик)
- Баланс контрагента = SUM(amount WHERE type='expense' AND counterparty=X) - SUM(amount WHERE type='income' AND counterparty=X)
  Положительный = мы заплатили больше (аванс). Отрицательный = мы должны.

Счета (erp_finance_accounts):
- Тинькофф (card, RUB) — основной
- USDT (crypto, USD) — крипто-кошелёк
- CNY (other, CNY) — юаневый
- Наличные (cash, RUB)
- Расчётный счёт (bank, RUB)

Контрагенты-посредники:
- Слава — обмен RUB ↔ USDT
- Nadex — обмен USDT ↔ CNY

Поставки (erp_supplies + erp_supply_items):
- status: draft, confirmed, shipped, delivered, cancelled
- supply_items связаны с supplies через supply_id и с products через product_id

Задачи (erp_tasks):
- status: todo, in_progress, done, cancelled
- priority: low, normal, high, urgent

Товары (erp_products):
- is_active=1 для активных
- category_id ссылается на erp_product_categories

== КРИТИЧЕСКИ ВАЖНЫЕ ПРАВИЛА ==

1. ВСЕГДА используй параметризованные запросы: WHERE id = ? с params:[значение]. НИКОГДА не вставляй значения прямо в SQL.
2. Используй ТОЛЬКО таблицы из схемы выше (erp_*).
3. Для чтения данных — type=query с SELECT-запросами.
4. Для изменения данных (INSERT/UPDATE/DELETE) — type=plan. Пользователь подтвердит перед выполнением.
5. В message пиши понятное человеку описание что делаешь/нашёл.
6. В queries/operations у каждого элемента пиши description — что делает этот запрос.
7. Если нужно несколько запросов — группируй их в один ответ.
8. Если не уверен что нужно пользователю — переспроси (type=text).
9. Для агрегаций используй SUM, COUNT, GROUP BY и т.д. — минимизируй кол-во возвращаемых строк.
10. Формат дат: YYYY-MM-DD. Для текущей даты используй CURDATE().

== ФОРМАТ ОТВЕТА ==

Отвечай СТРОГО валидным JSON. Без markdown, без ```json```, без пояснений вне JSON.

Чтение данных:
{"type":"query","message":"Описание что покажу","queries":[{"description":"Что делает запрос","sql":"SELECT ...","params":[]}]}

Изменение данных:
{"type":"plan","message":"Описание плана","operations":[{"description":"Что делает операция","sql":"INSERT/UPDATE/DELETE ...","params":[]}]}

Текстовый ответ:
{"type":"text","message":"Ответ пользователю"}
PROMPT;
    }

    private function getDbSchema(): array {
        $pdo = DB::get();
        $cfg = require __DIR__ . '/../config.php';
        $dbName = $cfg['db']['name'];

        $tables = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'erp_%' ORDER BY TABLE_NAME");
        $tables->execute([$dbName]);

        $schema = [];
        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!in_array($table, self::ALLOWED_TABLES)) continue;
            $cols = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
            $cols->execute([$dbName, $table]);
            $schema[$table] = [];
            foreach ($cols->fetchAll() as $col) {
                $schema[$table][] = ['name' => $col['COLUMN_NAME'], 'type' => $col['COLUMN_TYPE'], 'key' => $col['COLUMN_KEY'] ?: null];
            }
        }
        return $schema;
    }

    private function getQuickContext(): array {
        $pdo = DB::get();
        return [
            'today' => date('Y-m-d'),
            'accounts' => $pdo->query("SELECT id, name, currency, balance FROM erp_finance_accounts WHERE is_active=1")->fetchAll(),
            'counterparties' => $pdo->query("SELECT id, name, type FROM erp_counterparties WHERE is_active=1 ORDER BY name")->fetchAll(),
            'warehouses' => $pdo->query("SELECT id, name FROM erp_warehouses WHERE is_active=1")->fetchAll(),
            'product_count' => (int)$pdo->query("SELECT COUNT(*) FROM erp_products WHERE is_active=1")->fetchColumn(),
            'open_tasks' => (int)$pdo->query("SELECT COUNT(*) FROM erp_tasks WHERE status NOT IN ('done','cancelled')")->fetchColumn(),
        ];
    }

    private function validateSql(string $sql): bool {
        preg_match_all('/(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?(\w+)`?/i', $sql, $matches);
        foreach (($matches[1] ?? []) as $table) {
            if (!str_starts_with($table, 'erp_')) return false;
        }
        return true;
    }
}
