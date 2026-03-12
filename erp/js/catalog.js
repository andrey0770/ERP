// ── catalog.js — Product catalog, categories, bulk operations ──
export function initCatalog(ctx) {
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
            await api('products.update', { id: editData.id, sku: editData.sku, name: editData.name, barcode: editData.barcode, alias: editData.alias, product_code: editData.product_code, purchase_price: editData.purchase_price, sell_price: editData.sell_price, min_stock: editData.min_stock, ozon_product_id: editData.ozon_product_id, ozon_sku: editData.ozon_sku, supplier: editData.supplier, cue_type: editData.cue_type, cue_parts: editData.cue_parts, cue_material: editData.cue_material });
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
    const defaultCols = { image: true, sku: true, product_code: false, alias: false, name: true, brand: true, supplier: true, purchase_price: true, sell_price: true, stock: true, source: true, cue_type: true, cue_parts: true, cue_material: true };
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
