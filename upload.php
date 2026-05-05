<?php
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');
 
require 'vendor/autoload.php';
 
// Disable exception mode so duplicate key errors return false instead of throwing,
// allowing the per-row error handling in the loop to catch them correctly
mysqli_report(MYSQLI_REPORT_OFF);
 
include 'dbcon.php';
 
$file_err   = $err_msg = $succ_msg = "";
$valid_ext  = ['xlsx'];
$upload_dir = 'uploads/';
 
$rows_inserted = 0;
$rows_skipped  = [];
$errors        = [];
 
// ── Fetch active dictionaries for the dropdown ────────────────────────────────
$dictionaries = [];
$dict_result  = mysqli_query($conn, "SELECT dict_id, name, type FROM dictionaries WHERE is_active = TRUE ORDER BY name");
while ($row = mysqli_fetch_assoc($dict_result)) {
    $dictionaries[] = $row;
}
 
if (isset($_POST['submit'])) {
 
    $dict_id = isset($_POST['dict_id']) ? (int)$_POST['dict_id'] : 0;
 
    if ($_FILES['input_file']['name'] == '') {
        $file_err = 'Please select a file.';
    } elseif ($dict_id <= 0) {
        $file_err = 'Please select a dictionary.';
    } else {
        $file_name = $_FILES['input_file']['name'];
        $tmp_name  = $_FILES['input_file']['tmp_name'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
 
        if (in_array($ext, $valid_ext)) {
 
            // ── Look up chosen dictionary to determine bilingual vs trilingual ─
            $d_stmt = mysqli_prepare($conn, "SELECT type FROM dictionaries WHERE dict_id = ? AND is_active = TRUE");
            mysqli_stmt_bind_param($d_stmt, 'i', $dict_id);
            mysqli_stmt_execute($d_stmt);
            $d_row = mysqli_fetch_assoc(mysqli_stmt_get_result($d_stmt));
            mysqli_stmt_close($d_stmt);
 
            if (!$d_row) {
                $err_msg = 'Selected dictionary not found.';
            } else {
                $is_trilingual = ($d_row['type'] === 'trilingual');
                $new_file      = time() . '-' . basename($file_name);
 
                try {
                    move_uploaded_file($tmp_name, $upload_dir . $new_file);
 
                    $reader      = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($upload_dir . $new_file);
                    $worksheet   = $spreadsheet->getActiveSheet();
 
                    /*
                     * Expected column order:
                     *   Col A (0) : lang_1 — required
                     *   Col B (1) : lang_2 — required
                     *   Col C (2) : lang_3 — required for trilingual, ignored for bilingual
                     */
                    $sql  = "INSERT INTO dictionary_entries (dict_id, lang_1, lang_2, lang_3) VALUES (?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $sql);
 
                    foreach ($worksheet->getRowIterator() as $row) {
                        $excel_row  = $row->getRowIndex();
                        $cell_iter  = $row->getCellIterator();
                        $cell_iter->setIterateOnlyExistingCells(false);
 
                        $cells  = [];
                        foreach ($cell_iter as $cell) {
                            $cells[] = $cell->getValue();
                            if (count($cells) === 3) break; // only need first 3 columns
                        }
 
                        $lang_1 = isset($cells[0]) ? trim((string)$cells[0]) : '';
                        $lang_2 = isset($cells[1]) ? trim((string)$cells[1]) : '';
                        $lang_3 = $is_trilingual ? (isset($cells[2]) ? trim((string)$cells[2]) : '') : null;
 
                        // Silently skip fully blank rows
                        if ($lang_1 === '' && $lang_2 === '') {
                            continue;
                        }
 
                        // lang_1 and lang_2 are always required
                        if ($lang_1 === '' || $lang_2 === '') {
                            $errors[] = "Row {$excel_row}: lang_1 and lang_2 are both required — row skipped.";
                            continue;
                        }
 
                        // lang_3 is required for trilingual dictionaries
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
 
                    $succ_msg = "Successfully inserted {$rows_inserted} row(s) into the dictionary.";
                    if (!empty($rows_skipped)) {
                        $succ_msg .= ' ' . count($rows_skipped) . ' duplicate(s) skipped.';
                    }
 
                } catch (mysqli_sql_exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $rows_skipped[] = 'One or more rows were duplicates and skipped.';
                        $succ_msg = "Inserted {$rows_inserted} row(s). " . count($rows_skipped) . ' duplicate(s) skipped.';
                    } else {
                        $err_msg = 'Database error: ' . $e->getMessage();
                    }
                } catch (Exception $e) {
                    $err_msg = 'Failed to read Excel file: ' . $e->getMessage();
                }
            }
        } else {
            $err_msg = 'Invalid file type. Only .xlsx files are allowed.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IndicLex — Upload Dictionary Entries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/indiclex-custom.css">
    <script src="js/theme.js"></script>
</head>
 
<body>
<div class="container mt-4">
    <h1 class="mb-4">Upload Dictionary Entries</h1>
 
    <?php if (!empty($err_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err_msg) ?></div>
    <?php endif; ?>
 
    <?php if (!empty($succ_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($succ_msg) ?></div>
    <?php endif; ?>
 
    <?php if (!empty($rows_skipped)): ?>
        <div class="alert alert-warning">
            <strong>Skipped duplicates (<?= count($rows_skipped) ?>):</strong>
            <ul class="mb-0 mt-1 small">
                <?php foreach ($rows_skipped as $s): ?>
                    <li><?= htmlspecialchars($s) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
 
    <?php if (!empty($errors)): ?>
        <div class="alert alert-warning">
            <strong>Row errors (<?= count($errors) ?>):</strong>
            <ul class="mb-0 mt-1 small">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
 
    <form action="" method="post" enctype="multipart/form-data">
 
        <div class="mb-3">
            <label for="dict_id" class="form-label fw-bold">Target Dictionary</label>
            <select class="form-select" name="dict_id" id="dict_id" required>
                <option value="" disabled selected>— Select a dictionary —</option>
                <?php foreach ($dictionaries as $d): ?>
                    <option value="<?= $d['dict_id'] ?>"
                        <?= (isset($_POST['dict_id']) && (int)$_POST['dict_id'] === $d['dict_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['name']) ?> (<?= $d['type'] ?>)
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
 
        <div class="text-danger mb-2"><?= htmlspecialchars($file_err) ?></div>
 
        <button type="submit" class="btn btn-primary" name="submit">
            <i class="fas fa-upload me-2"></i>Submit
        </button>
        <a href="index.php" class="btn btn-secondary ms-2">
            <i class="fas fa-arrow-left me-2"></i>Back
        </a>
 
    </form>
</div>
</body>
</html>