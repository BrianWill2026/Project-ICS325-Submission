<?php
session_start();
// connect to database
require_once 'dbcon.php'; 
//make sure to change name for later
 
// get the form values
$query = "";
$mode = "exact";
$dict_id = "";
$page = 1;

if(isset($_GET['query'])){
    $query = trim($_GET['query']);
}
if(isset($_GET['mode'])){
    $raw_mode = $_GET['mode'];
    $allowed_modes = ['exact', 'prefix', 'suffix', 'substring'];
    $mode = in_array($raw_mode, $allowed_modes, true) ? $raw_mode : 'exact';
}
if(isset($_GET['dict_id'])){
    $dict_id = $_GET['dict_id'];
}
if(isset($_GET['page'])){
    $page = (int)$_GET['page'];
}

// make sure page is not less than 1
if($page < 1){
    $page = 1;
}

// how many results per page
$per_page = 10;
$offset = ($page - 1) * $per_page;

// get all dictionaries for the dropdown
$dict_list = [];
$result = mysqli_query($conn, "SELECT dict_id, name FROM dictionaries WHERE is_active = 1");
while($row = mysqli_fetch_assoc($result)){
    $dict_list[] = $row;
}

// search stuff
$results = [];
$total = 0;
$did_search = false;

if($query != ""){
    $did_search = true;

    // Escape special LIKE characters in user input
    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);

    // figure out the like pattern based on mode
    if($mode == "prefix"){
        $pattern = $escaped . "%";
    } else if($mode == "suffix"){
        $pattern = "%" . $escaped;
    } else if($mode == "substring"){
        $pattern = "%" . $escaped . "%";
    } else {
        // exact
        $pattern = $escaped;
    }

    // count how many results there are total (for pagination)
    if($dict_id != ""){
        // specific dictionary — bind dict_id as integer
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM dictionary_entries e JOIN dictionaries d ON e.dict_id = d.dict_id WHERE e.is_active = 1 AND d.is_active = 1 AND e.lang_1 LIKE ? AND d.dict_id = ?");
        $dict_id_int = (int)$dict_id;
        mysqli_stmt_bind_param($count_stmt, "si", $pattern, $dict_id_int);
    } else {
        // all dictionaries
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM dictionary_entries e JOIN dictionaries d ON e.dict_id = d.dict_id WHERE e.is_active = 1 AND d.is_active = 1 AND e.lang_1 LIKE ?");
        mysqli_stmt_bind_param($count_stmt, "s", $pattern);
    }

    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $count_row = mysqli_fetch_assoc($count_result);
    $total = $count_row['total'];
    mysqli_stmt_close($count_stmt);

    // now get the actual results
    if($dict_id != ""){
        $stmt = mysqli_prepare($conn, "SELECT e.lang_1, e.lang_2, e.lang_3, e.pronunciation, e.part_of_speech, d.name AS dict_name FROM dictionary_entries e JOIN dictionaries d ON e.dict_id = d.dict_id WHERE e.is_active = 1 AND d.is_active = 1 AND e.lang_1 LIKE ? AND d.dict_id = ? ORDER BY e.lang_1 LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, "siii", $pattern, $dict_id_int, $per_page, $offset);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT e.lang_1, e.lang_2, e.lang_3, e.pronunciation, e.part_of_speech, d.name AS dict_name FROM dictionary_entries e JOIN dictionaries d ON e.dict_id = d.dict_id WHERE e.is_active = 1 AND d.is_active = 1 AND e.lang_1 LIKE ? ORDER BY e.lang_1 LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, "sii", $pattern, $per_page, $offset);
    }

    mysqli_stmt_execute($stmt);
    $search_result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($search_result)){
        $results[] = $row;
    }
}
 
