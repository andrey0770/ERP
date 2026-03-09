"""
Синхронизация остатков из МойСклад → ERP
Сопоставление по коду товара (МойСклад code = ERP sku)
"""
import requests
import os
import sys
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
MS_HEADERS = {'Authorization': f'Bearer {MOYSKLAD_TOKEN}', 'Accept-Encoding': 'gzip'}


def fetch_moysklad_stock():
    """Получить все остатки из МойСклад"""
    items = []
    offset = 0
    while True:
        r = requests.get(f'{MS_API}/report/stock/all', headers=MS_HEADERS,
                         params={'limit': 1000, 'offset': offset})
        r.raise_for_status()
        rows = r.json().get('rows', [])
        items.extend(rows)
        if len(rows) < 1000:
            break
        offset += 1000
    return items


def fetch_erp_products():
    """Получить все товары из ERP"""
    r = requests.get(ERP_API, params={'action': 'products.list', 'limit': 1000})
    r.raise_for_status()
    return r.json()['items']


def sync():
    print('=== Синхронизация остатков МойСклад → ERP ===')
    print()

    # 1. Получаем остатки из МойСклад
    print('Загрузка остатков из МойСклад...')
    ms_stock = fetch_moysklad_stock()
    print(f'  Получено: {len(ms_stock)} позиций')

    # Индекс по коду
    stock_by_code = {}
    for item in ms_stock:
        code = item.get('code', '')
        if code:
            stock_by_code[code] = {
                'quantity': item.get('stock', 0),  # stock = quantity - reserve + inTransit
                'reserve': item.get('reserve', 0),
                'name': item.get('name', ''),
            }

    # 2. Получаем товары из ERP
    print('Загрузка товаров из ERP...')
    erp_products = fetch_erp_products()
    print(f'  Получено: {len(erp_products)} товаров')
    print()

    # 3. Формируем данные для обновления
    sync_items = []
    matched = 0
    zero_stock = 0

    for p in erp_products:
        sku = p.get('sku', '')
        ms = stock_by_code.get(sku)
        if ms:
            matched += 1
            sync_items.append({
                'sku': sku,
                'quantity': ms['quantity'],
                'reserve': ms['reserve'],
            })
        else:
            # Нет в отчёте остатков = 0 на складе
            zero_stock += 1
            sync_items.append({
                'sku': sku,
                'quantity': 0,
                'reserve': 0,
            })

    print(f'Сопоставлено по коду: {matched}')
    print(f'Нет в МойСклад (остаток=0): {zero_stock}')
    print()

    # 4. Отправляем в ERP
    print('Обновление остатков в ERP...')
    r = requests.post(ERP_API, params={'action': 'products.stock_sync'},
                      json={'items': sync_items})
    r.raise_for_status()
    result = r.json()

    print(f'  Обновлено: {result.get("updated", 0)}')
    not_found = result.get('not_found', [])
    if not_found:
        print(f'  Не найдено в ERP: {len(not_found)} SKU')
        for sku in not_found[:10]:
            print(f'    {sku}')

    # 5. Статистика по остаткам
    has_stock = sum(1 for i in sync_items if i['quantity'] > 0)
    total_qty = sum(i['quantity'] for i in sync_items)
    print()
    print(f'Итого: {has_stock} товаров с остатками, общее кол-во: {total_qty:.0f} шт')
    print('Готово!')


if __name__ == '__main__':
    sync()
