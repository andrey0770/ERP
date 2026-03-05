#!/usr/bin/env python3
"""
Lenta ERP — Telegram Bot
Позволяет вносить записи в журнал, задавать вопросы AI, просматривать сводки.

Запуск:
  pip install -r requirements.txt
  python erp_bot.py

На сервере:
  nohup python3 /home/bril/web/billiarder.ru/public_html/erp/bot/erp_bot.py > /tmp/erp_bot.log 2>&1 &
"""

import os
import json
import logging
import asyncio
import aiohttp
from datetime import datetime

# ── Config ─────────────────────────────────────────────
BOT_TOKEN = os.environ.get("ERP_BOT_TOKEN", "")  # @BotFather token
API_BASE = os.environ.get("ERP_API_BASE", "https://sborka.billiarder.ru/erp/api/index.php")
API_TOKEN = os.environ.get("ERP_API_TOKEN", "")   # Bearer token
ALLOWED_USERS = set(map(int, filter(None, os.environ.get("ERP_ALLOWED_USERS", "").split(","))))

# ── Logging ────────────────────────────────────────────
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("erp_bot")


# ── Telegram API wrapper ───────────────────────────────
class TelegramBot:
    def __init__(self, token: str):
        self.token = token
        self.base_url = f"https://api.telegram.org/bot{token}"
        self.session: aiohttp.ClientSession | None = None
        self.offset = 0

    async def start(self):
        self.session = aiohttp.ClientSession()
        log.info("Bot started, polling...")
        me = await self.api("getMe")
        log.info(f"Bot: @{me.get('username', '?')}")

    async def stop(self):
        if self.session:
            await self.session.close()

    async def api(self, method: str, data: dict = None) -> dict:
        url = f"{self.base_url}/{method}"
        async with self.session.post(url, json=data or {}) as resp:
            result = await resp.json()
            if not result.get("ok"):
                log.error(f"Telegram API error: {result}")
                return {}
            return result.get("result", {})

    async def send(self, chat_id: int, text: str, parse_mode: str = "HTML"):
        # Telegram limit 4096 chars
        for chunk in [text[i:i+4000] for i in range(0, len(text), 4000)]:
            await self.api("sendMessage", {
                "chat_id": chat_id,
                "text": chunk,
                "parse_mode": parse_mode,
            })

    async def poll(self):
        while True:
            try:
                updates = await self.api("getUpdates", {
                    "offset": self.offset,
                    "timeout": 30,
                })
                if isinstance(updates, list):
                    for update in updates:
                        self.offset = update["update_id"] + 1
                        await self.handle_update(update)
            except Exception as e:
                log.error(f"Poll error: {e}")
                await asyncio.sleep(5)

    async def handle_update(self, update: dict):
        msg = update.get("message")
        if not msg or not msg.get("text"):
            return

        user_id = msg["from"]["id"]
        chat_id = msg["chat"]["id"]
        text = msg["text"].strip()
        username = msg["from"].get("username", str(user_id))

        # Auth check
        if ALLOWED_USERS and user_id not in ALLOWED_USERS:
            await self.send(chat_id, "⛔ Нет доступа. Ваш ID: <code>{}</code>".format(user_id))
            return

        log.info(f"[{username}] {text[:80]}")

        # Commands
        if text.startswith("/"):
            await self.handle_command(chat_id, text, username)
        else:
            # Любой текст → запись в журнал + AI-разбор
            await self.handle_journal_entry(chat_id, text, username)

    async def handle_command(self, chat_id: int, text: str, username: str):
        cmd = text.split()[0].lower().split("@")[0]
        args = text[len(cmd):].strip()

        if cmd == "/start" or cmd == "/help":
            await self.send(chat_id, """
<b>📊 Lenta ERP Bot</b>

Просто пиши текстом любую операцию — бот запишет в журнал и AI разберёт:

<i>Купил 20 кийев за 35000 у Мастер-Кий, безнал</i>
<i>Отгрузил 5 столов по заказу Ozon #12345</i>
<i>Задача: позвонить поставщику до пятницы</i>

<b>Команды:</b>
/balance — баланс счетов
/tasks — открытые задачи
/summary — финансовая сводка
/contacts — контакты CRM
/upcoming — предстоящие действия CRM
/ask &lt;вопрос&gt; — спросить AI
/low — товары с низким остатком
/id — твой Telegram ID
""")

        elif cmd == "/id":
            await self.send(chat_id, f"Твой Telegram ID: <code>{chat_id}</code>")

        elif cmd == "/balance":
            data = await self.erp_api("finance.accounts")
            if data and data.get("items"):
                lines = ["<b>💰 Счета:</b>"]
                for acc in data["items"]:
                    bal = float(acc.get("balance", 0))
                    emoji = "🟢" if bal >= 0 else "🔴"
                    lines.append(f"{emoji} {acc['name']}: <b>{bal:,.0f} ₽</b>")
                await self.send(chat_id, "\n".join(lines))
            else:
                await self.send(chat_id, "Нет данных о счетах")

        elif cmd == "/tasks":
            data = await self.erp_api("tasks.list", {"status": "todo", "limit": 15})
            if data and data.get("items"):
                lines = ["<b>✅ Открытые задачи:</b>"]
                for t in data["items"]:
                    priority = {"urgent": "🔴", "high": "🟡", "normal": "⚪", "low": "🔵"}.get(t["priority"], "⚪")
                    due = f" (до {t['due_date']})" if t.get("due_date") else ""
                    lines.append(f"{priority} {t['title']}{due}")
                await self.send(chat_id, "\n".join(lines))
            else:
                await self.send(chat_id, "✅ Нет открытых задач")

        elif cmd == "/summary":
            data = await self.erp_api("finance.summary")
            if data:
                income = float(data.get("income", 0))
                expense = float(data.get("expense", 0))
                profit = float(data.get("profit", 0))
                period = data.get("period", {})
                p_emoji = "🟢" if profit >= 0 else "🔴"
                msg = f"""<b>📊 Финансовая сводка</b>
<i>{period.get('from', '?')} — {period.get('to', '?')}</i>

🟢 Доход: <b>{income:,.0f} ₽</b>
🔴 Расход: <b>{expense:,.0f} ₽</b>
{p_emoji} Прибыль: <b>{profit:,.0f} ₽</b>"""
                await self.send(chat_id, msg)
            else:
                await self.send(chat_id, "Нет данных")

        elif cmd == "/low":
            data = await self.erp_api("products.low_stock")
            if data and data.get("items"):
                lines = ["<b>⚠️ Низкий остаток:</b>"]
                for p in data["items"][:20]:
                    lines.append(f"• {p['sku']} {p['name']}: <b>{p['stock']}</b> (мин. {p['min_stock']})")
                await self.send(chat_id, "\n".join(lines))
            else:
                await self.send(chat_id, "✅ Все остатки в норме")

        elif cmd == "/ask":
            if not args:
                await self.send(chat_id, "Укажи вопрос: /ask Какие расходы за этот месяц?")
                return
            await self.send(chat_id, "🤔 Думаю...")
            data = await self.erp_api("ai.ask", {"question": args})
            if data and data.get("answer"):
                await self.send(chat_id, f"🤖 {data['answer']}")
            else:
                await self.send(chat_id, "❌ AI не смог ответить")

        elif cmd == "/contacts":
            data = await self.erp_api("crm.contacts", {"limit": 15})
            if data and data.get("items"):
                lines = ["<b>👥 Контакты:</b>"]
                for c in data["items"]:
                    name = " ".join(filter(None, [c.get("first_name"), c.get("last_name")])) or c.get("company", "—")
                    phone = c.get("phone", "")
                    cnt = c.get("interaction_count", 0)
                    lines.append(f"• <b>{name}</b> {phone} ({cnt} взаимод.)")
                await self.send(chat_id, "\n".join(lines))
            else:
                await self.send(chat_id, "Нет контактов в CRM")

        elif cmd == "/upcoming":
            data = await self.erp_api("crm.upcoming", {"days": 7})
            if not data:
                await self.send(chat_id, "Нет данных")
                return
            lines = []
            overdue = data.get("overdue", [])
            upcoming = data.get("upcoming", [])
            if overdue:
                lines.append("<b>🔴 Просрочено:</b>")
                for u in overdue[:10]:
                    contact = u.get("contact_name") or u.get("counterparty_name") or "—"
                    lines.append(f"• {u['next_action_date']} {contact}: {u.get('next_action', '?')}")
            if upcoming:
                lines.append("\n<b>📅 Предстоящие (7 дней):</b>")
                for u in upcoming[:10]:
                    contact = u.get("contact_name") or u.get("counterparty_name") or "—"
                    lines.append(f"• {u['next_action_date']} {contact}: {u.get('next_action', '?')}")
            if not lines:
                lines.append("✅ Нет запланированных действий")
            await self.send(chat_id, "\n".join(lines))

        else:
            await self.send(chat_id, "Неизвестная команда. /help")

    async def handle_journal_entry(self, chat_id: int, text: str, username: str):
        """Любой текст → запись в журнал с AI-анализом"""
        await self.send(chat_id, "📝 Записываю...")

        data = await self.erp_api("journal.create", {
            "text": text,
            "source": "telegram",
            "user_name": username,
        })

        if not data:
            await self.send(chat_id, "❌ Ошибка записи")
            return

        entry_id = data.get("id", "?")
        ai = data.get("ai_parsed")

        msg = f"✅ Записано (#{entry_id})"

        if ai and isinstance(ai, dict) and not ai.get("error"):
            category = ai.get("category", "?")
            summary = ai.get("summary", "")
            cat_emoji = {"finance": "💰", "inventory": "📦", "task": "✅", "logistics": "🚚", "note": "📝"}.get(category, "📋")
            msg += f"\n{cat_emoji} <b>{category}</b>"
            if summary:
                msg += f"\n<i>{summary}</i>"

            # Подробности
            if ai.get("finance") and ai["finance"].get("amount"):
                fin = ai["finance"]
                t = {"income": "Доход", "expense": "Расход", "transfer": "Перевод"}.get(fin.get("type", ""), "")
                msg += f"\n💰 {t}: {fin['amount']:,.0f} ₽"

            if ai.get("task") and ai["task"].get("title"):
                msg += f"\n✅ Задача: {ai['task']['title']}"

            if ai.get("inventory") and ai["inventory"].get("items"):
                for it in ai["inventory"]["items"][:3]:
                    msg += f"\n📦 {it.get('name', '?')}: {it.get('quantity', '?')} {it.get('unit', 'шт')}"
        elif ai and ai.get("error"):
            msg += f"\n⚠️ AI: {ai['error']}"

        await self.send(chat_id, msg)

    async def erp_api(self, action: str, data: dict = None) -> dict | None:
        """Вызов ERP PHP API"""
        try:
            url = f"{API_BASE}?action={action}"
            headers = {"Content-Type": "application/json"}
            if API_TOKEN:
                headers["Authorization"] = f"Bearer {API_TOKEN}"

            if data:
                async with self.session.post(url, json=data, headers=headers, timeout=aiohttp.ClientTimeout(total=30)) as resp:
                    return await resp.json()
            else:
                async with self.session.get(url, headers=headers, timeout=aiohttp.ClientTimeout(total=15)) as resp:
                    return await resp.json()
        except Exception as e:
            log.error(f"ERP API error ({action}): {e}")
            return None


# ── Main ───────────────────────────────────────────────
async def main():
    if not BOT_TOKEN:
        log.error("Set ERP_BOT_TOKEN environment variable")
        log.info("Example: export ERP_BOT_TOKEN=123456:ABC-DEF...")
        return

    bot = TelegramBot(BOT_TOKEN)
    await bot.start()
    try:
        await bot.poll()
    except KeyboardInterrupt:
        log.info("Interrupted")
    finally:
        await bot.stop()


if __name__ == "__main__":
    asyncio.run(main())
