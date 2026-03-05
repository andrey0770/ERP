# Lenta ERP

Лёгкая ERP-система для малого бизнеса (бильярд / спорттовары).

## Стек

- **Frontend**: Vue 3 CDN — single HTML SPA
- **Backend**: PHP 8.3 REST API (модульная архитектура)
- **Bot**: Python Telegram-бот (aiohttp)
- **БД**: MariaDB / MySQL
- **AI**: OpenAI / Anthropic / Gemini (абстракция)

## Модули

| Модуль | Описание |
|--------|----------|
| Журнал | Лента событий с AI-парсингом |
| Товары | Каталог с SKU, штрихкодами, ценами |
| Склад | Остатки, приход/расход, перемещения |
| Закупки | Заказы поставщикам, позиции, приёмка |
| Продажи | История продаж (из складских расходов) |
| Финансы | Доходы/расходы, счета, сводки |
| Отчёты | Аналитика по категориям |
| CRM | Контакты, взаимодействия |
| Сделки | Воронка продаж (pipeline) |
| Задачи | Планирование, приоритеты, сроки |
| AI | Чат-помощник с контекстом БД |

## Структура

```
erp/
  index.html              — SPA (Vue 3)
  .htaccess               — Apache rewrite
  api/
    config.php            — Конфигурация (НЕ в git)
    db.php                — PDO + автомиграции
    index.php             — REST-роутер
    modules/
      journal.php         — Журнал операций
      products.php        — Товары
      inventory.php       — Склад
      supplies.php        — Закупки
      finance.php         — Финансы
      crm.php             — CRM
      deals.php           — Сделки
      tasks.php           — Задачи
      ai.php              — AI-интеграция
      system.php          — Система
  bot/
    erp_bot.py            — Telegram-бот
    requirements.txt
deploy/
  deploy_erp.py           — SFTP деплой
```

## Деплой

```bash
python deploy/deploy_erp.py
```

## Конфигурация

Скопируй `erp/api/config.example.php` → `erp/api/config.php` и заполни:
- MySQL credentials
- AI API keys
- Telegram bot token
- S3 credentials (опционально)

## Live

https://sborka.billiarder.ru/erp/
