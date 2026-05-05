<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');

require 'vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_OFF);

include 'dbcon.php';

$succ_msg   = "";
$create_err  = "";
$create_succ = "";

$file_err      = $err_msg = "";
$valid_ext     = ['xlsx'];
$upload_dir    = __DIR__ . '/uploads/';
$rows_inserted = 0;
$rows_skipped  = [];
$errors        = [];
$upload_succ_msg = "";

// ── Known languages ───────────────────────────────────────────────────────────
$known_languages = [
    'Telugu'    => ['script' => 'తెలుగు',   'script_name' => 'Telugu'],
    'Sanskrit'  => ['script' => 'संस्कृत', 'script_name' => 'Devanagari'],
    'Hindi'     => ['script' => 'हिन्दी',   'script_name' => 'Devanagari'],
    'English'   => ['script' => 'English',  'script_name' => 'Latin'],
    'Tamil'     => ['script' => 'தமிழ்',    'script_name' => 'Tamil'],
    'Kannada'   => ['script' => 'ಕನ್ನಡ',   'script_name' => 'Kannada'],
    'Malayalam' => ['script' => 'മലയാളം',  'script_name' => 'Malayalam'],
    'Bengali'   => ['script' => 'বাংলা',    'script_name' => 'Bengali'],
    'Marathi'   => ['script' => 'मराठी',    'script_name' => 'Devanagari'],
    'Gujarati'  => ['script' => 'ગુજરાતી', 'script_name' => 'Gujarati'],
    'Punjabi'   => ['script' => 'ਪੰਜਾਬੀ',  'script_name' => 'Gurmukhi'],
    'Odia'      => ['script' => 'ଓଡ଼ିଆ',   'script_name' => 'Odia'],
    'Urdu'      => ['script' => 'اردو',     'script_name' => 'Nastaliq'],
];

