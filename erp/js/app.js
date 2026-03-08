// ── app.js — Vue app shell, routing, shared state ──
import { api, formatMoney, formatDate, formatDateTime, sourceLabel, movementTypeLabel } from './core.js';
import { initDashboard } from './dashboard.js';
import { initCatalog } from './catalog.js';
import { initInventory } from './inventory.js';
import { initPurchasing } from './purchasing.js';
import { initSales } from './sales.js';
import { initCrm } from './crm.js';
import { initFinance } from './finance.js';
import { initTasks } from './tasks.js';
import { initSettings } from './settings.js';

const { createApp, ref, reactive, computed, onMounted, nextTick, watch } = Vue;

const app = createApp({
    setup() {
        // ── Navigation ──────────────────────────────
        const currentRoute = ref('inventory');
        const expandedGroups = reactive({ trade: true, finance: true, crm: true, tasks: true });

        function toggleGroup(group) {
            expandedGroups[group] = !expandedGroups[group];
        }

        const routeGroups = { supplies: 'trade', sales: 'trade', inventory: 'trade', products: 'trade', finance: 'finance', reports: 'finance', crm: 'crm', deals: 'crm', tasks: 'tasks', tasks_my: 'tasks', tasks_from: 'tasks' };

        function navigate(route) {
            currentRoute.value = route;
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
                case 'suppliers': purchasing.loadSuppliers2(); break;
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
            loadRouteData(currentRoute.value);
        });

        // ── Return all to template ──────────────────
        return {
            // Navigation
            currentRoute, expandedGroups, toggleGroup, navigate,
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
