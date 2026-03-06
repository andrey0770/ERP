"""
Import category tree from Lenta catalog into ERP database.
3-level hierarchy: Section → Group → Subcategory
"""
import json
import urllib.request
import ssl

API_BASE = "https://sborka.billiarder.ru/erp/api/index.php"

# Category tree from Lenta's catalog.html
TREE = [
    {"n": "Бильярд", "g": [
        {"n": "Аксессуары", "s": ["Перчатки", "Треугольники"]},
        {"n": "Аксессуары для киев", "s": ["Мел", "Наклейки"]},
        {"n": "Кии", "s": ["Прочие кии", "Пул", "Русский бильярд", "Снукер"]},
        {"n": "Комплектующие столов", "s": ["Лузы", "Резина для бортов"]},
        {"n": "Освещение", "s": ["Светильники"]},
        {"n": "Прочее", "s": ["Аксессуары"]},
        {"n": "Столы", "s": ["Бильярдные столы"]},
        {"n": "Сукно и покрытия", "s": ["Сукно"]},
        {"n": "Чехлы и тубусы", "s": ["Тубусы и футляры", "Чехлы для столов"]},
        {"n": "Шары", "s": ["Прочие шары", "Пул", "Русский бильярд", "Снукер", "Тренировочные"]},
    ]},
    {"n": "Дартс", "g": [
        {"n": "Аксессуары", "s": ["Прочее"]},
        {"n": "Дротики", "s": ["Дротики"]},
    ]},
    {"n": "Игровые столы", "g": [
        {"n": "Аэрохоккей", "s": ["Аэрохоккей"]},
        {"n": "Настольный футбол", "s": ["Кикер"]},
    ]},
    {"n": "Настольный теннис", "g": [
        {"n": "Ракетки", "s": ["Ракетки"]},
        {"n": "Столы", "s": ["Теннисные столы"]},
    ]},
    {"n": "Покер", "g": [
        {"n": "Карты", "s": ["Карты"]},
        {"n": "Наборы", "s": ["Наборы для покера"]},
        {"n": "Фишки", "s": ["Фишки"]},
    ]},
    {"n": "Прочее", "g": [
        {"n": "Без категории", "s": ["Прочее"]},
    ]},
    {"n": "Тренажеры", "g": [
        {"n": "Тренажеры", "s": ["Тренажеры"]},
    ]},
]

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

def api_call(action, data=None):
    url = f"{API_BASE}?action={action}"
    body = json.dumps(data).encode() if data else None
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    resp = urllib.request.urlopen(req, context=ctx)
    return json.loads(resp.read().decode())

def create_cat(name, parent_id=None):
    result = api_call("products.category_create", {"name": name, "parent_id": parent_id})
    cat_id = result.get("id")
    print(f"  + [{cat_id}] {name}" + (f" (parent={parent_id})" if parent_id else ""))
    return cat_id

def main():
    # Check existing — categories with id > 0 means tree already imported
    # We just continue and add whatever is missing
    print("Importing category tree...")

    total = 0
    for section in TREE:
        section_id = create_cat(section["n"])
        total += 1
        for group in section.get("g", []):
            group_id = create_cat(group["n"], section_id)
            total += 1
            for sub_name in group.get("s", []):
                create_cat(sub_name, group_id)
                total += 1

    print(f"\nDone: {total} categories imported.")

if __name__ == "__main__":
    main()
