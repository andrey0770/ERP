// ── crm.js — Contacts, Interactions, Deals ──
export function initCrm(ctx) {
    const { api, toast, showModal, detailData, editData, ref, reactive } = ctx;

    // ── CRM ─────────────────────────────────────
    const crmContacts = ref([]);
    const crmInteractions = ref([]);
    const crmUpcoming = ref([]);
    const crmStats = reactive({ total_contacts: 0, total_counterparties: 0, total_interactions: 0, overdue_actions: 0 });
    const crmFilter = reactive({ q: '' });
    let crmSearchTimer = null;
    const newContact = reactive({ first_name: '', last_name: '', company: '', phone: '', email: '', telegram: '', whatsapp: '', source: '', notes: '' });
    const newInteraction = reactive({ contact_id: null, type: 'call', direction: 'outgoing', subject: '', content: '', result: '', next_action: '', next_action_date: '' });

    async function loadCrm() {
        try {
            const [contacts, interactions, upcoming, stats] = await Promise.all([
                api('crm.contacts', { limit: 100 }),
                api('crm.interactions', { limit: 50 }),
                api('crm.upcoming', { days: 14 }),
                api('crm.stats'),
            ]);
            crmContacts.value = contacts.items || [];
            crmInteractions.value = interactions.items || [];
            crmUpcoming.value = [...(upcoming.overdue || []), ...(upcoming.upcoming || [])];
            Object.assign(crmStats, stats);
        } catch (e) { toast('Ошибка загрузки CRM: ' + e.message, 'error'); }
    }

    function debounceSearchCrm() {
        clearTimeout(crmSearchTimer);
        crmSearchTimer = setTimeout(async () => {
            if (crmFilter.q.length >= 2) {
                try {
                    const data = await api('crm.contacts', { q: crmFilter.q, limit: 100 });
                    crmContacts.value = data.items || [];
                } catch (e) {}
            } else {
                loadCrm();
            }
        }, 400);
    }

    async function createContact() {
        try {
            await api('crm.contact_create', { ...newContact });
            toast('Контакт создан', 'success');
            showModal.value = null;
            Object.assign(newContact, { first_name: '', last_name: '', company: '', phone: '', email: '', telegram: '', whatsapp: '', source: '', notes: '' });
            loadCrm();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function createInteraction() {
        try {
            await api('crm.interaction_create', { ...newInteraction });
            toast('Взаимодействие создано', 'success');
            showModal.value = null;
            Object.assign(newInteraction, { contact_id: null, type: 'call', direction: 'outgoing', subject: '', content: '', result: '', next_action: '', next_action_date: '' });
            loadCrm();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function showContactDetail(id) {
        api('crm.contact_get', { id }).then(data => {
            Object.assign(detailData, data);
            showModal.value = 'contactDetail';
        }).catch(e => toast(e.message, 'error'));
    }

    function editContactFromDetail() {
        Object.assign(editData, { ...detailData, _type: 'contact' });
        showModal.value = 'contactEdit';
    }

    async function saveContact() {
        try {
            await api('crm.contact_update', { id: editData.id, first_name: editData.first_name, last_name: editData.last_name, company: editData.company, position: editData.position, phone: editData.phone, email: editData.email, telegram: editData.telegram, whatsapp: editData.whatsapp, address: editData.address, source: editData.source, notes: editData.notes });
            toast('Контакт обновлён', 'success');
            showModal.value = null;
            loadCrm();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function interactionTypeLabel(type) {
        const map = { call: 'Звонок', email: 'Email', meeting: 'Встреча', message: 'Сообщение', note: 'Заметка', order: 'Заказ', delivery: 'Доставка', complaint: 'Рекламация', other: 'Другое' };
        return map[type] || type;
    }

    // ── Deals (Сделки) ──────────────────────────
    const deals = reactive({ items: [], total: 0 });
    const dealStats = reactive({ new: 0, in_progress: 0, won: 0, pipeline_amount: 0 });
    const dealFilter = reactive({ stage: '', q: '' });
    let dealSearchTimer = null;
    const newDeal = reactive({ title: '', contact_id: null, amount: null, stage: 'new', assignee: '', description: '' });

    async function loadDeals() {
        try {
            const [data, stats] = await Promise.all([
                api('deals.list', { ...dealFilter, limit: 100 }),
                api('deals.stats'),
            ]);
            deals.items = data.items || [];
            deals.total = data.total || 0;
            Object.assign(dealStats, stats);
        } catch (e) { toast('Ошибка загрузки сделок: ' + e.message, 'error'); }
    }

    function debounceSearchDeals() {
        clearTimeout(dealSearchTimer);
        dealSearchTimer = setTimeout(loadDeals, 400);
    }

    async function createDeal() {
        if (!newDeal.title.trim()) return toast('Укажи название сделки', 'error');
        try {
            await api('deals.create', { ...newDeal });
            toast('Сделка создана', 'success');
            showModal.value = null;
            Object.assign(newDeal, { title: '', contact_id: null, amount: null, stage: 'new', assignee: '', description: '' });
            loadDeals();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editDeal(d) {
        Object.assign(editData, { ...d, _type: 'deal' });
        showModal.value = 'dealEdit';
    }

    async function saveDeal() {
        try {
            await api('deals.update', { id: editData.id, title: editData.title, contact_id: editData.contact_id, amount: editData.amount, stage: editData.stage, assignee: editData.assignee, description: editData.description, lost_reason: editData.lost_reason });
            toast('Сделка обновлена', 'success');
            showModal.value = null;
            loadDeals();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function dealStageLabel(stage) {
        const map = { new: 'Новая', qualifying: 'Квалификация', proposal: 'Предложение', negotiation: 'Переговоры', won: 'Выиграна', lost: 'Проиграна' };
        return map[stage] || stage;
    }

    return {
        // CRM
        crmContacts, crmInteractions, crmUpcoming, crmStats, crmFilter,
        newContact, newInteraction,
        loadCrm, debounceSearchCrm, createContact, createInteraction, showContactDetail, interactionTypeLabel,
        editContactFromDetail, saveContact,
        // Deals
        deals, dealStats, dealFilter, newDeal,
        loadDeals, debounceSearchDeals, createDeal, dealStageLabel,
        editDeal, saveDeal,
    };
}
