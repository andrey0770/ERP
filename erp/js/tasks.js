// ── tasks.js — Tasks with filters ──
export function initTasks(ctx) {
    const { api, toast, showModal, editData, ref, reactive } = ctx;

    const tasks = reactive({ items: [], total: 0 });
    const taskStats = ref(null);
    const taskFilter = reactive({ status: '', priority: '', assignee: '', creator: '' });
    const newTask = reactive({ title: '', description: '', priority: 'normal', due_date: '', assignee: '' });

    async function loadTasks() {
        try {
            const [data, stats] = await Promise.all([
                api('tasks.list', { ...taskFilter, limit: 100 }),
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

    return {
        tasks, taskStats, taskFilter, loadTasks, createTask, newTask, toggleTask, isOverdue,
        editTask, saveTask,
    };
}
