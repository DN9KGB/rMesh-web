<?php
/**
 * rMesh Admin – Remote Command Logs
 */
require_once 'auth.php';
require_auth();

$db = get_db();

// Ensure table exists
require_once __DIR__ . '/../command_log_helper.php';
_ensureCommandLogTable($db);

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$search  = trim($_GET['q'] ?? '');
$command = trim($_GET['command'] ?? '');
$band    = in_array($_GET['band'] ?? '', ['433', '868']) ? $_GET['band'] : '';

$params = [];
$where  = 'WHERE 1=1';
if ($search !== '') {
    $where .= ' AND (c.`call` LIKE :q OR c.sender LIKE :q2)';
    $params[':q']  = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
}
if ($command !== '') {
    $where .= ' AND c.command = :command';
    $params[':command'] = $command;
}

$joinNodes = $band !== '' ? "LEFT JOIN rmesh_nodes n ON n.`call` = c.`call`" : '';
if ($band !== '') {
    $where .= ' AND n.band = :band';
    $params[':band'] = $band;
}

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM rmesh_command_log c $joinNodes $where");
$countStmt->execute($params);
$total   = (int)$countStmt->fetchColumn();
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$params[':limit']  = $perPage;
$params[':offset'] = $offset;

$stmt = $db->prepare("
    SELECT c.id, c.`call`, c.sender, c.command, c.timestamp
    FROM rmesh_command_log c
    $joinNodes
    $where
    ORDER BY c.timestamp DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) {
    if ($k !== ':limit' && $k !== ':offset') $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct commands for filter
$commands = $db->query("SELECT DISTINCT command FROM rmesh_command_log ORDER BY command")->fetchAll(PDO::FETCH_COLUMN);

function buildUrl(array $extra = []): string {
    $q = array_merge($_GET, $extra);
    return '?' . http_build_query(array_filter($q, function($v) { return $v !== ''; }));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rMesh – Admin Remote Commands</title>
    <?php include '_head_styles.php'; ?>
</head>
<body>
<?php include '_nav.php'; ?>
<div class="page">
    <h1 class="page-title">Remote Commands <span style="font-size:1rem;color:#888;font-weight:400;">(<?= $total ?> Einträge)</span></h1>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Rufzeichen / Absender…" value="<?= htmlspecialchars($search) ?>">
        <label>Band:
            <select name="band" onchange="this.form.submit()">
                <option value=""    <?= $band===''    ?'selected':'' ?>>Alle</option>
                <option value="433" <?= $band==='433' ?'selected':'' ?>>433 MHz</option>
                <option value="868" <?= $band==='868' ?'selected':'' ?>>868 MHz</option>
            </select>
        </label>
        <label>Befehl:
            <select name="command" onchange="this.form.submit()">
                <option value="">Alle</option>
                <?php foreach ($commands as $cmd): ?>
                    <option value="<?= htmlspecialchars($cmd) ?>" <?= $command===$cmd?'selected':'' ?>><?= htmlspecialchars($cmd) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="hidden" name="page" value="1">
        <button type="submit" style="padding:7px 14px;background:#0f3460;color:#4ecca3;border:1px solid #4ecca3;border-radius:6px;cursor:pointer;font-size:0.875rem;">Suchen</button>
    </form>

    <div class="table-wrap">
        <table data-sortable>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Zeit</th>
                    <th>Empfänger</th>
                    <th>Absender</th>
                    <th>Befehl</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="muted"><?= $r['id'] ?></td>
                    <td class="mono" data-sort="<?= $r['timestamp'] ?>"><?= date('d.m.Y H:i:s', $r['timestamp']) ?></td>
                    <td class="strong mono"><?= htmlspecialchars($r['call']) ?></td>
                    <td class="mono"><?= htmlspecialchars($r['sender'] ?: '—') ?></td>
                    <td data-sort="<?= htmlspecialchars($r['command']) ?>"><?php
                        $cmd = $r['command'];
                        if ($cmd === 'reboot')       $cls = 'badge-err';
                        elseif ($cmd === 'version')   $cls = 'badge-info';
                        else                          $cls = 'badge-neu';
                        echo "<span class=\"badge $cls\">" . htmlspecialchars($cmd) . '</span>';
                    ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="text-align:center;color:#555;padding:20px;">Keine Einträge gefunden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="pag-btn">← Zurück</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 3);
        $end   = min($pages, $page + 3);
        if ($start > 1) { echo '<a href="' . buildUrl(['page' => 1]) . '" class="pag-btn">1</a>'; if ($start > 2) echo '<span class="pag-info">…</span>'; }
        for ($i = $start; $i <= $end; $i++) {
            $cls = $i === $page ? ' active' : '';
            echo '<a href="' . buildUrl(['page' => $i]) . '" class="pag-btn' . $cls . '">' . $i . '</a>';
        }
        if ($end < $pages) { if ($end < $pages - 1) echo '<span class="pag-info">…</span>'; echo '<a href="' . buildUrl(['page' => $pages]) . '" class="pag-btn">' . $pages . '</a>'; }
        ?>
        <?php if ($page < $pages): ?>
            <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="pag-btn">Weiter →</a>
        <?php endif; ?>
        <span class="pag-info">Seite <?= $page ?> / <?= $pages ?> &nbsp;(<?= $total ?> Einträge)</span>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
