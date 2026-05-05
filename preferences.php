<?php
session_start();
// ============================================================
// preferences.php  —  IndicLex  Iteration 5
// Preference resolution chain:
//   1. Cookie  →  2. preferences table  →  3. hard-coded fallback
// ============================================================
require_once 'dbcon.php';

// ── Helper: read one system-default from the preferences table ──
function getSystemDefault(mysqli $conn, string $key, string $fallback): string {
    $stmt = mysqli_prepare($conn, "SELECT pref_value FROM preferences WHERE pref_key = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row ? $row['pref_value'] : $fallback;
}

// ── Helper: safe cookie read ────────────────────────────────
function getCookieVal(string $name): string {
    return isset($_COOKIE[$name]) ? trim($_COOKIE[$name]) : '';
}

// ── Allowed values (whitelist) ──────────────────────────────
$allowed_rpp   = ['5', '10', '25', '50', '100'];
$allowed_theme = ['light', 'dark'];
$allowed_mode  = ['exact', 'prefix', 'suffix', 'substring'];

// ── Resolve each preference: cookie → DB default → fallback ─
$pref_theme = getCookieVal('indiclex_theme');
if (!in_array($pref_theme, $allowed_theme, true)) {
    $pref_theme = getSystemDefault($conn, 'theme', 'light');
}

$pref_rpp = getCookieVal('indiclex_rpp');
if (!in_array($pref_rpp, $allowed_rpp, true)) {
    $pref_rpp = getSystemDefault($conn, 'results_per_page', '10');
}

$pref_dict = getCookieVal('indiclex_dict');   // validated against live dict list below
$pref_mode = getCookieVal('indiclex_mode');
if (!in_array($pref_mode, $allowed_mode, true)) {
    $pref_mode = getSystemDefault($conn, 'default_mode', 'exact');
}

// ── Load live dictionary list for dropdown ──────────────────
$dict_list = [];
$dresult = mysqli_query($conn, "SELECT dict_id, name FROM dictionaries WHERE is_active = 1 ORDER BY name");
while ($row = mysqli_fetch_assoc($dresult)) {
    $dict_list[] = $row;
}

// Validate pref_dict against actual dict IDs
$valid_dict_ids = array_column($dict_list, 'dict_id');
if ($pref_dict !== '' && !in_array((string)$pref_dict, array_map('strval', $valid_dict_ids), true)) {
    $pref_dict = '';   // fall back to "all"
}

// ── Handle POST save ────────────────────────────────────────
$save_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prefs'])) {
    $new_dict  = isset($_POST['default_dictionary']) ? trim($_POST['default_dictionary']) : '';
    $new_rpp   = isset($_POST['results_per_page'])   ? trim($_POST['results_per_page'])   : '10';
    $new_theme = isset($_POST['theme'])              ? trim($_POST['theme'])              : 'light';
    $new_mode  = isset($_POST['default_mode'])       ? trim($_POST['default_mode'])       : 'exact';

    // Whitelist-validate
    if (!in_array($new_rpp,   $allowed_rpp))   { $new_rpp   = '10'; }
    if (!in_array($new_theme, $allowed_theme)) { $new_theme = 'light'; }
    if (!in_array($new_mode,  $allowed_mode))  { $new_mode  = 'exact'; }
    if ($new_dict !== '' && !in_array((string)$new_dict, array_map('strval', $valid_dict_ids), true)) { $new_dict = ''; }

    $cookie_path   = '/';
    $cookie_days   = 120 * 86400;    // 120 days in seconds

    setcookie('indiclex_theme', $new_theme, time() + $cookie_days, $cookie_path, '', false, false);
    setcookie('indiclex_rpp',   $new_rpp,   time() + $cookie_days, $cookie_path, '', false, false);
    setcookie('indiclex_dict',  $new_dict,  time() + $cookie_days, $cookie_path, '', false, false);
    setcookie('indiclex_mode',  $new_mode,  time() + $cookie_days, $cookie_path, '', false, false);

    // Reflect immediately without waiting for redirect
    $pref_theme = $new_theme;
    $pref_rpp   = $new_rpp;
    $pref_dict  = $new_dict;
    $pref_mode  = $new_mode;

    $save_msg = 'Preferences saved — cookies updated for 120 days.';
}

