// ── core.js — API client & shared utilities ── v26 ──
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
    const rawText = await resp.text();
    let json;
    try {
        json = JSON.parse(rawText);
    } catch (e) {
        if (resp.status === 504 || resp.status === 502) throw new Error('Сервер не отвечает (таймаут). Попробуйте ещё раз.');
        throw new Error(`Сервер вернул не JSON (HTTP ${resp.status})`);
    }
    if (!resp.ok || json.error) throw new Error(json.error || `HTTP ${resp.status}`);
    return json;
}

// ── Formatting helpers ──────────────────────────
export function formatMoney(val, currency) {
    const n = parseFloat(val) || 0;
    const sym = { RUB: '₽', CNY: '¥', USD: '$', USDT: 'USDT', EUR: '€' };
    const s = sym[currency] || (currency ? currency : '₽');
    return n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ' + s;
}

export function formatAmount(val, currency) {
    const n = parseFloat(val) || 0;
    const sym = { RUB: '₽', CNY: '¥', USD: '$', USDT: 'USDT', EUR: '€' };
    const s = sym[currency] || currency || '₽';
    const formatted = Math.abs(n).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (n < 0 ? '-' : n > 0 ? '+' : '') + formatted + ' ' + s;
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

// ── File upload (multipart/form-data) ───────────
export async function apiUpload(action, formData) {
    const params = new URLSearchParams({ action });
    const opts = { method: 'POST', body: formData };
    if (API_TOKEN) opts.headers = { 'Authorization': `Bearer ${API_TOKEN}` };
    const url = `${API_BASE}?${params}`;
    const resp = await fetch(url, opts);
    const json = await resp.json();
    if (!resp.ok || json.error) throw new Error(json.error || `HTTP ${resp.status}`);
    return json;
}

// ── Attachments helper ──────────────────────────
export function initAttachments(ctx) {
    const { api, apiUpload, toast, ref, reactive } = ctx;

    const attachmentsCache = reactive({});
    const attachDesc = ref('');
    const attachUploading = ref(false);

    function attachKey(entityType, entityId) { return `${entityType}_${entityId}`; }

    async function loadAttachments(entityType, entityId) {
        const key = attachKey(entityType, entityId);
        try {
            const data = await api('attachments.list', { entity_type: entityType, entity_id: entityId });
            attachmentsCache[key] = data.items || [];
        } catch (e) { attachmentsCache[key] = []; }
        return attachmentsCache[key];
    }

    function getAttachments(entityType, entityId) {
        return attachmentsCache[attachKey(entityType, entityId)] || [];
    }

    async function uploadAttachment(entityType, entityId, file, description) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('entity_type', entityType);
        fd.append('entity_id', entityId);
        if (description) fd.append('description', description);
        const result = await apiUpload('attachments.upload', fd);
        await loadAttachments(entityType, entityId);
        return result;
    }

    async function deleteAttachment(entityType, entityId, attachId) {
        await api('attachments.delete', { id: attachId });
        await loadAttachments(entityType, entityId);
    }

    async function updateAttachmentDesc(attachId, description) {
        await api('attachments.update', { id: attachId, description });
    }

    async function handleSupplyAttach(event, supplyId) {
        const file = event.target.files?.[0];
        if (!file) return;
        attachUploading.value = true;
        try {
            await uploadAttachment('supply', supplyId, file, attachDesc.value);
            toast('Файл загружен', 'success');
            attachDesc.value = '';
        } catch (e) { toast('Ошибка загрузки: ' + e.message, 'error'); }
        attachUploading.value = false;
        event.target.value = '';
    }

    async function handleTxAttach(event, txId) {
        const file = event.target.files?.[0];
        if (!file) return;
        attachUploading.value = true;
        try {
            await uploadAttachment('transaction', txId, file, attachDesc.value);
            toast('Файл загружен', 'success');
            attachDesc.value = '';
        } catch (e) { toast('Ошибка загрузки: ' + e.message, 'error'); }
        attachUploading.value = false;
        event.target.value = '';
    }

    function triggerFileUpload(event) {
        const wrap = event.currentTarget.closest('.attachment-upload');
        if (wrap) wrap.querySelector('input[type=file]').click();
    }

    return {
        attachmentsCache, attachDesc, attachUploading,
        loadAttachments, getAttachments,
        uploadAttachment, deleteAttachment, updateAttachmentDesc,
        handleSupplyAttach, handleTxAttach, triggerFileUpload,
    };
}
