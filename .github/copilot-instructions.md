# ERP System — Copilot Instructions

## Проект
ERP-система для бильярдного бизнеса (billiarder.ru). Управление товарами, складом, финансами, задачами, CRM, поставками.

## Стек
- **Фронтенд:** Vue 3 (CDN, `vue.global.prod.js`), Composition API, НЕ ES-модули
- **CSS:** Кастомный CSS (erp/css/style.css), иконки — Phosphor Icons CDN
- **Бэкенд:** PHP 8, REST API, единая точка входа `erp/api/index.php`
- **БД:** MySQL, база `bril_1`, префикс таблиц `erp_` (shared DB с OpenCart)
- **Хранилище:** Yandex S3 (бакет `sborka-video`, префикс `erp/`)
- **Хостинг:** `sborka.billiarder.ru` (SFTP)

## Структура проекта
```
erp/
├── index.html          # SPA shell — все Vue-шаблоны здесь
├── css/style.css       # Все стили
├── js/
│   ├── core.js         # API-клиент, утилиты, toast, общие refs
│   ├── dashboard.js    # Дашборд
│   ├── catalog.js      # Каталог товаров, фильтры, колонки, категории
│   ├── inventory.js    # Складские остатки, движения
│   ├── purchasing.js   # Закупки, приёмки
│   ├── sales.js        # Продажи, отгрузки
│   ├── crm.js          # CRM — контакты, взаимодействия, сделки
│   ├── finance.js      # Финансы — счета, транзакции
│   ├── tasks.js        # Задачи, kanban-доска
│   ├── settings.js     # Настройки, Ozon-импорт
│   ├── app.js          # Vue app creation, routing, навигация (ПОСЛЕДНИЙ в бандле)
│   └── erp.bundle.js   # Собранный бандл (auto-generated, не править!)
├── api/
│   ├── index.php       # REST API роутер: ?action=module.method
│   ├── config.php      # Конфиг (PROTECTED — не деплоится)
│   ├── db.php          # PDO + авто-миграции (v001–v021)
│   └── modules/        # PHP модули: products, inventory, journal, finance, tasks, crm, deals, supplies, suppliers, ai, system
├── bot/
│   └── erp_bot.py      # Telegram-бот
├── build.js            # Node.js бандлер
deploy/
├── deploy_erp.py       # SFTP деплой-скрипт
├── backup_db.py        # Бэкап БД (mysqldump → S3)
```

## Workflow разработки
1. Редактировать исходные JS-файлы в `erp/js/` (НЕ erp.bundle.js)
2. Собирать: `node erp/build.js`
3. Деплоить: `python deploy/deploy_erp.py`
4. `config.php` — protected, не перезаписывается деплоем

## Архитектурные соглашения

### Бандлер
- `build.js` конкатенирует JS-файлы в порядке: core → модули → app
- Оборачивает в IIFE, убирает import/export
- Все модули работают в одном scope — делят переменные через замыкание

### API
- Единая точка: `erp/api/index.php?action=module.method`
- Модули в `erp/api/modules/`: каждый возвращает массив `['method' => handler]`
- Фронт вызывает: `api('products.list', { limit: 50 })` через core.js

### Миграции БД
- Версионные в `db.php`, ключ формата `v021_описание`
- Применяются автоматически при первом API запросе
- Последняя: v031_transfer_dest_amount
- При добавлении миграции — всегда следующий номер (v032, v033...)
- MySQL 8: НЕ поддерживает `ADD COLUMN IF NOT EXISTS` — используй `INFORMATION_SCHEMA.COLUMNS` для проверки
- После массовых UPDATE/ALTER — делать бэкап: `python deploy/backup_db.py`

### Vue
- Composition API в `setup()`, возвращает объект с рефами и методами
- Каждый JS-модуль возвращает часть объекта setup через spread: `...catalogSetup()`
- Модальные окна: `showModal = ref(null)`, переключаются строковыми ключами

### CSS
- CSS-переменные в `:root` — цвета, отступы
- Темная тема поддерживается (`.dark-theme`)
- БЕМ не используется, именование через `.module-element` (catalog-sidebar, col-filter-popup)

### Деплой
- SFTP, сравнение по размеру файла (НЕ по хешу — это известная проблема)
- `config.php` защищён от перезаписи
- Если изменения не деплоятся — проверь что размер файла реально изменился

