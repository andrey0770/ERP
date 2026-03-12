// ── ai.js — AI-ассистент: чат через Cloudflare Worker → Claude ──
import { api } from './core.js';

const WORKER_URL = 'https://ai-proxy.andrey0770.workers.dev/';
const WORKER_KEY = 'BILLIARDER_ERP_2026';
const AI_MODEL = 'claude-sonnet-4-20250514';

export function initAi(ctx) {
    const { ref, nextTick } = ctx;

    const aiChat = ref([]);       // UI messages
    const aiInput = ref('');
    const aiLoading = ref(false);
    const aiPendingPlan = ref(null);
    const aiFiles = ref([]);

    let msgId = 0;
    let systemPrompt = '';
    const apiHistory = [];         // raw messages для AI (user text + assistant JSON)

    async function aiLoadContext() {
        if (systemPrompt) return;
        try {
            const res = await api('ai.context');
            if (res.ok) systemPrompt = res.system_prompt;
        } catch (e) {
            console.error('Failed to load AI context:', e);
        }
    }

    function addMessage(role, content) {
        aiChat.value.push({ id: ++msgId, role, ...content });
        nextTick(() => {
            const el = document.querySelector('.ai-messages');
            if (el) el.scrollTop = el.scrollHeight;
        });
    }

    function aiAttach() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*,.pdf,.csv,.txt,.xlsx,.xls,.doc,.docx';
        input.multiple = true;
        input.onchange = () => {
            Array.from(input.files).forEach(file => {
                const reader = new FileReader();
                reader.onload = () => {
                    const dataUrl = reader.result;
                    const base64 = dataUrl.split(',')[1];
                    aiFiles.value.push({ name: file.name, type: file.type, size: file.size, dataUrl, base64 });
                };
                reader.readAsDataURL(file);
            });
        };
        input.click();
    }

    function aiRemoveFile(idx) {
        aiFiles.value.splice(idx, 1);
    }

    async function callWorker(messages) {
        const resp = await fetch(WORKER_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Proxy-Key': WORKER_KEY },
            body: JSON.stringify({ messages, model: AI_MODEL }),
        });
        const data = await resp.json();
        if (!resp.ok || data.error) {
            throw new Error(data.error?.message || data.error || `HTTP ${resp.status}`);
        }
        return data.content || '';
    }

    function parseAiResponse(raw) {
        let clean = raw.replace(/^```(?:json)?\s*\n?/gm, '').replace(/\n?```\s*$/gm, '').trim();
        try {
            const parsed = JSON.parse(clean);
            if (parsed && parsed.type) return parsed;
        } catch (e) {}
        return { type: 'text', message: raw };
    }

    async function aiSend() {
        const text = aiInput.value.trim();
        const files = [...aiFiles.value];
        if ((!text && !files.length) || aiLoading.value) return;

        const images = files.filter(f => f.type.startsWith('image/'));
        addMessage('user', {
            text: text || (files.length ? '📎 ' + files.map(f => f.name).join(', ') : ''),
            images: images.map(f => f.dataUrl),
        });
        aiInput.value = '';
        aiFiles.value = [];
        aiLoading.value = true;
        aiPendingPlan.value = null;

        try {
            await aiLoadContext();

            // Собираем messages: system + history + текущее
            const messages = [];
            if (systemPrompt) {
                messages.push({ role: 'system', content: systemPrompt });
            }

            // Добавляем историю (ограничиваем последние 20)
            const recent = apiHistory.slice(-20);
            for (const m of recent) {
                messages.push(m);
            }

            // Текущее сообщение пользователя
            let userContent;
            if (images.length > 0) {
                // Claude vision format
                const parts = [];
                for (const img of images) {
                    const mediaType = img.type || 'image/jpeg';
                    parts.push({ type: 'image', source: { type: 'base64', media_type: mediaType, data: img.base64 } });
                }
                if (text) parts.push({ type: 'text', text });
                userContent = parts;
            } else {
                userContent = text;
            }
            messages.push({ role: 'user', content: userContent });

            // Сохраняем user в историю
            apiHistory.push({ role: 'user', content: typeof userContent === 'string' ? userContent : text });

            // Вызываем AI
            const raw = await callWorker(messages);

            // Сохраняем сырой ответ AI в историю (чтобы Claude видел свои прошлые JSON-ответы)
            apiHistory.push({ role: 'assistant', content: raw });

            const parsed = parseAiResponse(raw);

            switch (parsed.type) {
                case 'query': {
                    const qRes = await api('ai.query', { queries: parsed.queries || [] });
                    const results = qRes.results || [];
                    addMessage('assistant', { text: parsed.message || '', type: 'query_result', results });
                    // Добавляем результат запроса в историю чтобы AI знал что получилось
                    const summary = results.map(r =>
                        r.error ? `Ошибка: ${r.error}` :
                        `${r.description || 'Результат'}: ${r.count} строк` + (r.rows && r.rows.length <= 20 ? ': ' + JSON.stringify(r.rows) : '')
                    ).join('\n');
                    apiHistory.push({ role: 'user', content: '[Результаты запросов]\n' + summary });
                    break;
                }

                case 'plan':
                    aiPendingPlan.value = parsed.operations || [];
                    addMessage('assistant', { text: parsed.message || '', type: 'plan', plan: parsed.operations || [] });
                    break;

                default:
                    addMessage('assistant', { text: parsed.message || raw, type: 'text' });
            }
        } catch (e) {
            addMessage('assistant', { text: '❌ Ошибка: ' + e.message, type: 'error' });
        } finally {
            aiLoading.value = false;
        }
    }

    async function aiConfirmPlan() {
        if (!aiPendingPlan.value) return;
        const operations = aiPendingPlan.value;
        aiPendingPlan.value = null;
        aiLoading.value = true;
        addMessage('user', { text: '✅ Подтверждаю выполнение' });
        apiHistory.push({ role: 'user', content: 'Пользователь подтвердил план. Выполняю.' });

        try {
            const res = await api('ai.execute', { operations });
            if (res.ok) {
                let summary = '✅ ' + res.message + '\n';
                (res.results || []).forEach((r, i) => {
                    summary += `\n${i + 1}. ${r.sql}\n   → строк: ${r.affected_rows}`;
                    if (r.last_id) summary += `, ID: ${r.last_id}`;
                });
                addMessage('assistant', { text: summary, type: 'executed' });
                apiHistory.push({ role: 'assistant', content: summary });
            } else {
                addMessage('assistant', { text: '❌ ' + (res.message || 'Ошибка'), type: 'error' });
            }
        } catch (e) {
            addMessage('assistant', { text: '❌ Ошибка: ' + e.message, type: 'error' });
        } finally {
            aiLoading.value = false;
        }
    }

    function aiRejectPlan() {
        aiPendingPlan.value = null;
        addMessage('user', { text: '❌ Отменяю' });
        addMessage('assistant', { text: 'Хорошо, операция отменена.', type: 'text' });
        apiHistory.push({ role: 'user', content: 'Пользователь отменил план.' });
        apiHistory.push({ role: 'assistant', content: '{"type":"text","message":"Хорошо, операция отменена."}' });
    }

    function aiClearChat() {
        aiChat.value = [];
        aiPendingPlan.value = null;
        apiHistory.length = 0;
        msgId = 0;
    }

    return {
        aiChat, aiInput, aiLoading, aiPendingPlan, aiFiles,
        aiSend, aiConfirmPlan, aiRejectPlan, aiClearChat, aiAttach, aiRemoveFile,
        aiLoadContext,
    };
}
