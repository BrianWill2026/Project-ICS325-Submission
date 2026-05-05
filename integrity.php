<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');

require_once 'dbcon.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ── Fetch active dictionaries for filters ─────────────────────────────────────
$dictionaries = [];
$dict_result  = mysqli_query($conn, "SELECT dict_id, name, type FROM dictionaries WHERE is_active = TRUE ORDER BY name");
while ($row = mysqli_fetch_assoc($dict_result)) {
    $dictionaries[] = $row;
}

// ── Selected dictionary filter ────────────────────────────────────────────────
// Check both GET (normal filter) and POST (delete action carries it as hidden field)
$filter_dict_id = 0;
if (isset($_GET['dict_id']))  $filter_dict_id = (int)$_GET['dict_id'];
if (isset($_POST['dict_id_filter'])) $filter_dict_id = (int)$_POST['dict_id_filter'];

// ── Handle delete duplicates POST ─────────────────────────────────────────────
$delete_msg     = '';
$delete_ok      = false;
$deleted_count  = 0;

if (isset($_POST['delete_duplicates'])) {
    $deleted_count = 0;
    $dry_run = isset($_POST['dry_run']);

    // Step 1: Fetch only entries that have a valid lang_1
    if ($filter_dict_id > 0) {
        $all = mysqli_query($conn,
            "SELECT entry_id, dict_id, LOWER(TRIM(lang_1)) AS lang_key
             FROM dictionary_entries
             WHERE dict_id = " . (int)$filter_dict_id . "
             AND lang_1 IS NOT NULL AND TRIM(lang_1) != ''
             ORDER BY entry_id ASC");
    } else {
        $all = mysqli_query($conn,
            "SELECT entry_id, dict_id, LOWER(TRIM(lang_1)) AS lang_key
             FROM dictionary_entries
             WHERE lang_1 IS NOT NULL AND TRIM(lang_1) != ''
             ORDER BY entry_id ASC");
    }

    // Group entry_ids by dict_id + lang_key in PHP
    $groups = [];
    while ($row = mysqli_fetch_assoc($all)) {
        if ($row['lang_key'] === null || $row['lang_key'] === '') continue;
        $key = $row['dict_id'] . '||' . $row['lang_key'];
        $groups[$key][] = (int)$row['entry_id'];
    }

    // Collect IDs to delete — keep the lowest entry_id per group
    $ids_to_delete = [];
    $ids_to_keep   = [];
    foreach ($groups as $ids) {
        if (count($ids) > 1) {
            $ids_to_keep[] = $ids[0];
            $to_delete = array_slice($ids, 1);
            foreach ($to_delete as $id) {
                $ids_to_delete[] = $id;
            }
        }
    }

    if ($dry_run) {
        // Count total entries in DB vs what we'd keep
        $total_in_db = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM dictionary_entries"))['c'];
        $delete_ok  = false;
        $delete_msg = "DRY RUN: DB has {$total_in_db} entries total. Would delete " . count($ids_to_delete) . ", keeping " . count($ids_to_keep) . " from duplicate groups + " . (count($groups) - count($ids_to_keep)) . " unique entries. Smallest keep ID: " . (min($ids_to_keep) ?? 'n/a') . ", smallest delete ID: " . (min($ids_to_delete) ?? 'n/a') . ".";
    } elseif (!empty($ids_to_delete)) {
        // Delete in batches of 100
        $chunks = array_chunk($ids_to_delete, 100);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', $chunk);
            mysqli_query($conn, "DELETE FROM dictionary_entries WHERE entry_id IN ($placeholders)");
            $deleted_count += mysqli_affected_rows($conn);
        }
        $delete_ok  = true;
        $delete_msg = "Deleted {$deleted_count} duplicate entr" . ($deleted_count === 1 ? 'y' : 'ies') . ". One copy of each headword was kept.";
    } else {
        $delete_ok  = true;
        $delete_msg = 'No duplicates found to delete.';
    }
}


// ── Run checks ────────────────────────────────────────────────────────────────

// 1. Missing lang_1 or lang_2
if ($filter_dict_id > 0) {
    $q = mysqli_prepare($conn,
        "SELECT e.entry_id, e.lang_1, e.lang_2, e.lang_3, e.part_of_speech, d.name AS dict_name, d.type AS dict_type
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1 AND e.dict_id = ?
           AND (e.lang_1 IS NULL OR TRIM(e.lang_1) = '' OR e.lang_2 IS NULL OR TRIM(e.lang_2) = '')
         ORDER BY d.name, e.entry_id
         LIMIT 200");
    mysqli_stmt_bind_param($q, 'i', $filter_dict_id);
} else {
    $q = mysqli_prepare($conn,
        "SELECT e.entry_id, e.lang_1, e.lang_2, e.lang_3, e.part_of_speech, d.name AS dict_name, d.type AS dict_type
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1
           AND (e.lang_1 IS NULL OR TRIM(e.lang_1) = '' OR e.lang_2 IS NULL OR TRIM(e.lang_2) = '')
         ORDER BY d.name, e.entry_id
         LIMIT 200");
}
mysqli_stmt_execute($q);
$missing_rows = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
mysqli_stmt_close($q);

// 2. Duplicates (same lang_1 within the same dictionary)
if ($filter_dict_id > 0) {
    $q2 = mysqli_prepare($conn,
        "SELECT e.lang_1, d.name AS dict_name, COUNT(*) AS occurrences
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1 AND e.dict_id = ?
         GROUP BY e.dict_id, LOWER(TRIM(e.lang_1))
         HAVING COUNT(*) > 1
         ORDER BY occurrences DESC, d.name
         LIMIT 200");
    mysqli_stmt_bind_param($q2, 'i', $filter_dict_id);
} else {
    $q2 = mysqli_prepare($conn,
        "SELECT e.lang_1, d.name AS dict_name, COUNT(*) AS occurrences
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1
         GROUP BY e.dict_id, LOWER(TRIM(e.lang_1))
         HAVING COUNT(*) > 1
         ORDER BY occurrences DESC, d.name
         LIMIT 200");
}
mysqli_stmt_execute($q2);
$duplicate_rows = mysqli_fetch_all(mysqli_stmt_get_result($q2), MYSQLI_ASSOC);
mysqli_stmt_close($q2);

// 3. Trilingual entries missing lang_3
if ($filter_dict_id > 0) {
    $q3 = mysqli_prepare($conn,
        "SELECT e.entry_id, e.lang_1, e.lang_2, d.name AS dict_name
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1 AND d.type = 'trilingual'
           AND e.dict_id = ?
           AND (e.lang_3 IS NULL OR TRIM(e.lang_3) = '')
         ORDER BY d.name, e.entry_id
         LIMIT 200");
    mysqli_stmt_bind_param($q3, 'i', $filter_dict_id);
} else {
    $q3 = mysqli_prepare($conn,
        "SELECT e.entry_id, e.lang_1, e.lang_2, d.name AS dict_name
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1 AND d.type = 'trilingual'
           AND (e.lang_3 IS NULL OR TRIM(e.lang_3) = '')
         ORDER BY d.name, e.entry_id
         LIMIT 200");
}
mysqli_stmt_execute($q3);
$missing_lang3_rows = mysqli_fetch_all(mysqli_stmt_get_result($q3), MYSQLI_ASSOC);
mysqli_stmt_close($q3);

// 4. Entries with unusually long values (> 300 chars in any language field)
if ($filter_dict_id > 0) {
    $q4 = mysqli_prepare($conn,
        "SELECT e.entry_id, e.lang_1, e.lang_2, e.lang_3, d.name AS dict_name,
                CHAR_LENGTH(e.lang_1) AS len1, CHAR_LENGTH(e.lang_2) AS len2, CHAR_LENGTH(e.lang_3) AS len3
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1 AND e.dict_id = ?
           AND (CHAR_LENGTH(e.lang_1) > 300 OR CHAR_LENGTH(e.lang_2) > 300 OR CHAR_LENGTH(e.lang_3) > 300)
         ORDER BY GREATEST(COALESCE(CHAR_LENGTH(e.lang_1),0), COALESCE(CHAR_LENGTH(e.lang_2),0), COALESCE(CHAR_LENGTH(e.lang_3),0)) DESC
         LIMIT 200");
    mysqli_stmt_bind_param($q4, 'i', $filter_dict_id);
} else {
    $q4 = mysqli_prepare($conn,
        "SELECT e.entry_id, e.lang_1, e.lang_2, e.lang_3, d.name AS dict_name,
                CHAR_LENGTH(e.lang_1) AS len1, CHAR_LENGTH(e.lang_2) AS len2, CHAR_LENGTH(e.lang_3) AS len3
         FROM dictionary_entries e
         JOIN dictionaries d ON e.dict_id = d.dict_id
         WHERE e.is_active = 1 AND d.is_active = 1
           AND (CHAR_LENGTH(e.lang_1) > 300 OR CHAR_LENGTH(e.lang_2) > 300 OR CHAR_LENGTH(e.lang_3) > 300)
         ORDER BY GREATEST(COALESCE(CHAR_LENGTH(e.lang_1),0), COALESCE(CHAR_LENGTH(e.lang_2),0), COALESCE(CHAR_LENGTH(e.lang_3),0)) DESC
         LIMIT 200");
}
mysqli_stmt_execute($q4);
$long_value_rows = mysqli_fetch_all(mysqli_stmt_get_result($q4), MYSQLI_ASSOC);
mysqli_stmt_close($q4);

// 5. Summary stats per dictionary
$q5 = mysqli_prepare($conn,
    "SELECT d.name AS dict_name, d.type AS dict_type,
            COUNT(e.entry_id) AS total_entries,
            SUM(CASE WHEN e.lang_1 IS NULL OR TRIM(e.lang_1) = '' THEN 1 ELSE 0 END) AS missing_lang1,
            SUM(CASE WHEN e.lang_2 IS NULL OR TRIM(e.lang_2) = '' THEN 1 ELSE 0 END) AS missing_lang2,
            SUM(CASE WHEN d.type = 'trilingual' AND (e.lang_3 IS NULL OR TRIM(e.lang_3) = '') THEN 1 ELSE 0 END) AS missing_lang3
     FROM dictionaries d
     LEFT JOIN dictionary_entries e ON d.dict_id = e.dict_id AND e.is_active = 1
     WHERE d.is_active = 1
     GROUP BY d.dict_id, d.name, d.type
     ORDER BY d.name");
mysqli_stmt_execute($q5);
$summary_rows = mysqli_fetch_all(mysqli_stmt_get_result($q5), MYSQLI_ASSOC);
mysqli_stmt_close($q5);

// ── Total issue counts (for header badges) ────────────────────────────────────
$total_issues = count($missing_rows) + count($duplicate_rows) + count($missing_lang3_rows) + count($long_value_rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="IndicLex — Data Integrity & Validation">
  <title>IndicLex — Data Integrity</title>

  <script src="js/theme.js"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">

  <style>
    /* ── Issue severity badges ──────────────────────────────────────────── */
    .issue-count {
      display: inline-block;
      min-width: 1.6rem;
      padding: 0.1rem 0.45rem;
      border-radius: 999px;
      font-size: 0.72rem;
      font-family: var(--il-font-mono);
      font-weight: 700;
      text-align: center;
      line-height: 1.5;
    }
    .issue-count.none  { background: var(--il-surface-2); color: var(--il-text-muted); border: 1px solid var(--il-border); }
    .issue-count.warn  { background: #fff3cd; color: #664d03; border: 1px solid #ffc107; }
    .issue-count.error { background: #f8d7da; color: #58151c; border: 1px solid #f5c6cb; }

    /* ── Section nav tabs ───────────────────────────────────────────────── */
    .integrity-tabs .nav-link {
      font-size: 0.85rem;
      color: var(--il-text-muted);
      border-radius: 6px 6px 0 0;
      padding: 0.45rem 1rem;
    }
    .integrity-tabs .nav-link.active {
      color: var(--il-primary);
      background: var(--il-surface);
      border-bottom-color: var(--il-surface);
    }

    /* ── Truncated cell with expand ─────────────────────────────────────── */
    .cell-trunc {
      max-width: 220px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: inline-block;
      vertical-align: bottom;
      cursor: pointer;
    }
    .cell-trunc:hover { text-decoration: underline dotted; }

    /* ── Summary health bar ─────────────────────────────────────────────── */
    .health-bar {
      height: 6px;
      border-radius: 3px;
      background: var(--il-border);
      overflow: hidden;
      margin-top: 0.3rem;
    }
    .health-bar-fill {
      height: 100%;
      border-radius: 3px;
      background: var(--il-primary);
      transition: width 0.4s ease;
    }
    .health-bar-fill.warn  { background: #ffc107; }
    .health-bar-fill.error { background: #dc3545; }
  </style>
</head>

<body id="page-top">
<div id="wrapper">

  <!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
  <ul class="navbar-nav sidebar sidebar-dark accordion d-flex flex-column" id="accordionSidebar"
      style="min-width:220px; min-height:100vh; position:sticky; top:0;">

    <a class="sidebar-brand d-flex align-items-center justify-content-center text-decoration-none py-3" href="index.php">
      <div class="sidebar-brand-text">Indic<span>Lex</span></div>
    </a>

    <hr class="sidebar-divider my-1">
    <div class="sidebar-heading">Navigation</div>

    <li class="nav-item">
      <a class="nav-link" href="index.php">
        <i class="fas fa-fw fa-home me-2"></i><span>Home</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="search.php">
        <i class="fas fa-fw fa-search me-2"></i><span>Search</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="preferences.php">
        <i class="fas fa-fw fa-sliders-h me-2"></i><span>Preferences</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="admin.php">
        <i class="fas fa-fw fa-tachometer-alt me-2"></i><span>Dashboard</span>
      </a>
    </li>
    <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
    <li class="nav-item active">
      <a class="nav-link" href="integrity.php">
        <i class="fas fa-fw fa-shield-alt me-2"></i><span>Reporting</span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="catalog.php">
        <i class="fas fa-fw fa-book-open me-2"></i><span>Dictionary Catalog</span>
      </a>
    </li>
    <?php endif; ?>

    <hr class="sidebar-divider d-none d-md-block mt-auto">
    <div class="text-center d-none d-md-inline pb-2">
      <button class="rounded-circle border-0" id="sidebarToggle"
              style="width:2rem;height:2rem;background:rgba(255,255,255,0.08);color:#fff;">
        <i class="fas fa-angle-left" style="font-size:0.8rem;"></i>
      </button>
    </div>
  </ul>

  <!-- ── Content wrapper ─────────────────────────────────────────────────── -->
  <div id="content-wrapper" class="d-flex flex-column flex-grow-1" style="min-width:0;">
    <div id="content">

      <!-- Topbar -->
      <nav class="navbar navbar-expand topbar mb-4 static-top shadow-sm">
        <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle me-3">
          <i class="fa fa-bars"></i>
        </button>
        <span class="d-md-none fw-bold" style="font-family:var(--il-font-display); font-size:1rem;">IndicLex</span>
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item me-2">
            <button id="themeToggleBtn" aria-label="Toggle theme">
              <i class="fas fa-moon me-1"></i>
              <span class="d-none d-md-inline">Dark Mode</span>
            </button>
          </li>
          <div class="topbar-divider d-none d-sm-block"
               style="border-left:1px solid var(--il-border); height:2rem; margin:0 0.75rem;"></div>
          <li class="nav-item">
            <span style="font-size:0.8rem; color:var(--il-text-muted);">
              <i class="fas fa-code-branch me-1"></i>Iteration 4
            </span>
          </li>
        </ul>
      </nav>

      <div class="container-fluid px-0">

        <!-- Page header -->
        <div class="il-page-header">
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="index.php">Home</a></li>
              <li class="breadcrumb-item active" aria-current="page">Data Integrity</li>
            </ol>
          </nav>
          <h2>
            <i class="fas fa-shield-alt me-2" style="color:var(--il-accent); font-size:1.3rem;"></i>
            Data Integrity &amp; Validation
            <?php if ($total_issues > 0): ?>
              <span class="issue-count error ms-2"><?= $total_issues ?> issue<?= $total_issues !== 1 ? 's' : '' ?></span>
            <?php else: ?>
              <span class="issue-count none ms-2">All clear</span>
            <?php endif; ?>
          </h2>
          <p style="font-size:0.85rem; color:var(--il-text-muted); margin-top:0.25rem;">
            Scans active entries across all dictionaries for missing fields, duplicates, and anomalies.
            Results are capped at 200 rows per check.
          </p>
        </div>

        <!-- ── Filter bar ────────────────────────────────────────────────── -->
        <div class="card mb-4">
          <div class="card-body py-2">
            <form method="get" action="" class="d-flex align-items-center gap-3 flex-wrap">
              <label class="form-label mb-0 fw-bold" style="font-size:0.85rem; white-space:nowrap;">
                <i class="fas fa-filter me-1" style="color:var(--il-primary);"></i>Filter by Dictionary
              </label>
              <select class="form-select form-select-sm" name="dict_id" style="max-width:260px;">
                <option value="0" <?= $filter_dict_id === 0 ? 'selected' : '' ?>>— All Dictionaries —</option>
                <?php foreach ($dictionaries as $d): ?>
                  <option value="<?= (int)$d['dict_id'] ?>" <?= $filter_dict_id === (int)$d['dict_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['name']) ?> (<?= $d['type'] ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm btn-primary">
                <i class="fas fa-sync-alt me-1"></i>Run Checks
              </button>
              <?php if ($filter_dict_id > 0): ?>
                <a href="integrity.php" class="btn btn-sm"
                   style="background:var(--il-surface-2); border:1px solid var(--il-border); color:var(--il-text-muted);">
                  <i class="fas fa-times me-1"></i>Clear Filter
                </a>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <?php if ($delete_msg !== ''): ?>
          <div class="alert <?= $delete_ok ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show" role="alert">
            <i class="fas <?= $delete_ok ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
            <?= htmlspecialchars($delete_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- ── Charts ─────────────────────────────────────────────────────── -->
        <?php if (!empty($summary_rows)): ?>
        <div class="row g-4 mb-4">

          <!-- Stacked bar: entries vs issues per dictionary -->
          <div class="col-lg-8">
            <div class="card h-100">
              <div class="card-header">
                <i class="fas fa-chart-bar me-2"></i>Dictionary Health — Entries vs Issues
              </div>
              <div class="card-body" style="position:relative; min-height:280px;">
                <canvas id="healthBarChart"></canvas>
              </div>
            </div>
          </div>

          <!-- Doughnut: issue type breakdown -->
          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header">
                <i class="fas fa-chart-pie me-2"></i>Issue Breakdown
              </div>
              <div class="card-body d-flex flex-column align-items-center justify-content-center" style="min-height:280px;">
                <?php if ($total_issues > 0): ?>
                  <canvas id="issueDonut" style="max-width:240px; max-height:240px;"></canvas>
                <?php else: ?>
                  <div class="text-center" style="color:var(--il-text-muted);">
                    <i class="fas fa-check-circle d-block mb-2" style="font-size:2.5rem; color:#198754;"></i>
                    <div style="font-size:0.9rem; font-weight:600;">No issues found</div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>

        <!-- Health summary table kept for detail reference -->
        <div class="card mb-4">
          <div class="card-header">
            <i class="fas fa-table me-2"></i>Dictionary Health Summary
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0" style="font-size:0.85rem;">
                <thead>
                  <tr>
                    <th style="font-size:0.8rem;">Dictionary</th>
                    <th style="font-size:0.8rem;">Type</th>
                    <th style="font-size:0.8rem; text-align:right;">Entries</th>
                    <th style="font-size:0.8rem;">Missing lang_1</th>
                    <th style="font-size:0.8rem;">Missing lang_2</th>
                    <th style="font-size:0.8rem;">Missing lang_3</th>
                    <th style="font-size:0.8rem; min-width:100px;">Health</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($summary_rows as $s):
                    $total   = max(1, (int)$s['total_entries']);
                    $issues  = (int)$s['missing_lang1'] + (int)$s['missing_lang2'] + (int)$s['missing_lang3'];
                    $pct_ok  = round(100 * max(0, $total - $issues) / $total);
                    $bar_cls = $pct_ok >= 99 ? '' : ($pct_ok >= 90 ? 'warn' : 'error');
                  ?>
                  <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($s['dict_name']) ?></td>
                    <td>
                      <span style="font-size:0.75rem; font-family:var(--il-font-mono);
                                   background:var(--il-surface-2); border:1px solid var(--il-border);
                                   border-radius:4px; padding:0.1rem 0.4rem;">
                        <?= htmlspecialchars($s['dict_type']) ?>
                      </span>
                    </td>
                    <td style="text-align:right; font-family:var(--il-font-mono);"><?= number_format((int)$s['total_entries']) ?></td>
                    <td>
                      <?php if ($s['missing_lang1'] > 0): ?>
                        <span class="issue-count error"><?= (int)$s['missing_lang1'] ?></span>
                      <?php else: ?>
                        <span class="issue-count none">0</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($s['missing_lang2'] > 0): ?>
                        <span class="issue-count error"><?= (int)$s['missing_lang2'] ?></span>
                      <?php else: ?>
                        <span class="issue-count none">0</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($s['dict_type'] === 'trilingual'): ?>
                        <?php if ($s['missing_lang3'] > 0): ?>
                          <span class="issue-count warn"><?= (int)$s['missing_lang3'] ?></span>
                        <?php else: ?>
                          <span class="issue-count none">0</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span style="color:var(--il-text-muted); font-size:0.75rem;">N/A</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span style="font-size:0.72rem; color:var(--il-text-muted);"><?= (int)$pct_ok ?>%</span>
                      <div class="health-bar">
                        <div class="health-bar-fill <?= $bar_cls ?>" style="width:<?= (int)$pct_ok ?>%;"></div>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="il-placeholder-panel">
              <i class="fas fa-database d-block mb-3"></i>
              <h6>No active dictionaries found.</h6>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- ── Tabbed issue sections ──────────────────────────────────────── -->
        <div class="card">
          <div class="card-header p-0">
            <ul class="nav nav-tabs integrity-tabs border-bottom-0 px-3 pt-2" id="integrityTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-missing" data-bs-toggle="tab" data-bs-target="#pane-missing" type="button" role="tab">
                  <i class="fas fa-exclamation-circle me-1" style="color:#dc3545;"></i>Missing Fields
                  <span class="issue-count <?= count($missing_rows) > 0 ? 'error' : 'none' ?> ms-1">
                    <?= count($missing_rows) ?>
                  </span>
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-dupes" data-bs-toggle="tab" data-bs-target="#pane-dupes" type="button" role="tab">
                  <i class="fas fa-copy me-1" style="color:#ffc107;"></i>Duplicates
                  <span class="issue-count <?= count($duplicate_rows) > 0 ? 'warn' : 'none' ?> ms-1">
                    <?= count($duplicate_rows) ?>
                  </span>
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-trilingual" data-bs-toggle="tab" data-bs-target="#pane-trilingual" type="button" role="tab">
                  <i class="fas fa-language me-1" style="color:#0d6efd;"></i>Missing lang_3
                  <span class="issue-count <?= count($missing_lang3_rows) > 0 ? 'warn' : 'none' ?> ms-1">
                    <?= count($missing_lang3_rows) ?>
                  </span>
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-long" data-bs-toggle="tab" data-bs-target="#pane-long" type="button" role="tab">
                  <i class="fas fa-text-width me-1" style="color:#6f42c1;"></i>Oversized Values
                  <span class="issue-count <?= count($long_value_rows) > 0 ? 'warn' : 'none' ?> ms-1">
                    <?= count($long_value_rows) ?>
                  </span>
                </button>
              </li>
            </ul>
          </div>

          <div class="card-body p-0">
            <div class="tab-content" id="integrityTabContent">

              <!-- ── Tab 1: Missing lang_1 / lang_2 ───────────────────── -->
              <div class="tab-pane fade show active" id="pane-missing" role="tabpanel">
                <?php if (empty($missing_rows)): ?>
                  <div class="il-placeholder-panel">
                    <i class="fas fa-check-circle d-block mb-3" style="color:#198754; font-size:2rem;"></i>
                    <h6>No missing field issues found.</h6>
                    <p>All active entries have both lang_1 and lang_2 populated.</p>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                      <thead>
                        <tr>
                          <th style="font-size:0.8rem;">Entry ID</th>
                          <th style="font-size:0.8rem;">Dictionary</th>
                          <th style="font-size:0.8rem;">lang_1</th>
                          <th style="font-size:0.8rem;">lang_2</th>
                          <th style="font-size:0.8rem;">Issue</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($missing_rows as $r):
                          $l1_empty = ($r['lang_1'] === null || trim($r['lang_1']) === '');
                          $l2_empty = ($r['lang_2'] === null || trim($r['lang_2']) === '');
                        ?>
                        <tr>
                          <td style="font-family:var(--il-font-mono); font-size:0.78rem; color:var(--il-text-muted);">
                            #<?= (int)$r['entry_id'] ?>
                          </td>
                          <td><?= htmlspecialchars($r['dict_name']) ?></td>
                          <td>
                            <?php if ($l1_empty): ?>
                              <span class="issue-count error">empty</span>
                            <?php else: ?>
                              <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_1']) ?>">
                                <?= htmlspecialchars($r['lang_1']) ?>
                              </span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($l2_empty): ?>
                              <span class="issue-count error">empty</span>
                            <?php else: ?>
                              <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_2']) ?>">
                                <?= htmlspecialchars($r['lang_2']) ?>
                              </span>
                            <?php endif; ?>
                          </td>
                          <td style="font-size:0.78rem; color:#dc3545;">
                            <?php
                              if ($l1_empty && $l2_empty) echo 'Both lang_1 and lang_2 empty';
                              elseif ($l1_empty)          echo 'lang_1 is empty';
                              else                        echo 'lang_2 is empty';
                            ?>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>

              <!-- ── Tab 2: Duplicates ──────────────────────────────────── -->
              <div class="tab-pane fade" id="pane-dupes" role="tabpanel">
                <?php if (empty($duplicate_rows)): ?>
                  <div class="il-placeholder-panel">
                    <i class="fas fa-check-circle d-block mb-3" style="color:#198754; font-size:2rem;"></i>
                    <h6>No duplicate headwords detected.</h6>
                    <p>Every lang_1 value is unique within its dictionary.</p>
                  </div>
                <?php else: ?>
                  <div class="d-flex align-items-center justify-content-between px-3 pt-3 pb-2">
                    <span style="font-size:0.85rem; color:var(--il-text-muted);">
                      <?= count($duplicate_rows) ?> headword<?= count($duplicate_rows) !== 1 ? 's' : '' ?> with duplicates detected.
                      The oldest entry (lowest ID) will be kept.
                    </span>
                    <form method="post" action="" onsubmit="return confirm('This will permanently delete all duplicate entries, keeping one copy of each headword. This cannot be undone. Continue?');">
                      <?php if ($filter_dict_id > 0): ?>
                        <input type="hidden" name="dict_id_filter" value="<?= $filter_dict_id ?>">
                      <?php endif; ?>
                      <div class="d-flex gap-2">
                        <button type="submit" name="delete_duplicates" class="btn btn-sm btn-outline-secondary">
                          <i class="fas fa-eye me-1"></i>Dry Run
                          <input type="hidden" name="dry_run" value="1">
                        </button>
                        <button type="submit" name="delete_duplicates" class="btn btn-sm btn-danger" onclick="this.form.querySelector('[name=dry_run]').remove()">
                          <i class="fas fa-trash me-1"></i>Delete All Duplicates
                        </button>
                      </div>
                    </form>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                      <thead>
                        <tr>
                          <th style="font-size:0.8rem;">lang_1 (Headword)</th>
                          <th style="font-size:0.8rem;">Dictionary</th>
                          <th style="font-size:0.8rem; text-align:right;">Occurrences</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($duplicate_rows as $r): ?>
                        <tr>
                          <td style="font-family:var(--il-font-mono); font-weight:600;">
                            <?= htmlspecialchars($r['lang_1']) ?>
                          </td>
                          <td><?= htmlspecialchars($r['dict_name']) ?></td>
                          <td style="text-align:right;">
                            <span class="issue-count warn"><?= (int)$r['occurrences'] ?>&times;</span>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>

              <!-- ── Tab 3: Missing lang_3 (trilingual) ────────────────── -->
              <div class="tab-pane fade" id="pane-trilingual" role="tabpanel">
                <?php if (empty($missing_lang3_rows)): ?>
                  <div class="il-placeholder-panel">
                    <i class="fas fa-check-circle d-block mb-3" style="color:#198754; font-size:2rem;"></i>
                    <h6>All trilingual entries have lang_3 populated.</h6>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                      <thead>
                        <tr>
                          <th style="font-size:0.8rem;">Entry ID</th>
                          <th style="font-size:0.8rem;">Dictionary</th>
                          <th style="font-size:0.8rem;">lang_1</th>
                          <th style="font-size:0.8rem;">lang_2</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($missing_lang3_rows as $r): ?>
                        <tr>
                          <td style="font-family:var(--il-font-mono); font-size:0.78rem; color:var(--il-text-muted);">
                            #<?= (int)$r['entry_id'] ?>
                          </td>
                          <td><?= htmlspecialchars($r['dict_name']) ?></td>
                          <td>
                            <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_1']) ?>">
                              <?= htmlspecialchars($r['lang_1']) ?>
                            </span>
                          </td>
                          <td>
                            <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_2']) ?>">
                              <?= htmlspecialchars($r['lang_2']) ?>
                            </span>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>

              <!-- ── Tab 4: Oversized values ────────────────────────────── -->
              <div class="tab-pane fade" id="pane-long" role="tabpanel">
                <?php if (empty($long_value_rows)): ?>
                  <div class="il-placeholder-panel">
                    <i class="fas fa-check-circle d-block mb-3" style="color:#198754; font-size:2rem;"></i>
                    <h6>No oversized values found.</h6>
                    <p>All field values are within 300 characters.</p>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                      <thead>
                        <tr>
                          <th style="font-size:0.8rem;">Entry ID</th>
                          <th style="font-size:0.8rem;">Dictionary</th>
                          <th style="font-size:0.8rem;">lang_1</th>
                          <th style="font-size:0.8rem; text-align:right;">len</th>
                          <th style="font-size:0.8rem;">lang_2</th>
                          <th style="font-size:0.8rem; text-align:right;">len</th>
                          <th style="font-size:0.8rem;">lang_3</th>
                          <th style="font-size:0.8rem; text-align:right;">len</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($long_value_rows as $r): ?>
                        <tr>
                          <td style="font-family:var(--il-font-mono); font-size:0.78rem; color:var(--il-text-muted);">
                            #<?= (int)$r['entry_id'] ?>
                          </td>
                          <td><?= htmlspecialchars($r['dict_name']) ?></td>
                          <td>
                            <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_1'] ?? '') ?>">
                              <?= htmlspecialchars($r['lang_1'] ?? '—') ?>
                            </span>
                          </td>
                          <td style="text-align:right; font-family:var(--il-font-mono);">
                            <?php if ((int)$r['len1'] > 300): ?>
                              <span class="issue-count warn"><?= (int)$r['len1'] ?></span>
                            <?php else: ?>
                              <?= (int)$r['len1'] ?>
                            <?php endif; ?>
                          </td>
                          <td>
                            <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_2'] ?? '') ?>">
                              <?= htmlspecialchars($r['lang_2'] ?? '—') ?>
                            </span>
                          </td>
                          <td style="text-align:right; font-family:var(--il-font-mono);">
                            <?php if ((int)$r['len2'] > 300): ?>
                              <span class="issue-count warn"><?= (int)$r['len2'] ?></span>
                            <?php else: ?>
                              <?= $r['len2'] !== null ? (int)$r['len2'] : '—' ?>
                            <?php endif; ?>
                          </td>
                          <td>
                            <span class="cell-trunc" title="<?= htmlspecialchars($r['lang_3'] ?? '') ?>">
                              <?= htmlspecialchars($r['lang_3'] ?? '—') ?>
                            </span>
                          </td>
                          <td style="text-align:right; font-family:var(--il-font-mono);">
                            <?php if ((int)$r['len3'] > 300): ?>
                              <span class="issue-count warn"><?= (int)$r['len3'] ?></span>
                            <?php else: ?>
                              <?= $r['len3'] !== null ? (int)$r['len3'] : '—' ?>
                            <?php endif; ?>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>

            </div><!-- /.tab-content -->
          </div>
        </div><!-- /.card -->

      </div><!-- /.container-fluid -->
    </div>
  </div>

  <footer class="sticky-footer mt-auto">
    <div class="container-fluid d-flex flex-column flex-md-row justify-content-between align-items-center">
      <div>
        <span class="fw-semibold" style="color:var(--il-text);">IndicLex</span>
        &mdash; Multilingual Dictionary Platform
      </div>
    </div>
  </footer>

</div><!-- /#wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<!-- Chart.js loaded after jQuery so inline chart code can safely reference both -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
// ── Sidebar toggle (mobile) ───────────────────────────────────────────────────
$('#sidebarToggleTop').on('click', function () {
  $('#accordionSidebar').toggleClass('d-none');
});

// ── Sidebar collapse arrow toggle ─────────────────────────────────────────────
var sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) {
  sidebarToggle.addEventListener('click', function () {
    document.getElementById('accordionSidebar').classList.toggle('d-none');
  });
}

// ── Cell expand on click ──────────────────────────────────────────────────────
$(document).on('click', '.cell-trunc', function () {
  var $el = $(this);
  if ($el.hasClass('expanded')) {
    $el.css({ 'white-space': 'nowrap', 'max-width': '220px' }).removeClass('expanded');
  } else {
    $el.css({ 'white-space': 'normal', 'max-width': 'none' }).addClass('expanded');
  }
});

// ── Persist active tab across page reloads ────────────────────────────────────
var storedTab = sessionStorage.getItem('integrity_tab');
if (storedTab) {
  var $tab = $('#integrityTabs button[data-bs-target="' + storedTab + '"]');
  if ($tab.length) { new bootstrap.Tab($tab[0]).show(); }
}
$('#integrityTabs button').on('shown.bs.tab', function (e) {
  sessionStorage.setItem('integrity_tab', $(e.target).data('bs-target'));
});

// ── Chart.js — read CSS variables for theme-aware colours ────────────────────
var style      = getComputedStyle(document.documentElement);
var colPrimary = style.getPropertyValue('--il-primary').trim()  || '#4e73df';
var colAccent  = style.getPropertyValue('--il-accent').trim()   || '#1cc88a';
var colText    = style.getPropertyValue('--il-text').trim()     || '#333';
var colMuted   = style.getPropertyValue('--il-text-muted').trim() || '#888';
var colBorder  = style.getPropertyValue('--il-border').trim()   || '#e0e0e0';

// PHP → JS data (all values cast to numbers)
var summaryData = <?php
  $chart_rows = [];
  foreach ($summary_rows as $s) {
      $total  = max(0, (int)$s['total_entries']);
      $issues = (int)$s['missing_lang1'] + (int)$s['missing_lang2'] + (int)$s['missing_lang3'];
      $clean  = max(0, $total - $issues);
      $chart_rows[] = [
          'label'   => $s['dict_name'],
          'total'   => $total,
          'issues'  => $issues,
          'clean'   => $clean,
      ];
  }
  echo json_encode($chart_rows, JSON_HEX_TAG | JSON_HEX_AMP);
?>;

// ── Stacked bar: healthy entries vs issues per dictionary ─────────────────────
var barCtx = document.getElementById('healthBarChart');
if (barCtx && summaryData.length) {
  new Chart(barCtx, {
    type: 'bar',
    data: {
      labels: summaryData.map(function(d){ return d.label; }),
      datasets: [
        {
          label: 'Healthy Entries',
          data:  summaryData.map(function(d){ return d.clean; }),
          backgroundColor: 'rgba(28,200,138,0.75)',
          borderColor:     'rgba(28,200,138,1)',
          borderWidth: 1,
          borderRadius: 3,
        },
        {
          label: 'Issues',
          data:  summaryData.map(function(d){ return d.issues; }),
          backgroundColor: 'rgba(220,53,69,0.75)',
          borderColor:     'rgba(220,53,69,1)',
          borderWidth: 1,
          borderRadius: 3,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          labels: { color: colText, font: { size: 12 } }
        },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              var d = summaryData[ctx.dataIndex];
              var pct = d.total > 0 ? ((d.clean / d.total) * 100).toFixed(1) : '0.0';
              if (ctx.dataset.label === 'Healthy Entries') {
                return ' Healthy: ' + ctx.parsed.y.toLocaleString() + ' (' + pct + '%)';
              }
              return ' Issues: ' + ctx.parsed.y.toLocaleString();
            }
          }
        }
      },
      scales: {
        x: {
          stacked: true,
          ticks: { color: colMuted, font: { size: 11 } },
          grid:  { color: colBorder }
        },
        y: {
          stacked: true,
          ticks: { color: colMuted, font: { size: 11 } },
          grid:  { color: colBorder }
        }
      }
    }
  });
}

// ── Doughnut: issue type breakdown ────────────────────────────────────────────
var donutCtx = document.getElementById('issueDonut');
if (donutCtx) {
  var missingFields = <?= count($missing_rows) ?>;
  var duplicates    = <?= count($duplicate_rows) ?>;
  var missingLang3  = <?= count($missing_lang3_rows) ?>;
  var oversized     = <?= count($long_value_rows) ?>;

  new Chart(donutCtx, {
    type: 'doughnut',
    data: {
      labels: ['Missing Fields', 'Duplicates', 'Missing lang_3', 'Oversized Values'],
      datasets: [{
        data: [missingFields, duplicates, missingLang3, oversized],
        backgroundColor: [
          'rgba(220,53,69,0.8)',
          'rgba(255,193,7,0.8)',
          'rgba(13,110,253,0.8)',
          'rgba(111,66,193,0.8)',
        ],
        borderColor: [
          'rgba(220,53,69,1)',
          'rgba(255,193,7,1)',
          'rgba(13,110,253,1)',
          'rgba(111,66,193,1)',
        ],
        borderWidth: 2,
        hoverOffset: 6,
      }]
    },
    options: {
      responsive: true,
      cutout: '65%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: colText, font: { size: 11 }, padding: 12 }
        },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              var total = ctx.dataset.data.reduce(function(a, b){ return a + b; }, 0);
              var pct   = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
              return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
            }
          }
        }
      }
    }
  });
}
</script>
</body>
</html>