// total pages
$total_pages = 0;
if($total > 0){
    $total_pages = ceil($total / $per_page);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="IndicLex — Search Interface">
  <title>IndicLex — Search</title>
 
  <script src="js/theme.js"></script>
 
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">
 
  <style>
    /* ── Autocomplete dropdown ─────────────────────────────────────────── */
    #ac-list {
      position: absolute;
      z-index: 1050;
      width: 100%;
      max-height: 240px;
      overflow-y: auto;
      background: var(--il-surface);
      border: 1px solid var(--il-border);
      border-top: none;
      border-radius: 0 0 8px 8px;
      box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }
    #ac-list .ac-item {
      padding: 0.45rem 0.8rem;
      cursor: pointer;
      display: flex;
      align-items: baseline;
      gap: 0.5rem;
      border-bottom: 1px solid var(--il-border);
      font-size: 0.85rem;
      transition: background 0.1s;
    }
    #ac-list .ac-item:last-child { border-bottom: none; }
    #ac-list .ac-item:hover,
    #ac-list .ac-item.ac-active  { background: var(--il-surface-2); }
    #ac-list .ac-w1  { font-weight: 600; color: var(--il-text); font-family: var(--il-font-mono); }
    #ac-list .ac-w2  { color: var(--il-text-muted); font-size: 0.8rem; }
    #ac-list .ac-pos {
      margin-left: auto;
      font-size: 0.7rem;
      font-family: var(--il-font-mono);
      background: var(--il-surface-2);
      border: 1px solid var(--il-border);
      border-radius: 4px;
      padding: 0.05rem 0.35rem;
      color: var(--il-text-muted);
      white-space: nowrap;
    }
    #query-wrap { position: relative; }
 
  </style>
</head>
 
