<?php
/**
 * ERP Module: Системные операции
 * 
 * system.migrate — запуск миграций
 * system.status  — статус системы
 * system.config  — (read-only) текущие настройки без секретов
 */
class ERP_System {

    public function migrate(): array {
        $results = DB::migrate();
        return ['ok' => true, 'migrations' => $results];
    }

    public function status(): array {
        $pdo = DB::get();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        $counts = [];
        foreach ($tables as $t) {
            if ($t === 'erp_migrations') continue;
            $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        }

        return [
            'ok'      => true,
            'version' => '0.1.0',
            'tables'  => $tables,
            'counts'  => $counts,
            'php'     => PHP_VERSION,
            'time'    => date('Y-m-d H:i:s'),
        ];
    }

    public function config(): array {
        $cfg = require __DIR__ . '/../config.php';
        return [
            'ai_provider' => $cfg['ai_provider'],
            'ai_models'   => array_map(fn($p) => $p['model'], $cfg['ai']),
            'db_name'     => $cfg['db']['name'],
            'db_host'     => $cfg['db']['host'],
        ];
    }
}
