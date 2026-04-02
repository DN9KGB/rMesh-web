<?php
/**
 * rMesh Remote Command Log Helper
 */

function _ensureCommandLogTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS rmesh_command_log (
        `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `call`         VARCHAR(16)   NOT NULL DEFAULT '',
        `sender`       VARCHAR(16)   NOT NULL DEFAULT '',
        `command`      VARCHAR(32)   NOT NULL DEFAULT '',
        `timestamp`    INT UNSIGNED  NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_call_ts` (`call`, `timestamp`),
        KEY `idx_sender`  (`sender`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function logCommandEvent(string $call, string $sender, string $command): void {
    require_once __DIR__ . '/db_config.php';
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET . ';connect_timeout=2';
        $db  = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        _ensureCommandLogTable($db);
        $stmt = $db->prepare("INSERT INTO rmesh_command_log
            (`call`, `sender`, `command`, `timestamp`)
            VALUES (:call, :sender, :command, :ts)");
        $stmt->execute(array(
            ':call'    => $call,
            ':sender'  => $sender,
            ':command' => $command,
            ':ts'      => time(),
        ));
    } catch (Exception $e) {
        error_log('rMesh command_log: ' . $e->getMessage());
        throw $e;
    }
}
