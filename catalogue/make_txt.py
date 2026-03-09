import csv
import os
import re
from collections import OrderedDict

script_dir = os.path.dirname(os.path.abspath(__file__))
csv_path = os.path.join(script_dir, 'products.csv')
out_path = os.path.join(script_dir, 'catalog_new.txt')

with open(csv_path, encoding='utf-8-sig') as f:
    products = list(csv.DictReader(f, delimiter=';', quotechar='"'))

tree = {}
for p in products:
    r = p['razdel']
    c = p['new_category']
    tree.setdefault(r, {}).setdefault(c, []).append(p)

razdel_order = ['Бильярд', 'Покер', 'Дартс', 'Настольный теннис', 'Игровые столы']
billiard_cat_order = [
    'Кии', 'Шары', 'Столы', 'Сукно',
    'Покрывала и чехлы для столов',
    'Тубусы и чехлы для киев', 'Киевницы',
    'Комплектующие столов', 'Аксессуары', 'Наборы', 'Освещение',
]
stubs = {
    'Бильярд': ['Столы', 'Освещение'],
    'Дартс': ['Дротики', 'Мишени', 'Аксессуары'],
    'Настольный теннис': ['Столы', 'Ракетки'],
    'Покер': ['Фишки', 'Наборы'],
    'Игровые столы': ['Аэрохоккей', 'Настольный футбол'],
}

# Подпапки внутри категорий — группировка однотипных товаров
# Ключ = категория, значение = { подпапка: функция-матчер(name) }
def subfolders_for(cat_name, prods):
    """Возвращает OrderedDict { subfolder_name: [products] } или None если группировка не нужна."""
    n = lambda p: p['name'].lower()

    if cat_name == 'Аксессуары':
        groups = OrderedDict([
            ('Перчатки', lambda p: 'перчатка' in n(p)),
            ('Мел', lambda p: n(p).startswith('мел ')),
            ('Наклейки и колпачки', lambda p: any(w in n(p) for w in ['наклейк', 'колпачки'])),
            ('Инструменты для наклеек', lambda p: any(w in n(p) for w in ['инструмент', 'фиксатор'])),
            ('Мосты и древки', lambda p: any(w in n(p) for w in ['мост ', 'древко'])),
            ('Щётки и станки', lambda p: any(w in n(p) for w in ['щетка', 'щётка', 'станок'])),
            ('Держатели мела', lambda p: 'пенал' in n(p) or 'держатель' in n(p) and 'мел' in n(p)),
            ('Подвесы и держатели киев', lambda p: 'подвес' in n(p) or ('держател' in n(p) and 'ки' in n(p))),
            ('Киевницы', lambda p: 'киевница' in n(p)),
            ('Полки для шаров', lambda p: 'полка' in n(p)),
            ('Средства ухода', lambda p: any(w in n(p) for w in ['средство', 'муфт', 'аксессуар'])),
            ('Тренажёры', lambda p: any(w in n(p) for w in ['тренажер', 'тренажёр'])),
            ('Наборы аксессуаров', lambda p: 'набор' in n(p)),
            ('Чехлы и тубусы для киев', lambda p: any(w in n(p) for w in ['чехол', 'тубус', 'футляр'])),
            ('Шары', lambda p: 'шар' in n(p)),
            ('Кии', lambda p: any(w in n(p) for w in ['кий ', 'кии ', 'комплект'])),
        ])
    elif cat_name == 'Комплектующие столов':
        groups = OrderedDict([
            ('Лузы', lambda p: 'лузы' in n(p)),
            ('Резина для бортов', lambda p: 'резина' in n(p)),
            ('Сетки для луз', lambda p: 'сетки' in n(p)),
            ('Пелерины', lambda p: 'пелерин' in n(p)),
            ('Скобы', lambda p: 'скобы' in n(p)),
            ('Опоры и подпятники', lambda p: 'подпятник' in n(p)),
            ('Крюки', lambda p: 'крюк' in n(p)),
        ])
    elif cat_name == 'Тубусы и чехлы для киев':
        groups = OrderedDict([
            ('Тубусы', lambda p: 'тубус' in n(p)),
            ('Чехлы', lambda p: 'чехол' in n(p)),
            ('Футляры и кейсы', lambda p: any(w in n(p) for w in ['футляр', 'кейс'])),
        ])
    elif cat_name == 'Покрывала и чехлы для столов':
        groups = OrderedDict([
            ('Покрывала', lambda p: 'покрывало' in n(p)),
            ('Чехлы', lambda p: 'чехол' in n(p)),
        ])
    else:
        return None

    result = OrderedDict()
    remaining = list(prods)
    for group_name, matcher in groups.items():
        matched = [p for p in remaining if matcher(p)]
        if matched:
            result[group_name] = matched
            remaining = [p for p in remaining if p not in matched]
    if remaining:
        result['Прочее'] = remaining
    return result


razdel = 'Бильярд'
razdel_cats = tree.get(razdel, {})
cat_names = billiard_cat_order

razdel_total = sum(len(razdel_cats.get(c, [])) for c in cat_names)
lines = []
lines.append(f'{razdel} ({razdel_total})')

for cat_name in cat_names:
    prods = razdel_cats.get(cat_name, [])
    lines.append(f'\t{cat_name} ({len(prods)})')

    subs = subfolders_for(cat_name, prods)
    if subs:
        for sub_name, sub_prods in subs.items():
            lines.append(f'\t\t{sub_name} ({len(sub_prods)})')
            for p in sub_prods:
                lines.append(f'\t\t\t{p["name"]}  [{p["sku"]}]')
    else:
        for p in prods:
            lines.append(f'\t\t{p["name"]}  [{p["sku"]}]')

with open(out_path, 'w', encoding='utf-8') as f:
    f.write('\n'.join(lines) + '\n')

print(f'Записано {len(lines)} строк в {out_path}')
