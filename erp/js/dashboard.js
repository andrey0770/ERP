// ── dashboard.js — Dashboard, Quick Entry, Journal ──
export function initDashboard(ctx) {
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