// ── Handle POST reset ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_prefs'])) {
    $cookie_path = '/';
    // Expire all indiclex pref cookies
    foreach (['indiclex_theme', 'indiclex_rpp', 'indiclex_dict', 'indiclex_mode'] as $ck) {
        setcookie($ck, '', time() - 3600, $cookie_path, '', false, false);
        unset($_COOKIE[$ck]); // clear superglobal so prefSource() reflects new state immediately
    }
    // Re-read system defaults
    $pref_theme = getSystemDefault($conn, 'theme', 'light');
    $pref_rpp   = getSystemDefault($conn, 'results_per_page', '10');
    $pref_dict  = '';
    $pref_mode  = getSystemDefault($conn, 'default_mode', 'exact');

    $save_msg = 'Preferences reset — cookies cleared. System defaults are now active.';
}

// ── Derive preference source labels for the info panel ──────
function prefSource(string $cookieName, string $value, string $dbKey, mysqli $conn): string {
    if (isset($_COOKIE[$cookieName]) && trim($_COOKIE[$cookieName]) !== '') {
        return 'cookie';
    }
    $stmt = mysqli_prepare($conn, "SELECT pref_value FROM preferences WHERE pref_key = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $dbKey);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return ($row && $row['pref_value'] === $value) ? 'database default' : 'built-in fallback';
}

$src_theme = prefSource('indiclex_theme', $pref_theme, 'theme', $conn);
$src_rpp   = prefSource('indiclex_rpp',   $pref_rpp,   'results_per_page', $conn);
$src_dict  = prefSource('indiclex_dict',  $pref_dict,  'default_dict', $conn);
$src_mode  = prefSource('indiclex_mode',  $pref_mode,  'default_mode', $conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="IndicLex — Preferences">
  <title>IndicLex — Preferences</title>

  <!-- Theme must load FIRST to prevent flash -->
  <script src="js/theme.js"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">
</head>

<body id="page-top">
<div id="wrapper">

  <!-- ── Sidebar ── -->
  <ul class="navbar-nav sidebar sidebar-dark accordion d-flex flex-column" id="accordionSidebar"
      style="min-width:220px; min-height:100vh; position:sticky; top:0;">

    <a class="sidebar-brand d-flex align-items-center justify-content-center text-decoration-none py-3"
       href="index.php">
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
    <li class="nav-item active">
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
      <a class="nav-link" href="integrity.php">
        <i class="fas fa-fw fa-sliders-h me-2"></i><span>Reporting</span>
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

  <!-- ── Main content ── -->
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
              <i class="fas fa-code-branch me-1"></i>Iteration 5
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
              <li class="breadcrumb-item active" aria-current="page">Preferences</li>
            </ol>
          </nav>
          <h2><i class="fas fa-sliders-h me-2" style="color:#3a7a3a; font-size:1.3rem;"></i>Preferences</h2>
        </div>

        <!-- Save / Reset feedback -->
        <?php if ($save_msg !== ''): ?>
        <div class="mb-4" style="max-width:760px;">
          <div style="background:rgba(50,160,50,0.1);
                      border:1px solid rgba(50,160,50,0.3);
                      border-left:4px solid #3a9a3a;
                      border-radius:0 var(--il-radius) var(--il-radius) 0;
                      padding:0.75rem 1rem; font-size:0.84rem; color:var(--il-text);">
            <i class="fas fa-check-circle me-1" style="color:#3a9a3a;"></i>
            <?php echo htmlspecialchars($save_msg); ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">

          <!-- ── Preferences form ── -->
          <div class="col-lg-7">
            <form method="post" action="" novalidate>

              <!-- Search Defaults card -->
              <div class="card mb-4">
                <div class="card-header">
                  <i class="fas fa-book me-2"></i>Search Defaults
                </div>
                <div class="card-body">

                  <div class="mb-3">
                    <label class="form-label" for="pref_dict">Default Dictionary</label>
                    <select class="form-select" id="pref_dict" name="default_dictionary">
                      <option value="" <?php echo $pref_dict === '' ? 'selected' : ''; ?>>
                        — All Dictionaries —
                      </option>
                      <?php foreach ($dict_list as $d): ?>
                        <option value="<?php echo (int)$d['dict_id']; ?>"
                          <?php echo ((string)$pref_dict === (string)$d['dict_id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($d['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size:0.75rem; color:var(--il-text-muted);">
                      Pre-selects this dictionary on the Search page.
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label" for="pref_mode">Default Search Mode</label>
                    <select class="form-select" id="pref_mode" name="default_mode">
                      <option value="exact"     <?php echo $pref_mode === 'exact'     ? 'selected' : ''; ?>>Exact Match</option>
                      <option value="prefix"    <?php echo $pref_mode === 'prefix'    ? 'selected' : ''; ?>>Prefix</option>
                      <option value="suffix"    <?php echo $pref_mode === 'suffix'    ? 'selected' : ''; ?>>Suffix</option>
                      <option value="substring" <?php echo $pref_mode === 'substring' ? 'selected' : ''; ?>>Substring</option>
                    </select>
                  </div>

                  <div class="mb-1">
                    <label class="form-label" for="pref_rpp">Results Per Page</label>
                    <select class="form-select" id="pref_rpp" name="results_per_page" style="max-width:180px;">
                      <?php foreach (['5','10','25','50','100'] as $n): ?>
                        <option value="<?php echo $n; ?>" <?php echo $pref_rpp === $n ? 'selected' : ''; ?>>
                          <?php echo $n; ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size:0.75rem; color:var(--il-text-muted);">
                      Number of dictionary entries shown per page of results.
                    </div>
                  </div>

                </div>
              </div>

              <!-- Display Settings card -->
              <div class="card mb-4">
                <div class="card-header">
                  <i class="fas fa-palette me-2"></i>Display Settings
                </div>
                <div class="card-body">

                  <div class="mb-3">
                    <label class="form-label">Interface Theme</label>
                    <div class="d-flex gap-3 flex-wrap">

                      <label class="d-flex align-items-center gap-2 p-3 rounded theme-opt"
                             id="themeOptLight"
                             style="border:2px solid <?php echo $pref_theme === 'light' ? 'var(--il-primary)' : 'var(--il-border)'; ?>;
                                    cursor:pointer; min-width:150px; background:var(--il-surface-2); transition:border-color 0.2s;">
                        <input type="radio" name="theme" value="light" id="themeLight"
                               style="accent-color:var(--il-primary);"
                               <?php echo $pref_theme === 'light' ? 'checked' : ''; ?>>
                        <div>
                          <div style="font-size:0.85rem; font-weight:600;">☀️ Light</div>
                          <div style="font-size:0.72rem; color:var(--il-text-muted);">Default — white background</div>
                        </div>
                      </label>

                      <label class="d-flex align-items-center gap-2 p-3 rounded theme-opt"
                             id="themeOptDark"
                             style="border:2px solid <?php echo $pref_theme === 'dark' ? 'var(--il-primary)' : 'var(--il-border)'; ?>;
                                    cursor:pointer; min-width:150px; background:var(--il-surface-2); transition:border-color 0.2s;">
                        <input type="radio" name="theme" value="dark" id="themeDark"
                               style="accent-color:var(--il-primary);"
                               <?php echo $pref_theme === 'dark' ? 'checked' : ''; ?>>
                        <div>
                          <div style="font-size:0.85rem; font-weight:600;">🌙 Dark</div>
                          <div style="font-size:0.72rem; color:var(--il-text-muted);">Reduced eye strain</div>
                        </div>
                      </label>

                    </div>
                    <div class="form-text mt-2" style="font-size:0.75rem; color:var(--il-text-muted);">
                      Theme is also toggleable via the button in the top navigation bar.
                    </div>
                  </div>

                </div>
              </div>

              <!-- Buttons -->
              <div class="d-flex gap-2">
                <button type="submit" name="save_prefs" value="1" class="btn btn-primary">
                  <i class="fas fa-save me-2"></i>Save Preferences
                </button>
                <button type="submit" name="reset_prefs" value="1" class="btn"
                        style="background:var(--il-surface-2); border:1px solid var(--il-border);
                               color:var(--il-text-muted); font-size:0.88rem;"
                        onclick="return confirm('Clear all saved preferences and revert to system defaults?');">
                  <i class="fas fa-undo me-1"></i>Reset to Defaults
                </button>
              </div>

            </form>
          </div>

          <!-- ── Preference resolution info panel ── -->
          <div class="col-lg-5">
            <div class="card">
              <div class="card-header">
                <i class="fas fa-info-circle me-2"></i>Active Preference Sources
              </div>
              <div class="card-body p-0">
                <table class="table mb-0" style="font-size:0.82rem;">
                  <thead>
                    <tr>
                      <th>Preference</th>
                      <th>Current Value</th>
                      <th>Source</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $src_badge = function(string $src): string {
                          if ($src === 'cookie') {
                              return '<span style="font-size:0.72rem; background:rgba(58,122,58,0.15);
                                        border:1px solid rgba(58,122,58,0.3); border-radius:4px;
                                        padding:0.1rem 0.4rem; color:#2a6a2a; font-family:monospace;">cookie</span>';
                          } elseif ($src === 'database default') {
                              return '<span style="font-size:0.72rem; background:rgba(58,90,160,0.12);
                                        border:1px solid rgba(58,90,160,0.3); border-radius:4px;
                                        padding:0.1rem 0.4rem; color:#2a3a8a; font-family:monospace;">db default</span>';
                          } else {
                              return '<span style="font-size:0.72rem; background:rgba(160,100,20,0.12);
                                        border:1px solid rgba(160,100,20,0.3); border-radius:4px;
                                        padding:0.1rem 0.4rem; color:#8a5010; font-family:monospace;">fallback</span>';
                          }
                      };

                      $dict_label = '— All Dictionaries —';
                      if ($pref_dict !== '') {
                          foreach ($dict_list as $d) {
                              if ((string)$d['dict_id'] === (string)$pref_dict) {
                                  $dict_label = htmlspecialchars($d['name']);
                                  break;
                              }
                          }
                      }
                    ?>
                    <tr>
                      <td style="color:var(--il-text-muted);">Theme</td>
                      <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($pref_theme); ?></code></td>
                      <td><?php echo $src_badge($src_theme); ?></td>
                    </tr>
                    <tr>
                      <td style="color:var(--il-text-muted);">Results / page</td>
                      <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($pref_rpp); ?></code></td>
                      <td><?php echo $src_badge($src_rpp); ?></td>
                    </tr>
                    <tr>
                      <td style="color:var(--il-text-muted);">Default dict</td>
                      <td style="font-size:0.78rem;"><?php echo htmlspecialchars($dict_label); ?></td>
                      <td><?php echo $src_badge($src_dict); ?></td>
                    </tr>
                    <tr>
                      <td style="color:var(--il-text-muted);">Search mode</td>
                      <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($pref_mode); ?></code></td>
                      <td><?php echo $src_badge($src_mode); ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="card-footer" style="font-size:0.75rem; color:var(--il-text-muted); background:var(--il-surface-2);">
                <i class="fas fa-layer-group me-1"></i>
                Resolution chain: <strong>Cookie</strong> → <strong>DB default</strong> → <strong>Built-in fallback</strong>
              </div>
            </div>

            <!-- Cookie values currently set -->
            <div class="card mt-4">
              <div class="card-header">
                <i class="fas fa-cookie-bite me-2"></i>Cookies Currently Set
              </div>
              <div class="card-body p-0">
                <table class="table mb-0" style="font-size:0.8rem;">
                  <thead>
                    <tr><th>Cookie Name</th><th>Value</th></tr>
                  </thead>
                  <tbody>
                    <?php
                      $tracked = ['indiclex_theme','indiclex_rpp','indiclex_dict','indiclex_mode'];
                      foreach ($tracked as $ck):
                          $val = isset($_COOKIE[$ck]) ? $_COOKIE[$ck] : null;
                    ?>
                    <tr>
                      <td style="font-family:monospace; color:var(--il-text-muted); font-size:0.76rem;">
                        <?php echo htmlspecialchars($ck); ?>
                      </td>
                      <td>
                        <?php if ($val !== null && $val !== ''): ?>
                          <code style="font-size:0.76rem;"><?php echo htmlspecialchars($val); ?></code>
                        <?php else: ?>
                          <span style="color:var(--il-text-muted); font-style:italic; font-size:0.76rem;">not set</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
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
<script>
  // Sidebar mobile toggle
  var sidebarToggleTop = document.getElementById('sidebarToggleTop');
  var sidebar = document.getElementById('accordionSidebar');
  if (sidebarToggleTop) {
    sidebarToggleTop.addEventListener('click', function () {
      sidebar.classList.toggle('d-none');
    });
  }

  // Sidebar collapse arrow toggle
  var sidebarToggle = document.getElementById('sidebarToggle');
  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function () {
      sidebar.classList.toggle('d-none');
    });
  }

  // Highlight active theme radio border
  ['themeLight', 'themeDark'].forEach(function (id) {
    document.getElementById(id).addEventListener('change', function () {
      document.getElementById('themeOptLight').style.borderColor =
        this.value === 'light' ? 'var(--il-primary)' : 'var(--il-border)';
      document.getElementById('themeOptDark').style.borderColor =
        this.value === 'dark'  ? 'var(--il-primary)' : 'var(--il-border)';
    });
  });
</script>
</body>
</html>
