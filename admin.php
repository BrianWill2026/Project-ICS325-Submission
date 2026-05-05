<?php
session_start();

if (isset($_POST['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// ── ROUTE PROTECTION ─────────────────────────────────────────
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// connect to database
require_once 'dbcon.php';

// ── PULL DASHBOARD STATS ──────────────────────────────────────

// total number of active dictionaries
$total_dicts = 0;
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM dictionaries WHERE is_active = 1");
$row = mysqli_fetch_assoc($r);
$total_dicts = $row['total'];

// total number of entries across all dictionaries
$total_entries = 0;
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM dictionary_entries WHERE is_active = 1");
$row = mysqli_fetch_assoc($r);
$total_entries = $row['total'];

// total number of users
$total_users = 0;
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE is_active = 1");
$row = mysqli_fetch_assoc($r);
$total_users = $row['total'];

// per-dictionary stats: name, type, entry count
$dict_stats = [];
$r = mysqli_query($conn, "SELECT name, type, source_lang_1, source_lang_2, source_lang_3, entry_count FROM dictionaries WHERE is_active = 1 ORDER BY name");
while($row = mysqli_fetch_assoc($r)){
    $dict_stats[] = $row;
}

// language breakdown: how many entries per source language
$lang_stats = [];
$r = mysqli_query($conn, "
    SELECT source_lang_1 AS language, SUM(entry_count) AS total_entries
    FROM dictionaries
    WHERE is_active = 1
    GROUP BY source_lang_1
    UNION
    SELECT source_lang_2, SUM(entry_count)
    FROM dictionaries
    WHERE is_active = 1
    GROUP BY source_lang_2
    ORDER BY total_entries DESC
");
while($row = mysqli_fetch_assoc($r)){
    $lang_stats[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>IndicLex — Admin Dashboard</title>

  <script src="js/theme.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">

  <!-- DataTables CSS -->
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
    <li class="nav-item active">
      <a class="nav-link" href="admin.php">
        <i class="fas fa-fw fa-tachometer-alt me-2"></i><span>Dashboard</span>
      </a>
    </li>
    <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
    <li class="nav-item">
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
  </ul>

  <div id="content-wrapper" class="d-flex flex-column flex-grow-1" style="min-width:0;">
    <div id="content">

      <nav class="navbar navbar-expand topbar mb-4 static-top shadow-sm">
        <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle me-3">
          <i class="fa fa-bars"></i>
        </button>
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item me-2">
            <button id="themeToggleBtn" aria-label="Toggle theme">
              <i class="fas fa-moon me-1"></i>
              <span class="d-none d-md-inline">Dark Mode</span>
            </button>
          </li>
          <div class="topbar-divider d-none d-sm-block" style="border-left:1px solid var(--il-border); height:2rem; margin:0 0.75rem;"></div>
          <li class="nav-item me-3">
            <span style="font-size:0.8rem; color:var(--il-text-muted);">
              <i class="fas fa-user-shield me-1"></i>
              <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>
            </span>
          </li>
          <li class="nav-item">
              <form method="post" action="">
                <button type="submit" name="logout" class="btn btn-sm"
            style="background:var(--il-surface-2); border:1px solid var(--il-border); color:var(--il-text-muted); font-size:0.8rem;">
      <i class="fas fa-sign-out-alt me-1"></i>Logout
    </button>
  </form>
</li>
        </ul>
      </nav>

      <div class="container-fluid px-0">

        <div class="il-page-header">
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="index.php">Home</a></li>
              <li class="breadcrumb-item active" aria-current="page">Admin Dashboard</li>
            </ol>
          </nav>
          <h2><i class="fas fa-tachometer-alt me-2" style="color:var(--il-primary); font-size:1.3rem;"></i>Admin Dashboard</h2>
        </div>

        <!-- ── SUMMARY STAT CARDS ─────────────────────────────── -->
        <div class="row g-3 mb-4">

          <div class="col-sm-4">
            <div class="card text-center py-2">
              <div class="card-body py-3">
                <div style="font-size:2rem; font-weight:700; font-family:var(--il-font-mono); color:var(--il-primary);">
                  <?php echo (int)$total_dicts; ?>
                </div>
                <div style="font-size:0.75rem; color:var(--il-text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">
                  Active Dictionaries
                </div>
              </div>
            </div>
          </div>

          <div class="col-sm-4">
            <div class="card text-center py-2">
              <div class="card-body py-3">
                <div style="font-size:2rem; font-weight:700; font-family:var(--il-font-mono); color:var(--il-accent);">
                  <?php echo (int)$total_entries; ?>
                </div>
                <div style="font-size:0.75rem; color:var(--il-text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">
                  Total Entries
                </div>
              </div>
            </div>
          </div>

          <div class="col-sm-4">
            <div class="card text-center py-2">
              <div class="card-body py-3">
                <div style="font-size:2rem; font-weight:700; font-family:var(--il-font-mono);">
                  <?php echo (int)$total_users; ?>
                </div>
                <div style="font-size:0.75rem; color:var(--il-text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">
                  Registered Users
                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="row g-4">

          <!-- ── DICTIONARY STATS TABLE (with DataTables) ─────── -->
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header">
                <i class="fas fa-table me-2"></i>Dictionary Breakdown
              </div>
              <div class="card-body">
                <table id="dictTable" class="table table-hover mb-0" style="width:100%;">
                  <thead>
                    <tr>
                      <th>Dictionary Name</th>
                      <th>Type</th>
                      <th>Lang 1</th>
                      <th>Lang 2</th>
                      <th>Lang 3</th>
                      <th>Entry Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($dict_stats as $d){ ?>
                    <tr>
                      <td style="font-size:0.88rem; font-weight:600;"><?php echo htmlspecialchars($d['name']); ?></td>
                      <td>
                        <span style="font-size:0.75rem; background:var(--il-surface-2); border:1px solid var(--il-border); border-radius:4px; padding:0.15rem 0.5rem; font-family:var(--il-font-mono);">
                          <?php echo htmlspecialchars($d['type']); ?>
                        </span>
                      </td>
                      <td style="font-size:0.85rem;"><?php echo htmlspecialchars($d['source_lang_1']); ?></td>
                      <td style="font-size:0.85rem;"><?php echo htmlspecialchars($d['source_lang_2']); ?></td>
                      <td style="font-size:0.85rem;"><?php echo $d['source_lang_3'] ? htmlspecialchars($d['source_lang_3']) : '—'; ?></td>
                      <td style="font-family:var(--il-font-mono); font-size:0.85rem; font-weight:600; color:var(--il-primary);">
                        <?php echo (int)$d['entry_count']; ?>
                      </td>
                    </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ── LANGUAGE BREAKDOWN ────────────────────────────── -->
          <div class="col-lg-4">
            <div class="card">
              <div class="card-header">
                <i class="fas fa-language me-2"></i>Language Breakdown
              </div>
              <div class="card-body p-0">
                <table class="table mb-0">
                  <thead>
                    <tr>
                      <th>Language</th>
                      <th>Total Entries</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($lang_stats as $l){ ?>
                    <tr>
                      <td style="font-size:0.88rem;"><?php echo htmlspecialchars($l['language']); ?></td>
                      <td style="font-family:var(--il-font-mono); font-size:0.85rem; font-weight:600; color:var(--il-accent);">
                        <?php echo (int)$l['total_entries']; ?>
                      </td>
                    </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <footer class="sticky-footer mt-auto">
      <div class="container-fluid d-flex justify-content-between">
        <div><span class="fw-semibold" style="color:var(--il-text);">IndicLex</span> &mdash; Admin Panel &mdash; Iteration 6</div>
      </div>
    </footer>
  </div>
</div>

<!-- jQuery (needed for DataTables) -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
  // sidebar toggle for mobile
  var sidebarToggleTop = document.getElementById('sidebarToggleTop');
  var sidebar = document.getElementById('accordionSidebar');
  if(sidebarToggleTop){
    sidebarToggleTop.addEventListener('click', function(){
      sidebar.classList.toggle('d-none');
    });
  }

  // init DataTables on the dictionary table
  // this adds search, sort, and pagination automatically
  $(document).ready(function(){
    $('#dictTable').DataTable({
      paging: false,       // we have few rows so no need for paging
      info: false,         // hides "Showing 1 to N of N entries"
      searching: true      // keeps the search box
    });
  });
</script>
</body>
</html>
