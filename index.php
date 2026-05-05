<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="IndicLex — Multilingual Dictionary Management and Search Platform">
  <title>IndicLex — Home</title>
  <script src="js/theme.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">
</head>
 
<body id="page-top">
<div id="wrapper">
 
  <ul class="navbar-nav sidebar sidebar-dark accordion d-flex flex-column"
      id="accordionSidebar"
      style="min-width:220px; min-height:100vh; position:sticky; top:0;">
    <a class="sidebar-brand d-flex align-items-center justify-content-center text-decoration-none py-3" href="index.php">
      <div class="sidebar-brand-text">Indic<span>Lex</span></div>
    </a>
    <hr class="sidebar-divider my-1">
    <div class="sidebar-heading">Navigation</div>
    <li class="nav-item active">
      <a class="nav-link" href="index.php"><i class="fas fa-fw fa-home me-2"></i><span>Home</span></a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="search.php"><i class="fas fa-fw fa-search me-2"></i><span>Search</span></a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="preferences.php"><i class="fas fa-fw fa-sliders-h me-2"></i><span>Preferences</span></a>
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
      <a class="nav-link" href="catalog.php"><i class="fas fa-fw fa-book-open me-2"></i><span>Dictionary Catalog</span></a>
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
        <span class="d-md-none fw-bold" style="font-family:var(--il-font-display); font-size:1rem;">IndicLex</span>
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item me-2">
            <button id="themeToggleBtn" aria-label="Toggle theme">
              <i class="fas fa-moon me-1"></i>
              <span class="d-none d-md-inline">Dark Mode</span>
            </button>
          </li>
          <li class="nav-item">
            <span style="font-size:0.8rem; color:var(--il-text-muted);">
              <i class="fas fa-code-branch me-1"></i>Iteration 4
            </span>
          </li>
        </ul>
      </nav>
 
      <div class="container-fluid px-0">
 
        <div class="il-hero">
          <div class="badge-iteration">Iteration 4 — Search Engine</div>
          <h1>Welcome to <em>IndicLex</em></h1>
          <p>A multilingual dictionary management and search platform.</p>
        </div>

        <div class="row mb-4 g-3">
          <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
          <div class="col-sm-6 col-xl-3">
            <a href="catalog.php" class="il-feature-card">
              <div class="feature-icon catalog"><i class="fas fa-book-open"></i></div>
              <h5>Dictionary Catalog</h5>
              <p>Browse all available dictionaries.</p>
            </a>
          </div>
          <?php endif; ?>
          <?php $card_col = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) ? 'col-sm-6 col-xl-3' : 'col-sm-6 col-xl-4'; ?>
          <div class="<?php echo $card_col; ?>">
            <a href="search.php" class="il-feature-card">
              <div class="feature-icon search"><i class="fas fa-search"></i></div>
              <h5>Search Interface</h5>
              <p>Search across dictionaries.</p>
            </a>
          </div>
          <div class="<?php echo $card_col; ?>">
            <a href="preferences.php" class="il-feature-card">
              <div class="feature-icon prefs"><i class="fas fa-sliders-h"></i></div>
              <h5>User Preferences</h5>
              <p>Set default options.</p>
            </a>
          </div>
          <div class="<?php echo $card_col; ?>">
            <div class="il-feature-card" style="cursor:pointer;" onclick="window.IndicLexTheme.toggle()">
              <div class="feature-icon theme"><i class="fas fa-circle-half-stroke"></i></div>
              <h5>Theme Toggle</h5>
              <p>Switch between light and dark mode.</p>
            </div>
          </div>
        </div>

        <!-- Upload section removed — now lives on catalog.php -->

      </div>
    </div>
 
    <footer class="sticky-footer mt-auto">
      <div class="container-fluid d-flex justify-content-between">
        <div>
          <span class="fw-semibold" style="color:var(--il-text);">IndicLex</span>
          &mdash; Multilingual Dictionary Platform
        </div>
      </div>
    </footer>
  </div>
</div>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  var sidebarToggleTop = document.getElementById('sidebarToggleTop');
  var sidebar = document.getElementById('accordionSidebar');
  if (sidebarToggleTop) {
    sidebarToggleTop.addEventListener('click', function () {
      sidebar.classList.toggle('d-none');
    });
  }
</script>
</body>
</html>
