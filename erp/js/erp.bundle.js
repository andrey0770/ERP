// ── ERP Bundle (auto-generated) ──
(function() {
"use strict";

// ── core.js ──
// ── core.js — API client & shared utilities ─────
const API_BASE = (() => {
    const loc = window.location;
    if (loc.hostname === 'localhost' || loc.hostname === '127.0.0.1') {
        return 'http://localhost/erp/api/index.php';
    }
    return 'https://sborka.billiarder.ru/erp/api/index.php';
})();
const API_TOKEN = '';

async function api(action, data = null) {
    const params = new URLSearchParams({ action });
    const opts = {
        headers: { 'Content-Type': 'application/json' },
    };
    if (API_TOKEN) opts.headers['Authorization'] = `Bearer ${API_TOKEN}`;

    if (data && typeof data === 'object' && !Array.isArray(data) && Object.keys(data).some(k => data[k] !== undefined)) {
        opts.method = 'POST';
        opts.body = JSON.stringify(data);
    } else {
        opts.method = 'GET';
        if (data && typeof data === 'object') {
            Object.entries(data).forEach(([k, v]) => {
                if (v !== undefined && v !== null && v !== '') params.set(k, v);
            });
        }
    }

    const url = `${API_BASE}?${params}`;
    const resp = await fetch(url, opts);
    const json = await resp.json();
    if (!resp.ok || json.error) throw new Error(json.error || `HTTP ${resp.status}`);
    return json;
}

// ── Formatting helpers ──────────────────────────
function formatMoney(val) {
    const n = parseFloat(val) || 0;
    return n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₽';
}

function formatDate(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString('ru-RU');
}

function formatDateTime(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

// ── Label helpers ───────────────────────────────
function sourceLabel(src) {
    const map = { ozon_kp: 'Ozon КП', ozon_bsh: 'Ozon БСХ', yandex_market: 'Яндекс.Маркет', moysklad: 'МойСклад' };
    return map[src] || src || '';
}

function movementTypeLabel(type) {
    const map = { purchase: 'Приход', sale: 'Расход', adjustment: 'Коррекция', transfer_in: 'Вход', transfer_out: 'Выход', return: 'Возврат' };
    return map[type] || type;
}


// ── dashboard.js ──
// ── dashboard.js — Dashboard, Quick Entry, Journal ──
function initDashboard(ctx) {
    const { api, toast, showModal, detailData, ref, reactive } = ctx;

    // Dashboard
    const dash = reactive({ income: 0, expense: 0, profit: 0, openTasks: 0, overdue: 0, products: 0, lowStock: 0, journalEntries: 0 });
    const recentJournal = ref([]);

    async function loadDashboard() {
        try {
            const [fin, taskSt, journalSt, prodCount] = await Promise.all([
                api('finance.summary').catch(() => ({ income: 0, expense: 0, profit: 0 })),
                api('tasks.stats').catch(() => ({ overdue: 0, due_today: 0 })),
                api('journal.stats').catch(() => ({ total: 0 })),
                api('products.low_stock').catch(() => ({ items: [] })),
            ]);
            dash.income = fin.income || 0;
            dash.expense = fin.expense || 0;
            dash.profit = fin.profit || 0;
            dash.openTasks = taskSt.by_status?.find(s => s.status !== 'done' && s.status !== 'cancelled')?.cnt || 0;
            dash.overdue = taskSt.overdue || 0;
            dash.journalEntries = journalSt.total || 0;
            dash.lowStock = prodCount.items?.length || 0;

            const jList = await api('journal.list', { limit: 10 }).catch(() => ({ items: [] }));
            recentJournal.value = jList.items || [];

            const pList = await api('products.list', { limit: 1 }).catch(() => ({ total: 0 }));
            dash.products = pList.total || 0;
        } catch (e) { console.warn('Dashboard load error:', e); }
    }

    // Quick entry
    const quickEntry = ref('');
    const submitting = ref(false);

    async function submitQuickEntry() {
        if (!quickEntry.value.trim() || submitting.value) return;
        submitting.value = true;
        try {
            const result = await api('journal.create', { text: quickEntry.value, source: 'web' });
            toast('Запись создана' + (result.ai_parsed ? ' (AI обработал)' : ''), 'success');
            quickEntry.value = '';
            loadDashboard();
        } catch (e) {
            toast('Ошибка: ' + e.message, 'error');
        } finally {
            submitting.value = false;
        }
    }

    // Journal
    const journal = reactive({ items: [], total: 0 });
    const journalFilter = reactive({ category: '', from: '', to: '', q: '' });
    let journalSearchTimer = null;

    async function loadJournal() {
        try {
            const data = await api('journal.list', { ...journalFilter, limit: 50 });
            journal.items = data.items || [];
            journal.total = data.total || 0;
        } catch (e) { toast('Ошибка загрузки журнала: ' + e.message, 'error'); }
    }

    function debounceSearchJournal() {
        clearTimeout(journalSearchTimer);
        journalSearchTimer = setTimeout(() => {
            if (journalFilter.q.length >= 2) {
                api('journal.search', { q: journalFilter.q, limit: 50 })
                    .then(data => { journal.items = data.items || []; journal.total = data.items?.length || 0; })
                    .catch(() => {});
            } else {
                loadJournal();
            }
        }, 400);
    }

    async function loadMoreJournal() {
        try {
            const data = await api('journal.list', { ...journalFilter, limit: 50, offset: journal.items.length });
            journal.items.push(...(data.items || []));
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function showJournalEntry(id) {
        api('journal.get', { id }).then(data => {
            Object.assign(detailData, data);
            showModal.value = 'journalDetail';
        }).catch(e => toast(e.message, 'error'));
    }

    return {
        dash, recentJournal, loadDashboard,
        quickEntry, submitting, submitQuickEntry,
        journal, journalFilter, loadJournal, loadMoreJournal, debounceSearchJournal, showJournalEntry,
    };
}


// ── catalog.js ──
// ── catalog.js — Product catalog, categories, bulk operations ──
function initCatalog(ctx) {
    const { api, toast, showModal, editData, ref, reactive, computed } = ctx;

    // Products (simple CRUD)
    const products = reactive({ items: [], total: 0 });
    const allProducts = ref([]);
    const productFilter = reactive({ q: '' });
    let productSearchTimer = null;
    const newProduct = reactive({ sku: '', name: '', barcode: '', purchase_price: null, sell_price: null, min_stock: 0 });

    async function loadProducts() {
        try {
            const data = await api('products.list', { ...productFilter, limit: 200 });
            products.items = data.items || [];
            products.total = data.total || 0;
            allProducts.value = products.items;
        } catch (e) { toast('Ошибка загрузки товаров: ' + e.message, 'error'); }
    }

    function debounceSearchProducts() {
        clearTimeout(productSearchTimer);
        productSearchTimer = setTimeout(loadProducts, 400);
    }

    async function createProduct() {
        try {
            await api('products.create', { ...newProduct });
            toast('Товар создан', 'success');
            showModal.value = null;
            Object.assign(newProduct, { sku: '', name: '', barcode: '', purchase_price: null, sell_price: null, min_stock: 0 });
            loadProducts();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editProduct(p) {
        Object.assign(editData, { ...p, _type: 'product' });
        showModal.value = 'productEdit';
    }

    async function saveProduct() {
        try {
            await api('products.update', { id: editData.id, sku: editData.sku, name: editData.name, barcode: editData.barcode, alias: editData.alias, purchase_price: editData.purchase_price, sell_price: editData.sell_price, min_stock: editData.min_stock, ozon_product_id: editData.ozon_product_id, ozon_sku: editData.ozon_sku, supplier: editData.supplier, cue_type: editData.cue_type, cue_parts: editData.cue_parts, cue_material: editData.cue_material });
            toast('Товар обновлён', 'success');
            showModal.value = null;
            loadProducts();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Catalog
    const catalogProducts = ref([]);
    const catalogTotal = ref(0);
    const catalogCategories = ref([]);
    const catalogBrands = ref([]);
    const catalogSources = ref([]);
    const catalogSuppliers = ref([]);
    const catalogFilter = reactive({ q: '', filter_sku: '', filter_name: '', category_id: null, has_image: false, no_image: false, in_stock: false, zero_stock: false, brands: [], sources: [], suppliers: [], cue_types: [], cue_parts: [], cue_materials: [], sort: 'name' });
    const catalogView = ref('list');
    const catalogSidebarMode = ref('tree');
    const catalogLoading = ref(false);
    const colFilterOpen = ref(null);
    const colVisibleOpen = ref(false);
    const defaultCols = { image: true, sku: true, alias: false, name: true, brand: true, supplier: true, purchase_price: true, sell_price: true, stock: true, source: true, cue_type: true, cue_parts: true, cue_material: true };
    const colVisible = reactive(JSON.parse(localStorage.getItem('catalogColVisible') || 'null') || { ...defaultCols });

    function toggleColVisible(col) {
        colVisible[col] = !colVisible[col];
        localStorage.setItem('catalogColVisible', JSON.stringify(colVisible));
    }

    let catalogSearchTimer = null;
    const catalogPageSize = 60;
    const sidebarAccordion = reactive({ categories: true, filters: true, brands: false, sources: false, suppliers: false, cueAttrs: true });
    const expandedCats = reactive({});
    const catalogSidebarWidth = ref(parseInt(localStorage.getItem('catalogSidebarWidth')) || 300);

    function startSidebarResize(e) {
        e.preventDefault();
        const startX = e.clientX;
        const startW = catalogSidebarWidth.value;
        const handle = e.target;
        handle.classList.add('dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        function onMove(ev) {
            const w = Math.max(220, Math.min(500, startW + ev.clientX - startX));
            catalogSidebarWidth.value = w;
        }
        function onUp() {
            handle.classList.remove('dragging');
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            localStorage.setItem('catalogSidebarWidth', catalogSidebarWidth.value);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    // Product detail
    const productDetail = reactive({});
    const productDetailImgIdx = ref(0);

    const productDetailMainImg = computed(() => {
        if (productDetail.images_arr && productDetail.images_arr.length) {
            return productDetail.images_arr[productDetailImgIdx.value] || productDetail.image_url;
        }
        return productDetail.image_url;
    });

    async function loadCatalog(reset = true) {
        catalogLoading.value = true;
        try {
            const params = { limit: catalogPageSize, offset: reset ? 0 : catalogProducts.value.length };
            if (catalogFilter.q) params.q = catalogFilter.q;
            if (catalogFilter.category_id) params.category_id = catalogFilter.category_id;
            if (catalogFilter.sort) params.sort = catalogFilter.sort;
            if (catalogFilter.has_image) params.has_image = 1;
            if (catalogFilter.no_image) params.no_image = 1;
            if (catalogFilter.in_stock) params.in_stock = 1;
            if (catalogFilter.zero_stock) params.zero_stock = 1;
            if (catalogFilter.brands.length) params.brand = catalogFilter.brands.join(',');
            if (catalogFilter.sources.length) params.marketplace_source = catalogFilter.sources.join(',');
            if (catalogFilter.suppliers.length) params.supplier = catalogFilter.suppliers.join(',');
            if (catalogFilter.cue_types.length) params.cue_type = catalogFilter.cue_types.join(',');
            if (catalogFilter.cue_parts.length) params.cue_parts = catalogFilter.cue_parts.join(',');
            if (catalogFilter.cue_materials.length) params.cue_material = catalogFilter.cue_materials.join(',');
            if (catalogFilter.filter_sku) params.filter_sku = catalogFilter.filter_sku;
            if (catalogFilter.filter_name) params.filter_name = catalogFilter.filter_name;

            const data = await api('products.list', params);
            if (reset) {
                catalogProducts.value = data.items || [];
            } else {
                catalogProducts.value.push(...(data.items || []));
            }
            catalogTotal.value = data.total || 0;
        } catch (e) { toast('Ошибка загрузки каталога: ' + e.message, 'error'); }
        finally { catalogLoading.value = false; }
    }

    function loadMoreCatalog() { loadCatalog(false); }

    function debounceCatalogSearch() {
        clearTimeout(catalogSearchTimer);
        catalogSearchTimer = setTimeout(() => loadCatalog(true), 400);
    }

    // Detect if current category is inside "Кии" subtree
    const CUE_CATEGORY_NAME = 'Кии';
    const isCueCategory = computed(() => {
        const catId = catalogFilter.category_id;
        if (!catId) return false;
        const cats = catalogCategories.value;
        // Find "Кии" node and check if catId is it or a descendant
        function findNode(nodes, id) {
            for (const n of nodes) {
                if (n.id === id) return n;
                if (n.children) { const f = findNode(n.children, id); if (f) return f; }
            }
            return null;
        }
        function findByName(nodes, name) {
            for (const n of nodes) {
                if (n.name === name) return n;
                if (n.children) { const f = findByName(n.children, name); if (f) return f; }
            }
            return null;
        }
        function isDescendant(node, targetId) {
            if (node.id === targetId) return true;
            if (node.children) { for (const c of node.children) { if (isDescendant(c, targetId)) return true; } }
            return false;
        }
        const cueNode = findByName(cats, CUE_CATEGORY_NAME);
        if (!cueNode) return false;
        return isDescendant(cueNode, catId);
    });

    const cueTypeOptions = ['пирамида', 'пул', 'снукер', 'укороченный', 'удлинённый', 'древко'];
    const cuePartsOptions = [1, 2];
    const cueMaterialOptions = ['клён', 'рамин', 'композит'];

    function selectCategory(catId) {
        catalogFilter.category_id = catId;
        // Reset cue filters when leaving cue category
        catalogFilter.cue_types.length = 0;
        catalogFilter.cue_parts.length = 0;
        catalogFilter.cue_materials.length = 0;
        loadCatalog(true);
    }

    function toggleCatExpand(catId) {
        expandedCats[catId] = !expandedCats[catId];
    }

    // А-Я: leaf categories grouped by first letter
    const leafCategoriesByLetter = computed(() => {
        const cats = catalogCategories.value;
        const parentIds = new Set();
        function walkCats(list) {
            for (const c of list) {
                if (c.children && c.children.length) {
                    parentIds.add(c.id);
                    walkCats(c.children);
                }
            }
        }
        walkCats(cats);
        const leaves = [];
        function collectLeaves(list) {
            for (const c of list) {
                if (!c.children || c.children.length === 0) {
                    leaves.push(c);
                } else {
                    collectLeaves(c.children);
                }
            }
        }
        collectLeaves(cats);
        leaves.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
        const groups = {};
        for (const c of leaves) {
            const letter = (c.name[0] || '?').toUpperCase();
            if (!groups[letter]) groups[letter] = [];
            groups[letter].push(c);
        }
        return Object.entries(groups).sort((a, b) => a[0].localeCompare(b[0], 'ru'));
    });

    function onFilterImageToggle(which) {
        if (which === 'has_image' && catalogFilter.has_image) catalogFilter.no_image = false;
        if (which === 'no_image' && catalogFilter.no_image) catalogFilter.has_image = false;
        loadCatalog();
    }

    function onFilterStockToggle(which) {
        if (which === 'in_stock' && catalogFilter.in_stock) catalogFilter.zero_stock = false;
        if (which === 'zero_stock' && catalogFilter.zero_stock) catalogFilter.in_stock = false;
        loadCatalog();
    }

    function toggleColFilter(name) {
        colFilterOpen.value = colFilterOpen.value === name ? null : name;
    }

    function setCatalogSort(s) {
        catalogFilter.sort = s;
        loadCatalog();
    }

    // Close column filter on outside click
    document.addEventListener('click', () => { colFilterOpen.value = null; colVisibleOpen.value = false; });

    // ── Bulk operations ──
    const bulkMode = ref(false);
    const bulkSelected = reactive([]);
    const bulkMoveTarget = ref(null);
    const bulkMoveSearch = ref('');

    function toggleBulkSelect(id) {
        const idx = bulkSelected.indexOf(id);
        if (idx >= 0) bulkSelected.splice(idx, 1);
        else bulkSelected.push(id);
        if (bulkSelected.length > 0) bulkMode.value = true;
    }

    function selectAllVisible() {
        bulkSelected.length = 0;
        catalogProducts.value.forEach(p => bulkSelected.push(p.id));
    }

    const flatCategoriesForMove = computed(() => {
        const cats = catalogCategories.value;
        const result = [];
        function walk(nodes, prefix) {
            for (const n of nodes) {
                const path = prefix ? prefix + ' > ' + n.name : n.name;
                result.push({ id: n.id, name: n.name, path, count: n.count || 0 });
                if (n.children && n.children.length) walk(n.children, path);
            }
        }
        walk(cats, '');
        return result;
    });

    const filteredMoveCategories = computed(() => {
        const q = bulkMoveSearch.value.toLowerCase();
        if (!q) return flatCategoriesForMove.value;
        return flatCategoriesForMove.value.filter(c => c.path.toLowerCase().includes(q));
    });

    async function executeBulkMove() {
        if (!bulkMoveTarget.value || !bulkSelected.length) return;
        try {
            await api('products.bulk_move', { ids: [...bulkSelected], category_id: bulkMoveTarget.value });
            const target = flatCategoriesForMove.value.find(c => c.id === bulkMoveTarget.value);
            toast(`${bulkSelected.length} товаров перемещено в "${target?.name || 'категорию'}"`, 'success');
            bulkSelected.length = 0;
            bulkMode.value = false;
            bulkMoveTarget.value = null;
            bulkMoveSearch.value = '';
            showModal.value = null;
            loadCatalog();
            loadCategories();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Bulk assign attributes
    const bulkAttrFields = reactive({ cue_type: '', cue_parts: '', cue_material: '' });

    async function executeBulkAttr() {
        const fields = {};
        if (bulkAttrFields.cue_type) fields.cue_type = bulkAttrFields.cue_type;
        if (bulkAttrFields.cue_parts) fields.cue_parts = bulkAttrFields.cue_parts;
        if (bulkAttrFields.cue_material) fields.cue_material = bulkAttrFields.cue_material;
        if (!Object.keys(fields).length) { toast('Выберите хотя бы одно значение', 'error'); return; }
        try {
            await api('products.bulk_update_attr', { ids: [...bulkSelected], fields });
            toast(`Атрибуты обновлены для ${bulkSelected.length} товаров`, 'success');
            bulkSelected.length = 0;
            bulkMode.value = false;
            Object.assign(bulkAttrFields, { cue_type: '', cue_parts: '', cue_material: '' });
            showModal.value = null;
            loadCatalog();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Auto-fill cue attributes from names
    async function autoFillCueAttrs() {
        const catId = catalogFilter.category_id;
        if (!catId) { toast('Сначала выберите категорию Кии', 'error'); return; }
        try {
            const data = await api('products.auto_fill_cue', { category_id: catId });
            toast(`Автозаполнение: ${data.updated} обновлено, ${data.skipped} не определено`, 'success');
            loadCatalog();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function loadCategories() {
        try {
            const data = await api('products.categories');
            const cats = data.items || [];
            const map = {};
            cats.forEach(c => { map[c.id] = { ...c, children: [] }; });
            const tree = [];
            cats.forEach(c => {
                if (c.parent_id && map[c.parent_id]) {
                    map[c.parent_id].children.push(map[c.id]);
                } else {
                    tree.push(map[c.id]);
                }
            });
            catalogCategories.value = tree;
        } catch (e) { console.warn('Categories load error:', e); }
    }

    async function loadCatalogMeta() {
        try {
            const data = await api('products.meta');
            catalogBrands.value = data.brands || [];
            catalogSources.value = data.sources || [];
            catalogSuppliers.value = data.suppliers || [];
        } catch (e) { console.warn('Catalog meta error:', e); }
    }

    async function showProductDetail(id) {
        try {
            const data = await api('products.get', { id });
            let imagesArr = [];
            if (data.images) {
                try { imagesArr = typeof data.images === 'string' ? JSON.parse(data.images) : data.images; } catch(e) {}
            }
            if (data.image_url && !imagesArr.includes(data.image_url)) {
                imagesArr.unshift(data.image_url);
            }
            Object.assign(productDetail, { ...data, images_arr: imagesArr });
            productDetailImgIdx.value = 0;
            showModal.value = 'productDetail';
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    return {
        // Products
        products, allProducts, productFilter, loadProducts, debounceSearchProducts,
        createProduct, newProduct, editProduct, saveProduct,
        // Catalog
        catalogProducts, catalogTotal, catalogCategories, catalogBrands, catalogSources, catalogSuppliers,
        catalogFilter, catalogView, catalogLoading, loadCatalog, loadMoreCatalog,
        debounceCatalogSearch, selectCategory, colFilterOpen, toggleColFilter, setCatalogSort,
        catalogSidebarMode, leafCategoriesByLetter,
        sidebarAccordion, expandedCats, toggleCatExpand, onFilterImageToggle, onFilterStockToggle,
        catalogSidebarWidth, startSidebarResize,
        isCueCategory, cueTypeOptions, cuePartsOptions, cueMaterialOptions,
        bulkMode, bulkSelected, bulkMoveTarget, bulkMoveSearch,
        toggleBulkSelect, selectAllVisible, filteredMoveCategories, executeBulkMove,
        bulkAttrFields, executeBulkAttr, autoFillCueAttrs,
        // Product detail
        productDetail, productDetailImgIdx, productDetailMainImg, showProductDetail,
        // Column visibility
        colVisible, colVisibleOpen, toggleColVisible,
        // Categories / Meta
        loadCategories, loadCatalogMeta,
    };
}

// v0.3.1


// ── inventory.js ──
// ── inventory.js — Stock, Movements, Receive/Ship/Adjust/Transfer ──
function initInventory(ctx) {
    const { api, toast, showModal, ref, reactive, computed } = ctx;

    const inventoryList = ref([]);
    const movements = ref([]);
    const newMovement = reactive({ product_id: null, quantity: null, unit_price: null, reason: '' });
    const inventoryTab = ref('catalog');
    const stockFilter = reactive({ q: '', low_only: false });
    const movementFilter = reactive({ type: '', from: '', to: '' });

    const filteredInventory = computed(() => {
        let list = inventoryList.value;
        if (stockFilter.q) {
            const q = stockFilter.q.toLowerCase();
            list = list.filter(i => (i.product_name || '').toLowerCase().includes(q) || (i.sku || '').toLowerCase().includes(q));
        }
        if (stockFilter.low_only) {
            list = list.filter(i => i.quantity <= i.min_stock);
        }
        return list;
    });

    function debounceStockSearch() {
        // Client-side filtering via computed, no need for API call
    }

    async function loadInventory() {
        try {
            const [inv, mov] = await Promise.all([
                api('inventory.list'),
                api('inventory.movements', { limit: 30 }),
            ]);
            inventoryList.value = inv.items || [];
            movements.value = mov.items || [];
        } catch (e) { toast('Ошибка загрузки склада: ' + e.message, 'error'); }
    }

    async function loadMovements() {
        try {
            const params = { limit: 100 };
            if (movementFilter.type) params.type = movementFilter.type;
            if (movementFilter.from) params.from = movementFilter.from;
            if (movementFilter.to) params.to = movementFilter.to;
            const data = await api('inventory.movements', params);
            movements.value = data.items || [];
        } catch (e) { toast('Ошибка загрузки движений: ' + e.message, 'error'); }
    }

    async function submitMovement(action) {
        try {
            const endpoint = action === 'receive' ? 'inventory.receive' : 'inventory.ship';
            await api(endpoint, { ...newMovement });
            toast(action === 'receive' ? 'Приход оформлен' : 'Расход оформлен', 'success');
            showModal.value = null;
            Object.assign(newMovement, { product_id: null, quantity: null, unit_price: null, reason: '' });
            loadInventory();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Inventory Adjust
    const adjustData = reactive({ product_id: null, quantity: null, reason: '' });

    async function submitAdjust() {
        try {
            const result = await api('inventory.adjust', { ...adjustData });
            toast(`Корректировка: ${result.previous} → ${result.new} (${result.diff > 0 ? '+' : ''}${result.diff})`, 'success');
            showModal.value = null;
            Object.assign(adjustData, { product_id: null, quantity: null, reason: '' });
            loadInventory();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Inventory Transfer
    const transferData = reactive({ product_id: null, quantity: null, from_warehouse_id: 1, to_warehouse_id: 2 });

    async function submitTransfer() {
        try {
            await api('inventory.transfer', { ...transferData });
            toast('Перемещение выполнено', 'success');
            showModal.value = null;
            Object.assign(transferData, { product_id: null, quantity: null, from_warehouse_id: 1, to_warehouse_id: 2 });
            loadInventory();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function switchInventoryTab(tab) {
        inventoryTab.value = tab;
        // Note: catalog loading is handled in app.js loadRouteData
        if (tab === 'stock') { loadInventory(); }
        else if (tab === 'movements') { loadMovements(); }
    }

    // ── Warehouses ──────────────────────────────────
    const warehousesList = ref([]);
    const newWarehouse = reactive({ name: '', address: '', type: 'regular', parent_id: null, sort_order: 0, notes: '' });
    const editWarehouse = reactive({});

    async function loadWarehouses() {
        try {
            const data = await api('inventory.warehouses');
            warehousesList.value = data.items || [];
        } catch (e) { toast('Ошибка загрузки складов: ' + e.message, 'error'); }
    }

    async function createWarehouse() {
        if (!newWarehouse.name.trim()) return toast('Укажи название', 'error');
        try {
            await api('inventory.warehouse_create', { ...newWarehouse });
            toast('Склад создан', 'success');
            showModal.value = null;
            Object.assign(newWarehouse, { name: '', address: '', type: 'regular', parent_id: null, sort_order: 0, notes: '' });
            loadWarehouses();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editWarehouseOpen(wh) {
        Object.assign(editWarehouse, { ...wh });
        showModal.value = 'warehouseEdit';
    }

    async function saveWarehouse() {
        try {
            await api('inventory.warehouse_update', { ...editWarehouse });
            toast('Склад обновлён', 'success');
            showModal.value = null;
            loadWarehouses();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function deleteWarehouse(id) {
        if (!confirm('Удалить склад?')) return;
        try {
            await api('inventory.warehouse_delete', { id });
            toast('Склад удалён', 'success');
            loadWarehouses();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    return {
        inventoryList, movements, newMovement, loadInventory, submitMovement,
        adjustData, submitAdjust, transferData, submitTransfer,
        inventoryTab, switchInventoryTab, stockFilter, movementFilter, filteredInventory,
        debounceStockSearch, loadMovements,
        // Warehouses
        warehousesList, newWarehouse, editWarehouse,
        loadWarehouses, createWarehouse, editWarehouseOpen, saveWarehouse, deleteWarehouse,
    };
}


// ── purchasing.js ──
// ── purchasing.js — Counterparties + Supply orders ──
function initPurchasing(ctx) {
    const { api, toast, showModal, detailData, editData, ref, reactive, computed } = ctx;

    // ── Counterparties (Контрагенты) ──────
    const suppliersData = reactive({ items: [], total: 0 });
    const supplierFilter = reactive({ q: '', type: '', countries: [] });
    let supplierSearchTimer2 = null;
    const newSupplierData = reactive({ name: '', type: 'supplier', alias: '', synonyms: '', inn: '', phone: '', email: '', website: '', country: '', address: '', notes: '', currency: 'RUB' });

    // Column visibility
    const supplierColVisOpen = ref(false);
    const defaultSupplierCols = { name: true, alias: true, type: true, synonyms: false, country: true, balance: true, phone: true, email: true, products: true };
    const supplierColVis = reactive(JSON.parse(localStorage.getItem('supplierColVisible') || 'null') || { ...defaultSupplierCols });
    function toggleSupplierCol(col) {
        supplierColVis[col] = !supplierColVis[col];
        localStorage.setItem('supplierColVisible', JSON.stringify(supplierColVis));
    }
    document.addEventListener('click', () => { supplierColVisOpen.value = false; });

    const supplierCountries = computed(() => {
        const set = new Set();
        (suppliersData.items || []).forEach(s => { if (s.country) set.add(s.country); });
        return [...set].sort();
    });
    const filteredSuppliers = computed(() => {
        if (!supplierFilter.countries.length) return suppliersData.items;
        return suppliersData.items.filter(s => supplierFilter.countries.includes(s.country));
    });
    function toggleCountryFilter(c) {
        const i = supplierFilter.countries.indexOf(c);
        if (i >= 0) supplierFilter.countries.splice(i, 1); else supplierFilter.countries.push(c);
    }

    let counterpartyRouteType = '';

    function loadCounterparties(type) {
        if (type !== undefined) {
            counterpartyRouteType = type;
            supplierFilter.type = type;
        }
        loadSuppliers2();
    }

    async function loadSuppliers2() {
        try {
            const params = { q: supplierFilter.q, limit: 200 };
            const effectiveType = counterpartyRouteType || supplierFilter.type;
            if (effectiveType) params.type = effectiveType;
            const data = await api('counterparties.list', params);
            suppliersData.items = data.items || [];
            suppliersData.total = data.total || 0;
        } catch (e) { toast('Ошибка загрузки контрагентов: ' + e.message, 'error'); }
    }
    }

    function debounceSearchSuppliers2() {
        clearTimeout(supplierSearchTimer2);
        supplierSearchTimer2 = setTimeout(loadSuppliers2, 400);
    }

    async function createSupplier2() {
        if (!newSupplierData.name.trim()) return toast('Укажи название', 'error');
        try {
            await api('counterparties.create', { ...newSupplierData });
            toast('Контрагент создан', 'success');
            showModal.value = null;
            loadSuppliers2();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function showSupplierDetail2(id) {
        api('counterparties.get', { id }).then(data => {
            Object.assign(detailData, data);
            showModal.value = 'supplierDetail';
        }).catch(e => toast(e.message, 'error'));
    }

    function editSupplier2(s) {
        Object.assign(editData, { ...s, _type: 'counterparty' });
        showModal.value = 'supplierEdit';
    }

    async function saveSupplier2() {
        try {
            await api('counterparties.update', { id: editData.id, name: editData.name, type: editData.type, alias: editData.alias, synonyms: editData.synonyms, inn: editData.inn, phone: editData.phone, email: editData.email, website: editData.website, country: editData.country, address: editData.address, notes: editData.notes, currency: editData.currency });
            toast('Контрагент обновлён', 'success');
            showModal.value = null;
            loadSuppliers2();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // ── Supplies (Поставки) ─────────────────────
    const supplies = reactive({ items: [], total: 0 });
    const supplyStats = reactive({ pending: 0, shipped: 0, received: 0, open_total: 0 });
    const supplyFilter = reactive({ status: '', q: '' });
    let supplySearchTimer = null;
    const newSupply = reactive({
        supplier_name: '', number: '', status: 'draft', expected_date: '',
        notes: '', items: [{ product_id: null, quantity: 1, unit_price: 0 }]
    });

    async function loadSupplies() {
        try {
            const [data, stats] = await Promise.all([
                api('supplies.list', { ...supplyFilter, limit: 100 }),
                api('supplies.stats'),
            ]);
            supplies.items = data.items || [];
            supplies.total = data.total || 0;
            Object.assign(supplyStats, stats);
        } catch (e) { toast('Ошибка загрузки поставок: ' + e.message, 'error'); }
    }

    function debounceSearchSupplies() {
        clearTimeout(supplySearchTimer);
        supplySearchTimer = setTimeout(loadSupplies, 400);
    }

    async function createSupply() {
        if (!newSupply.supplier_name.trim()) return toast('Укажи поставщика', 'error');
        const validItems = newSupply.items.filter(i => i.product_id && i.quantity > 0);
        if (!validItems.length) return toast('Добавь хотя бы одну позицию', 'error');
        try {
            await api('supplies.create', {
                supplier_name: newSupply.supplier_name,
                number: newSupply.number,
                status: newSupply.status,
                expected_date: newSupply.expected_date || null,
                notes: newSupply.notes,
                items: validItems
            });
            toast('Поставка создана', 'success');
            showModal.value = null;
            Object.assign(newSupply, { supplier_name: '', number: '', status: 'draft', expected_date: '', notes: '' });
            newSupply.items = [{ product_id: null, quantity: 1, unit_price: 0 }];
            loadSupplies();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function showSupplyDetail(id) {
        try {
            const data = await api('supplies.get', { id });
            Object.assign(detailData, data);
            showModal.value = 'supplyDetail';
        } catch (e) { toast(e.message, 'error'); }
    }

    function editSupplyFromDetail() {
        Object.assign(editData, { ...detailData, _type: 'supply' });
        showModal.value = 'supplyEdit';
    }

    async function saveSupply() {
        try {
            await api('supplies.update', { id: editData.id, supplier_name: editData.supplier_name, number: editData.number, status: editData.status, expected_date: editData.expected_date, notes: editData.notes });
            toast('Поставка обновлена', 'success');
            showModal.value = null;
            loadSupplies();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function receiveSupply(id) {
        try {
            await api('supplies.receive', { id });
            toast('Поставка получена, товар оприходован', 'success');
            showModal.value = null;
            loadSupplies();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function supplyStatusLabel(status) {
        const map = { draft: 'Черновик', ordered: 'Заказано', shipped: 'В пути', partial: 'Частично', received: 'Получено', cancelled: 'Отменено' };
        return map[status] || status;
    }

    return {
        // Suppliers
        suppliersData, supplierFilter, newSupplierData,
        supplierColVisOpen, supplierColVis, toggleSupplierCol,
        supplierCountries, filteredSuppliers, toggleCountryFilter,
        loadSuppliers2, loadCounterparties, debounceSearchSuppliers2, createSupplier2, showSupplierDetail2,
        editSupplier2, saveSupplier2,
        // Supplies
        supplies, supplyStats, supplyFilter, newSupply,
        loadSupplies, debounceSearchSupplies, createSupply, showSupplyDetail, supplyStatusLabel,
        editSupplyFromDetail, saveSupply, receiveSupply,
    };
}


// ── sales.js ──
// ── sales.js — Sales list & stats ──
function initSales(ctx) {
    const { api, toast, ref, reactive } = ctx;

    const sales = reactive({ items: [], total: 0 });
    const salesStats = reactive({ today_count: 0, today_amount: 0, month_amount: 0, avg_check: 0 });
    const salesFilter = reactive({ from: '', to: '', q: '' });
    let salesSearchTimer = null;

    async function loadSales() {
        try {
            const data = await api('inventory.movements', { type: 'sale', ...salesFilter, limit: 100 });
            sales.items = (data.items || []).map(m => ({
                ...m,
                quantity: Math.abs(m.quantity),
                customer: m.reason || '',
                channel: 'manual'
            }));
            sales.total = sales.items.length;
            // stats
            const today = new Date().toISOString().split('T')[0];
            const todaySales = sales.items.filter(s => (s.created_at || '').startsWith(today));
            salesStats.today_count = todaySales.length;
            salesStats.today_amount = todaySales.reduce((s, i) => s + i.quantity * (i.unit_price || 0), 0);
            salesStats.month_amount = sales.items.reduce((s, i) => s + i.quantity * (i.unit_price || 0), 0);
            salesStats.avg_check = sales.total ? Math.round(salesStats.month_amount / sales.total) : 0;
        } catch (e) { toast('Ошибка загрузки продаж: ' + e.message, 'error'); }
    }

    function debounceSearchSales() {
        clearTimeout(salesSearchTimer);
        salesSearchTimer = setTimeout(loadSales, 400);
    }

    return {
        sales, salesStats, salesFilter, loadSales, debounceSearchSales,
    };
}


// ── crm.js ──
// ── crm.js — Contacts, Interactions, Deals ──
function initCrm(ctx) {
    const { api, toast, showModal, detailData, editData, ref, reactive } = ctx;

    // ── CRM ─────────────────────────────────────
    const crmContacts = ref([]);
    const crmInteractions = ref([]);
    const crmUpcoming = ref([]);
    const crmStats = reactive({ total_contacts: 0, total_counterparties: 0, total_interactions: 0, overdue_actions: 0 });
    const crmFilter = reactive({ q: '' });
    let crmSearchTimer = null;
    const newContact = reactive({ first_name: '', last_name: '', company: '', phone: '', email: '', telegram: '', whatsapp: '', source: '', notes: '' });
    const newInteraction = reactive({ contact_id: null, type: 'call', direction: 'outgoing', subject: '', content: '', result: '', next_action: '', next_action_date: '' });

    async function loadCrm() {
        try {
            const [contacts, interactions, upcoming, stats] = await Promise.all([
                api('crm.contacts', { limit: 100 }),
                api('crm.interactions', { limit: 50 }),
                api('crm.upcoming', { days: 14 }),
                api('crm.stats'),
            ]);
            crmContacts.value = contacts.items || [];
            crmInteractions.value = interactions.items || [];
            crmUpcoming.value = [...(upcoming.overdue || []), ...(upcoming.upcoming || [])];
            Object.assign(crmStats, stats);
        } catch (e) { toast('Ошибка загрузки CRM: ' + e.message, 'error'); }
    }

    function debounceSearchCrm() {
        clearTimeout(crmSearchTimer);
        crmSearchTimer = setTimeout(async () => {
            if (crmFilter.q.length >= 2) {
                try {
                    const data = await api('crm.contacts', { q: crmFilter.q, limit: 100 });
                    crmContacts.value = data.items || [];
                } catch (e) {}
            } else {
                loadCrm();
            }
        }, 400);
    }

    async function createContact() {
        try {
            await api('crm.contact_create', { ...newContact });
            toast('Контакт создан', 'success');
            showModal.value = null;
            Object.assign(newContact, { first_name: '', last_name: '', company: '', phone: '', email: '', telegram: '', whatsapp: '', source: '', notes: '' });
            loadCrm();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function createInteraction() {
        try {
            await api('crm.interaction_create', { ...newInteraction });
            toast('Взаимодействие создано', 'success');
            showModal.value = null;
            Object.assign(newInteraction, { contact_id: null, type: 'call', direction: 'outgoing', subject: '', content: '', result: '', next_action: '', next_action_date: '' });
            loadCrm();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function showContactDetail(id) {
        api('crm.contact_get', { id }).then(data => {
            Object.assign(detailData, data);
            showModal.value = 'contactDetail';
        }).catch(e => toast(e.message, 'error'));
    }

    function editContactFromDetail() {
        Object.assign(editData, { ...detailData, _type: 'contact' });
        showModal.value = 'contactEdit';
    }

    async function saveContact() {
        try {
            await api('crm.contact_update', { id: editData.id, first_name: editData.first_name, last_name: editData.last_name, company: editData.company, position: editData.position, phone: editData.phone, email: editData.email, telegram: editData.telegram, whatsapp: editData.whatsapp, address: editData.address, source: editData.source, notes: editData.notes });
            toast('Контакт обновлён', 'success');
            showModal.value = null;
            loadCrm();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function interactionTypeLabel(type) {
        const map = { call: 'Звонок', email: 'Email', meeting: 'Встреча', message: 'Сообщение', note: 'Заметка', order: 'Заказ', delivery: 'Доставка', complaint: 'Рекламация', other: 'Другое' };
        return map[type] || type;
    }

    // ── Deals (Сделки) ──────────────────────────
    const deals = reactive({ items: [], total: 0 });
    const dealStats = reactive({ new: 0, in_progress: 0, won: 0, pipeline_amount: 0 });
    const dealFilter = reactive({ stage: '', q: '' });
    let dealSearchTimer = null;
    const newDeal = reactive({ title: '', contact_id: null, amount: null, stage: 'new', assignee: '', description: '' });

    async function loadDeals() {
        try {
            const [data, stats] = await Promise.all([
                api('deals.list', { ...dealFilter, limit: 100 }),
                api('deals.stats'),
            ]);
            deals.items = data.items || [];
            deals.total = data.total || 0;
            Object.assign(dealStats, stats);
        } catch (e) { toast('Ошибка загрузки сделок: ' + e.message, 'error'); }
    }

    function debounceSearchDeals() {
        clearTimeout(dealSearchTimer);
        dealSearchTimer = setTimeout(loadDeals, 400);
    }

    async function createDeal() {
        if (!newDeal.title.trim()) return toast('Укажи название сделки', 'error');
        try {
            await api('deals.create', { ...newDeal });
            toast('Сделка создана', 'success');
            showModal.value = null;
            Object.assign(newDeal, { title: '', contact_id: null, amount: null, stage: 'new', assignee: '', description: '' });
            loadDeals();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editDeal(d) {
        Object.assign(editData, { ...d, _type: 'deal' });
        showModal.value = 'dealEdit';
    }

    async function saveDeal() {
        try {
            await api('deals.update', { id: editData.id, title: editData.title, contact_id: editData.contact_id, amount: editData.amount, stage: editData.stage, assignee: editData.assignee, description: editData.description, lost_reason: editData.lost_reason });
            toast('Сделка обновлена', 'success');
            showModal.value = null;
            loadDeals();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function dealStageLabel(stage) {
        const map = { new: 'Новая', qualifying: 'Квалификация', proposal: 'Предложение', negotiation: 'Переговоры', won: 'Выиграна', lost: 'Проиграна' };
        return map[stage] || stage;
    }

    return {
        // CRM
        crmContacts, crmInteractions, crmUpcoming, crmStats, crmFilter,
        newContact, newInteraction,
        loadCrm, debounceSearchCrm, createContact, createInteraction, showContactDetail, interactionTypeLabel,
        editContactFromDetail, saveContact,
        // Deals
        deals, dealStats, dealFilter, newDeal,
        loadDeals, debounceSearchDeals, createDeal, dealStageLabel,
        editDeal, saveDeal,
    };
}


// ── finance.js ──
// ── finance.js — Finance transactions, accounts, reports ──
function initFinance(ctx) {
    const { api, toast, showModal, editData, summaryData, formatMoney, ref, reactive, watch } = ctx;

    const finance = reactive({ items: [], total: 0 });
    const financeAccounts = ref([]);
    const financeFilter = reactive({ type: '', from: '', to: '', q: '' });
    let financeSearchTimer = null;
    const newFinance = reactive({ type: 'expense', amount: null, date: new Date().toISOString().split('T')[0], category: '', counterparty: '', account_id: null, description: '' });

    async function loadFinance() {
        try {
            const [data, accs] = await Promise.all([
                api('finance.list', { ...financeFilter, limit: 100 }),
                api('finance.accounts'),
            ]);
            finance.items = data.items || [];
            finance.total = data.total || 0;
            financeAccounts.value = accs.items || [];
        } catch (e) { toast('Ошибка загрузки финансов: ' + e.message, 'error'); }
    }

    function debounceSearchFinance() {
        clearTimeout(financeSearchTimer);
        financeSearchTimer = setTimeout(loadFinance, 400);
    }

    async function loadFinanceSummary() {
        try {
            const data = await api('finance.summary');
            Object.assign(summaryData, data);
            showModal.value = 'financeSummary';
        } catch (e) { toast(e.message, 'error'); }
    }

    async function createFinance() {
        try {
            await api('finance.create', { ...newFinance });
            toast('Транзакция создана', 'success');
            showModal.value = null;
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editFinance(tx) {
        Object.assign(editData, { ...tx, _type: 'finance' });
        showModal.value = 'financeEdit';
    }

    async function saveFinance() {
        try {
            await api('finance.update', { id: editData.id, type: editData.type, amount: editData.amount, date: editData.date, category: editData.category, counterparty: editData.counterparty, account_id: editData.account_id, description: editData.description });
            toast('Транзакция обновлена', 'success');
            showModal.value = null;
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Finance Account
    const newAccount = reactive({ name: '', type: 'bank', currency: 'RUB', balance: 0 });

    async function createAccount() {
        try {
            await api('finance.account_create', { ...newAccount });
            toast('Счёт создан', 'success');
            showModal.value = null;
            Object.assign(newAccount, { name: '', type: 'bank', currency: 'RUB', balance: 0 });
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Reports (Отчёты) — computed from finance items
    const reportData = reactive({ income: 0, expense: 0, profit: 0, count: 0, top_expenses: [], top_income: [] });

    watch(() => finance.items, () => {
        reportData.income = finance.items.filter(t => t.type === 'income').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
        reportData.expense = finance.items.filter(t => t.type === 'expense').reduce((s, t) => s + parseFloat(t.amount || 0), 0);
        reportData.profit = reportData.income - reportData.expense;
        reportData.count = finance.items.length;
        // top expense categories
        const expByCat = {};
        finance.items.filter(t => t.type === 'expense').forEach(t => {
            const cat = t.category || 'Без категории';
            expByCat[cat] = (expByCat[cat] || 0) + parseFloat(t.amount || 0);
        });
        reportData.top_expenses = Object.entries(expByCat).map(([category, total]) => ({ category, total })).sort((a, b) => b.total - a.total).slice(0, 10);
        // top income categories
        const incByCat = {};
        finance.items.filter(t => t.type === 'income').forEach(t => {
            const cat = t.category || 'Без категории';
            incByCat[cat] = (incByCat[cat] || 0) + parseFloat(t.amount || 0);
        });
        reportData.top_income = Object.entries(incByCat).map(([category, total]) => ({ category, total })).sort((a, b) => b.total - a.total).slice(0, 10);
    }, { deep: true });

    return {
        finance, financeAccounts, financeFilter, loadFinance, debounceSearchFinance, loadFinanceSummary,
        createFinance, newFinance, editFinance, saveFinance,
        newAccount, createAccount,
        reportData,
    };
}


// ── tasks.js ──
// ── tasks.js — Tasks with filters + kanban board ──
function initTasks(ctx) {
    const { api, toast, showModal, editData, ref, reactive, computed } = ctx;

    const tasks = reactive({ items: [], total: 0 });
    const taskStats = ref(null);
    const taskFilter = reactive({ status: '', priority: '', assignee: '', creator: '' });
    const newTask = reactive({ title: '', description: '', priority: 'normal', due_date: '', assignee: '' });

    // View mode: 'list' or 'board'
    const taskView = ref('list');
    const dragOverColumn = ref(null);

    // Kanban columns definition
    const taskColumns = [
        { status: 'todo', label: 'К выполнению' },
        { status: 'in_progress', label: 'В работе' },
        { status: 'done', label: 'Выполнено' },
        { status: 'cancelled', label: 'Отменено' },
    ];

    // Tasks grouped by status for the kanban board
    const tasksByStatus = computed(() => {
        const grouped = { todo: [], in_progress: [], done: [], cancelled: [] };
        for (const t of tasks.items) {
            if (grouped[t.status]) grouped[t.status].push(t);
        }
        return grouped;
    });

    async function loadTasks() {
        try {
            const params = { ...taskFilter, limit: 200 };
            // On board view, don't filter by status (show all columns)
            if (taskView.value === 'board') params.status = '';
            const [data, stats] = await Promise.all([
                api('tasks.list', params),
                api('tasks.stats'),
            ]);
            tasks.items = data.items || [];
            tasks.total = data.total || 0;
            taskStats.value = stats;
        } catch (e) { toast('Ошибка загрузки задач: ' + e.message, 'error'); }
    }

    async function createTask() {
        try {
            await api('tasks.create', { ...newTask });
            toast('Задача создана', 'success');
            showModal.value = null;
            Object.assign(newTask, { title: '', description: '', priority: 'normal', due_date: '', assignee: '' });
            loadTasks();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editTask(t) {
        Object.assign(editData, { ...t, _type: 'task' });
        showModal.value = 'taskEdit';
    }

    async function saveTask() {
        try {
            await api('tasks.update', { id: editData.id, title: editData.title, description: editData.description, priority: editData.priority, status: editData.status, due_date: editData.due_date, assignee: editData.assignee });
            toast('Задача обновлена', 'success');
            showModal.value = null;
            loadTasks();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function toggleTask(task) {
        try {
            if (task.status === 'done') {
                await api('tasks.update', { id: task.id, status: 'todo' });
            } else {
                await api('tasks.done', { id: task.id });
            }
            loadTasks();
        } catch (e) { toast(e.message, 'error'); }
    }

    function isOverdue(task) {
        return task.due_date && task.due_date < new Date().toISOString().split('T')[0] && task.status !== 'done' && task.status !== 'cancelled';
    }

    // Drag and drop handlers for kanban
    function dragTask(event, task) {
        event.dataTransfer.setData('text/plain', String(task.id));
        event.dataTransfer.effectAllowed = 'move';
    }

    async function dropTask(event, newStatus) {
        dragOverColumn.value = null;
        const taskId = parseInt(event.dataTransfer.getData('text/plain'));
        if (!taskId) return;
        const task = tasks.items.find(t => t.id === taskId);
        if (!task || task.status === newStatus) return;
        try {
            await api('tasks.update', { id: taskId, status: newStatus });
            task.status = newStatus;
            toast('Статус обновлён', 'success');
            loadTasks();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    return {
        tasks, taskStats, taskFilter, loadTasks, createTask, newTask, toggleTask, isOverdue,
        editTask, saveTask,
        taskView, taskColumns, tasksByStatus, dragOverColumn, dragTask, dropTask,
    };
}


// ── settings.js ──
// ── settings.js — Settings, AI chat, system info ──
function initSettings(ctx) {
    const { api, toast, ref, nextTick } = ctx;

    // AI
    const aiChat = ref([]);
    const aiQuestion = ref('');
    const aiLoading = ref(false);

    async function askAI() {
        if (!aiQuestion.value.trim() || aiLoading.value) return;
        const q = aiQuestion.value;
        aiChat.value.push({ id: Date.now(), role: 'user', text: q });
        aiQuestion.value = '';
        aiLoading.value = true;

        try {
            const result = await api('ai.ask', { question: q });
            aiChat.value.push({ id: Date.now(), role: 'assistant', text: result.answer || 'Нет ответа' });
        } catch (e) {
            aiChat.value.push({ id: Date.now(), role: 'assistant', text: '❌ Ошибка: ' + e.message });
        } finally {
            aiLoading.value = false;
            await nextTick();
        }
    }

    // Settings
    const systemInfo = ref(null);
    const aiProviders = ref([]);

    async function runMigrations() {
        try {
            const result = await api('system.migrate');
            systemInfo.value = result;
            toast('Миграции выполнены', 'success');
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function checkStatus() {
        try {
            const result = await api('system.status');
            systemInfo.value = result;
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function loadSettings() {
        try {
            const data = await api('ai.providers');
            aiProviders.value = data.providers || [];
        } catch (e) { console.warn(e); }
    }

    return {
        aiChat, aiQuestion, aiLoading, askAI,
        systemInfo, aiProviders, runMigrations, checkStatus, loadSettings,
    };
}


// ── app.js ──
// ── app.js — Vue app shell, routing, shared state ──










const { createApp, ref, reactive, computed, onMounted, nextTick, watch } = Vue;

const app = createApp({
    setup() {
        // ── Navigation ──────────────────────────────
        const currentRoute = ref(localStorage.getItem('erpRoute') || 'inventory');
        const expandedGroups = reactive({ purchasing: true, sales: true, goods: true, finance: true, tasks: true });
        const sidebarWidth = ref(parseInt(localStorage.getItem('sidebarWidth')) || 240);

        function toggleGroup(group) {
            expandedGroups[group] = !expandedGroups[group];
        }

        function startMainSidebarResize(e) {
            e.preventDefault();
            const startX = e.clientX;
            const startW = sidebarWidth.value;
            const handle = e.target;
            handle.classList.add('dragging');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            function onMove(ev) {
                sidebarWidth.value = Math.max(180, Math.min(400, startW + ev.clientX - startX));
            }
            function onUp() {
                handle.classList.remove('dragging');
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                localStorage.setItem('sidebarWidth', sidebarWidth.value);
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }

        const routeGroups = { supplies: 'purchasing', receiving: 'purchasing', suppliers: 'purchasing', sales: 'sales', shipments: 'sales', returns: 'sales', profitability: 'sales', customers: 'sales', crm: 'sales', deals: 'sales', inventory: 'goods', warehouses: 'goods', finance: 'finance', reports: 'finance', tasks: 'tasks', tasks_my: 'tasks', tasks_from: 'tasks' };

        function navigate(route) {
            currentRoute.value = route;
            localStorage.setItem('erpRoute', route);
            if (routeGroups[route]) expandedGroups[routeGroups[route]] = true;
            loadRouteData(route);
        }

        // ── Toasts ──────────────────────────────────
        const toasts = ref([]);
        let toastId = 0;
        function toast(message, type = 'info') {
            const id = ++toastId;
            toasts.value.push({ id, message, type });
            setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id); }, 3500);
        }

        // ── Shared edit/detail state ────────────────
        const detailData = reactive({});
        const editData = reactive({});
        const deleteTarget = reactive({ type: '', id: 0, label: '' });
        const summaryData = reactive({ income: 0, expense: 0, profit: 0, by_category: [] });
        const showModal = ref(null);

        // ── Shared context for modules ──────────────
        const ctx = { api, toast, showModal, detailData, editData, deleteTarget, summaryData, formatMoney, formatDate, formatDateTime, sourceLabel, movementTypeLabel, ref, reactive, computed, watch, nextTick };

        // ── Init all modules ────────────────────────
        const dashboard = initDashboard(ctx);
        const catalog = initCatalog(ctx);
        const inventory = initInventory(ctx);
        const purchasing = initPurchasing(ctx);
        const sales = initSales(ctx);
        const crm = initCrm(ctx);
        const finance = initFinance(ctx);
        const tasks = initTasks(ctx);
        const settings = initSettings(ctx);

        // ── Route data loader ───────────────────────
        function loadRouteData(route) {
            switch (route) {
                case 'dashboard': dashboard.loadDashboard(); break;
                case 'journal':   dashboard.loadJournal(); break;
                case 'products':  catalog.loadProducts(); break;
                case 'finance':   finance.loadFinance(); break;
                case 'reports':   finance.loadFinance(); break;
                case 'inventory': catalog.loadCatalog(); catalog.loadCategories(); catalog.loadCatalogMeta(); inventory.loadInventory(); catalog.loadProducts(); break;
                case 'supplies':  purchasing.loadSupplies(); catalog.loadProducts(); break;
                case 'sales':     sales.loadSales(); break;
                case 'tasks':     tasks.taskFilter.assignee = ''; tasks.taskFilter.creator = ''; tasks.loadTasks(); break;
                case 'tasks_my':  tasks.taskFilter.assignee = 'me'; tasks.taskFilter.creator = ''; tasks.loadTasks(); break;
                case 'tasks_from': tasks.taskFilter.assignee = ''; tasks.taskFilter.creator = 'me'; tasks.loadTasks(); break;
                case 'crm':       crm.loadCrm(); break;
                case 'deals':     crm.loadDeals(); crm.loadCrm(); break;
                case 'counterparties': purchasing.loadCounterparties(''); break;
                case 'suppliers': purchasing.loadCounterparties('supplier'); break;
                case 'customers': purchasing.loadCounterparties('customer'); break;
                case 'warehouses': inventory.loadWarehouses(); break;
                case 'settings':  settings.loadSettings(); break;
            }
        }

        // ── Universal Delete ────────────────────────
        function confirmDelete(type, item) {
            const labels = {
                product: `товар "${item.name}"`,
                finance: `транзакцию #${item.id} (${formatMoney(item.amount)})`,
                deal: `сделку "${item.title}"`,
                supply: `поставку #${item.number || item.id}`,
                contact: `контакт "${[item.first_name, item.last_name].filter(Boolean).join(' ') || item.company}"`,
            };
            Object.assign(deleteTarget, { type, id: item.id, label: labels[type] || `#${item.id}` });
            showModal.value = 'confirmDelete';
        }

        async function executeDelete() {
            const { type, id } = deleteTarget;
            try {
                const endpointMap = {
                    product: 'products.delete',
                    finance: 'finance.delete',
                    deal: 'deals.delete',
                    supply: 'supplies.delete',
                    contact: 'crm.contact_delete',
                };
                await api(endpointMap[type], { id });
                toast('Удалено', 'success');
                showModal.value = null;
                const reloadMap = { product: catalog.loadProducts, finance: finance.loadFinance, deal: crm.loadDeals, supply: purchasing.loadSupplies, contact: crm.loadCrm };
                if (reloadMap[type]) reloadMap[type]();
            } catch (e) { toast('Ошибка удаления: ' + e.message, 'error'); }
        }

        // ── Init ────────────────────────────────────
        onMounted(() => {
            const r = currentRoute.value;
            if (routeGroups[r]) expandedGroups[routeGroups[r]] = true;
            loadRouteData(r);
        });

        // ── Return all to template ──────────────────
        return {
            // Navigation
            currentRoute, expandedGroups, toggleGroup, navigate, sidebarWidth, startMainSidebarResize,
            // Toasts
            toasts, toast,
            // Shared state
            detailData, editData, deleteTarget, summaryData, showModal,
            // Delete
            confirmDelete, executeDelete,
            // Formatting
            formatMoney, formatDate, formatDateTime, sourceLabel, movementTypeLabel,
            // Dashboard module
            ...dashboard,
            // Catalog module
            ...catalog,
            // Inventory module
            ...inventory,
            // Purchasing module
            ...purchasing,
            // Sales module
            ...sales,
            // CRM module
            ...crm,
            // Finance module
            ...finance,
            // Tasks module
            ...tasks,
            // Settings module
            ...settings,
        };
    }
});
app.mount('#app');


})();