<body id="page-top">
<div id="wrapper">
 
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
    <li class="nav-item active">
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
    <li class="nav-item">
      <a class="nav-link" href="integrity.php"><i class="fas fa-fw fa-sliders-h me-2"></i><span>Reporting</span></a>
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
 
  <div id="content-wrapper" class="d-flex flex-column flex-grow-1" style="min-width:0;">
    <div id="content">
 
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
          <div class="topbar-divider d-none d-sm-block" style="border-left:1px solid var(--il-border); height:2rem; margin:0 0.75rem;"></div>
          <li class="nav-item">
            <span style="font-size:0.8rem; color:var(--il-text-muted);">
              <i class="fas fa-code-branch me-1"></i>Iteration 4
            </span>
          </li>
        </ul>
      </nav>
 
      <div class="container-fluid px-0">
 
        <div class="il-page-header">
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="index.php">Home</a></li>
              <li class="breadcrumb-item active" aria-current="page">Search</li>
            </ol>
          </nav>
          <h2><i class="fas fa-search me-2" style="color:var(--il-accent); font-size:1.3rem;"></i>Dictionary Search</h2>
        </div>
 
        <div class="row g-4">
 
          <!-- search form on the left -->
          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-header">
                <i class="fas fa-filter me-2"></i>Search Parameters
              </div>
              <div class="card-body">
 
                <form method="get" action="search.php" novalidate>
 
                  <div class="mb-3">
                    <label class="form-label" for="dict_id">
                      <i class="fas fa-book me-1" style="color:var(--il-primary);"></i>Dictionary
                    </label>
                    <select class="form-select" id="dict_id" name="dict_id">
                      <option value="">— All Dictionaries —</option>
                      <?php foreach($dict_list as $d){ ?>
                        <option value="<?php echo $d['dict_id']; ?>"
                          <?php if((string)$dict_id === (string)$d['dict_id']){ echo "selected"; } ?>>
                          <?php echo htmlspecialchars($d['name']); ?>
                        </option>
                      <?php } ?>
                    </select>
                  </div>
 
                  <div class="mb-3">
                    <label class="form-label" for="mode">
                      <i class="fas fa-sliders-h me-1" style="color:var(--il-primary);"></i>Search Mode
                    </label>
                    <select class="form-select" id="mode" name="mode">
                      <option value="exact"     <?php if($mode=="exact"){echo "selected";} ?>>Exact Match</option>
                      <option value="prefix"    <?php if($mode=="prefix"){echo "selected";} ?>>Prefix</option>
                      <option value="suffix"    <?php if($mode=="suffix"){echo "selected";} ?>>Suffix</option>
                      <option value="substring" <?php if($mode=="substring"){echo "selected";} ?>>Substring</option>
                    </select>
                    <div class="form-text" style="font-size:0.75rem; color:var(--il-text-muted);" id="modeDescription"></div>
                  </div>
 
                  <div class="mb-4">
                    <label class="form-label" for="query">
                      <i class="fas fa-keyboard me-1" style="color:var(--il-primary);"></i>Search Query
                    </label>
                    <div id="query-wrap">
                      <input type="text" class="form-control" id="query" name="query"
                             placeholder="Enter search term…"
                             value="<?php echo htmlspecialchars($query); ?>"
                             autocomplete="off"
                             aria-autocomplete="list"
                             aria-controls="ac-list">
                      <div id="ac-list" role="listbox" style="display:none;"></div>
                    </div>
                    <div class="form-text" style="font-size:0.75rem; color:var(--il-text-muted);">
                      Supports Unicode — enter terms in native script or transliteration.
                      Autocomplete activates after 2 characters.
                    </div>
                  </div>
 
                  <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                      <i class="fas fa-search me-2"></i>Search Dictionary
                    </button>
                  </div>
 
                  <div class="mt-3 d-grid">
                    <a href="search.php" class="btn"
                       style="background:var(--il-surface-2); border:1px solid var(--il-border); color:var(--il-text-muted); font-size:0.85rem; text-align:center;">
                      <i class="fas fa-times me-1"></i>Clear
                    </a>
                  </div>
 
                </form>
              </div>
            </div>
          </div>
 
          <!-- results on the right -->
          <div class="col-lg-8">
            <div class="card">
 
              <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fas fa-list me-2"></i>Search Results</span>
                <span style="font-size:0.75rem; color:var(--il-text-muted); font-family:var(--il-font-body); font-weight:400;">
                  <?php if($did_search){ ?>
                    <?php echo (int)$total; ?> result(s) for "<?php echo htmlspecialchars($query); ?>"
                  <?php } else { ?>
                    No query submitted
                  <?php } ?>
                </span>
              </div>
 
              <div class="card-body <?php if($did_search && count($results) > 0){ echo 'p-0'; } ?>">
 
                <?php if(!$did_search){ ?>
                  <!-- nothing searched yet -->
                  <div class="il-placeholder-panel">
                    <i class="fas fa-search d-block mb-3"></i>
                    <h6>Ready to Search</h6>
                    <p>Select a dictionary and search mode, enter a query, and press <strong>Search Dictionary</strong>. Results will appear here.</p>
                  </div>
 
                <?php } else if(count($results) == 0){ ?>
                  <!-- no results found -->
                  <div class="il-placeholder-panel">
                    <i class="fas fa-inbox d-block mb-3" style="font-size:2rem; color:var(--il-text-muted);"></i>
                    <h6>No Results Found</h6>
                    <p>Nothing matched "<?php echo htmlspecialchars($query); ?>" using <?php echo htmlspecialchars($mode); ?> mode.</p>
                    <p style="font-size:0.8rem; color:var(--il-text-muted);">Try using Substring mode or a shorter query.</p>
                  </div>
 
                <?php } else { ?>
                  <!-- show the results table -->
                  <div class="table-responsive">
                    <table class="table table-hover mb-0">
                      <thead>
                        <tr>
                          <th style="font-size:0.8rem;">#</th>
                          <th style="font-size:0.8rem;">Headword</th>
                          <th style="font-size:0.8rem;">Translation</th>
                          <th style="font-size:0.8rem;">Part of Speech</th>
                          <th style="font-size:0.8rem;">Dictionary</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                          $num = $offset + 1;
                          foreach($results as $r){
                        ?>
                        <tr>
                          <td style="font-family:var(--il-font-mono); font-size:0.78rem; color:var(--il-text-muted);">
                            <?php echo $num; ?>
                          </td>
                          <td>
                            <span style="font-size:1rem; font-weight:600; font-family:var(--il-font-mono);">
                              <?php echo htmlspecialchars($r['lang_1']); ?>
                            </span>
                            <?php if(!empty($r['pronunciation'])){ ?>
                              <br>
                              <small style="color:var(--il-text-muted); font-size:0.72rem;">
                                [<?php echo htmlspecialchars($r['pronunciation']); ?>]
                              </small>
                            <?php } ?>
                          </td>
                          <td style="font-size:0.88rem;"><?php echo htmlspecialchars($r['lang_2']); ?></td>
                          <td>
                            <?php if(!empty($r['part_of_speech'])){ ?>
                              <span style="font-size:0.75rem; background:var(--il-surface-2); border:1px solid var(--il-border); border-radius:4px; padding:0.15rem 0.5rem; font-family:var(--il-font-mono);">
                                <?php echo htmlspecialchars($r['part_of_speech']); ?>
                              </span>
                            <?php } else { ?>
                              <span style="color:var(--il-text-muted); font-size:0.75rem;">—</span>
                            <?php } ?>
                          </td>
                          <td style="font-size:0.78rem; color:var(--il-text-muted);">
                            <?php echo htmlspecialchars($r['dict_name']); ?>
                          </td>
                        </tr>
                        <?php
                            $num++;
                          }
                        ?>
                      </tbody>
                    </table>
                  </div>
 
                <?php } ?>
 
              </div>
 
              <!-- pagination buttons -->
              <?php if($did_search && $total_pages > 1){ ?>
              <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2"
                   style="background:var(--il-surface-2); font-size:0.82rem;">
 
                <span style="color:var(--il-text-muted);">
                  Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;&middot;&nbsp; <?php echo $total; ?> total result(s)
                </span>
 
                <div class="d-flex gap-2">
                  <?php
                    // build the base url keeping the current search params
                    $base_url = "search.php?query=" . urlencode($query) . "&mode=" . urlencode($mode) . "&dict_id=" . urlencode($dict_id);
                  ?>
 
                  <?php if($page > 1){ ?>
                    <a href="<?php echo $base_url . '&page=' . ($page - 1); ?>" class="btn btn-sm"
                       style="background:var(--il-surface-2); border:1px solid var(--il-border); color:var(--il-text); font-size:0.82rem;">
                      <i class="fas fa-chevron-left me-1"></i>Previous
                    </a>
                  <?php } else { ?>
                    <button class="btn btn-sm" disabled style="font-size:0.82rem; opacity:0.4;">
                      <i class="fas fa-chevron-left me-1"></i>Previous
                    </button>
                  <?php } ?>
 
                  <?php if($page < $total_pages){ ?>
                    <a href="<?php echo $base_url . '&page=' . ($page + 1); ?>" class="btn btn-primary btn-sm" style="font-size:0.82rem;">
                      Next<i class="fas fa-chevron-right ms-1"></i>
                    </a>
                  <?php } else { ?>
                    <button class="btn btn-sm" disabled style="font-size:0.82rem; opacity:0.4;">
                      Next<i class="fas fa-chevron-right ms-1"></i>
                    </button>
                  <?php } ?>
 
                </div>
              </div>
              <?php } ?>
 
            </div>
 
            <!-- search mode reference table -->
            <div class="card mt-4">
              <div class="card-header">
                <i class="fas fa-info-circle me-2"></i>Search Mode Reference
              </div>
              <div class="card-body p-0">
                <table class="table mb-0">
                  <thead>
                    <tr>
                      <th>Mode</th>
                      <th>Description</th>
                      <th>Example Query</th>
                      <th>SQL Pattern</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td><code style="font-size:0.82rem;">exact</code></td>
                      <td style="font-size:0.85rem;">Whole-word match only</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">vanamu</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">vanamu</td>
                    </tr>
                    <tr>
                      <td><code style="font-size:0.82rem;">prefix</code></td>
                      <td style="font-size:0.85rem;">Entries starting with query</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">van</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">van%</td>
                    </tr>
                    <tr>
                      <td><code style="font-size:0.82rem;">suffix</code></td>
                      <td style="font-size:0.85rem;">Entries ending with query</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">amu</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">%amu</td>
                    </tr>
                    <tr>
                      <td><code style="font-size:0.82rem;">substring</code></td>
                      <td style="font-size:0.85rem;">Query appears anywhere</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">ana</td>
                      <td style="font-family:var(--il-font-mono); font-size:0.82rem;">%ana%</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
 
          </div><!-- /.col-lg-8 -->
      </div>
    </div>
 
    <footer class="sticky-footer mt-auto">
      <div class="container-fluid d-flex flex-column flex-md-row justify-content-between align-items-center">
        <div><span class="fw-semibold" style="color:var(--il-text);">IndicLex</span> &mdash; Multilingual Dictionary Platform</div>
      </div>
    </footer>
 
  </div>
