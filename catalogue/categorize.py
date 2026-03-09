#!/usr/bin/env python3
"""
Скрипт категоризации товаров по новой структуре каталога.
Читает ../catalog_full.txt → выдаёт catalogue/products.csv
"""

import csv
import re
import os
from collections import Counter


def parse_products(filepath):
    """Парсит catalog_full.txt, возвращает список товаров с SKU, именем и старой категорией."""
    products = []
    category_stack = []

    with open(filepath, 'r', encoding='utf-8') as f:
        for line in f:
            stripped = line.rstrip()
            if not stripped:
                continue

            tabs = len(line) - len(line.lstrip('\t'))
            content = stripped.strip()

            sku_match = re.search(r'\[([^\]]+)\]', content)
            if sku_match and '₽' in content:
                sku = sku_match.group(1)
                name = content[:sku_match.start()].strip()
                old_cat = ' > '.join(category_stack[:tabs]) if tabs <= len(category_stack) else ''
                products.append({
                    'sku': sku,
                    'name': name,
                    'old_category': old_cat,
                })
            else:
                cat_match = re.match(r'(.+?)\s*\(\d+\)', content)
                if cat_match:
                    cat_name = cat_match.group(1).strip()
                    if tabs >= len(category_stack):
                        category_stack.append(cat_name)
                    else:
                        category_stack = category_stack[:tabs]
                        category_stack.append(cat_name)

    return products


# ---------------------------------------------------------------------------
# Правила категоризации
# ---------------------------------------------------------------------------

def categorize(name):
    """Возвращает (раздел, категория, [теги]) для товара по его названию."""
    n = name.lower()

    razdel = 'Бильярд'
    category = None
    tags = []

    # --- 0. Покер ----------------------------------------------------------
    if 'покер' in n or n.startswith('карты'):
        return 'Покер', 'Карты', _extract_tags(name, 'Покер')

    # --- 1. Наборы (подарочные с кием) — проверяем ДО киев ----------------
    if 'подарочн' in n and 'набор' in n:
        return razdel, 'Наборы', _extract_tags(name, 'Наборы')

    # --- 2. Кии -----------------------------------------------------------
    if (n.startswith('кий ') or n.startswith('кии ')
            or (n.startswith('комплект') and 'киев' in n)):
        return razdel, 'Кии', _extract_tags(name, 'Кии')

    # --- 3. Шары ----------------------------------------------------------
    if n.startswith('шар'):
        return razdel, 'Шары', _extract_tags(name, 'Шары')

    # --- 4. Сукно ---------------------------------------------------------
    if n.startswith('сукно'):
        return razdel, 'Сукно', _extract_tags(name, 'Сукно')

    # --- 5. Покрывала и чехлы для СТОЛОВ ----------------------------------
    if n.startswith('покрывало'):
        return razdel, 'Покрывала и чехлы для столов', _extract_tags(name, 'Покрывала')
    if n.startswith('чехол') and ('стол' in n or 'покрывало' in n):
        return razdel, 'Покрывала и чехлы для столов', _extract_tags(name, 'Покрывала')

    # --- 6. Тубусы и чехлы для КИЕВ ---------------------------------------
    if n.startswith('тубус'):
        return razdel, 'Тубусы и чехлы для киев', _extract_tags(name, 'Тубусы')
    if n.startswith('чехол') and ('кий' in n or 'кия' in n or 'киев' in n):
        return razdel, 'Тубусы и чехлы для киев', _extract_tags(name, 'Тубусы')
    if n.startswith('футляр') and ('кия' in n or 'кий' in n):
        return razdel, 'Тубусы и чехлы для киев', _extract_tags(name, 'Тубусы')

    # --- 7. Киевницы ------------------------------------------------------
    if n.startswith('киевница'):
        return razdel, 'Киевницы', _extract_tags(name, 'Киевницы')

    # --- 8. Комплектующие столов ------------------------------------------
    if (n.startswith('лузы') or n.startswith('резина для борт')
            or n.startswith('резина для бильярд')
            or n.startswith('сетки для')
            or n.startswith('пелерин')
            or n.startswith('скобы')
            or n.startswith('подпятник')
            or n.startswith('крюк')):
        return razdel, 'Комплектующие столов', _extract_tags(name, 'Комплектующие')

    # --- 9. Аксессуары (всё остальное бильярдное) -------------------------
    accessory_prefixes = [
        'перчатка', 'мел ', 'мел\t', 'наклейки', 'колпачки',
        'инструмент', 'фиксатор',
        'мост ', 'мост\t', 'древко',
        'щетка', 'щётка', 'станок',
        'аксессуар', 'пенал', 'подвес',
        'полка', 'средство', 'тренажер', 'тренажёр',
        'набор', 'треугольник',
    ]
    for prefix in accessory_prefixes:
        if n.startswith(prefix):
            return razdel, 'Аксессуары', _extract_tags(name, 'Аксессуары')

    # --- Fallback ---------------------------------------------------------
    return razdel, 'Аксессуары', _extract_tags(name, 'Аксессуары')


