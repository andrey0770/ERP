#!/usr/bin/env python3
"""
ERP Marketplace Product Importer
─────────────────────────────────
Скачивает товары из Ozon Seller API и Яндекс.Маркет,
загружает картинки в S3 (Yandex Cloud), импортирует в ERP.

Зависимости: pip install requests boto3
"""

import os
import sys
import json
import time
import hashlib
import requests
import boto3
from botocore.config import Config as BotoConfig
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from dotenv import load_dotenv

# Load .env from same directory as this script
load_dotenv(Path(__file__).parent / ".env")

# Force unbuffered output
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

def log(msg):
    print(msg, flush=True)

# ═══════════════════════════════════════════════════════════════
# CONFIGURATION
# ═══════════════════════════════════════════════════════════════

# ERP API
ERP_API = "https://sborka.billiarder.ru/erp/api/"

# Ozon Seller API — direct
OZON_STORES = {
    "kp": {
        "name": "КП - Бильярд с 1999",
        "client_id": os.environ["OZON_KP_CLIENT_ID"],
        "api_key": os.environ["OZON_KP_API_KEY"],
    },
    "bsh": {
        "name": "БСХ - Бильярд Спорт и Хобби",
        "client_id": os.environ["OZON_BSH_CLIENT_ID"],
        "api_key": os.environ["OZON_BSH_API_KEY"],
    },
}

# Yandex Market — через Cloudflare Worker proxy
CF_WORKER_URL = "https://shrill-field-1c66.andrey0770.workers.dev"
YM_BUSINESS_ID = os.environ.get("YM_BUSINESS_ID", "705133")

# S3 (Yandex Cloud)
S3_CONFIG = {
    "endpoint_url": "https://storage.yandexcloud.net",
    "region_name": "ru-central1",
    "aws_access_key_id": os.environ["S3_ACCESS_KEY_ID"],
    "aws_secret_access_key": os.environ["S3_SECRET_ACCESS_KEY"],
}
S3_BUCKET = "sborka-video"
S3_PREFIX = "erp/products/"  # prefix for product images
S3_PUBLIC_URL = f"https://{S3_BUCKET}.storage.yandexcloud.net"

# ═══════════════════════════════════════════════════════════════
# S3 CLIENT
# ═══════════════════════════════════════════════════════════════

def get_s3_client():
    session = boto3.session.Session()
    return session.client(
        "s3",
        endpoint_url=S3_CONFIG["endpoint_url"],
        region_name=S3_CONFIG["region_name"],
        aws_access_key_id=S3_CONFIG["aws_access_key_id"],
        aws_secret_access_key=S3_CONFIG["aws_secret_access_key"],
        config=BotoConfig(signature_version="s3v4"),
    )


def upload_image_to_s3(s3, image_url: str, sku: str, idx: int = 0) -> str | None:
    """Download image from URL and upload to S3. Returns S3 public URL."""
    if not image_url:
        return None
    try:
        resp = requests.get(image_url, timeout=30, stream=True)
        resp.raise_for_status()

        # Determine extension from content-type
        ct = resp.headers.get("Content-Type", "image/jpeg")
        ext_map = {"image/jpeg": ".jpg", "image/png": ".png", "image/webp": ".webp", "image/gif": ".gif"}
        ext = ext_map.get(ct.split(";")[0].strip(), ".jpg")

        # Clean SKU for filename
        clean_sku = "".join(c if c.isalnum() or c in "-_." else "_" for c in sku)
        suffix = f"_{idx}" if idx > 0 else ""
        s3_key = f"{S3_PREFIX}{clean_sku}{suffix}{ext}"

        s3.put_object(
            Bucket=S3_BUCKET,
            Key=s3_key,
            Body=resp.content,
            ContentType=ct.split(";")[0].strip(),
            # Make publicly readable
        )
        return f"{S3_PUBLIC_URL}/{s3_key}"
    except Exception as e:
        log(f"  ⚠ Image upload failed for {sku}: {e}")
        return None


