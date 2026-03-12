"""
Заполнение поля supplier_product_name из МойСклад → ERP
Доп.поле "Наименование у поставщика" в МойСклад → supplier_product_name
Сопоставление по коду товара (МойСклад code = ERP sku)
"""
import requests
import json
import os
import sys
from dotenv import load_dotenv

# --- Load secrets ---
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

session = requests.Session()
session.headers.update({'Authorization': f'Bearer {MOYSKLAD_TOKEN}', 'Accept-Encoding': 'gzip'})


def erp_get(action, **params):
    r = requests.get(ERP_API, params={'action': action, **params})
    r.raise_for_status()
    return r.json()

def erp_post(action, data):
    r = requests.post(ERP_API + '?action=' + action, json=data)
    r.raise_for_status()
    return r.json()

def fetch_all_ms_products():
    """Fetch all products from MoySklad with attributes"""
    items = []
    offset = 0
    while True:
        r = session.get(f'{MS_API}/entity/product', params={'limit': 1000, 'offset': offset})
        r.raise_for_status()
        data = r.json()
        rows = data.get('rows', [])
        items.extend(rows)
        if len(rows) < 1000:
            break
        offset += 1000
    return items


def main():
    print('=== Заполнение "Наименование у поставщика" из МойСклад ===\n')

    # 1. Get all MoySklad products
    print('1. Загрузка товаров из МойСклад...')
    ms_products = fetch_all_ms_products()
    print(f'   Загружено: {len(ms_products)} товаров')

    # 2. Extract supplier_product_name from attributes
    # Find the attribute "Наименование у поставщика" in any product
    ms_data = {}  # code -> supplier_product_name
    attr_name_variants = ['наименование у поставщика', 'наименование у\nпоставщика']
    
    found_attr = 0
    for p in ms_products:
        code = p.get('code', '').strip()
        if not code:
            continue
        
        attrs = p.get('attributes', [])
        for attr in attrs:
            attr_name = attr.get('name', '').lower().strip()
            if any(v in attr_name for v in attr_name_variants):
                val = attr.get('value', '').strip()
                if val:
                    ms_data[code] = val
                    found_attr += 1
                break
    
    print(f'   Найдено с "Наименование у поставщика": {found_attr}')
    
    # Show first 5 examples
    for i, (code, val) in enumerate(list(ms_data.items())[:5]):
        print(f'   ex: code={code} → "{val}"')

    # 3. Load ERP products
    print('\n2. Загрузка товаров из ERP...')
    erp_products = erp_get('products.list', limit='500')['items']
    print(f'   Загружено: {len(erp_products)} товаров')

    # 4. Match by sku = MoySklad code
    updates = []
    for ep in erp_products:
        sku = str(ep['sku']).strip()
        if sku in ms_data:
            ms_val = ms_data[sku]
            current = (ep.get('supplier_product_name') or '').strip()
            if current != ms_val:
                updates.append({
                    'id': ep['id'],
                    'sku': sku,
                    'name': ep.get('alias') or ep.get('short_name') or ep['name'],
                    'supplier_product_name': ms_val,
                    'current': current,
                })

    print(f'\n3. Обновлений: {len(updates)}')
    
    if not updates:
        print('Нечего обновлять.')
        return

    # Show preview
    for u in updates:
        cur = u['current'] or '(пусто)'
        print(f"  #{u['id']} {u['sku']:10s} {u['name'][:30]:30s} | {cur} → {u['supplier_product_name']}")

    # 4. Update
    print(f'\n4. Обновление {len(updates)} товаров...')
    ok = 0
    for u in updates:
        result = erp_post('products.update', {'id': u['id'], 'supplier_product_name': u['supplier_product_name']})
        if result.get('ok') or result.get('success'):
            ok += 1
        else:
            print(f"  FAIL #{u['id']}: {result}")
    
    print(f'\nГотово! Обновлено {ok}/{len(updates)}')


if __name__ == '__main__':
    main()
