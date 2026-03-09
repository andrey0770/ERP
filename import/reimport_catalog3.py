"""
Reimport catalog v3: from updated catalog_new.trln
Top-level categories directly (no Бильярд wrapper).
Plus Карты игральные at the end.
"""
import json, re, requests

API = "https://sborka.billiarder.ru/erp/api/"

with open("catalogue/catalog_new.trln", "r", encoding="utf-8") as f:
    data = json.load(f)
nodes = {n["uid"]: n for n in data["nodes"]}
top_uids = data["properties"]["topnodes"]

def clean(name):
    return re.sub(r'\s*\(\d+\)\s*$', '', name).strip()

def extract_sku(name):
    m = re.search(r'\[([^\]]+)\]\s*$', name)
    return m.group(1) if m else None

# Build ordered category list with product SKU mappings
categories = []  # [{name, parent_idx, skus}]
sku_to_cat_idx = {}

def walk(uid, parent_idx):
    n = nodes[uid]
    name = n["data"].get("Имя", "")
    kids = n.get("children", [])
    sku = extract_sku(name)
    if not kids and sku:
        # Product leaf
        if parent_idx is not None:
            categories[parent_idx]["skus"].append(sku)
            sku_to_cat_idx[sku] = parent_idx
        return

    cat_name = clean(name)
    idx = len(categories)
    categories.append({"name": cat_name, "parent_idx": parent_idx, "skus": []})

    for cuid in kids:
        cn = nodes.get(cuid)
        if not cn:
            continue
        cname = cn["data"].get("Имя", "")
        csku = extract_sku(cname)
        ckids = cn.get("children", [])
        if not ckids and csku:
            # Direct product child
            categories[idx]["skus"].append(csku)
            sku_to_cat_idx[csku] = idx
        else:
            walk(cuid, idx)

for uid in top_uids:
    walk(uid, None)

# Add Карты игральные
cards_idx = len(categories)
categories.append({"name": "Карты игральные", "parent_idx": None, "skus": []})

# Print structure
print("Categories in order:")
for i, c in enumerate(categories):
    depth = 0
    pi = c["parent_idx"]
    while pi is not None:
        depth += 1
        pi = categories[pi]["parent_idx"]
    prods = f" [{len(c['skus'])} prods]" if c["skus"] else ""
    print(f"  {'  '*depth}{c['name']}{prods}")
print(f"\nTotal: {len(categories)} categories, {len(sku_to_cat_idx)} SKU mappings")

# Clear and recreate
print("\nClearing categories...")
requests.get(API, params={"action": "products.categories_clear"})

print("Creating categories...")
idx_to_id = {}
for i, c in enumerate(categories):
    parent_id = idx_to_id.get(c["parent_idx"])
    payload = {"name": c["name"]}
    if parent_id:
        payload["parent_id"] = parent_id
    r = requests.post(API, params={"action": "products.category_create"}, json=payload)
    res = r.json()
    if res.get("ok"):
        idx_to_id[i] = res["id"]
    else:
        print(f"  ERROR: {c['name']}: {res}")
print(f"Created {len(idx_to_id)} categories")

# Map products
print("\nFetching products...")
prods = requests.get(API, params={"action": "products.list", "limit": "9999"}).json().get("items", [])
sku_to_prod = {p["sku"]: p for p in prods}
print(f"  {len(prods)} products")

# Reset all
all_ids = [p["id"] for p in prods]
requests.post(API, params={"action": "products.bulk_move"}, json={"ids": all_ids, "category_id": None})

# Batch move
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

# Карты -> SKU 9448
cards_cat_id = idx_to_id.get(cards_idx)
if cards_cat_id and "9448" in sku_to_prod:
    requests.post(API, params={"action": "products.bulk_move"},
                  json={"ids": [sku_to_prod["9448"]["id"]], "category_id": cards_cat_id})
    print(f"  Карты игральные: 1 product")

# Check unmapped
prods_after = requests.get(API, params={"action": "products.list", "limit": "9999"}).json().get("items", [])
no_cat = [p for p in prods_after if not p.get("category_id")]
print(f"\nDone! Unmapped: {len(no_cat)}")
for p in no_cat[:30]:
    print(f"  - [{p.get('sku')}] {p.get('name','')[:80]}")