# ═══════════════════════════════════════════════════════════════
# OZON SELLER API
# ═══════════════════════════════════════════════════════════════

OZON_API = "https://api-seller.ozon.ru"


def ozon_request(store_key: str, method: str, body: dict = None) -> dict:
    """Make authenticated Ozon Seller API request."""
    store = OZON_STORES[store_key]
    headers = {
        "Client-Id": store["client_id"],
        "Api-Key": store["api_key"],
        "Content-Type": "application/json",
    }
    url = f"{OZON_API}{method}"
    resp = requests.post(url, json=body or {}, headers=headers, timeout=60)
    resp.raise_for_status()
    return resp.json()


def fetch_ozon_product_ids(store_key: str) -> list[int]:
    """Get all product IDs from Ozon store (with pagination)."""
    all_ids = []
    last_id = ""
    while True:
        body = {"filter": {"visibility": "ALL"}, "limit": 1000}
        if last_id:
            body["last_id"] = last_id
        data = ozon_request(store_key, "/v3/product/list", body)
        result = data.get("result", {})
        items = result.get("items", [])
        if not items:
            break
        all_ids.extend([it["product_id"] for it in items])
        last_id = result.get("last_id", "")
        total = result.get("total", 0)
        log(f"  📦 {store_key}: got {len(all_ids)}/{total} product IDs...")
        if len(items) < 1000:
            break
        time.sleep(0.5)
    return all_ids


def fetch_ozon_product_details(store_key: str, product_ids: list[int]) -> list[dict]:
    """Get detailed product info in batches of 100."""
    all_products = []
    for i in range(0, len(product_ids), 100):
        batch = product_ids[i : i + 100]
        data = ozon_request(store_key, "/v3/product/info/list", {"product_id": batch})
        items = data.get("items", [])
        all_products.extend(items)
        log(f"  📋 Details: {len(all_products)}/{len(product_ids)}")
        time.sleep(0.3)
    return all_products


def fetch_ozon_products_full(store_key: str) -> list[dict]:
    """Fetch all products with attributes from Ozon store."""
    # Step 1: Get product IDs
    product_ids = fetch_ozon_product_ids(store_key)
    if not product_ids:
        log(f"  ⚠ No products found in {store_key}")
        return []

    # Step 2: Get details
    products = fetch_ozon_product_details(store_key, product_ids)

    # Step 3: Get attributes in batches (for descriptions)
    all_attrs = []
    for i in range(0, len(product_ids), 100):
        batch = product_ids[i : i + 100]
        try:
            body = {
                "filter": {"product_id": batch, "visibility": "ALL"},
                "limit": 100,
            }
            data = ozon_request(store_key, "/v4/product/info/attributes", body)
            items = data.get("result", [])
            all_attrs.extend(items)
        except Exception as e:
            log(f"  ⚠ Attributes batch error: {e}")
        time.sleep(0.3)

    # Index attrs by product_id (v4 uses "id" field)
    attrs_map = {}
    for a in all_attrs:
        pid = a.get("id") or a.get("product_id")
        if pid:
            attrs_map[pid] = a

    # Merge
    for p in products:
        pid = p.get("id")
        if pid in attrs_map:
            p["_attrs"] = attrs_map[pid]

    return products


