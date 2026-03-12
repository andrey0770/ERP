// ── finance.js — Finance transactions, accounts, reports ──
export function initFinance(ctx) {
    const { api, toast, showModal, editData, summaryData, formatMoney, ref, reactive, watch, computed } = ctx;

    const finance = reactive({ items: [], total: 0 });
    const financeAccounts = ref([]);
    const financeFilter = reactive({ type: '', status: '', from: '', to: '', q: '' });
    let financeSearchTimer = null;
    const newFinance = reactive({ type: 'expense', amount: null, date: new Date().toISOString().split('T')[0], category: '', counterparty: '', account_id: null, to_account_id: null, dest_amount: null, dest_currency: null, description: '' });

    // Группировка связанных транзакций (переводы)
    const financeGrouped = computed(() => {
        const items = finance.items;
        if (!items.length) return [];

        // Собираем группы по linked_id
        const groups = {};
        const ungrouped = [];
        items.forEach(tx => {
            if (tx.linked_id) {
                if (!groups[tx.linked_id]) groups[tx.linked_id] = [];
                groups[tx.linked_id].push(tx);
            } else {
                ungrouped.push(tx);
            }
        });

        // Собираем результат: помечаем позицию в группе
        const result = [];
        const processed = new Set();
        items.forEach(tx => {
            if (processed.has(tx.id)) return;
            if (tx.linked_id && groups[tx.linked_id]) {
                const grp = groups[tx.linked_id];
                // Сортируем по id (хронологический порядок цепочки)
                grp.sort((a, b) => a.id - b.id);
                grp.forEach((g, i) => {
                    processed.add(g.id);
                    result.push({
                        ...g,
                        _groupPos: i === 0 ? 'first' : i === grp.length - 1 ? 'last' : 'mid',
                        _groupSize: grp.length,
                        _groupItems: grp,
                    });
                });
            } else {
                processed.add(tx.id);
                result.push({ ...tx, _groupPos: null, _groupSize: 0, _groupItems: null });
            }
        });
        return result;
    });

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
            const payload = { ...newFinance };
            if (payload.type !== 'transfer') {
                delete payload.to_account_id;
                delete payload.dest_amount;
                delete payload.dest_currency;
            }
            await api('finance.create', payload);
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
            const payload = {
                id: editData.id, type: editData.type, amount: editData.amount,
                date: editData.date, category: editData.category,
                counterparty: editData.counterparty, account_id: editData.account_id,
                to_account_id: editData.to_account_id, linked_id: editData.linked_id,
                dest_amount: editData.dest_amount, dest_currency: editData.dest_currency,
                description: editData.description
            };
            if (payload.type !== 'transfer') {
                delete payload.to_account_id;
                delete payload.dest_amount;
                delete payload.dest_currency;
            }
            await api('finance.update', payload);
            toast('Транзакция обновлена', 'success');
            showModal.value = null;
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    // Связать транзакции (перевод)
    const linkingMode = ref(false);
    const linkSourceId = ref(null);

    function startLink(txId) {
        linkingMode.value = true;
        linkSourceId.value = txId;
    }

    async function finishLink(targetId) {
        if (!linkSourceId.value || linkSourceId.value === targetId) {
            linkingMode.value = false;
            linkSourceId.value = null;
            return;
        }
        try {
            await api('finance.link', { ids: [linkSourceId.value, targetId] });
            toast('Транзакции связаны', 'success');
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
        linkingMode.value = false;
        linkSourceId.value = null;
    }

    async function unlinkTx(txId) {
        try {
            await api('finance.unlink', { id: txId });
            toast('Связь убрана', 'success');
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function confirmTx(txId) {
        try {
            await api('finance.confirm', { id: txId });
            toast('Транзакция подтверждена', 'success');
            loadFinance();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function rejectTx(txId) {
        try {
            await api('finance.reject', { id: txId });
            toast('Черновик отклонён', 'success');
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
        financeGrouped, linkingMode, linkSourceId, startLink, finishLink, unlinkTx,
        confirmTx, rejectTx,
    };
}
