<?php
/**
 * ERP Module: AI-анализ
 * 
 * ai.analyze    — разобрать текст и вернуть структурированные данные
 * ai.ask        — свободный вопрос по данным ERP
 * ai.providers  — список доступных AI провайдеров
 * 
 * Также вызывается из journal.create для автоматического разбора записей.
 */
class ERP_Ai {

    /**
     * Анализ текста из журнала:
     * POST { "text": "Купил 50 кг мела для бильярда за 15000 у ИП Иванов, оплатил наличкой" }
     * 
     * AI возвращает:
     * - category: finance|inventory|task|logistics|note
     * - разбивку на составляющие для записи в соответствующие журналы
     */
    public function analyze(): array {
        $input = jsonInput();
        $text = trim($input['text'] ?? '');
        if (!$text) errorResponse('text required');

        $result = $this->callAI($this->buildAnalyzePrompt($text));
        return ['ok' => true, 'analysis' => $result];
    }

    /**
     * Свободный вопрос к AI с контекстом ERP данных
     * POST { "question": "Какие расходы за последний месяц?" }
     */
    public function ask(): array {
        $input = jsonInput();
        $question = trim($input['question'] ?? '');
        if (!$question) errorResponse('question required');

        // Собираем контекст из БД
        $context = $this->gatherContext($question);
        $result = $this->callAI($this->buildAskPrompt($question, $context));

        return ['ok' => true, 'answer' => $result];
    }

    /**
     * Список AI провайдеров
     */
    public function providers(): array {
        $cfg = require __DIR__ . '/../config.php';
        $providers = [];
        foreach ($cfg['ai'] as $name => $p) {
            $providers[] = [
                'name'       => $name,
                'model'      => $p['model'],
                'configured' => !empty($p['api_key']),
                'active'     => ($name === $cfg['ai_provider']),
            ];
        }
        return ['providers' => $providers];
    }

    // ── Вызывается из journal.create ────────────────────

