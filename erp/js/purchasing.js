// ── purchasing.js — Counterparties + Supply orders ──
export function initPurchasing(ctx) {
    const { api, toast, navigate, showModal, detailData, editData, ref, reactive, computed } = ctx;

    // ── Counterparties (Контрагенты) ──────
    const suppliersData = reactive({ items: [], total: 0 });
    const supplierFilter = reactive({ q: '', type: '', countries: [] });
    let supplierSearchTimer2 = null;
    const newSupplierData = reactive({ name: '', type: 'supplier', alias: '', synonyms: '', inn: '', phone: '', email: '', website: '', country: '', address: '', notes: '', currency: 'RUB' });

    // Column visibility
    const supplierColVisOpen = ref(false);
    const defaultSupplierCols = { name: true, alias: true, type: true, synonyms: false, country: true, currency: true, balance: true, phone: true, email: true, products: true };
    const supplierColVis = reactive(JSON.parse(localStorage.getItem('supplierColVisible') || 'null') || { ...defaultSupplierCols });
    function toggleSupplierCol(col) {
        supplierColVis[col] = !supplierColVis[col];
        localStorage.setItem('supplierColVisible', JSON.stringify(supplierColVis));
    }
    document.addEventListener('click', () => { supplierColVisOpen.value = false; spProductColVisOpen.value = false; });

    // Supplier page products column visibility
    const spProductColVisOpen = ref(false);
    const defaultSpProductCols = { image: true, article: true, product_code: true, alias: true, short_name: true, supplier_product_name: true, supplier_name: true, sku: false, name: false, brand: false, purchase_price: false, sell_price: false, stock: false };
    const spProductColVis = reactive(JSON.parse(localStorage.getItem('spProductColVisible') || 'null') || { ...defaultSpProductCols });
    function toggleSpProductCol(col) {
        spProductColVis[col] = !spProductColVis[col];
        localStorage.setItem('spProductColVisible', JSON.stringify(spProductColVis));
    }

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

    // Column visibility for supplies
    const supplyColVisOpen = ref(false);
    const defaultSupplyCols = { number: true, date: true, supplier: true, items: true, amount: true, status: true, expected_date: true, files: true, notes: true };
    const supplyColVis = reactive(JSON.parse(localStorage.getItem('supplyColVisible') || 'null') || { ...defaultSupplyCols });
    function toggleSupplyCol(col) {
        supplyColVis[col] = !supplyColVis[col];
        localStorage.setItem('supplyColVisible', JSON.stringify(supplyColVis));
    }
    document.addEventListener('click', () => { supplyColVisOpen.value = false; });
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
            await api('supplies.update', {
                id: editData.id, supplier_name: editData.supplier_name, number: editData.number,
                status: editData.status, expected_date: editData.expected_date, notes: editData.notes,
                tracking_number: editData.tracking_number || null, cargo_places: editData.cargo_places || null,
                cargo_weight: editData.cargo_weight || null, cargo_volume: editData.cargo_volume || null,
                logistics_cost: editData.logistics_cost || null, logistics_currency: editData.logistics_currency || 'USD',
                logistics_detail: editData.logistics_detail || null
            });
            toast('Поставка обновлена', 'success');
            showModal.value = null;
            loadSupplies();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    const receiveData = reactive({ id: null, warehouse_id: 1 });

    function startReceive(id) {
        receiveData.id = id;
        receiveData.warehouse_id = 1;
        showModal.value = 'supplyReceive';
    }

    async function confirmReceive() {
        try {
            await api('supplies.receive', { id: receiveData.id, warehouse_id: receiveData.warehouse_id });
            toast('Поставка принята, товар зачислен на склад', 'success');
            showModal.value = null;
            loadSupplies();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function supplyStatusLabel(status) {
        const map = { draft: 'Черновик', ordered: 'Заказано', shipped: 'В пути', partial: 'Частично', received: 'Получено', cancelled: 'Отменено' };
        return map[status] || status;
    }

    function openSupplyPage(id) {
        // TODO: отдельная страница заказа — будет реализована позже
        toast('Страница заказа — в разработке', 'info');
    }

    // ── Supplier Page (Страница поставщика) ─────
    const supplierPageData = reactive({ id: null, name: '', alias: '', type: '', currency: 'RUB', balance: 0, country: '', inn: '', phone: '', email: '', website: '', address: '', notes: '', synonyms: '', products: [], transactions: [], supplies: [] });
    const supplierPageTab = ref('products');
    const supplierPageSelected = reactive([]);
    const spFilter = reactive({ search: '', dateFrom: '', dateTo: '' });
    const spLightbox = ref(null);
    const spViewMode = ref('table');

    const spMonthLabel = computed(() => {
        if (!spFilter.dateFrom || !spFilter.dateTo) return '';
        const d = new Date(spFilter.dateFrom + 'T00:00:00');
        return d.toLocaleString('ru-RU', { month: 'long', year: 'numeric' });
    });

    function spSetMonth(offset) {
        const now = new Date();
        const d = new Date(now.getFullYear(), now.getMonth() + offset, 1);
        const from = d.toISOString().slice(0, 10);
        const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
        const to = last.toISOString().slice(0, 10);
        Object.assign(spFilter, { dateFrom: from, dateTo: to });
    }

    function spShiftMonth(dir) {
        if (!spFilter.dateFrom) { spSetMonth(0); return; }
        const d = new Date(spFilter.dateFrom + 'T00:00:00');
        d.setMonth(d.getMonth() + dir);
        const from = d.toISOString().slice(0, 10);
        const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
        const to = last.toISOString().slice(0, 10);
        Object.assign(spFilter, { dateFrom: from, dateTo: to });
    }

    function spIsCurrentMonth(offset) {
        if (!spFilter.dateFrom) return false;
        const now = new Date();
        const target = new Date(now.getFullYear(), now.getMonth() + offset, 1);
        return spFilter.dateFrom === target.toISOString().slice(0, 10);
    }

    const spFilteredSupplies = computed(() => {
        let list = supplierPageData.supplies || [];
        if (spFilter.search) {
            const q = spFilter.search.toLowerCase();
            list = list.filter(s => (s.number || '').toLowerCase().includes(q));
        }
        if (spFilter.dateFrom) list = list.filter(s => (s.created_at || '') >= spFilter.dateFrom);
        if (spFilter.dateTo) list = list.filter(s => (s.created_at || '').slice(0,10) <= spFilter.dateTo);
        return list;
    });

    const spFilteredTransactions = computed(() => {
        let list = supplierPageData.transactions || [];
        if (spFilter.search) {
            const q = spFilter.search.toLowerCase();
            list = list.filter(t => (t.description || '').toLowerCase().includes(q) || (t.account_name || '').toLowerCase().includes(q));
        }
        if (spFilter.dateFrom) list = list.filter(t => (t.date || '') >= spFilter.dateFrom);
        if (spFilter.dateTo) list = list.filter(t => (t.date || '') <= spFilter.dateTo);
        return list;
    });

    async function openSupplierPage(id) {
        try {
            const data = await api('counterparties.get', { id });
            Object.keys(supplierPageData).forEach(k => { if (data[k] !== undefined) supplierPageData[k] = data[k]; });
            supplierPageSelected.length = 0;
            Object.assign(spFilter, { search: '', dateFrom: '', dateTo: '' });
            showModal.value = null;
            ctx.navigate('supplierPage');
        } catch (e) { toast(e.message, 'error'); }
    }

    function toggleSupplierProduct(productId) {
        const idx = supplierPageSelected.indexOf(productId);
        if (idx >= 0) supplierPageSelected.splice(idx, 1);
        else supplierPageSelected.push(productId);
    }

    function toggleAllSupplierProducts() {
        if (supplierPageSelected.length === supplierPageData.products.length) {
            supplierPageSelected.length = 0;
        } else {
            supplierPageSelected.length = 0;
            supplierPageData.products.forEach(p => supplierPageSelected.push(p.id));
        }
    }

    function createOrderFromSelected() {
        if (!supplierPageSelected.length) return toast('Выберите товары', 'error');
        const selectedProducts = supplierPageData.products.filter(p => supplierPageSelected.includes(p.id));
        Object.assign(newSupply, {
            supplier_name: supplierPageData.name,
            number: '',
            status: 'draft',
            expected_date: '',
            notes: '',
        });
        newSupply.items = selectedProducts.map(p => ({
            product_id: p.id,
            product_name: p.alias || p.name,
            quantity: 1,
            unit_price: parseFloat(p.purchase_price) || 0,
        }));
        showModal.value = 'supplyCreate';
    }

    return {
        // Suppliers
        suppliersData, supplierFilter, newSupplierData,
        supplierColVisOpen, supplierColVis, toggleSupplierCol,
        supplierCountries, filteredSuppliers, toggleCountryFilter,
        loadSuppliers2, loadCounterparties, debounceSearchSuppliers2, createSupplier2, showSupplierDetail2,
        editSupplier2, saveSupplier2,
        // Supplier Page
        supplierPageData, supplierPageTab, supplierPageSelected,
        spFilter, spLightbox, spViewMode, spMonthLabel, spSetMonth, spShiftMonth, spIsCurrentMonth,
        spProductColVisOpen, spProductColVis, toggleSpProductCol,
        spFilteredSupplies, spFilteredTransactions,
        openSupplierPage, toggleSupplierProduct, toggleAllSupplierProducts, createOrderFromSelected,
        // Supplies
        supplies, supplyStats, supplyFilter, newSupply,
        supplyColVisOpen, supplyColVis, toggleSupplyCol,
        loadSupplies, debounceSearchSupplies, createSupply, showSupplyDetail, supplyStatusLabel, openSupplyPage,
        editSupplyFromDetail, saveSupply, receiveData, startReceive, confirmReceive,
    };
}
