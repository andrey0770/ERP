// ── sales.js — Sales list & stats ──
export function initSales(ctx) {
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
