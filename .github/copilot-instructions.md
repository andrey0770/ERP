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
- Последняя: v021_cue_attributes
- При добавлении миграции — всегда следующий номер (v022, v023...)

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
