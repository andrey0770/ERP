"""
Заполнение поставщиков из приходных накладных МойСклад → ERP

Поставщик = контрагент приходного документа (entity/counterparty).
Агенты типа entity/organization (собственная организация) — пропускаются.

1. Собирает контрагентов из приходных за 1.5 года
2. Создаёт записи в erp_suppliers (справочник поставщиков)
3. Линкует supplier + supplier_id на товарах
"""
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
import os
import sys
from datetime import datetime, timedelta
from dotenv import load_dotenv

# --- Загрузка секретов ---
for env_path in [
    os.path.join(os.path.dirname(__file__), '.env'),
    r'G:\Мой диск\secrets\ERP\.env',
    r'G:\My Drive\secrets\ERP\.env',
]:
    if os.path.exists(env_path):
        load_dotenv(env_path)
        break

MOYSKLAD_TOKEN = os.getenv('MOYSKLAD_TOKEN')
if not MOYSKLAD_TOKEN:
    print('ERROR: MOYSKLAD_TOKEN not found in .env')
    sys.exit(1)

ERP_API = 'https://sborka.billiarder.ru/erp/api/'
MS_API = 'https://api.moysklad.ru/api/remap/1.2'

# Сессия с retry и connection pooling
session = requests.Session()
session.headers.update({'Authorization': f'Bearer {MOYSKLAD_TOKEN}', 'Accept-Encoding': 'gzip'})
retry = Retry(total=5, backoff_factor=1, status_forcelist=[429, 500, 502, 503, 504])
session.mount('https://', HTTPAdapter(max_retries=retry, pool_maxsize=1))


def fetch_all(url, params=None, silent=False):
    """Загрузить все записи с пагинацией"""
    items = []
    offset = 0
    base_params = params or {}
    while True:
        p = {**base_params, 'limit': 1000, 'offset': offset}
        r = session.get(url, params=p, timeout=30)
        r.raise_for_status()
        data = r.json()
        rows = data.get('rows', [])
        items.extend(rows)
        total = data.get('meta', {}).get('size', 0)
        if offset == 0 and not silent:
            print(f'  Всего: {total}')
        if len(rows) < 1000:
            break
        offset += 1000
        if not silent:
            print(f'  ...{len(items)}')
    return items


