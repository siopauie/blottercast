<?php
// ============================================================
// documents.php — CRUD REST API for the recording/monitoring
// document modules (Census, Barangay Clearance, Certificate of
// Indigency). No ML/analytics — record and monitor only, per
// the thesis's stated system scope.
//   GET    ?type=census|clearance|indigency
//   POST   ?type=...
//   PUT    ?type=...&id=5
//   DELETE ?type=...&id=5
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';

$mysqli = db();
$type = $_GET['type'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') require_permission('edit_records');
if ($method === 'DELETE') require_permission('delete_records');

// Age in whole years as of today, from a 'YYYY-MM-DD' date of birth string.
// Returns null if $dob is empty/unparseable (age-based comparisons are then
// skipped rather than treating everyone with a missing DOB as duplicates).
function computeAge(?string $dob): ?int {
    if (!$dob) return null;
    try {
        $d = new DateTime($dob);
        $now = new DateTime();
        if ($d > $now) return null;
        return (int)$d->diff($now)->y;
    } catch (Exception $e) {
        return null;
    }
}

function nextCtrlNo($mysqli, string $table, string $prefix): string {
    $year = date('Y');
    $stmt = $mysqli->prepare("SELECT ctrl_no FROM $table WHERE ctrl_no LIKE ? ORDER BY ctrl_no DESC LIMIT 1");
    $like = "$prefix-$year-%";
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $n = 1;
    if ($row) {
        $parts = explode('-', $row['ctrl_no']);
        $n = (int)end($parts) + 1;
    }
    $checkStmt = $mysqli->prepare("SELECT 1 FROM $table WHERE ctrl_no = ?");
    while (true) {
        $candidate = sprintf('%s-%s-%03d', $prefix, $year, $n);
        $checkStmt->bind_param('s', $candidate);
        $checkStmt->execute();
        if (!$checkStmt->get_result()->fetch_assoc()) return $candidate;
        $n++;
    }
}

/**
 * Next O.R. (Official Receipt) number, e.g. "OR-2026-001". Shared across
 * Clearance, Certificate of Residency, and Certificate of Non-Residency
 * (the three fee-based documents) rather than kept separate per type,
 * since in practice these all draw from the same barangay treasurer's
 * receipt booklet — one continuous sequence, not one per document type.
 * Indigency has no O.R. number because it's issued free of charge.
 * Same highest-existing-number + collision-guard approach as
 * nextSeqNo()/nextCtrlNo() above, just checked across all three tables.
 */
function nextOrNo($mysqli): string {
    $orTables = ['barangay_clearance', 'barangay_residency', 'barangay_non_residency'];
    $year = date('Y');
    $like = "OR-$year-%";
    $n = 1;
    foreach ($orTables as $table) {
        $stmt = $mysqli->prepare("SELECT or_no FROM $table WHERE or_no LIKE ? ORDER BY or_no DESC LIMIT 1");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $parts = explode('-', $row['or_no']);
            $candidateN = (int)end($parts) + 1;
            if ($candidateN > $n) $n = $candidateN;
        }
    }
    while (true) {
        $candidate = sprintf('OR-%s-%03d', $year, $n);
        $taken = false;
        foreach ($orTables as $table) {
            $checkStmt = $mysqli->prepare("SELECT 1 FROM $table WHERE or_no = ?");
            $checkStmt->bind_param('s', $candidate);
            $checkStmt->execute();
            if ($checkStmt->get_result()->fetch_assoc()) { $taken = true; break; }
        }
        if (!$taken) return $candidate;
        $n++;
    }
}

function logAudit($mysqli, string $action, string $module, string $details): void {
    $user = $_SESSION['username'] ?? 'system';
    $stmt = $mysqli->prepare('INSERT INTO audit_logs (username, action, module, details) VALUES (?,?,?,?)');
    $stmt->bind_param('ssss', $user, $action, $module, $details);
    $stmt->execute();
}

// ---------------- O.R. NUMBER PEEK (used by Clearance/Residency/Non-Residency forms) ----------------
// Preview-only look-ahead at the next O.R. No., same pattern as the
// Docket No. peek in records.php — shown on the "Issue New…" form before
// the record is actually saved. Doesn't reserve the number; the real one
// is generated fresh again at save time in each POST handler below.
if ($type === 'or_peek' && $method === 'GET') {
    json_response(['orNo' => nextOrNo($mysqli)]);
}