def normalize_ozon_product(p: dict, store_key: str) -> dict:
    """Normalize Ozon product into ERP format."""
    offer_id = p.get("offer_id", "")
    name = p.get("name", "")
    barcode = ""
    barcodes = p.get("barcodes", [])
    if isinstance(barcodes, list) and barcodes:
        barcode = barcodes[0] if isinstance(barcodes[0], str) else str(barcodes[0])
    elif isinstance(barcodes, dict):
        barcode = barcodes.get("ean13", "") or barcodes.get("upc", "") or ""
    elif isinstance(barcodes, str):
        barcode = barcodes

    # Images (v3: images is a list of URLs)
    images = p.get("images", []) or []
    if isinstance(images, str):
        images = [images] if images else []
    primary_image = images[0] if images else ""

    # Price
    price_data = p.get("price", "") or p.get("marketing_price", "") or p.get("old_price", "")
    sell_price = None
    if isinstance(price_data, str) and price_data:
        try:
            sell_price = float(price_data)
        except (ValueError, TypeError):
            pass

    # Description from attributes
    description = ""
    attrs = p.get("_attrs", {})
    if attrs:
        for attr in attrs.get("attributes", []):
            if attr.get("id") == 4191:  # description
                for v in attr.get("values", []):
                    description = v.get("value", "")

    # Brand
    brand = ""
    if attrs:
        for attr in attrs.get("attributes", []):
            if attr.get("id") == 85:  # brand
                for v in attr.get("values", []):
                    brand = v.get("value", "")

    # Weight (in grams → kg)
    weight = None
    if attrs:
        for attr in attrs.get("attributes", []):
            if attr.get("id") in (4497, 4382):  # weight
                for v in attr.get("values", []):
                    try:
                        w = float(v.get("value", "0"))
                        if w > 100:  # likely grams
                            weight = w / 1000
                        else:
                            weight = w
                    except (ValueError, TypeError):
                        pass

    # FBS/FBO SKU from stocks
    fbs_sku = ""
    fbo_sku = ""
    stocks_data = p.get("stocks", {})
    for st in stocks_data.get("stocks", []):
        if st.get("source") == "fbs":
            fbs_sku = str(st.get("sku", ""))
        elif st.get("source") == "fbo":
            fbo_sku = str(st.get("sku", ""))

    return {
        "sku": offer_id,
        "name": name,
        "barcode": barcode,
        "ozon_product_id": str(p.get("id", "")),
        "ozon_sku": fbs_sku or fbo_sku,
        "marketplace_source": f"ozon_{store_key}",
        "sell_price": sell_price,
        "description": description[:5000] if description else None,
        "brand": brand or None,
        "weight": weight,
        "image_url": primary_image or (images[0] if images else None),
        "_images": images,  # original URLs for S3 upload
    }


# ═══════════════════════════════════════════════════════════════
# YANDEX MARKET API (via Cloudflare Worker)
# ═══════════════════════════════════════════════════════════════

def ym_request(method: str, path: str, body: dict = None) -> dict:
    """Request to Yandex Market via CF Worker proxy."""
    url = f"{CF_WORKER_URL}/yandex{path}"
    if method == "GET":
        resp = requests.get(url, timeout=60)
    else:
        resp = requests.post(url, json=body or {}, timeout=60, headers={"Content-Type": "application/json"})
    resp.raise_for_status()
    return resp.json()


def fetch_ym_products() -> list[dict]:
    """Fetch all products from Yandex Market via offer-mappings endpoint."""
    all_products = []
    page_token = None

    log(f"\n📦 Загрузка товаров Яндекс.Маркет (business {YM_BUSINESS_ID})...")

    while True:
        # Use direct Yandex API path through worker (POST method)
        path = f"/businesses/{YM_BUSINESS_ID}/offer-mappings"
        body = {"limit": 200}
        if page_token:
            body["page_token"] = page_token

        try:
            url = f"{CF_WORKER_URL}/yandex{path}"
            resp = requests.post(url, json=body, timeout=60,
                                 headers={"Content-Type": "application/json"})
            resp.raise_for_status()
            data = resp.json()
        except Exception as e:
            log(f"  ⚠ YM fetch error: {e}")
            break

        result = data.get("result", {})
        items = result.get("offerMappings", [])
        if not items:
            break

        all_products.extend(items)
        log(f"  📋 YM: loaded {len(all_products)} offer mappings...")

        paging = result.get("paging", {})
        page_token = paging.get("nextPageToken")
        if not page_token:
            break
        time.sleep(0.5)

    log(f"  ✅ Яндекс.Маркет: {len(all_products)} товаров")
    return all_products


