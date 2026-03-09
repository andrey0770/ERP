#!/usr/bin/env python3
"""
Бэкап БД ERP → Yandex S3.

Делает mysqldump таблиц с префиксом erp_ из базы bril_1,
заливает gzip-архив в S3 с ротацией (хранит последние N копий).

Использование:
  python deploy/backup_db.py              # ручной бэкап
  python deploy/backup_db.py --list       # показать бэкапы в S3
  python deploy/backup_db.py --restore    # скачать последний бэкап

Для крона на сервере:
  0 3 * * * cd /home/bril/erp-tools && python3 backup_db.py >> /var/log/erp_backup.log 2>&1

Зависимости: pip install paramiko boto3
"""

import os
import sys
import gzip
import tempfile
from datetime import datetime
from pathlib import Path

# ── Загрузка секретов из .env ──
def load_env():
    env_path = Path(__file__).resolve().parent / ".env"
    if env_path.exists():
        for line in env_path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                key, _, val = line.partition("=")
                os.environ.setdefault(key.strip(), val.strip())

load_env()

# ── Подключение к серверу (mysqldump выполняется удалённо) ──
SFTP_HOST = os.environ.get("SFTP_HOST", "sborka.billiarder.ru")
SFTP_USER = os.environ.get("SFTP_USER", "")
SFTP_PASS = os.environ.get("SFTP_PASS", "")

# ── БД ──
DB_NAME = os.environ.get("DB_NAME", "bril_1")
DB_USER = os.environ.get("DB_USER", "")
DB_PASS = os.environ.get("DB_PASS", "")
TABLE_PREFIX = "erp_"

# ── S3 ──
S3_ENDPOINT = os.environ.get("S3_ENDPOINT", "https://storage.yandexcloud.net")
S3_REGION = os.environ.get("S3_REGION", "ru-central1")
S3_BUCKET = os.environ.get("S3_BUCKET", "sborka-video")
S3_PREFIX = "erp/backups/"
S3_ACCESS_KEY = os.environ.get("S3_ACCESS_KEY", "")
S3_SECRET_KEY = os.environ.get("S3_SECRET_KEY", "")

# Хранить последние N бэкапов
MAX_BACKUPS = 30


def get_s3_client():
    import boto3
    return boto3.client(
        "s3",
        endpoint_url=S3_ENDPOINT,
        region_name=S3_REGION,
        aws_access_key_id=S3_ACCESS_KEY,
        aws_secret_access_key=S3_SECRET_KEY,
    )


def get_ssh_connection():
    import paramiko
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(SFTP_HOST, username=SFTP_USER, password=SFTP_PASS)
    return ssh


def run_remote_command(ssh, cmd):
    """Выполнить команду на сервере, вернуть stdout bytes."""
    stdin, stdout, stderr = ssh.exec_command(cmd)
    exit_code = stdout.channel.recv_exit_status()
    if exit_code != 0:
        err = stderr.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Remote command failed (exit {exit_code}): {err}")
    return stdout.read()


def get_erp_tables(ssh):
    """Получить список таблиц с префиксом erp_."""
    cmd = (
        f'mysql -u{DB_USER} -p"{DB_PASS}" -N -e '
        f'"SELECT table_name FROM information_schema.tables '
        f"WHERE table_schema='{DB_NAME}' AND table_name LIKE '{TABLE_PREFIX}%'\" "
    )
    output = run_remote_command(ssh, cmd).decode("utf-8").strip()
    tables = [t.strip() for t in output.split("\n") if t.strip()]
    return tables


def do_backup():
    """Создать бэкап и залить в S3."""
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Starting ERP DB backup...")

    ssh = get_ssh_connection()

    # Получить список таблиц
    tables = get_erp_tables(ssh)
    if not tables:
        print("  ERROR: no erp_ tables found!")
        ssh.close()
        return False

    print(f"  Found {len(tables)} tables: {', '.join(tables[:5])}...")

    # Выполнить mysqldump на сервере
    table_list = " ".join(tables)
    dump_cmd = (
        f'mysqldump -u{DB_USER} -p"{DB_PASS}" '
        f"--single-transaction --routines --triggers "
        f"{DB_NAME} {table_list}"
    )
    dump_data = run_remote_command(ssh, dump_cmd)
    ssh.close()

    print(f"  Dump size: {len(dump_data):,} bytes")

    # Сжать
    compressed = gzip.compress(dump_data, compresslevel=9)
    print(f"  Compressed: {len(compressed):,} bytes")

    # Залить в S3
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    s3_key = f"{S3_PREFIX}erp_backup_{timestamp}.sql.gz"

    s3 = get_s3_client()
    s3.put_object(Bucket=S3_BUCKET, Key=s3_key, Body=compressed)
    print(f"  Uploaded to s3://{S3_BUCKET}/{s3_key}")

    # Ротация — удалить старые
    rotate_backups(s3)

    print(f"  Backup complete!")
    return True


def rotate_backups(s3):
    """Удалить старые бэкапы, оставить последние MAX_BACKUPS."""
    response = s3.list_objects_v2(Bucket=S3_BUCKET, Prefix=S3_PREFIX)
    objects = response.get("Contents", [])
    backups = sorted(
        [o for o in objects if o["Key"].endswith(".sql.gz")],
        key=lambda o: o["LastModified"],
        reverse=True,
    )

    if len(backups) > MAX_BACKUPS:
        to_delete = backups[MAX_BACKUPS:]
        for obj in to_delete:
            s3.delete_object(Bucket=S3_BUCKET, Key=obj["Key"])
            print(f"  Rotated out: {obj['Key']}")


def list_backups():
    """Показать все бэкапы в S3."""
    s3 = get_s3_client()
    response = s3.list_objects_v2(Bucket=S3_BUCKET, Prefix=S3_PREFIX)
    objects = response.get("Contents", [])
    backups = sorted(
        [o for o in objects if o["Key"].endswith(".sql.gz")],
        key=lambda o: o["LastModified"],
        reverse=True,
    )

    if not backups:
        print("No backups found.")
        return

    print(f"Found {len(backups)} backup(s):\n")
    for b in backups:
        size_mb = b["Size"] / 1024 / 1024
        date = b["LastModified"].strftime("%Y-%m-%d %H:%M:%S")
        name = b["Key"].split("/")[-1]
        print(f"  {date}  {size_mb:6.2f} MB  {name}")


def restore_latest():
    """Скачать последний бэкап в текущую директорию."""
    s3 = get_s3_client()
    response = s3.list_objects_v2(Bucket=S3_BUCKET, Prefix=S3_PREFIX)
    objects = response.get("Contents", [])
    backups = sorted(
        [o for o in objects if o["Key"].endswith(".sql.gz")],
        key=lambda o: o["LastModified"],
        reverse=True,
    )

    if not backups:
        print("No backups found!")
        return

    latest = backups[0]
    filename = latest["Key"].split("/")[-1]
    print(f"Downloading: {filename} ({latest['Size'] / 1024 / 1024:.2f} MB)...")

    s3.download_file(S3_BUCKET, latest["Key"], filename)
    print(f"Saved to: {filename}")
    print(f"\nTo restore:")
    print(f"  gunzip {filename}")
    print(f"  mysql -u{DB_USER} -p'{DB_PASS}' {DB_NAME} < {filename.replace('.gz', '')}")


if __name__ == "__main__":
    if "--list" in sys.argv:
        list_backups()
    elif "--restore" in sys.argv:
        restore_latest()
    else:
        do_backup()
