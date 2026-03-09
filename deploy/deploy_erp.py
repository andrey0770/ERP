#!/usr/bin/env python3
"""
Деплой ERP на хостинг через SFTP.
Заливает erp/ в public_html/erp/ на оба домена.

Использование:
  python deploy/deploy_erp.py
"""

import paramiko
from pathlib import Path
import os

# ── Load .env ──
def load_env():
    env_path = Path(__file__).resolve().parent / ".env"
    if env_path.exists():
        for line in env_path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                key, _, val = line.partition("=")
                os.environ.setdefault(key.strip(), val.strip())

load_env()

SFTP_HOST = os.environ.get("SFTP_HOST", "sborka.billiarder.ru")
SFTP_USER = os.environ.get("SFTP_USER", "")
SFTP_PASS = os.environ.get("SFTP_PASS", "")
REMOTE_FOLDER = "erp"

DEPLOY_TARGETS = [
    "/home/bril/web/sborka.billiarder.ru/public_html",
]

PROJECT_ROOT = Path(__file__).resolve().parent.parent
LOCAL_DIR = PROJECT_ROOT / "erp"

IGNORE = {".vscode", ".git", "node_modules", ".env", "__pycache__", "cache"}
IGNORE_SUFFIXES = (".md", ".pyc")

# Не перезаписывать на сервере если уже есть
PROTECT_FILES = {"config.php", "s3_config.php"}


def create_sftp_connection():
    transport = paramiko.Transport((SFTP_HOST, 22))
    transport.set_keepalive(15)
    transport.connect(username=SFTP_USER, password=SFTP_PASS)
    return paramiko.SFTPClient.from_transport(transport), transport


def ensure_remote_dir(sftp, path):
    try:
        sftp.stat(path)
    except FileNotFoundError:
        parent = "/".join(path.rstrip("/").split("/")[:-1])
        ensure_remote_dir(sftp, parent)
        sftp.mkdir(path)
        print(f"  [dir] {path}")


def remote_file_exists(sftp, path):
    try:
        sftp.stat(path)
        return True
    except FileNotFoundError:
        return False


def remote_file_same(sftp, remote_path, local_path: Path) -> bool:
    try:
        remote_stat = sftp.stat(remote_path)
        local_size = local_path.stat().st_size
        return remote_stat.st_size == local_size
    except FileNotFoundError:
        return False


def should_upload(path: Path) -> bool:
    rel = path.relative_to(LOCAL_DIR)
    for part in rel.parts:
        if part in IGNORE:
            return False
    if rel.suffix.lower() in IGNORE_SUFFIXES:
        return False
    return True


def main():
    if not LOCAL_DIR.is_dir():
        print(f"ERROR: Folder not found: {LOCAL_DIR}")
        return 1

    print(f"Deploy ERP -> billiarder.ru/{REMOTE_FOLDER}/")
    print(f"   Host: {SFTP_HOST}")
    print(f"   Local: {LOCAL_DIR}")
    print()

    try:
        for sftp_base in DEPLOY_TARGETS:
            sftp, transport = create_sftp_connection()
            domain = sftp_base.split("/web/")[1].split("/")[0]
            print(f"--- {domain}/{REMOTE_FOLDER}/ ---")
            remote_base = f"{sftp_base.rstrip('/')}/{REMOTE_FOLDER}"
            ensure_remote_dir(sftp, remote_base)

            uploaded = 0
            skipped = 0
            protected = 0

            for f in sorted(LOCAL_DIR.rglob("*")):
                if not f.is_file():
                    continue
                if not should_upload(f):
                    continue

                rel = f.relative_to(LOCAL_DIR)
                remote_path = f"{remote_base}/{rel.as_posix()}"

                # Защищённые конфиги
                if rel.name in PROTECT_FILES and remote_file_exists(sftp, remote_path):
                    print(f"  SKIP {rel} (protected)")
                    protected += 1
                    continue

                remote_dir = str(Path(remote_path).parent).replace("\\", "/")
                ensure_remote_dir(sftp, remote_dir)

                # Пропуск неизменённых
                if remote_file_same(sftp, remote_path, f):
                    skipped += 1
                    continue

                sftp.put(str(f), remote_path)
                print(f"  OK {rel}")
                uploaded += 1

            print(f"  -> {uploaded} uploaded, {skipped} unchanged, {protected} protected\n")

            sftp.close()
            transport.close()

        print("Done.")
        return 0

    except Exception as e:
        print(f"Error: {e}")
        return 1


if __name__ == "__main__":
    exit(main())