def normalize_ym_product(item: dict) -> dict:
    """Normalize YM offer-mapping into ERP format."""
    offer = item.get("offer", {})
    mapping = item.get("mapping", {})

    offer_id = offer.get("offerId", "")
    name = offer.get("name", "")
    barcode = ""
    barcodes = offer.get("barcodes", [])
    if barcodes:
        barcode = barcodes[0] if isinstance(barcodes[0], str) else ""

    # Category
    category = offer.get("category", "")

    # Description
    description = offer.get("description", "")

    # Brand / vendor
    brand = offer.get("vendor", "") or offer.get("manufacturer", "")

    # Weight
    weight = None
    weight_dims = offer.get("weightDimensions", {})
    if weight_dims:
        w = weight_dims.get("weight")
        if w:
            try:
                weight = float(w)
            except (ValueError, TypeError):
                pass

    # Price
    sell_price = None
    basic_price = offer.get("basicPrice", {})
    if basic_price:
        try:
            sell_price = float(basic_price.get("value", 0))
        except (ValueError, TypeError):
            pass

    # Images
    images = offer.get("pictures", []) or []

    # SKU from mapping
    ya_market_sku = str(mapping.get("marketSku", "")) if mapping.get("marketSku") else None

    return {
        "sku": offer_id,
        "name": name,
        "barcode": barcode or None,
        "ya_market_sku": ya_market_sku,
        "ya_offer_id": offer_id,
        "marketplace_source": "yandex_market",
        "sell_price": sell_price,
        "description": description[:5000] if description else None,
        "brand": brand or None,
        "weight": weight,
        "image_url": images[0] if images else None,
        "_images": images,
    }


# ═══════════════════════════════════════════════════════════════
# IMAGE PROCESSING
# ═══════════════════════════════════════════════════════════════

def process_images(products: list[dict], s3) -> list[dict]:
    """Download images from marketplaces and upload to S3."""
    log(f"\n🖼  Загрузка картинок в S3 ({len(products)} товаров)...")
    total_images = 0
    failed_images = 0

    for i, p in enumerate(products):
        images = p.pop("_images", [])
        if not images:
            continue

        s3_urls = []
        # Upload main image
        main_url = upload_image_to_s3(s3, images[0], p["sku"], 0)
        if main_url:
            p["image_url"] = main_url
            s3_urls.append(main_url)
            total_images += 1
        else:
            failed_images += 1

        # Upload additional images
        for idx, img_url in enumerate(images[1:], start=1):
            s3_url = upload_image_to_s3(s3, img_url, p["sku"], idx)
            if s3_url:
                s3_urls.append(s3_url)
                total_images += 1
            else:
                failed_images += 1

        p["images"] = s3_urls

        if (i + 1) % 50 == 0:
            log(f"  🖼  Обработано {i+1}/{len(products)} товаров, {total_images} картинок загружено")

    log(f"  ✅ Картинки: {total_images} загружено, {failed_images} ошибок")
    return products


# ═══════════════════════════════════════════════════════════════
# ERP IMPORT
# ═══════════════════════════════════════════════════════════════

def import_to_erp(products: list[dict]) -> dict:
    """Send products to ERP import API in batches."""
    log(f"\n📤 Импорт в ERP ({len(products)} товаров)...")

    total_created = 0
    total_updated = 0
    all_errors = []

    batch_size = 100
    for i in range(0, len(products), batch_size):
        batch = products[i : i + batch_size]
        # Remove internal fields
        clean_batch = []
        for p in batch:
            item = {k: v for k, v in p.items() if not k.startswith("_") and v is not None}
            clean_batch.append(item)

        try:
            resp = requests.post(
                ERP_API,
                params={"action": "products.import"},
                json={"products": clean_batch},
                timeout=120,
            )
            resp.raise_for_status()
            data = resp.json()
            total_created += data.get("created", 0)
            total_updated += data.get("updated", 0)
            errors = data.get("errors", [])
            if errors:
                all_errors.extend(errors)
            log(f"  📤 Batch {i//batch_size + 1}: +{data.get('created',0)} new, ~{data.get('updated',0)} upd")
        except Exception as e:
            log(f"  ❌ Batch error: {e}")
            all_errors.append(str(e))
        
        time.sleep(0.3)

    result = {
        "created": total_created,
        "updated": total_updated,
        "errors": all_errors,
    }
    log(f"\n✅ Импорт завершён: {total_created} создано, {total_updated} обновлено, {len(all_errors)} ошибок")
    return result