def _extract_tags(name, category):
    """Извлекает теги из названия товара."""
    n = name.lower()
    tags = []

    # Вид игры (из текста)
    if any(w in n for w in ['пул', 'pool', 'американск']):
        tags.append('пул')
    if any(w in n for w in ['русск', 'пирамид']):
        tags.append('русский бильярд')
    if any(w in n for w in ['снукер', 'snooker']):
        tags.append('снукер')
    if 'карамбол' in n:
        tags.append('карамболь')

    # Составность (для киев и наборов)
    if category in ('Кии', 'Наборы'):
        if any(w in n for w in ['цельный', '1-составной', 'односоставн']):
            tags.append('цельный')
        if any(w in n for w in ['разборный', '2-составной', 'двухсоставн', '3/4']):
            tags.append('разборный')
        if 'укороченн' in n:
            tags.append('укороченный')
        if 'карбонов' in n:
            tags.append('карбон')

    # Длина (для киев и мостов)
    if category in ('Кии', 'Наборы', 'Аксессуары'):
        m = re.search(r'(\d{2,3})\s*см', n)
        if m:
            tags.append(f'{m.group(1)} см')

    # Размер стола
    if category in ('Сукно', 'Покрывала', 'Комплектующие'):
        ft = re.search(r'(\d{1,2})\s*фут', n)
        if ft:
            tags.append(f'{ft.group(1)} фт')

    # Размер шаров (мм)
    if category == 'Шары':
        mm = re.search(r'([\d]+[,.][\d]+)\s*мм', name)
        if mm:
            tags.append(f'{mm.group(1)} мм')

    # Бренды
    brands = [
        'Aramith', 'Iwan Simonis', 'Tweeten', 'Porter',
        'Maple Crown', 'Astro', 'Eurosprint', 'Mirtex',
        'Champion', 'Manhattan', 'Grand', 'ManCity',
        'Longoni', 'Fiberglass', 'Тафгай', 'Compositor',
        'Player', 'Crown', 'Brookstone', 'O\'MinCues',
    ]
    for b in brands:
        if b.lower() in n:
            tags.append(b)

    # Тип киевницы
    if category == 'Киевницы':
        if 'напольн' in n:
            tags.append('напольная')
        if 'настенн' in n:
            tags.append('настенная')
        m = re.search(r'для\s+(\d+)\s+киев', n)
        if m:
            tags.append(f'на {m.group(1)} киев')

    # Тип тубуса/чехла
    if category == 'Тубусы':
        if 'тубус' in n:
            tags.append('тубус')
        if 'чехол' in n:
            tags.append('чехол')
        if 'футляр' in n or 'кейс' in n:
            tags.append('футляр')
        m = re.search(r'(\d+)\s*отделени', n)
        if m:
            tags.append(f'{m.group(1)} отд.')

    # Тип покрывала/чехла для стола
    if category == 'Покрывала':
        if 'покрывало' in n:
            tags.append('покрывало')
        if 'чехол' in n:
            tags.append('чехол')
        if 'резинк' in n:
            tags.append('на резинке')

    # Тип аксессуара
    if category == 'Аксессуары':
        accessory_types = {
            'перчатка': 'перчатки',
            'мел ': 'мел', 'мелк': 'мел',
            'наклейки': 'наклейки', 'наклейк': 'наклейки',
            'колпачки': 'наклейки',
            'инструмент': 'инструменты',
            'фиксатор': 'инструменты',
            'мост ': 'мосты', 'мост\t': 'мосты',
            'древко': 'мосты',
            'щетка': 'щётки', 'щётка': 'щётки',
            'станок': 'щётки',
            'муфт': 'уход за кием',
            'аксессуар': 'уход за кием',
            'пенал': 'держатели мела',
            'подвес': 'держатели киев',
            'полка': 'полки для шаров',
            'средство': 'уход за шарами',
            'тренажер': 'тренажёры', 'тренажёр': 'тренажёры',
            'треугольник': 'треугольники',
            'набор': 'наборы аксессуаров',
        }
        for key, val in accessory_types.items():
            if key in n:
                tags.insert(0, val)
                break

    # Тип комплектующей
    if category == 'Комплектующие':
        if 'лузы' in n:
            tags.insert(0, 'лузы')
        elif 'резина' in n:
            tags.insert(0, 'резина для бортов')
        elif 'сетки' in n:
            tags.insert(0, 'сетки для луз')
        elif 'пелерин' in n:
            tags.insert(0, 'пелерины')
        elif 'скобы' in n:
            tags.insert(0, 'скобы')
        elif 'подпятник' in n:
            tags.insert(0, 'опоры')
        elif 'крюк' in n:
            tags.insert(0, 'крюки')

    # Перчатки — тип застёжки
    if 'перчатка' in n:
        if 'липучк' in n or 'velcro' in n:
            tags.append('на липучке')
        elif 'резинк' in n:
            tags.append('на резинке')
        elif 'безразмерн' in n:
            tags.append('безразмерная')

    return tags


# ---------------------------------------------------------------------------

def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    catalog_file = os.path.join(script_dir, 'catalog_full.txt')
    output_file = os.path.join(script_dir, 'products.csv')

    products = parse_products(catalog_file)
    print(f'Прочитано товаров: {len(products)}')

    for p in products:
        razdel, cat, tags = categorize(p['name'])
        p['razdel'] = razdel
        p['new_category'] = cat
        p['tags'] = '; '.join(dict.fromkeys(tags))  # уникальные, сохраняя порядок

    # CSV
    with open(output_file, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.DictWriter(
            f,
            fieldnames=['sku', 'razdel', 'new_category', 'tags', 'name', 'old_category'],
            delimiter=';',
            quoting=csv.QUOTE_ALL,
        )
        writer.writeheader()
        for p in products:
            writer.writerow(p)

    print(f'Записано в {output_file}')

    # Сводка
    cats = Counter()
    for p in products:
        key = f'{p["razdel"]} > {p["new_category"]}'
        cats[key] += 1

    print(f'\n{"="*55}')
    print(f'РАСПРЕДЕЛЕНИЕ ПО КАТЕГОРИЯМ')
    print(f'{"="*55}')
    for cat, count in sorted(cats.items(), key=lambda x: (-x[1], x[0])):
        print(f'  {cat:45s} {count:>4}')
    print(f'{"="*55}')
    print(f'  {"ИТОГО":45s} {sum(cats.values()):>4}')


if __name__ == '__main__':
    main()
