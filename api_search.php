<?php
// ── api/search.php — GET /api/search ─────────────────────────────────────────
// Mirrors the parameter names, modes, and column names of search.php exactly.
//
// Parameters:
//   query   (required) — search term
//   dict_id (optional) — restrict to a specific dictionary; omit = all active
//   mode    (optional) — exact | prefix | suffix | substring  (default: exact)
//   page    (optional) — page number for pagination            (default: 1)
//
// Responses:
//   200  { query, dict_id, mode, page, per_page, total, total_pages, count, results: [...] }
//   400  { error: "message" }
//   404  { error: "message" }
//   500  { error: "message" }
// ─────────────────────────────────────────────────────────────────────────────

mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../dbcon.php';

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Validate: query ───────────────────────────────────────────────────────────
$query = trim($_GET['query'] ?? '');
if ($query === '') {
    respond(400, ['error' => 'Missing required parameter: query']);
}
if (mb_strlen($query) > 200) {
    respond(400, ['error' => 'Parameter query exceeds maximum length of 200 characters.']);
}

// ── Validate: mode ────────────────────────────────────────────────────────────
$valid_modes = ['exact', 'prefix', 'suffix', 'substring'];
$mode = strtolower(trim($_GET['mode'] ?? 'exact'));
if (!in_array($mode, $valid_modes, true)) {
    respond(400, ['error' => 'Invalid mode. Allowed values: exact, prefix, suffix, substring.']);
}

// ── Validate: dict_id ─────────────────────────────────────────────────────────
$dict_id = null;
if (isset($_GET['dict_id']) && $_GET['dict_id'] !== '') {
    $dict_id = (int)$_GET['dict_id'];
    if ($dict_id <= 0) {
        respond(400, ['error' => 'Parameter dict_id must be a positive integer.']);
    }
    $chk = mysqli_prepare($conn, "SELECT dict_id FROM dictionaries WHERE dict_id = ? AND is_active = 1");
    mysqli_stmt_bind_param($chk, 'i', $dict_id);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    $found = mysqli_stmt_num_rows($chk) > 0;
    mysqli_stmt_close($chk);
    if (!$found) {
        respond(404, ['error' => "Dictionary with id $dict_id not found or is inactive."]);
    }
}

// ── Validate: page ────────────────────────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

// ── Build LIKE pattern (matching search.php logic exactly) ────────────────────
$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
$pattern = match($mode) {
    'prefix'    => $escaped . '%',
    'suffix'    => '%' . $escaped,
    'substring' => '%' . $escaped . '%',
    default     => $escaped,   // exact
};

// ── Count + fetch ─────────────────────────────────────────────────────────────
try {
    // Count total
    if ($dict_id !== null) {
        $count_stmt = mysqli_prepare($conn,
            "SELECT COUNT(*) AS total
             FROM   dictionary_entries e
             JOIN   dictionaries d ON e.dict_id = d.dict_id
             WHERE  e.is_active = 1 AND d.is_active = 1
               AND  e.lang_1 LIKE ?
               AND  d.dict_id = ?");
        mysqli_stmt_bind_param($count_stmt, 'si', $pattern, $dict_id);
    } else {
        $count_stmt = mysqli_prepare($conn,
            "SELECT COUNT(*) AS total
             FROM   dictionary_entries e
             JOIN   dictionaries d ON e.dict_id = d.dict_id
             WHERE  e.is_active = 1 AND d.is_active = 1
               AND  e.lang_1 LIKE ?");
        mysqli_stmt_bind_param($count_stmt, 's', $pattern);
    }
    mysqli_stmt_execute($count_stmt);
    $total       = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'] ?? 0);
    $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 0;
    mysqli_stmt_close($count_stmt);

    if ($page > $total_pages && $total_pages > 0) {
        respond(400, ['error' => "Page $page exceeds total pages ($total_pages)."]);
    }

    // Fetch results — same columns as search.php
    if ($dict_id !== null) {
        $stmt = mysqli_prepare($conn,
            "SELECT e.lang_1, e.lang_2, e.lang_3, e.pronunciation, e.part_of_speech, d.name AS dict_name
             FROM   dictionary_entries e
             JOIN   dictionaries d ON e.dict_id = d.dict_id
             WHERE  e.is_active = 1 AND d.is_active = 1
               AND  e.lang_1 LIKE ?
               AND  d.dict_id = ?
             ORDER  BY e.lang_1
             LIMIT  ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, 'siii', $pattern, $dict_id, $per_page, $offset);
    } else {
        $stmt = mysqli_prepare($conn,
            "SELECT e.lang_1, e.lang_2, e.lang_3, e.pronunciation, e.part_of_speech, d.name AS dict_name
             FROM   dictionary_entries e
             JOIN   dictionaries d ON e.dict_id = d.dict_id
             WHERE  e.is_active = 1 AND d.is_active = 1
               AND  e.lang_1 LIKE ?
             ORDER  BY e.lang_1
             LIMIT  ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, 'sii', $pattern, $per_page, $offset);
    }

    if (!$stmt) {
        respond(500, ['error' => 'Database prepare failed: ' . mysqli_error($conn)]);
    }

    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    // Drop empty optional fields so JSON stays clean
    $results = array_map(function(array $r): array {
        $out = [
            'lang_1'    => $r['lang_1'],
            'lang_2'    => $r['lang_2'],
            'dict_name' => $r['dict_name'],
        ];
        if (!empty($r['lang_3']))         $out['lang_3']        = $r['lang_3'];
        if (!empty($r['pronunciation']))  $out['pronunciation']  = $r['pronunciation'];
        if (!empty($r['part_of_speech'])) $out['part_of_speech'] = $r['part_of_speech'];
        return $out;
    }, $rows);

    respond(200, [
        'query'       => $query,
        'dict_id'     => $dict_id,
        'mode'        => $mode,
        'page'        => $page,
        'per_page'    => $per_page,
        'total'       => $total,
        'total_pages' => $total_pages,
        'count'       => count($results),
        'results'     => $results,
    ]);

} catch (Throwable $e) {
    respond(500, ['error' => 'Internal server error: ' . $e->getMessage()]);
}