# ═══════════════════════════════════════════════════════════════
# DEDUPLICATION
# ═══════════════════════════════════════════════════════════════

def deduplicate_products(products: list[dict]) -> list[dict]:
    """Deduplicate by SKU, preferring Ozon data if both exist."""
    by_sku = {}
    for p in products:
        sku = p.get("sku", "")
        if not sku:
            continue
        if sku in by_sku:
            # Merge: fill empty fields from new source
            existing = by_sku[sku]
            for k, v in p.items():
                if v and not existing.get(k):
                    existing[k] = v
            # Merge marketplace_source
            src = existing.get("marketplace_source", "")
            new_src = p.get("marketplace_source", "")
            if new_src and new_src not in src:
                existing["marketplace_source"] = f"{src},{new_src}"
            # Merge images
            ex_imgs = existing.get("_images", [])
            new_imgs = p.get("_images", [])
            for img in new_imgs:
                if img not in ex_imgs:
                    ex_imgs.append(img)
            existing["_images"] = ex_imgs
        else:
            by_sku[sku] = p

    result = list(by_sku.values())
    log(f"  ⚡ Дедупликация: {len(products)} → {len(result)} уникальных SKU")
    return result


# ═══════════════════════════════════════════════════════════════
# MAIN
# ═══════════════════════════════════════════════════════════════

def main():
    log("=" * 60)
    log("ERP Marketplace Product Importer")
    log("=" * 60)

    all_products = []

    # ── OZON ──────────────────────────────────────────────
    for store_key, store_info in OZON_STORES.items():
        log(f"\n📦 Ozon: {store_info['name']} ({store_key})...")
        try:
            raw = fetch_ozon_products_full(store_key)
            log(f"  📊 Получено {len(raw)} товаров из Ozon {store_key}")
            for p in raw:
                normalized = normalize_ozon_product(p, store_key)
                if normalized["sku"]:
                    all_products.append(normalized)
        except Exception as e:
            log(f"  ❌ Ozon {store_key} error: {e}")

    # (Яндекс.Маркет — отложен, будет добавлен позже через МойСклад)

    if not all_products:
        log("\n⚠ Товары не найдены. Проверьте API ключи и подключения.")
        sys.exit(1)

    log(f"\n📊 Всего: {len(all_products)} товаров из маркетплейсов")

    # ── DEDUPLICATE ──────────────────────────────────────
    all_products = deduplicate_products(all_products)

    # ── IMAGES → S3 ─────────────────────────────────────
    s3 = get_s3_client()
    all_products = process_images(all_products, s3)

    # ── IMPORT TO ERP ────────────────────────────────────
    result = import_to_erp(all_products)

    # ── SUMMARY ──────────────────────────────────────────
    log("\n" + "=" * 60)
    log("ИТОГО:")
    log(f"  Создано:    {result['created']}")
    log(f"  Обновлено:  {result['updated']}")
    log(f"  Ошибки:     {len(result['errors'])}")
    if result['errors'][:5]:
        log("  Первые ошибки:")
        for e in result['errors'][:5]:
            log(f"    - {e}")
    log("=" * 60)

    # Save log
    log_path = Path(__file__).parent / "import_log.json"
    with open(log_path, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, indent=2)
    log(f"\nЛог сохранён: {log_path}")


if __name__ == "__main__":
    main()