def run():
    print('=== Заполнение поставщиков из МойСклад ===\n')

    # 1. Контрагенты → id:name (только counterparty, НЕ organization)
    print('1. Контрагенты...')
    counterparties = fetch_all(f'{MS_API}/entity/counterparty')
    cp_map = {cp['id']: cp['name'] for cp in counterparties}
    print(f'  Загружено: {len(cp_map)}\n')

    # 2. Товары МойСклад → href:code
    print('2. Товары МойСклад...')
    ms_products = fetch_all(f'{MS_API}/entity/product')
    product_code_map = {}
    for p in ms_products:
        code = p.get('code', '')
        if code:
            product_code_map[p['meta']['href']] = code
    print(f'  С кодом: {len(product_code_map)}\n')

    # 3. Приходные накладные за 1.5 года
    since = (datetime.now() - timedelta(days=548)).strftime('%Y-%m-%d 00:00:00')
    print(f'3. Приходные с {since[:10]}...')
    supplies = fetch_all(
        f'{MS_API}/entity/supply',
        params={'filter': f'moment>{since}', 'order': 'moment,asc'}
    )
    print(f'  Накладных: {len(supplies)}\n')

    # 4. Обработка позиций — только контрагенты (NOT organization)
    print('4. Позиции накладных...')
    product_supplier = {}  # code → {supplier, moment}
    total_pos = 0
    skipped_org = 0

    for i, supply in enumerate(supplies):
        agent_href = supply.get('agent', {}).get('meta', {}).get('href', '')

        # Поставщик = только контрагент, не собственная организация
        if '/entity/counterparty/' not in agent_href:
            skipped_org += 1
            continue

        agent_id = agent_href.rsplit('/', 1)[-1]
        agent_name = cp_map.get(agent_id)
        if not agent_name:
            continue
        moment = supply.get('moment', '')[:10]

        positions = fetch_all(f'{MS_API}/entity/supply/{supply["id"]}/positions', silent=True)
        total_pos += len(positions)

        for pos in positions:
            assort_href = pos.get('assortment', {}).get('meta', {}).get('href', '')
            code = product_code_map.get(assort_href)
            if not code and '/variant/' in assort_href:
                continue
            if code:
                existing = product_supplier.get(code)
                if not existing or moment > existing['moment']:
                    product_supplier[code] = {'supplier': agent_name, 'moment': moment}

        if (i + 1) % 100 == 0 or i == len(supplies) - 1:
            print(f'  {i + 1}/{len(supplies)} накл., {total_pos} поз., {len(product_supplier)} товаров (пропущено орг: {skipped_org})')

    # Статистика по поставщикам
    suppliers_count = {}
    for v in product_supplier.values():
        suppliers_count[v['supplier']] = suppliers_count.get(v['supplier'], 0) + 1
    print(f'\n  Поставщики ({len(suppliers_count)} уникальных):')
    for s, cnt in sorted(suppliers_count.items(), key=lambda x: -x[1]):
        print(f'    {s}: {cnt}')
    print()

    # 5. Создать поставщиков в erp_suppliers
    print('5. Создание записей в erp_suppliers...')
    unique_suppliers = sorted(suppliers_count.keys())
    supplier_id_map = {}  # name → id

    for name in unique_suppliers:
        r = session.post(ERP_API, json={
            'action': 'suppliers.create',
            'name': name
        })
        if r.status_code == 200:
            data = r.json()
            supplier_id_map[name] = data.get('id')
            print(f'  + {name} → id={data.get("id")}')
        else:
            # Может уже существует — ищем
            err = r.text
            if 'Duplicate' in err or 'already' in err.lower():
                print(f'  ~ {name} уже есть')
            else:
                print(f'  ERR {name}: {err}')

    # Если были дубликаты — подтянем весь список для маппинга
    r = session.get(ERP_API, params={'action': 'suppliers.list', 'limit': 500})
    if r.status_code == 200:
        for s in r.json().get('items', []):
            supplier_id_map[s['name']] = s['id']
    print(f'  Маппинг: {len(supplier_id_map)} поставщиков\n')

    # 6. Обновить товары: supplier + supplier_id
    print('6. Обновление товаров...')
    r = session.get(ERP_API, params={'action': 'products.list', 'limit': 1000})
    r.raise_for_status()
    erp_products = r.json()['items']
    print(f'  Товаров в ERP: {len(erp_products)}')

    updated = 0
    skipped = 0
    for ep in erp_products:
        sku = ep.get('sku', '')
        if sku in product_supplier:
            new_supplier = product_supplier[sku]['supplier']
            new_supplier_id = supplier_id_map.get(new_supplier)
            need_update = (
                ep.get('supplier') != new_supplier or
                ep.get('supplier_id') != new_supplier_id
            )
            if need_update:
                payload = {
                    'action': 'products.update',
                    'id': ep['id'],
                    'supplier': new_supplier,
                }
                if new_supplier_id:
                    payload['supplier_id'] = new_supplier_id
                r = session.post(ERP_API, json=payload)
                if r.status_code == 200:
                    updated += 1
                else:
                    print(f'  ERR {sku}: {r.text}')
            else:
                skipped += 1

    print(f'\n=== Результат ===')
    print(f'Пропущено накладных (собственная организация): {skipped_org}')
    print(f'Товаров с поставщиком: {len(product_supplier)}')
    print(f'Поставщиков создано: {len(unique_suppliers)}')
    print(f'Обновлено товаров: {updated}')
    print(f'Уже актуальны: {skipped}')


if __name__ == '__main__':
    run()