</div>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
 
<script>
// ════════════════════════════════════════════════════════════════════════════
//  IndicLex Search — autocomplete + word-length matcher
// ════════════════════════════════════════════════════════════════════════════
 
const API_URL = 'api/search.php';
 
// ── Utility ──────────────────────────────────────────────────────────────────
function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
 
function apiFetch(params) {
  return $.getJSON(API_URL, params);
}
 
// ── Mode description (preserving original behaviour) ─────────────────────────
var modeDescriptions = {
  exact:     'Returns entries matching the full headword exactly.',
  prefix:    'Returns entries whose headword begins with the query.',
  suffix:    'Returns entries whose headword ends with the query.',
  substring: 'Returns entries whose headword contains the query anywhere.'
};
var $modeSelect = $('#mode');
var $modeDesc   = $('#modeDescription');
function updateModeDesc() { $modeDesc.text(modeDescriptions[$modeSelect.val()]); }
$modeSelect.on('change', updateModeDesc);
updateModeDesc();
 
// ── Sidebar toggle (mobile) ───────────────────────────────────────────────────
$('#sidebarToggleTop').on('click', function() {
  $('#accordionSidebar').toggleClass('d-none');
});
 
// ════════════════════════════════════════════════════════════════════════════
//  AUTOCOMPLETE
// ════════════════════════════════════════════════════════════════════════════
var acXhr   = null;
var acIndex = -1;
var $qInput = $('#query');
var $acList = $('#ac-list');
 