// ---------------- BLOTTER CHECK (used before issuing Clearance/Indigency) ----------------
// Anyone who can reach this file at all (i.e. is logged in) can run this
// check — it's read-only and the whole point is to surface it to the
// same Desk Officer / Encoder who is about to issue the document, not
// just Admin/Captain, so it's not gated behind an extra permission.
if ($type === 'blotter_check' && $method === 'GET') {
    $lastName = trim($_GET['lastName'] ?? '');
    $firstName = trim($_GET['firstName'] ?? '');
    $residentId = !empty($_GET['residentId']) ? (int)$_GET['residentId'] : null;
    $hasName = $lastName !== '' && $firstName !== '';
    if (!$hasName && !$residentId) json_response([]);

    if ($residentId) {
        // Exact match: blotter_records.complainant_id/respondent_id now
        // stores the real census_records.id (set when the blotter entry's
        // complainant/respondent was picked from the search bar), so this
        // is precise even when two residents share the same name — the
        // fuzzy name-text match below is only a fallback for blotter
        // entries saved before that linkage existed.
        $stmt = $mysqli->prepare(
            "SELECT id, docket_no, date_filed, complainant, respondent, nature, case_type, status,
                    (complainant_id = ?) AS is_complainant
             FROM blotter_records
             WHERE complainant_id = ? OR respondent_id = ?
             ORDER BY date_filed DESC"
        );
        $stmt->bind_param('iii', $residentId, $residentId, $residentId);
        $stmt->execute();
        $idMatches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($idMatches as &$r) {
            $r['role'] = $r['is_complainant'] ? 'Complainant' : 'Respondent';
            unset($r['is_complainant']);
        }
        unset($r);
    } else {
        $idMatches = [];
    }

    // Fall back to a name-text match too, for older blotter entries saved
    // before complainant_id/respondent_id existed (free text only, no
    // link) — but only add rows not already found by exact ID above, so a
    // linked entry never shows up twice.
    $nameMatches = [];
    if ($hasName) {
        $stmt = $mysqli->prepare(
            "SELECT id, docket_no, date_filed, complainant, respondent, nature, case_type, status
             FROM blotter_records
             WHERE ((complainant LIKE ? AND complainant LIKE ?) OR (respondent LIKE ? AND respondent LIKE ?))
               AND complainant_id IS NULL AND respondent_id IS NULL"
        );
        $lastLike = "%$lastName%"; $firstLike = "%$firstName%";
        $stmt->bind_param('ssss', $lastLike, $firstLike, $lastLike, $firstLike);
        $stmt->execute();
        $nameMatches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($nameMatches as &$r) {
            $r['role'] = (stripos($r['complainant'], $lastName) !== false && stripos($r['complainant'], $firstName) !== false)
                ? 'Complainant' : 'Respondent';
        }
        unset($r);
    }

    json_response(array_merge($idMatches, $nameMatches));
}

