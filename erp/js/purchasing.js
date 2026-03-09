// ── purchasing.js — Counterparties + Supply orders ──
export function initPurchasing(ctx) {
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
