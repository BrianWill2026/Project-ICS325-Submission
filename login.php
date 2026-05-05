<?php
session_start();

// Already logged in → skip to dashboard immediately before any POST handling
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

// ── DEMO CREDENTIALS — replace before production ──────────────
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

$login_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';  // never trim passwords

    if ($username === ADMIN_USER && password_verify($password, ADMIN_PASS_HASH)) {
        session_regenerate_id(true); // prevent session fixation
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username']  = $username;
        header('Location: admin.php');
        exit;
    } else {
        $login_err = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex">
  <title>IndicLex — Admin Login</title>
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">
  <script src="js/theme.js" defer></script>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">

  <div class="card shadow" style="width:100%; max-width:420px;">

    <div class="card-header text-center py-4">
      <div style="font-size:1.5rem; font-weight:700; letter-spacing:0.02em;">
        Indic<span style="color:var(--il-primary);">Lex</span>
      </div>
      <div style="font-size:0.8rem; color:var(--il-text-muted); margin-top:0.25rem;">
        Admin Panel
      </div>
    </div>

    <div class="card-body p-4">

      <?php if ($login_err): ?>
        <div class="alert alert-danger py-2" style="font-size:0.875rem;">
          <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($login_err) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="">
        <div class="mb-3">
          <label for="username" class="form-label fw-bold">Username</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-user"></i></span>
            <input type="text" class="form-control" id="username" name="username"
                   autocomplete="off" required autofocus
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
          </div>
        </div>

        <div class="mb-4">
          <label for="password" class="form-label fw-bold">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password"
                   autocomplete="off" required>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100">
          <i class="fas fa-sign-in-alt me-2"></i>Login
        </button>
      </form>

    </div>

    <div class="card-footer text-center py-2" style="font-size:0.75rem; color:var(--il-text-muted);">
      <a href="index.php" style="color:var(--il-text-muted);">
        <i class="fas fa-arrow-left me-1"></i>Back to IndicLex
      </a>
    </div>

  </div>

</body>
</html>