// ---------------- CENSUS ----------------
if ($type === 'census') {
    if ($method === 'GET') {
        $res = $mysqli->query('SELECT * FROM census_records ORDER BY last_name, first_name');
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        if (!empty($_SERVER['HTTP_X_BULK_IMPORT'])) require_permission('import_data');
        $d = body();
        $dob = ($d['dob'] ?? '') ?: null;
        $lastName = $d['lastName'] ?? ''; $firstName = $d['firstName'] ?? ''; $middleName = $d['middleName'] ?? '';
        $sex = $d['sex'] ?? 'Male'; $civilStatus = $d['civilStatus'] ?? 'Single'; $nationality = ($d['nationality'] ?? '') ?: 'Filipino'; $zone = $d['zone'] ?? null;
        $address = $d['address'] ?? ''; $household = $d['householdNo'] ?? ''; $contact = $d['contactNo'] ?? '';
        $voter = $d['voterStatus'] ?? 'Not Registered'; $occupation = $d['occupation'] ?? ''; $status = $d['status'] ?? 'Active';
        // A deceased resident can't remain an active/registered voter, so
        // this is forced server-side regardless of what the client sent —
        // not just left to the UI to grey out.
        if ($status === 'Deceased') $voter = 'Deactivated';

        // Reject an exact duplicate resident: same name, address, household
        // number, age (derived from date of birth), and sex. Age is compared
        // rather than raw date of birth since that's what's asked for, and
        // it also tolerates same-birthday-different-year data-entry variance
        // being treated as distinct people.
        $age = computeAge($dob);
        if ($age !== null) {
            $dupStmt = $mysqli->prepare(
                'SELECT id FROM census_records
                 WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(COALESCE(middle_name, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                   AND LOWER(TRIM(COALESCE(address, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                   AND LOWER(TRIM(COALESCE(household_no, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                   AND sex = ?
                   AND date_of_birth IS NOT NULL
                   AND TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) = ?
                 LIMIT 1'
            );
            $dupStmt->bind_param('ssssssi', $lastName, $firstName, $middleName, $address, $household, $sex, $age);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                json_error('A resident with the same name, address, household number, age, and sex is already in Census.', 409);
            }
        }

        // Resident numbers must never repeat, even after older ones are
        // deleted, so this is based on the highest RES-#### number ever used
        // (not COUNT(*), which shrinks after a delete and reissues an
        // already-used number -> UNIQUE constraint violation on the next add).
        $maxRow = $mysqli->query(
            "SELECT MAX(CAST(SUBSTRING(resident_no, 5) AS INTEGER)) m FROM census_records WHERE resident_no ~ '^RES-[0-9]+$'"
        )->fetch_assoc();
        $nextNum = (int)($maxRow['m'] ?? 0) + 1;
        $residentNo = ($d['residentNo'] ?? '') ?: sprintf('RES-%04d', $nextNum);
        $stmt = $mysqli->prepare(
            'INSERT INTO census_records (resident_no, last_name, first_name, middle_name, date_of_birth, sex, civil_status, nationality, zone_id, address, household_no, contact_no, voter_status, occupation, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('sssssssssssssss', $residentNo, $lastName, $firstName, $middleName, $dob, $sex, $civilStatus, $nationality, $zone, $address, $household, $contact, $voter, $occupation, $status);
        $stmt->execute();
        $newId = $mysqli->insert_id;
        logAudit($mysqli, 'Created', 'Census', "New resident recorded: $firstName $lastName");
        json_response(['ok' => true, 'id' => $newId], 201);
    }
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $d = body();
        $dob = ($d['dob'] ?? '') ?: null;
        $lastName = $d['lastName'] ?? ''; $firstName = $d['firstName'] ?? ''; $middleName = $d['middleName'] ?? '';
        $sex = $d['sex'] ?? 'Male'; $civilStatus = $d['civilStatus'] ?? 'Single'; $nationality = ($d['nationality'] ?? '') ?: 'Filipino'; $zone = $d['zone'] ?? null;
        $address = $d['address'] ?? ''; $household = $d['householdNo'] ?? ''; $contact = $d['contactNo'] ?? '';
        $voter = $d['voterStatus'] ?? 'Not Registered'; $occupation = $d['occupation'] ?? ''; $status = $d['status'] ?? 'Active';

        // Desk Officer can only change a resident's Status — every other
        // field is reset back to its existing DB value here regardless of
        // what the request body contains, so the UI's read-only fields
        // can't be worked around with a direct API call.
        if (($_SESSION['role'] ?? '') === 'Desk Officer') {
            $existing = $mysqli->prepare('SELECT * FROM census_records WHERE id = ?');
            $existing->bind_param('i', $id);
            $existing->execute();
            $cur = $existing->get_result()->fetch_assoc();
            if (!$cur) json_error('Resident not found.', 404);
            $lastName = $cur['last_name']; $firstName = $cur['first_name']; $middleName = $cur['middle_name'];
            $dob = $cur['date_of_birth']; $sex = $cur['sex']; $civilStatus = $cur['civil_status'];
            $nationality = $cur['nationality']; $zone = $cur['zone_id']; $address = $cur['address'];
            $household = $cur['household_no']; $contact = $cur['contact_no']; $voter = $cur['voter_status'];
            $occupation = $cur['occupation'];
        }
        // A deceased resident can't remain an active/registered voter, so
        // this is forced server-side regardless of what the client sent —
        // not just left to the UI to grey out.
        if ($status === 'Deceased') $voter = 'Deactivated';

        // Same duplicate check as create, excluding this record itself so
        // editing a resident's own unrelated fields doesn't false-positive.
        $age = computeAge($dob);
        if ($age !== null) {
            $dupStmt = $mysqli->prepare(
                'SELECT id FROM census_records
                 WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(COALESCE(middle_name, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                   AND LOWER(TRIM(COALESCE(address, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                   AND LOWER(TRIM(COALESCE(household_no, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                   AND sex = ?
                   AND date_of_birth IS NOT NULL
                   AND TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) = ?
                   AND id != ?
                 LIMIT 1'
            );
            $dupStmt->bind_param('ssssssii', $lastName, $firstName, $middleName, $address, $household, $sex, $age, $id);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                json_error('Another resident with the same name, address, household number, age, and sex is already in Census.', 409);
            }
        }

        $stmt = $mysqli->prepare(
            'UPDATE census_records SET last_name=?, first_name=?, middle_name=?, date_of_birth=?, sex=?, civil_status=?, nationality=?, zone_id=?, address=?, household_no=?, contact_no=?, voter_status=?, occupation=?, status=? WHERE id=?'
        );
        $stmt->bind_param('ssssssssssssssi', $lastName, $firstName, $middleName, $dob, $sex, $civilStatus, $nationality, $zone, $address, $household, $contact, $voter, $occupation, $status, $id);
        $stmt->execute();
        logAudit($mysqli, 'Updated', 'Census', "Resident record #$id updated");
        json_response(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM census_records WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        logAudit($mysqli, 'Deleted', 'Census', "Resident record #$id deleted");
        json_response(['ok' => true]);
    }
}

// ---------------- BARANGAY CLEARANCE ----------------
if ($type === 'clearance') {
    if ($method === 'GET') {
        $res = $mysqli->query(
            'SELECT c.*, r.resident_no, r.last_name AS resident_last_name, r.first_name AS resident_first_name
             FROM barangay_clearance c
             JOIN census_records r ON r.id = c.resident_id
             ORDER BY c.date_issued DESC, c.id DESC'
        );
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        $d = body();
        $residentId = (int)($d['residentId'] ?? 0);
        if (!$residentId) json_error('A clearance must be issued to an existing census resident.');

        $resident = $mysqli->prepare('SELECT last_name, first_name, middle_name, date_of_birth, civil_status, address, voter_status FROM census_records WHERE id = ?');
        $resident->bind_param('i', $residentId);
        $resident->execute();
        $res = $resident->get_result()->fetch_assoc();
        if (!$res) json_error('That resident does not exist in Census.', 404);

        $ctrlNo = ($d['ctrlNo'] ?? '') ?: nextCtrlNo($mysqli, 'barangay_clearance', 'BC');
        $fullName = trim($res['last_name'] . ', ' . $res['first_name'] . ' ' . ($res['middle_name'] ?? ''));
        $age = null;
        if (!empty($res['date_of_birth'])) {
            $age = (new DateTime($res['date_of_birth']))->diff(new DateTime())->y;
        }
        $civilStatus = $res['civil_status']; $address = $res['address']; $voterStatus = $res['voter_status'];
        $purpose = $d['purpose'] ?? ''; $orNo = ($d['orNo'] ?? '') ?: nextOrNo($mysqli);
        $fee = ($d['fee'] ?? '') ?: 20.00; $dateIssued = ($d['dateIssued'] ?? '') ?: date('Y-m-d');
        $issuedBy = $_SESSION['full_name'] ?? 'System';
        $ageStr = $age === null ? null : (string)$age;
        $residentIdStr = (string)$residentId;

        $stmt = $mysqli->prepare(
            'INSERT INTO barangay_clearance (resident_id, ctrl_no, full_name, age, civil_status, address, voter_status, purpose, or_no, fee, date_issued, issued_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('ssssssssssss', $residentIdStr, $ctrlNo, $fullName, $ageStr, $civilStatus, $address, $voterStatus, $purpose, $orNo, $fee, $dateIssued, $issuedBy);
        $stmt->execute();
        $newId = $mysqli->insert_id;
        logAudit($mysqli, 'Created', 'Clearance', "Clearance issued: $ctrlNo for $fullName");
        json_response(['ok' => true, 'id' => $newId, 'ctrlNo' => $ctrlNo, 'orNo' => $orNo], 201);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM barangay_clearance WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        logAudit($mysqli, 'Deleted', 'Clearance', "Clearance record #$id deleted");
        json_response(['ok' => true]);
    }
}

// ---------------- CERTIFICATE OF RESIDENCY ----------------
if ($type === 'residency') {
    if ($method === 'GET') {
        $res = $mysqli->query(
            'SELECT c.*, r.resident_no, r.last_name AS resident_last_name, r.first_name AS resident_first_name, r.voter_status
             FROM barangay_residency c
             JOIN census_records r ON r.id = c.resident_id
             ORDER BY c.date_issued DESC, c.id DESC'
        );
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        $d = body();
        $residentId = (int)($d['residentId'] ?? 0);
        if (!$residentId) json_error('A certificate of residency must be issued to an existing census resident.');

        $resident = $mysqli->prepare('SELECT last_name, first_name, middle_name, date_of_birth, civil_status, address FROM census_records WHERE id = ?');
        $resident->bind_param('i', $residentId);
        $resident->execute();
        $res = $resident->get_result()->fetch_assoc();
        if (!$res) json_error('That resident does not exist in Census.', 404);

        $ctrlNo = ($d['ctrlNo'] ?? '') ?: nextCtrlNo($mysqli, 'barangay_residency', 'BR');
        $fullName = trim($res['last_name'] . ', ' . $res['first_name'] . ' ' . ($res['middle_name'] ?? ''));
        $age = null;
        if (!empty($res['date_of_birth'])) {
            $age = (new DateTime($res['date_of_birth']))->diff(new DateTime())->y;
        }
        $civilStatus = $res['civil_status']; $address = $res['address'];
        $yearsResidency = ($d['yearsResidency'] ?? '') !== '' ? (int)$d['yearsResidency'] : null;
        $durationUnit = ($d['durationUnit'] ?? '') === 'months' ? 'months' : 'years';
        if ($durationUnit === 'months' && $yearsResidency !== null && ($yearsResidency < 2 || $yearsResidency > 11)) {
            json_error('Months of residency must be between 2 and 11 (11 months and up should be issued in years).');
        }
        $purpose = $d['purpose'] ?? ''; $orNo = ($d['orNo'] ?? '') ?: nextOrNo($mysqli);
        $fee = ($d['fee'] ?? '') ?: 20.00; $dateIssued = ($d['dateIssued'] ?? '') ?: date('Y-m-d');
        $issuedBy = $_SESSION['full_name'] ?? 'System';
        $ageStr = $age === null ? null : (string)$age;
        $yearsStr = $yearsResidency === null ? null : (string)$yearsResidency;
        $residentIdStr = (string)$residentId;

        $stmt = $mysqli->prepare(
            'INSERT INTO barangay_residency (resident_id, ctrl_no, full_name, age, civil_status, address, years_residency, duration_unit, purpose, or_no, fee, date_issued, issued_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('sssssssssssss', $residentIdStr, $ctrlNo, $fullName, $ageStr, $civilStatus, $address, $yearsStr, $durationUnit, $purpose, $orNo, $fee, $dateIssued, $issuedBy);
        $stmt->execute();
        $newId = $mysqli->insert_id;
        logAudit($mysqli, 'Created', 'Residency', "Certificate of Residency issued: $ctrlNo for $fullName");
        json_response(['ok' => true, 'id' => $newId, 'ctrlNo' => $ctrlNo, 'orNo' => $orNo], 201);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM barangay_residency WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        logAudit($mysqli, 'Deleted', 'Residency', "Certificate of Residency record #$id deleted");
        json_response(['ok' => true]);
    }
}

// ---------------- CERTIFICATE OF NON-RESIDENCY ----------------
if ($type === 'non_residency') {
    if ($method === 'GET') {
        $res = $mysqli->query(
            'SELECT c.*, r.resident_no, r.last_name AS resident_last_name, r.first_name AS resident_first_name, r.voter_status
             FROM barangay_non_residency c
             JOIN census_records r ON r.id = c.resident_id
             ORDER BY c.date_issued DESC, c.id DESC'
        );
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        $d = body();
        $residentId = (int)($d['residentId'] ?? 0);
        if (!$residentId) json_error('A certificate of non-residency must reference an existing Census record.');

        $resident = $mysqli->prepare('SELECT last_name, first_name, middle_name FROM census_records WHERE id = ?');
        $resident->bind_param('i', $residentId);
        $resident->execute();
        $res = $resident->get_result()->fetch_assoc();
        if (!$res) json_error('That person does not exist in Census.', 404);

        $ctrlNo = ($d['ctrlNo'] ?? '') ?: nextCtrlNo($mysqli, 'barangay_non_residency', 'NR');
        $fullName = trim($res['last_name'] . ', ' . $res['first_name'] . ' ' . ($res['middle_name'] ?? ''));
        $previousAddress = $d['previousAddress'] ?? '';
        $purpose = $d['purpose'] ?? ''; $orNo = ($d['orNo'] ?? '') ?: nextOrNo($mysqli);
        $fee = ($d['fee'] ?? '') ?: 20.00; $dateIssued = ($d['dateIssued'] ?? '') ?: date('Y-m-d');
        $issuedBy = $_SESSION['full_name'] ?? 'System';
        $residentIdStr = (string)$residentId;

        $stmt = $mysqli->prepare(
            'INSERT INTO barangay_non_residency (resident_id, ctrl_no, full_name, previous_address, purpose, or_no, fee, date_issued, issued_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('sssssssss', $residentIdStr, $ctrlNo, $fullName, $previousAddress, $purpose, $orNo, $fee, $dateIssued, $issuedBy);
        $stmt->execute();
        $newId = $mysqli->insert_id;
        logAudit($mysqli, 'Created', 'NonResidency', "Certificate of Non-Residency issued: $ctrlNo for $fullName");
        json_response(['ok' => true, 'id' => $newId, 'ctrlNo' => $ctrlNo, 'orNo' => $orNo], 201);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM barangay_non_residency WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        logAudit($mysqli, 'Deleted', 'NonResidency', "Certificate of Non-Residency record #$id deleted");
        json_response(['ok' => true]);
    }
}

// ---------------- INDIGENCY ----------------
if ($type === 'indigency') {
    if ($method === 'GET') {
        $res = $mysqli->query(
            'SELECT c.*, r.resident_no, r.last_name AS resident_last_name, r.first_name AS resident_first_name
             FROM indigency_certificates c
             JOIN census_records r ON r.id = c.resident_id
             ORDER BY c.date_issued DESC, c.id DESC'
        );
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        $d = body();
        $residentId = (int)($d['residentId'] ?? 0);
        if (!$residentId) json_error('A certificate must be issued to an existing census resident.');

        $resident = $mysqli->prepare('SELECT last_name, first_name, middle_name, date_of_birth, civil_status, address FROM census_records WHERE id = ?');
        $resident->bind_param('i', $residentId);
        $resident->execute();
        $res = $resident->get_result()->fetch_assoc();
        if (!$res) json_error('That resident does not exist in Census.', 404);

        $ctrlNo = ($d['ctrlNo'] ?? '') ?: nextCtrlNo($mysqli, 'indigency_certificates', 'CI');
        $fullName = trim($res['last_name'] . ', ' . $res['first_name'] . ' ' . ($res['middle_name'] ?? ''));
        $age = null;
        if (!empty($res['date_of_birth'])) {
            $age = (new DateTime($res['date_of_birth']))->diff(new DateTime())->y;
        }
        $civilStatus = $res['civil_status']; $address = $res['address'];
        $purpose = $d['purpose'] ?? ''; $dateIssued = ($d['dateIssued'] ?? '') ?: date('Y-m-d');
        $issuedBy = $_SESSION['full_name'] ?? 'System';
        $ageStr = $age === null ? null : (string)$age;
        $residentIdStr = (string)$residentId;

        $stmt = $mysqli->prepare(
            'INSERT INTO indigency_certificates (resident_id, ctrl_no, full_name, age, civil_status, address, purpose, date_issued, issued_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('sssssssss', $residentIdStr, $ctrlNo, $fullName, $ageStr, $civilStatus, $address, $purpose, $dateIssued, $issuedBy);
        $stmt->execute();
        $newId = $mysqli->insert_id;
        logAudit($mysqli, 'Created', 'Indigency', "Certificate issued: $ctrlNo for $fullName");
        json_response(['ok' => true, 'id' => $newId, 'ctrlNo' => $ctrlNo], 201);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM indigency_certificates WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        logAudit($mysqli, 'Deleted', 'Indigency', "Certificate record #$id deleted");
        json_response(['ok' => true]);
    }
}

json_error('Unknown type or method', 404);