// ── Handle upload POST ────────────────────────────────────────────────────────
if (isset($_POST['submit'])) {

    $dict_id = isset($_POST['dict_id']) ? (int)$_POST['dict_id'] : 0;

    $upload_error = $_FILES['input_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upload_error !== UPLOAD_ERR_OK) {
        $file_err = match($upload_error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large to upload.',
            UPLOAD_ERR_PARTIAL                        => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE                        => 'Please select a file.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server error: missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE                     => 'Server error: failed to write file to disk.',
            UPLOAD_ERR_EXTENSION                      => 'Server error: upload blocked by a PHP extension.',
            default                                   => 'Unknown upload error. Please try again.',
        };
    } elseif ($_FILES['input_file']['name'] == '') {
        $file_err = 'Please select a file.';
    } elseif ($dict_id <= 0) {
        $file_err = 'Please select a dictionary.';
    } else {
        $file_name = $_FILES['input_file']['name'];
        $tmp_name  = $_FILES['input_file']['tmp_name'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $max_bytes = 5 * 1024 * 1024;
        if ($_FILES['input_file']['size'] > $max_bytes) {
            $file_err = 'File is too large. Maximum allowed size is 5 MB.';
        } elseif (!in_array($ext, $valid_ext)) {
            $err_msg = 'Invalid file type. Only .xlsx files are allowed.';
        } elseif (!in_array(
            mime_content_type($tmp_name),
            [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ]
        )) {
            $err_msg = 'Invalid file content. The file does not appear to be a valid .xlsx spreadsheet.';
        } else {
            $d_stmt = mysqli_prepare($conn, "SELECT type FROM dictionaries WHERE dict_id = ? AND is_active = TRUE");
            mysqli_stmt_bind_param($d_stmt, 'i', $dict_id);
            mysqli_stmt_execute($d_stmt);
            $d_row = mysqli_fetch_assoc(mysqli_stmt_get_result($d_stmt));
            mysqli_stmt_close($d_stmt);

            if (!$d_row) {
                $err_msg = 'Selected dictionary not found.';
            } else {
                $existing_files = glob($upload_dir . '*.xlsx');
                if ($existing_files && count($existing_files) >= 5) {
                    usort($existing_files, fn($a, $b) => filemtime($a) - filemtime($b));
                    unlink($existing_files[0]);
                }

                $safe_name     = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($file_name));
                $is_trilingual = ($d_row['type'] === 'trilingual');
                $new_file      = time() . '-' . $safe_name;

                try {
                    if (!move_uploaded_file($tmp_name, $upload_dir . $new_file)) {
                        $err_msg = 'Failed to move uploaded file. Check server permissions.';
                    } else {

                    $reader      = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($upload_dir . $new_file);
                    $worksheet   = $spreadsheet->getActiveSheet();

                    $sql  = "INSERT INTO dictionary_entries (dict_id, lang_1, lang_2, lang_3) VALUES (?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $sql);

                    foreach ($worksheet->getRowIterator() as $row) {
                        $excel_row  = $row->getRowIndex();
                        $cell_iter  = $row->getCellIterator();
                        $cell_iter->setIterateOnlyExistingCells(false);

                        $cells = [];
                        foreach ($cell_iter as $cell) {
                            $cells[] = $cell->getValue();
                            if (count($cells) === 3) break;
                        }

                        $lang_1 = isset($cells[0]) ? trim((string)$cells[0]) : '';
                        $lang_2 = isset($cells[1]) ? trim((string)$cells[1]) : '';
                        $lang_3 = $is_trilingual ? (isset($cells[2]) ? trim((string)$cells[2]) : '') : null;

                        if ($lang_1 === '' && $lang_2 === '') continue;

                        if ($lang_1 === '' || $lang_2 === '') {
                            $errors[] = "Row {$excel_row}: lang_1 and lang_2 are both required — row skipped.";
                            continue;
                        }

                        if ($is_trilingual && ($lang_3 === null || $lang_3 === '')) {
                            $errors[] = "Row {$excel_row}: lang_3 is required for a trilingual dictionary — row skipped.";
                            continue;
                        }

                        mysqli_stmt_bind_param($stmt, 'isss', $dict_id, $lang_1, $lang_2, $lang_3);

                        if (mysqli_stmt_execute($stmt)) {
                            $rows_inserted++;
                        } else {
                            $errno  = mysqli_stmt_errno($stmt);
                            $errmsg = mysqli_stmt_error($stmt);
                            if ($errno === 1062 || strpos($errmsg, 'Duplicate entry') !== false) {
                                $rows_skipped[] = "Row {$excel_row}: \"{$lang_1}\" already exists in this dictionary — skipped.";
                            } else {
                                $errors[] = "Row {$excel_row}: " . $errmsg;
                            }
                        }
                    }

                    mysqli_stmt_close($stmt);
                    $stmt = null;

                    // Delete the uploaded file after processing — no need to keep it on disk
                    if (file_exists($upload_dir . $new_file)) {
                        unlink($upload_dir . $new_file);
                    }

                    $upload_succ_msg = "Successfully inserted {$rows_inserted} row(s) into the dictionary.";
                    if (!empty($rows_skipped)) {
                        $upload_succ_msg .= ' ' . count($rows_skipped) . ' duplicate(s) skipped.';
                    }

                    } // end move_uploaded_file success block

                } catch (mysqli_sql_exception $e) {
                    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                        mysqli_stmt_close($stmt);
                    }
                    if (file_exists($upload_dir . $new_file)) {
                        unlink($upload_dir . $new_file);
                    }
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $rows_skipped[] = 'One or more rows were duplicates and skipped.';
                        $upload_succ_msg = "Inserted {$rows_inserted} row(s). " . count($rows_skipped) . ' duplicate(s) skipped.';
                    } else {
                        $err_msg = 'Database error: ' . $e->getMessage();
                    }
                } catch (Exception $e) {
                    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                        mysqli_stmt_close($stmt);
                    }
                    if (file_exists($upload_dir . $new_file)) {
                        unlink($upload_dir . $new_file);
                    }
                    $err_msg = 'Failed to read Excel file: ' . $e->getMessage();
                }
            }
        }
    }
}

$reopen_modal = isset($_POST['submit']) && ($file_err || $err_msg);

