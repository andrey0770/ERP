// ── settings.js — Settings, AI chat, system info ──
export function initSettings(ctx) {
    const { api, toast, ref, nextTick } = ctx;

    // AI
    const aiChat = ref([]);
    const aiQuestion = ref('');
    const aiLoading = ref(false);

    async function askAI() {
        if (!aiQuestion.value.trim() || aiLoading.value) return;
        const q = aiQuestion.value;
        aiChat.value.push({ id: Date.now(), role: 'user', text: q });
        aiQuestion.value = '';
        aiLoading.value = true;

        try {
            const result = await api('ai.ask', { question: q });
            aiChat.value.push({ id: Date.now(), role: 'assistant', text: result.answer || 'Нет ответа' });
        } catch (e) {
            aiChat.value.push({ id: Date.now(), role: 'assistant', text: '❌ Ошибка: ' + e.message });
        } finally {
            aiLoading.value = false;
            await nextTick();
        }
    }

    // Settings
    const systemInfo = ref(null);
    const aiProviders = ref([]);

    async function runMigrations() {
        try {
            const result = await api('system.migrate');
            systemInfo.value = result;
            toast('Миграции выполнены', 'success');
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function checkStatus() {
        try {
            const result = await api('system.status');
            systemInfo.value = result;
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function loadSettings() {
        try {
            const data = await api('ai.providers');
            aiProviders.value = data.providers || [];
        } catch (e) { console.warn(e); }
    }

    return {
        aiChat, aiQuestion, aiLoading, askAI,
        systemInfo, aiProviders, runMigrations, checkStatus, loadSettings,
    };
}
