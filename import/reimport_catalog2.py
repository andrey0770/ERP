"""
Reimport catalog: remove Бильярд wrapper, keep exact .trln order, add Карты игральные.
"""
import json, re, requests

API = "https://sborka.billiarder.ru/erp/api/"

# ── Parse .trln to get ordered structure ─────────────────
with open("catalogue/catalog_new.trln", "r", encoding="utf-8") as f:
    data = json.load(f)
nodes = {n["uid"]: n for n in data["nodes"]}

def clean(name):
    return re.sub(r'\s*\(\d+\)\s*$', '', name).strip()

def extract_sku(name):
    m = re.search(r'\[([^\]]+)\]\s*$', name)
    return m.group(1) if m else None

# Walk tree: skip top-level Бильярд, its children become top-level
top_uid = data["properties"]["topnodes"][0]
billiard = nodes[top_uid]

# Structure: list of (name, parent_index, [skus])
# We'll create categories in exact order
categories = []  # [{name, parent_idx (or None), skus: []}]
sku_to_cat_idx = {}

def walk(uid, parent_idx):
    n = nodes[uid]
    name = n["data"].get("Имя", "")
    children = n.get("children", [])
    
    # Check if product leaf
    sku = extract_sku(name)
    if not children and sku:
        if parent_idx is not None:
            categories[parent_idx]["skus"].append(sku)
            sku_to_cat_idx[sku] = parent_idx
        return
    
    # It's a category
    cat_name = clean(name)
    idx = len(categories)
    categories.append({"name": cat_name, "parent_idx": parent_idx, "skus": []})
    
    # Non-product leaf children (items directly under this category in .trln)
    for cuid in children:
        cn = nodes[cuid]
        csku = extract_sku(cn["data"].get("Имя", ""))
        if not cn.get("children") and csku:
            categories[idx]["skus"].append(csku)
            sku_to_cat_idx[csku] = idx
    
    # Recurse into category children only
    for cuid in children:
        cn = nodes[cuid]
        if cn.get("children") or not extract_sku(cn["data"].get("Имя", "")):
            if cn.get("children"):
                walk(cuid, idx)

# Children of Бильярд become top-level
for child_uid in billiard["children"]:
    cn = nodes[child_uid]
    if cn.get("children") or not extract_sku(cn["data"].get("Имя", "")):
        walk(child_uid, None)

# Add "Карты игральные" as top-level leaf
cards_idx = len(categories)
categories.append({"name": "Карты игральные", "parent_idx": None, "skus": []})

print("Categories in order:")
for i, c in enumerate(categories):
    depth = 0
    pi = c["parent_idx"]
    while pi is not None:
        depth += 1
        pi = categories[pi]["parent_idx"]
    skus_info = f" [{len(c['skus'])} products]" if c["skus"] else ""
    print(f"  {'  '*depth}{c['name']}{skus_info}")

print(f"\nTotal: {len(categories)} categories, {len(sku_to_cat_idx)} product mappings")

# ── Clear and recreate ───────────────────────────────────
print("\nClearing categories...")
r = requests.get(API, params={"action": "products.categories_clear"})
print(f"  {r.json()}")

print("\nCreating categories...")
idx_to_id = {}
for i, c in enumerate(categories):
    parent_id = idx_to_id.get(c["parent_idx"])
    payload = {"name": c["name"]}
    if parent_id:
        payload["parent_id"] = parent_id
    r = requests.post(API, params={"action": "products.category_create"}, json=payload)
    result = r.json()
    if result.get("ok"):
        idx_to_id[i] = result["id"]
    else:
        print(f"  ERROR creating {c['name']}: {result}")

print(f"Created {len(idx_to_id)} categories")

# ── Map products ─────────────────────────────────────────
print("\nFetching products...")
prods = requests.get(API, params={"action": "products.list", "limit": "9999"}).json().get("items", [])
sku_to_prod = {p["sku"]: p for p in prods}
print(f"  {len(prods)} products")

# Reset all
all_ids = [p["id"] for p in prods]
requests.post(API, params={"action": "products.bulk_move"}, json={"ids": all_ids, "category_id": None})

# Batch by category
for i, c in enumerate(categories):
    if not c["skus"]:
        continue
    cat_id = idx_to_id.get(i)
    if not cat_id:
        continue
    prod_ids = [sku_to_prod[s]["id"] for s in c["skus"] if s in sku_to_prod]
    if prod_ids:
        requests.post(API, params={"action": "products.bulk_move"}, json={"ids": prod_ids, "category_id": cat_id})
        print(f"  {c['name']}: {len(prod_ids)} products")

# Move "Карты" product (SKU 9448) to Карты игральные
cards_cat_id = idx_to_id.get(cards_idx)
if cards_cat_id and "9448" in sku_to_prod:
    requests.post(API, params={"action": "products.bulk_move"}, 
                  json={"ids": [sku_to_prod["9448"]["id"]], "category_id": cards_cat_id})
    print(f"  Карты игральные: 1 product (SKU 9448)")

# Check unmapped
prods_after = requests.get(API, params={"action": "products.list", "limit": "9999"}).json().get("items", [])
no_cat = [p for p in prods_after if not p.get("category_id")]
print(f"\nDone! Unmapped: {len(no_cat)}")
for p in no_cat[:30]:
    print(f"  - [{p.get('sku')}] {p.get('name','')[:80]}")