    public function analyzeJournalEntry(int $journalId, string $text): ?array {
        $cfg = require __DIR__ . '/../config.php';
        $provider = $cfg['ai'][$cfg['ai_provider']] ?? null;
        if (!$provider || empty($provider['api_key'])) {
            return null; // AI не настроен
        }

        try {
            $analysis = $this->callAI($this->buildAnalyzePrompt($text));
            if (!$analysis) return null;

            $parsed = is_string($analysis) ? json_decode($analysis, true) : $analysis;

            // Сохраняем результат в журнал
            $pdo = DB::get();
            $pdo->prepare("UPDATE erp_journal SET ai_parsed = ?, category = ? WHERE id = ?")
                ->execute([
                    json_encode($parsed, JSON_UNESCAPED_UNICODE),
                    $parsed['category'] ?? null,
                    $journalId,
                ]);

            // Автоматически создаём записи в специализированных таблицах
            $this->dispatchToModules($pdo, $journalId, $parsed);

            return $parsed;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ── Private ─────────────────────────────────────────

    private function buildAnalyzePrompt(string $text): string {
        return <<<PROMPT
Ты — AI-помощник ERP системы для малого бизнеса (бильярдная тематика: продажа бильярдных столов, кийи, шары, аксессуары, а также спорттовары).

Проанализируй запись и верни JSON:

{
  "category": "finance|inventory|task|logistics|note",
  "summary": "Краткое описание операции",
  "finance": {
    "type": "income|expense|transfer|null",
    "amount": 0,
    "currency": "RUB",
    "category": "закупка|продажа|зарплата|аренда|транспорт|...",
    "counterparty": "название или null",
    "account_hint": "наличные|карта|расчётный счёт|null"
  },
  "inventory": {
    "action": "purchase|sale|adjustment|null",
    "items": [{"name": "...", "quantity": 0, "unit": "шт", "unit_price": 0}]
  },
  "task": {
    "title": "...", 
    "priority": "normal|high|urgent",
    "due_date": "YYYY-MM-DD или null"
  },
  "tags": ["тег1", "тег2"]
}

Заполняй ТОЛЬКО релевантные секции, остальные ставь null.
Сумму и количество бери из текста. Если не указано — null.

Текст записи:
{$text}
PROMPT;
    }

    private function buildAskPrompt(string $question, array $context): string {
        $contextJSON = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return <<<PROMPT
Ты — AI-аналитик ERP системы малого бизнеса. Отвечай по-русски, кратко и по делу.

Данные из ERP:
{$contextJSON}

Вопрос пользователя:
{$question}

Ответь на вопрос на основе предоставленных данных. Если данных недостаточно — скажи что нужно.
PROMPT;
    }

    /**
     * Собрать контекст из БД для AI-ответа
     */
    private function gatherContext(string $question): array {
        $pdo = DB::get();
        $context = [];

        // Финансовая сводка за текущий и прошлый месяц
        $context['finance_this_month'] = $pdo->query("
            SELECT type, SUM(amount) as total, COUNT(*) as cnt 
            FROM erp_finance_transactions WHERE date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            GROUP BY type
        ")->fetchAll();

        $context['finance_last_month'] = $pdo->query("
            SELECT type, SUM(amount) as total, COUNT(*) as cnt 
            FROM erp_finance_transactions 
            WHERE date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
              AND date < DATE_FORMAT(CURDATE(), '%Y-%m-01')
            GROUP BY type
        ")->fetchAll();

        // Последние 10 транзакций
        $context['recent_transactions'] = $pdo->query("
            SELECT date, type, amount, category, counterparty, description 
            FROM erp_finance_transactions ORDER BY date DESC, id DESC LIMIT 10
        ")->fetchAll();

        // Счета
        $context['accounts'] = $pdo->query("SELECT name, balance, currency FROM erp_finance_accounts WHERE is_active=1")->fetchAll();

        // Задачи не выполненные
        $context['open_tasks'] = $pdo->query("
            SELECT title, status, priority, due_date FROM erp_tasks WHERE status NOT IN ('done','cancelled') ORDER BY due_date LIMIT 15
        ")->fetchAll();

        // Низкие остатки
        $context['low_stock'] = $pdo->query("
            SELECT p.name, p.sku, COALESCE(i.quantity,0) as stock, p.min_stock
            FROM erp_products p LEFT JOIN erp_inventory i ON i.product_id=p.id AND i.warehouse_id=1
            WHERE p.is_active=1 AND COALESCE(i.quantity,0) <= p.min_stock
            LIMIT 20
        ")->fetchAll();

        // Общие счётчики
        $context['totals'] = [
            'products' => (int) $pdo->query("SELECT COUNT(*) FROM erp_products WHERE is_active=1")->fetchColumn(),
            'journal_entries' => (int) $pdo->query("SELECT COUNT(*) FROM erp_journal")->fetchColumn(),
            'open_tasks' => (int) $pdo->query("SELECT COUNT(*) FROM erp_tasks WHERE status NOT IN ('done','cancelled')")->fetchColumn(),
        ];

        return $context;
    }

    /**
     * Вызов AI API (поддержка OpenAI, Anthropic, Gemini)
     */
    private function callAI(string $prompt): ?string {
        $cfg = require __DIR__ . '/../config.php';
        $providerName = $cfg['ai_provider'];
        $provider = $cfg['ai'][$providerName] ?? null;

        if (!$provider || empty($provider['api_key'])) {
            throw new Exception('AI provider not configured. Set api_key in config.php');
        }

        switch ($providerName) {
            case 'openai':
                return $this->callOpenAI($provider, $prompt);
            case 'anthropic':
                return $this->callAnthropic($provider, $prompt);
            case 'gemini':
                return $this->callGemini($provider, $prompt);
            default:
                throw new Exception("Unknown AI provider: {$providerName}");
        }
    }

    private function callOpenAI(array $p, string $prompt): string {
        $body = json_encode([
            'model'    => $p['model'],
            'messages' => [
                ['role' => 'system', 'content' => 'Отвечай JSON-ом когда просят JSON. Используй русский язык.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature'   => 0.3,
            'max_tokens'    => 2000,
            'response_format' => ['type' => 'json_object'],
        ]);

        $response = $this->httpPost($p['url'], $body, [
            "Authorization: Bearer {$p['api_key']}",
            "Content-Type: application/json",
        ]);

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function callAnthropic(array $p, string $prompt): string {
        $body = json_encode([
            'model'      => $p['model'],
            'max_tokens' => 2000,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $response = $this->httpPost($p['url'], $body, [
            "x-api-key: {$p['api_key']}",
            "anthropic-version: 2023-06-01",
            "Content-Type: application/json",
        ]);

        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? '';
    }

    private function callGemini(array $p, string $prompt): string {
        $url = $p['url'] . $p['model'] . ':generateContent?key=' . $p['api_key'];
        $body = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 2000],
        ]);

        $response = $this->httpPost($url, $body, ["Content-Type: application/json"]);
        $data = json_decode($response, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function httpPost(string $url, string $body, array $headers): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("AI API error: {$error}");
        if ($httpCode >= 400) throw new Exception("AI API HTTP {$httpCode}: {$response}");

        return $response;
    }

    /**
     * Распределить AI-разбор по модулям
     */
    private function dispatchToModules(PDO $pdo, int $journalId, array $parsed): void {
        // Финансовая транзакция
        if (!empty($parsed['finance']) && $parsed['finance']['type']) {
            $fin = $parsed['finance'];
            if ($fin['amount']) {
                $pdo->prepare("
                    INSERT INTO erp_finance_transactions (journal_id, date, type, amount, currency, category, counterparty, description)
                    VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $journalId,
                    $fin['type'],
                    $fin['amount'],
                    $fin['currency'] ?? 'RUB',
                    $fin['category'] ?? null,
                    $fin['counterparty'] ?? null,
                    $parsed['summary'] ?? null,
                ]);
            }
        }

        // Задача
        if (!empty($parsed['task']) && !empty($parsed['task']['title'])) {
            $task = $parsed['task'];
            $pdo->prepare("
                INSERT INTO erp_tasks (journal_id, title, priority, due_date)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $journalId,
                $task['title'],
                $task['priority'] ?? 'normal',
                $task['due_date'] ?? null,
            ]);
        }

        // Складские движения
        if (!empty($parsed['inventory']) && !empty($parsed['inventory']['items'])) {
            $action = $parsed['inventory']['action'] ?? 'purchase';
            foreach ($parsed['inventory']['items'] as $item) {
                // Ищем товар по имени (приблизительно)
                $found = $pdo->prepare("SELECT id FROM erp_products WHERE name LIKE ? AND is_active=1 LIMIT 1");
                $found->execute(['%' . ($item['name'] ?? '') . '%']);
                $productId = $found->fetchColumn();

                if ($productId && ($item['quantity'] ?? 0) > 0) {
                    $qty = $action === 'sale' ? -$item['quantity'] : $item['quantity'];
                    $pdo->prepare("
                        INSERT INTO erp_inventory_movements (journal_id, product_id, warehouse_id, type, quantity, unit_price, reason)
                        VALUES (?, ?, 1, ?, ?, ?, ?)
                    ")->execute([
                        $journalId,
                        $productId,
                        $action,
                        $qty,
                        $item['unit_price'] ?? null,
                        $parsed['summary'] ?? null,
                    ]);

                    // Обновляем остаток
                    $pdo->prepare("
                        INSERT INTO erp_inventory (product_id, warehouse_id, quantity)
                        VALUES (?, 1, ?)
                        ON DUPLICATE KEY UPDATE quantity = quantity + ?
                    ")->execute([$productId, max(0, $qty), $qty]);
                }
            }
        }
    }
}
