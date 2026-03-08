// ── core.js — API client & shared utilities ─────
const API_BASE = (() => {
    const loc = window.location;
    if (loc.hostname === 'localhost' || loc.hostname === '127.0.0.1') {
        return 'http://localhost/erp/api/index.php';
    }
    return 'https://sborka.billiarder.ru/erp/api/index.php';
})();
const API_TOKEN = '';

export async function api(action, data = null) {
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
export function formatMoney(val) {
    const n = parseFloat(val) || 0;
    return n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₽';
}

export function formatDate(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString('ru-RU');
}

export function formatDateTime(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

// ── Label helpers ───────────────────────────────
export function sourceLabel(src) {
    const map = { ozon_kp: 'Ozon КП', ozon_bsh: 'Ozon БСХ', yandex_market: 'Яндекс.Маркет', moysklad: 'МойСклад' };
    return map[src] || src || '';
}

export function movementTypeLabel(type) {
    const map = { purchase: 'Приход', sale: 'Расход', adjustment: 'Коррекция', transfer_in: 'Вход', transfer_out: 'Выход', return: 'Возврат' };
    return map[type] || type;
}
