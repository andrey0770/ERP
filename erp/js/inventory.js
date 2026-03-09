// ── inventory.js — Stock, Movements, Receive/Ship/Adjust/Transfer ──
export function initInventory(ctx) {
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