## Бизнес-контекст
- Товары импортируются из Ozon API (несколько магазинов: КП, БШ, СП)
- Изображения товаров скачиваются с Ozon и загружаются в Yandex S3
- Категории товаров — дерево (parent_id), импорт из Ozon
- Кии (cues) имеют доп. атрибуты: cue_type, cue_parts, cue_material
- Контекстные фильтры в каталоге — появляются только для категории "Кии"
- Навигация MoySklad-стиль: группы (Закупки, Продажи, Товары, Финансы, Задачи)

## Предупреждения
- НЕ ПРАВЬ `erp.bundle.js` — он генерируется автоматически
- НЕ ПРАВЬ `config.php` — он protected на сервере, содержит секреты
- База `bril_1` shared с OpenCart — не трогай таблицы без префикса `erp_`
- Перед ALTER TABLE / массовым UPDATE — сначала бэкап БД

## Финансовый модуль — правила записи транзакций

### Типы транзакций
- **income** — реальный доход (деньги пришли В бизнес от клиента/покупателя)
- **expense** — реальный расход (деньги УШЛИ из бизнеса поставщику/за услуги)
- **transfer** — перевод между СВОИМИ счетами (НЕ расход и НЕ доход, в P&L не участвует)

### Transfer (перевод) — мультивалютный
Одна запись = одна операция перевода. Поля:
- `account_id` — счёт-источник (деньги списываются)
- `to_account_id` — счёт-назначение (деньги зачисляются)
- `amount` + `currency` — сумма и валюта СПИСАНИЯ
- `dest_amount` + `dest_currency` — сумма и валюта ЗАЧИСЛЕНИЯ (если валюты разные)
- Если `dest_amount` не указан — зачисляется та же сумма что и списывается

Пример: обмен рублей на USDT через посредника:
```
type: transfer, account_id: Тинькофф, to_account_id: USDT
amount: 122473.48, currency: RUB
dest_amount: 1530.92, dest_currency: USD
counterparty: Слава
```

### Цепочки операций (linked_id)
Связанные транзакции объединяются через `linked_id` (= id первой записи в группе).
Пример полной цепочки оплаты поставщику в Китае:
1. **transfer** RUB→USDT (обмен через Славу) — linked_id=1
2. **transfer** USDT→CNY (обмен через Nadex) — linked_id=1
3. **expense** CNY→Condy (оплата поставщику) — linked_id=1

В UI связанные записи показываются как единый визуальный блок (синяя рамка).

### ВАЖНО: НЕ создавай пары income+expense для переводов!
❌ НЕПРАВИЛЬНО: expense RUB + income USDT (два расхода — двойной подсчёт)
✅ ПРАВИЛЬНО: один transfer с amount (RUB) и dest_amount (USDT)

### Баланс контрагента
Формула: `SUM(expense) - SUM(income)` по транзакциям контрагента.
- Положительный баланс = мы заплатили больше чем получили (аванс / Condy нам должен товар)
- Отрицательный = мы должны контрагенту
- Transfer между СВОИМИ счётами не влияет на баланс контрагента

### Счета (erp_finance_accounts)
- Тинькофф (card, RUB) — основной расчётный
- USDT (crypto, USD) — крипто-кошелёк
- CNY (other, CNY) — WeChat/юаневый счёт
- Наличные (cash, RUB), Расчётный счёт (bank, RUB)

### Контрагенты-посредники
- **Слава** — обмен RUB ↔ USDT
- **Nadex** — обмен USDT ↔ CNY
- Указываются в поле `counterparty` транзакции transfer

## Контрагенты

### Валюты контрагентов
- Все китайские поставщики — CNY (Condy, Leqicloth, Meilin, MingTai, Smart, Unicue, KL Logistics)
- HonFam — USD (инвойсы в долларах, фактическая оплата через CNY)
- Российские (Новак, Рыбин, Фортуна, Руптур, Старт, Скиба) — RUB
- Saluc (Бельгия), Simonis (Бельгия) — пока RUB (уточнить: EUR?)
- Tweeten (США) — пока RUB (уточнить: USD?)

### KL Logistics
- Логистическая компания в Китае, наш код: KL157
- Телефоны: 13533331440, 15303645057, 13039943064
- Адрес: 佛山市南海区里水镇丰西线海南洲工业区70号BL777库房晋原国际 KL157