// ── Handle create dictionary POST ─────────────────────────────────────────────
if (isset($_POST['create_dict'])) {
    $src_lang  = trim($_POST['src_lang']  ?? '');
    $tgt_lang  = trim($_POST['tgt_lang']  ?? '');
    $dict_type = trim($_POST['dict_type'] ?? 'bilingual');
    if (!in_array($dict_type, ['bilingual', 'trilingual'], true)) {
        $dict_type = 'bilingual';
    }

    if (!array_key_exists($src_lang, $known_languages) || !array_key_exists($tgt_lang, $known_languages)) {
        $create_err = 'Please select valid source and target languages.';
    } elseif ($src_lang === $tgt_lang) {
        $create_err = 'Source and target languages must be different.';
    } else {
        $dict_name = $src_lang . ' – ' . $tgt_lang;
        $chk = mysqli_prepare($conn, "SELECT dict_id FROM dictionaries WHERE name = ?");
        mysqli_stmt_bind_param($chk, 's', $dict_name);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        if (mysqli_stmt_num_rows($chk) > 0) {
            $create_err = "A dictionary named \"$dict_name\" already exists.";
        } else {
            $ins = mysqli_prepare($conn,
                "INSERT INTO dictionaries (name, type, is_active) VALUES (?, ?, TRUE)");
            mysqli_stmt_bind_param($ins, 'ss', $dict_name, $dict_type);
            if (mysqli_stmt_execute($ins)) {
                $create_succ = "Dictionary \"$dict_name\" created successfully.";
            } else {
                $create_err = 'Database error: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($ins);
        }
        mysqli_stmt_close($chk);
    }
}

// ── Handle delete POST ────────────────────────────────────────────────────────
if (isset($_POST['delete_entry'])) {
    $entry_id = (int)$_POST['entry_id'];
    if ($entry_id > 0) {
        $del_stmt = mysqli_prepare($conn, "DELETE de FROM dictionary_entries de JOIN dictionaries d ON de.dict_id = d.dict_id WHERE de.entry_id = ? AND d.is_active = 1");
        mysqli_stmt_bind_param($del_stmt, 'i', $entry_id);
        mysqli_stmt_execute($del_stmt);
        mysqli_stmt_close($del_stmt);
        $succ_msg = "Entry deleted successfully.";
    }
}

// ── Fetch active dictionaries for the dropdown ────────────────────────────────
$dictionaries = [];
$dict_result  = mysqli_query($conn, "SELECT dict_id, name, type FROM dictionaries WHERE is_active = TRUE ORDER BY name");
while ($row = mysqli_fetch_assoc($dict_result)) {
    $dictionaries[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="IndicLex — Dictionary Catalog">
  <title>IndicLex — Dictionary Catalog</title>

  <script src="js/theme.js"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="css/indiclex-custom.css" rel="stylesheet">
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
    <li class="nav-item active">
      <a class="nav-link" href="catalog.php">
        <i class="fas fa-fw fa-book-open me-2"></i><span>Dictionary Catalog</span>
      </a>
    </li>
    <?php endif; ?>

    <hr class="sidebar-divider d-none d-md-block mt-auto">
    <div class="text-center d-none d-md-inline pb-2">
      <button class="rounded-circle border-0" id="sidebarToggle" style="width:2rem;height:2rem;background:rgba(255,255,255,0.08);color:#fff;">
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
              <i class="fas fa-code-branch me-1"></i>Iteration 2
            </span>
          </li>
        </ul>
      </nav>

      <div class="container-fluid px-0">

        <div class="il-page-header">
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="index.php">Home</a></li>
              <li class="breadcrumb-item active" aria-current="page">Dictionary Catalog</li>
            </ol>
          </nav>
          <h2><i class="fas fa-book-open me-2" style="color:var(--il-primary); font-size:1.3rem;"></i>Dictionary Catalog</h2>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-sm-4">
            <div class="card text-center py-2">
              <div class="card-body py-3">
                <div style="font-size:1.75rem; font-weight:700; font-family:var(--il-font-mono); color:var(--il-primary);"><?= (int)count($dictionaries) ?></div>
                <div style="font-size:0.75rem; color:var(--il-text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">Active Dictionaries</div>
              </div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="card text-center py-2">
              <div class="card-body py-3">
                <?php
                  $src_langs = array_unique(array_map(function($d) {
                      return explode(' – ', $d['name'], 2)[0];
                  }, $dictionaries));
                ?>
                <div style="font-size:1.75rem; font-weight:700; font-family:var(--il-font-mono); color:var(--il-accent);"><?= (int)count($src_langs) ?></div>
                <div style="font-size:0.75rem; color:var(--il-text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">Source Languages</div>
              </div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="card text-center py-2">
              <div class="card-body py-3">
                <div style="font-size:1.75rem; font-weight:700; font-family:var(--il-font-mono);">—</div>
                <div style="font-size:0.75rem; color:var(--il-text-muted); text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">Total Entries</div>
              </div>
            </div>
          </div>
        </div>

        <?php if (!empty($succ_msg)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <strong><?= htmlspecialchars($succ_msg) ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!empty($upload_succ_msg)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <strong><?= htmlspecialchars($upload_succ_msg) ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?= count($errors) ?> row(s) had errors and were skipped.</strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!empty($rows_skipped)): ?>
          <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <strong><?= count($rows_skipped) ?> duplicate(s) skipped.</strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Upload Dictionary Entries -->
        <div class="card mb-4">
          <div class="card-header">
            <i class="fas fa-upload me-2"></i>Upload Dictionary Entries
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3">
              Upload an <code>.xlsx</code> file to add entries to a dictionary.
              Column order: <code>lang_1</code>, <code>lang_2</code>,
              <code>lang_3</code> (trilingual only). No header row.
            </p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
              <i class="fas fa-upload me-2"></i>Upload a File
            </button>
          </div>
        </div>

        

        <!-- Create Custom Dictionary -->
        <div class="card mb-4">
          <div class="card-header">
            <i class="fas fa-plus-circle me-2"></i>Create Custom Dictionary
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3">
              Select two languages to create a new dictionary. It will appear in the table below and be available for uploads immediately.
            </p>

            <?php if (!empty($create_succ)): ?>
              <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <strong><?= htmlspecialchars($create_succ) ?></strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            <?php endif; ?>
            <?php if (!empty($create_err)): ?>
              <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= htmlspecialchars($create_err) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            <?php endif; ?>

            <form method="post">
              <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                  <label for="src_lang" class="form-label fw-bold">Source Language</label>
                  <select class="form-select" name="src_lang" id="src_lang" required>
                    <option value="" disabled <?= !isset($_POST['src_lang']) ? 'selected' : '' ?>>— Select —</option>
                    <?php foreach ($known_languages as $lang => $meta): ?>
                      <option value="<?= htmlspecialchars($lang) ?>"
                        <?= (isset($_POST['src_lang']) && $_POST['src_lang'] === $lang) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lang) ?> (<?= htmlspecialchars($meta['script']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label for="tgt_lang" class="form-label fw-bold">Target Language</label>
                  <select class="form-select" name="tgt_lang" id="tgt_lang" required>
                    <option value="" disabled <?= !isset($_POST['tgt_lang']) ? 'selected' : '' ?>>— Select —</option>
                    <?php foreach ($known_languages as $lang => $meta): ?>
                      <option value="<?= htmlspecialchars($lang) ?>"
                        <?= (isset($_POST['tgt_lang']) && $_POST['tgt_lang'] === $lang) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lang) ?> (<?= htmlspecialchars($meta['script']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-2">
                  <label for="dict_type" class="form-label fw-bold">Type</label>
                  <select class="form-select" name="dict_type" id="dict_type">
                    <option value="bilingual"  <?= (!isset($_POST['dict_type']) || $_POST['dict_type'] === 'bilingual')  ? 'selected' : '' ?>>Bilingual</option>
                    <option value="trilingual" <?= (isset($_POST['dict_type']) && $_POST['dict_type'] === 'trilingual') ? 'selected' : '' ?>>Trilingual</option>
                  </select>
                </div>
                <div class="col-sm-2">
                  <button type="submit" name="create_dict" class="btn btn-success w-100">
                    <i class="fas fa-plus me-2"></i>Create
                  </button>
                </div>
              </div>
              <div class="mt-2" style="font-size:0.78rem; color:var(--il-text-muted);">
                The dictionary name is set automatically, e.g. <em>Telugu – English</em>.
              </div>
            </form>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="fas fa-table me-2"></i>Available Dictionaries</span>
            <span style="font-size:0.75rem; color:var(--il-text-muted); font-family:var(--il-font-body); font-weight:400;"><?= (int)count($dictionaries) ?> total</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead>
                  <tr>
                    <th scope="col">#</th>
                    <th scope="col">Dictionary Name</th>
                    <th scope="col">Source Language</th>
                    <th scope="col">Target Language</th>
                    <th scope="col">Script</th>
                    <th scope="col">Type</th>
                    <th scope="col">Status</th>
                    <th scope="col">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($dictionaries)): ?>
                    <tr>
                      <td colspan="8" class="text-center py-4" style="color:var(--il-text-muted); font-size:0.85rem;">
                        No dictionaries found. Create one above.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($dictionaries as $i => $d):
                        // Parse "Source – Target" name format
                        $parts      = explode(' – ', $d['name'], 2);
                        $src        = $parts[0] ?? $d['name'];
                        $tgt        = $parts[1] ?? '—';
                        $src_lc     = strtolower($src);
                        $src_script = $known_languages[$src]['script'] ?? '';
                        $src_script_name = $known_languages[$src]['script_name'] ?? '';
                    ?>
                    <tr>
                      <td style="font-family:var(--il-font-mono); font-size:0.8rem; color:var(--il-text-muted);"><?= str_pad((int)$d['dict_id'], 3, '0', STR_PAD_LEFT) ?></td>
                      <td>
                        <div class="fw-semibold" style="font-size:0.9rem;"><?= htmlspecialchars($d['name']) ?></div>
                        <div style="font-size:0.75rem; color:var(--il-text-muted);"><?= htmlspecialchars(ucfirst($d['type'])) ?> lexicon</div>
                      </td>
                      <td><span class="lang-badge lang-<?= htmlspecialchars($src_lc) ?>"><i class="fas fa-circle" style="font-size:0.4rem;"></i><?= htmlspecialchars($src) ?></span></td>
                      <td><span style="font-size:0.85rem;"><?= htmlspecialchars($tgt) ?></span></td>
                      <td><span style="font-size:0.9rem; font-family:var(--il-font-mono);"><?= htmlspecialchars($src_script_name) ?><?= $src_script ? ' (' . htmlspecialchars($src_script) . ')' : '' ?></span></td>
                      <td><span style="font-family:var(--il-font-mono); font-size:0.8rem; color:var(--il-text-muted);"><?= htmlspecialchars($d['type']) ?></span></td>
                      <td><span class="badge" style="background:rgba(50,180,50,0.15); color:#2a7a2a; font-size:0.72rem; padding:0.3rem 0.6rem; border-radius:12px;">Active</span></td>
                      <td>
                        <a href="search.php" class="btn btn-sm" style="background:var(--il-surface-2); border:1px solid var(--il-border); color:var(--il-primary); font-size:0.78rem; padding:0.25rem 0.65rem; border-radius:6px;">
                          <i class="fas fa-search me-1"></i>Search
                        </a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
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
  const sidebarToggleTop = document.getElementById('sidebarToggleTop');
  const sidebar = document.getElementById('accordionSidebar');
  if (sidebarToggleTop) {
    sidebarToggleTop.addEventListener('click', () => sidebar.classList.toggle('d-none'));
  }

  const sidebarToggle = document.getElementById('sidebarToggle');
  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('d-none'));
  }

  <?php if ($reopen_modal): ?>
  document.addEventListener('DOMContentLoaded', function () {
    var modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
  });
  <?php endif; ?>
</script>

<!-- ── Upload Modal ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="uploadModalLabel">
          <i class="fas fa-upload me-2"></i>Upload Dictionary Entries
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <?php if (!empty($err_msg)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($err_msg) ?></div>
        <?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data" id="uploadForm">

          <div class="mb-3">
            <label for="dict_id" class="form-label fw-bold">Target Dictionary</label>
            <select class="form-select" name="dict_id" id="dict_id" required>
              <option value="" disabled <?= !isset($_POST['dict_id']) ? 'selected' : '' ?>>— Select a dictionary —</option>
              <?php foreach ($dictionaries as $d): ?>
                <option value="<?= (int)$d['dict_id'] ?>"
                  <?= (isset($_POST['dict_id']) && (int)$_POST['dict_id'] === (int)$d['dict_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['type']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="input_file" class="form-label fw-bold">Upload File</label>
            <input
              type="file"
              class="form-control"
              name="input_file"
              id="input_file"
              accept=".xlsx" />
            <div class="form-text">
              Allowed file type: <strong>.xlsx</strong> — No header row.
              Column order: <code>lang_1</code>, <code>lang_2</code>,
              <code>lang_3</code> (trilingual dictionaries only).
            </div>
          </div>

          <?php if (!empty($file_err)): ?>
            <div class="text-danger mb-2"><?= htmlspecialchars($file_err) ?></div>
          <?php endif; ?>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" name="submit">
              <i class="fas fa-upload me-2"></i>Submit
            </button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="fas fa-times me-2"></i>Cancel
            </button>
          </div>

        </form>
      </div>

    </div>
  </div>
</div>

</body>
</html>
