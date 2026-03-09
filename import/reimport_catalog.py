"""
Reimport catalog structure from catalogue/catalog_new.trln into ERP.
Steps:
1. Parse .trln to get category tree (without individual products)
2. Clear existing categories via API
3. Create new categories via API (preserving hierarchy)
4. Map products to new categories by matching SKUs from .trln
"""
import json
import re
import requests

API = "https://sborka.billiarder.ru/erp/api/"

# ── Parse .trln ──────────────────────────────────────────
with open("catalogue/catalog_new.trln", "r", encoding="utf-8") as f:
    data = json.load(f)

nodes_by_uid = {n["uid"]: n for n in data["nodes"]}
top_uids = data["properties"]["topnodes"]

def extract_sku(name):
    """Extract SKU from product name like 'Product name  [SKU]'"""
    m = re.search(r'\[([^\]]+)\]\s*$', name)
    return m.group(1) if m else None

def is_product_node(node):
    """A product node has no children and its name contains [SKU]"""
    return not node.get("children") and extract_sku(node["data"].get("Имя", ""))

def clean_cat_name(name):
    """Remove count suffix like ' (15)' from category names"""
    return re.sub(r'\s*\(\d+\)\s*$', '', name).strip()

# ══ Collect category structure and product→category mappings ══
categories = []  # list of (path, name, parent_path, children_paths)
product_sku_to_category = {}  # sku -> category path

def walk(uid, parent_path=""):
    node = nodes_by_uid.get(uid)
    if not node:
        return
    name = node["data"].get("Имя", "?")
    children = node.get("children", [])
    
    # Check if this is a product (leaf with SKU)
    sku = extract_sku(name)
    if not children and sku:
        product_sku_to_category[sku] = parent_path
        return
    
    # It's a category
    cat_name = clean_cat_name(name)
    path = f"{parent_path}/{cat_name}" if parent_path else cat_name
    categories.append({"name": cat_name, "path": path, "parent_path": parent_path})
    
    # Recurse into children
    for child_uid in children:
        walk(child_uid, path)

for uid in top_uids:
    walk(uid)

print(f"Categories found: {len(categories)}")
print(f"Product→category mappings (by SKU): {len(product_sku_to_category)}")

# Print tree
for cat in categories:
    depth = cat["path"].count("/")
    print("  " * depth + cat["name"])

# ── Clear existing categories ────────────────────────────
print("\nClearing existing categories...")
r = requests.get(API, params={"action": "products.categories_clear"})
print(f"  {r.json()}")

# ── Create new categories (preserving hierarchy) ─────────
# We must create parents before children
path_to_id = {}  # path -> ERP category id

print("\nCreating categories...")
for cat in categories:
    parent_id = path_to_id.get(cat["parent_path"]) if cat["parent_path"] else None
    payload = {"name": cat["name"]}
    if parent_id:
        payload["parent_id"] = parent_id
    
    r = requests.post(API, params={"action": "products.category_create"}, json=payload)
    result = r.json()
    if result.get("ok"):
        path_to_id[cat["path"]] = result["id"]
        print(f"  ✓ {cat['name']} (id={result['id']})")
    else:
        print(f"  ✗ {cat['name']}: {result}")

print(f"\nCreated {len(path_to_id)} categories")

# ── Map products to new categories ───────────────────────
print("\nFetching all products from ERP...")
prods = requests.get(API, params={"action": "products.list", "limit": "9999"}).json().get("items", [])
print(f"  {len(prods)} products")

# Build SKU → product id map
sku_to_prod_id = {p["sku"]: p["id"] for p in prods if p.get("sku")}

# First, reset all categories
print("\nResetting all product categories...")
all_ids = [p["id"] for p in prods]
if all_ids:
    r = requests.post(API, params={"action": "products.bulk_move"}, json={"ids": all_ids, "category_id": None})
    print(f"  Reset: {r.json()}")

# Map by SKU from .trln
mapped = 0
unmapped_skus = []
for sku, cat_path in product_sku_to_category.items():
    cat_id = path_to_id.get(cat_path)
    prod_id = sku_to_prod_id.get(sku)
    if cat_id and prod_id:
        mapped += 1
    elif not prod_id:
        # Product in .trln but not in ERP — that's fine
        pass
    else:
        unmapped_skus.append(sku)

# Batch move products by category
cat_products = {}  # cat_id -> [prod_ids]
for sku, cat_path in product_sku_to_category.items():
    cat_id = path_to_id.get(cat_path)
    prod_id = sku_to_prod_id.get(sku)
    if cat_id and prod_id:
        cat_products.setdefault(cat_id, []).append(prod_id)

for cat_id, prod_ids in cat_products.items():
    r = requests.post(API, params={"action": "products.bulk_move"}, json={"ids": prod_ids, "category_id": cat_id})
    result = r.json()
    cat_name = [c["name"] for c in categories if path_to_id.get(c["path"]) == cat_id]
    print(f"  → {cat_name[0] if cat_name else cat_id}: {len(prod_ids)} products")

# Check unmapped products in ERP
prods_after = requests.get(API, params={"action": "products.list", "limit": "9999"}).json().get("items", [])
no_cat = [p for p in prods_after if not p.get("category_id")]
print(f"\nDone! Mapped: {mapped}, Unmapped products (no category): {len(no_cat)}")
if no_cat:
    print("Unmapped products:")
    for p in no_cat[:20]:
        print(f"  - [{p.get('sku')}] {p.get('name','')[:80]}")
    if len(no_cat) > 20:
        print(f"  ... and {len(no_cat) - 20} more")