function hideAC() {
  $acList.hide().empty();
  acIndex = -1;
}
 
function renderAC(results) {
  $acList.empty();
  if (!results.length) { hideAC(); return; }
 
  // Show up to 8 suggestions
  results.slice(0, 8).forEach(function(r) {
    var $item = $('<div>', {
      class: 'ac-item',
      role:  'option',
      'data-lang1': r.lang_1
    });
 
    var inner =
      '<span class="ac-w1">' + escHtml(r.lang_1) + '</span>' +
      '<span class="ac-w2">→ ' + escHtml(r.lang_2) + '</span>';
 
    if (r.part_of_speech) {
      inner += '<span class="ac-pos">' + escHtml(r.part_of_speech) + '</span>';
    }
 
    $item.html(inner).on('mousedown', function(e) {
      e.preventDefault();   // keep focus on input
      $qInput.val(r.lang_1);
      hideAC();
      // Submit the parent form so the normal server-side search runs
      $qInput.closest('form').submit();
    });
 
    $acList.append($item);
  });
 
  $acList.show();
}
 
function moveAC(dir) {
  var $items = $acList.find('.ac-item');
  if (!$items.length) return;
  $items.removeClass('ac-active');
  acIndex = Math.max(0, Math.min($items.length - 1, acIndex + dir));
  $items.eq(acIndex).addClass('ac-active');
}
 
$qInput.on('input', function() {
  var q = $(this).val().trim();
  acIndex = -1;
  if (acXhr) { acXhr.abort(); acXhr = null; }
  if (q.length < 2) { hideAC(); return; }
 
  // Use prefix mode for autocomplete — fastest and most natural
  acXhr = apiFetch({
    query:   q,
    dict_id: $('#dict_id').val(),
    mode:    'prefix',
    page:    1
  }).done(function(data) {
    renderAC(data.results || []);
  }).fail(function(xhr) {
    if (xhr.statusText !== 'abort') hideAC();
  });
});
 
$qInput.on('keydown', function(e) {
  if ($acList.is(':visible')) {
    if (e.key === 'ArrowDown') { e.preventDefault(); moveAC(1);  return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); moveAC(-1); return; }
    if (e.key === 'Escape')    { hideAC(); return; }
    if (e.key === 'Enter') {
      var $active = $acList.find('.ac-item.ac-active');
      if ($active.length) {
        e.preventDefault();
        $qInput.val($active.data('lang1'));
        hideAC();
        $qInput.closest('form').submit();
        return;
      }
    }
  }
  // Enter with no AC selection: let form submit normally
});
 
// Close on outside click
$(document).on('mousedown', function(e) {
  if (!$(e.target).closest('#query-wrap').length) hideAC();
});
 
 
</script>
</body>
</html>