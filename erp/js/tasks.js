// ── tasks.js — Tasks with filters + kanban board ──
export function initTasks(ctx) {
    const { api, toast, showModal, editData, ref, reactive, computed } = ctx;

    const tasks = reactive({ items: [], total: 0 });
    const taskStats = ref(null);
    const taskFilter = reactive({ status: '', priority: '', assignee: '', creator: '' });
    const newTask = reactive({ title: '', description: '', priority: 'normal', due_date: '', assignee: '' });

    // View mode: 'list' or 'board'
    const taskView = ref('list');
    const dragOverColumn = ref(null);

    // Kanban columns definition
    const taskColumns = [
        { status: 'todo', label: 'К выполнению' },
        { status: 'in_progress', label: 'В работе' },
        { status: 'done', label: 'Выполнено' },
        { status: 'cancelled', label: 'Отменено' },
    ];

    // Tasks grouped by status for the kanban board
    const tasksByStatus = computed(() => {
        const grouped = { todo: [], in_progress: [], done: [], cancelled: [] };
        for (const t of tasks.items) {
            if (grouped[t.status]) grouped[t.status].push(t);
        }
        return grouped;
    });

    async function loadTasks() {
        try {
            const params = { ...taskFilter, limit: 200 };
            // On board view, don't filter by status (show all columns)
            if (taskView.value === 'board') params.status = '';
            const [data, stats] = await Promise.all([
                api('tasks.list', params),
                api('tasks.stats'),
            ]);
            tasks.items = data.items || [];
            tasks.total = data.total || 0;
            taskStats.value = stats;
        } catch (e) { toast('Ошибка загрузки задач: ' + e.message, 'error'); }
    }

    async function createTask() {
        try {
            await api('tasks.create', { ...newTask });
            toast('Задача создана', 'success');
            showModal.value = null;
            Object.assign(newTask, { title: '', description: '', priority: 'normal', due_date: '', assignee: '' });
            loadTasks();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    function editTask(t) {
        Object.assign(editData, { ...t, _type: 'task' });
        showModal.value = 'taskEdit';
    }

    async function saveTask() {
        try {
            await api('tasks.update', { id: editData.id, title: editData.title, description: editData.description, priority: editData.priority, status: editData.status, due_date: editData.due_date, assignee: editData.assignee });
            toast('Задача обновлена', 'success');
            showModal.value = null;
            loadTasks();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    async function toggleTask(task) {
        try {
            if (task.status === 'done') {
                await api('tasks.update', { id: task.id, status: 'todo' });
            } else {
                await api('tasks.done', { id: task.id });
            }
            loadTasks();
        } catch (e) { toast(e.message, 'error'); }
    }

    function isOverdue(task) {
        return task.due_date && task.due_date < new Date().toISOString().split('T')[0] && task.status !== 'done' && task.status !== 'cancelled';
    }

    // Drag and drop handlers for kanban
    function dragTask(event, task) {
        event.dataTransfer.setData('text/plain', String(task.id));
        event.dataTransfer.effectAllowed = 'move';
    }

    async function dropTask(event, newStatus) {
        dragOverColumn.value = null;
        const taskId = parseInt(event.dataTransfer.getData('text/plain'));
        if (!taskId) return;
        const task = tasks.items.find(t => t.id === taskId);
        if (!task || task.status === newStatus) return;
        try {
            await api('tasks.update', { id: taskId, status: newStatus });
            task.status = newStatus;
            toast('Статус обновлён', 'success');
            loadTasks();
        } catch (e) { toast('Ошибка: ' + e.message, 'error'); }
    }

    return {
        tasks, taskStats, taskFilter, loadTasks, createTask, newTask, toggleTask, isOverdue,
        editTask, saveTask,
        taskView, taskColumns, tasksByStatus, dragOverColumn, dragTask, dropTask,
    };
}
