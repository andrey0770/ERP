// ── finance.js — Finance transactions, accounts, reports ──
export function initFinance(ctx) {
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
