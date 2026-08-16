<?php
// ============================================================
// records.php — CRUD REST API for incidents, blotter_records,
// and settlements. Frontend calls this via fetch().
//   GET    ?type=incidents                → list (optional filters)
//   POST   ?type=incidents                → create
//   PUT    ?type=incidents&id=5           → update
//   DELETE ?type=incidents&id=5           → delete
// type: incidents | blotter | settlements
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';

$mysqli = db();
$type = $_GET['type'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') require_permission('edit_records');
if ($method === 'DELETE') require_permission('delete_records');

function zoneCoords($mysqli, string $zoneId): array {
    $stmt = $mysqli->prepare('SELECT lat, lng FROM zones WHERE zone_id = ?');
    $stmt->bind_param('s', $zoneId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return [14.883, 120.965]; // barangay centroid fallback
    $jitter = fn() => (mt_rand() / mt_getrandmax() - 0.5) * 0.0011;
    return [round($row['lat'] + $jitter(), 6), round($row['lng'] + $jitter(), 6)];
}

/**
 * Next sequential number for the year, e.g. nextSeqNo($mysqli, 'incidents',
 * 'report_no', 'INC', 4) -> "INC-2026-0007". Counts existing rows for the
 * given prefix+year so numbers are assigned in real order, not at random —
 * two entries filed the same year never collide and the sequence reads
 * naturally (0001, 0002, 0003…) instead of jumping around.
 */
require __DIR__ . '/nextseq.php';

// ---------------- INCIDENTS ----------------
if ($type === 'incidents') {
    if ($method === 'GET') {
        // Preview-only: what would the next Report No. be right now?
        // Used to show it on the "New Incident Report" form before the
        // record is actually saved, purely as a look-ahead — the real,
        // authoritative number is still generated fresh at save time
        // (below), so this never reserves or double-books a number.
        if (!empty($_GET['peek'])) {
            json_response(['seqNo' => nextSeqNo($mysqli, 'incidents', 'report_no', 'INC', 4)]);
        }
        $where = [];
        $params = []; $types = '';
        if (!empty($_GET['from'])) { $where[] = 'incident_date >= ?'; $params[] = $_GET['from']; $types .= 's'; }
        if (!empty($_GET['to']))   { $where[] = 'incident_date <= ?'; $params[] = $_GET['to'];   $types .= 's'; }
        if (!empty($_GET['zone'])) { $where[] = 'zone_id = ?'; $params[] = $_GET['zone']; $types .= 's'; }
        if (!empty($_GET['category'])) { $where[] = 'category = ?'; $params[] = $_GET['category']; $types .= 's'; }
        $sql = 'SELECT * FROM incidents' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY incident_date DESC, id DESC';
        $stmt = $mysqli->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        json_response($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        $d = body();
        $zoneId = $d['zone'] ?? 'Zone 1';
        [$lat, $lng] = (isset($d['lat'], $d['lng']) && $d['lat'] !== '' && $d['lng'] !== '')
            ? [$d['lat'], $d['lng']] : zoneCoords($mysqli, $zoneId);
        $reportNo = ($d['reportNo'] ?? '') ?: nextSeqNo($mysqli, 'incidents', 'report_no', 'INC', 4);
        $date = ($d['date'] ?? '') ?: date('Y-m-d');
        $time = ($d['timeReported'] ?? '') ?: date('H:i:s');
        if (strlen($time) === 5) $time .= ':00';
        $hour = (int)explode(':', $time)[0];

        $stmt = $mysqli->prepare(
            'INSERT INTO incidents (report_no, incident_date, time_reported, hour, zone_id, location, lat, lng, category, description, reporter, officer, priority, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $hourStr = (string)$hour; $latStr = (string)$lat; $lngStr = (string)$lng;
        $location = $d['location'] ?? '';
        $category = $d['category'] ?? 'Other';
        $desc = $d['description'] ?? '';
        $reporter = $d['reporter'] ?? '';
        $officer = $d['officer'] ?? '';
        $priority = $d['priority'] ?? 'Medium';
        $status = $d['status'] ?? 'Under Investigation';
        $stmt->bind_param('ssssssssssssss', $reportNo, $date, $time, $hourStr, $zoneId, $location, $latStr, $lngStr, $category, $desc, $reporter, $officer, $priority, $status);
        $stmt->execute();
        json_response(['ok' => true, 'id' => $mysqli->insert_id], 201);
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $d = body();
        $zoneId = $d['zone'] ?? 'Zone 1';
        [$latDef, $lngDef] = zoneCoords($mysqli, $zoneId);
        $lat = (isset($d['lat']) && $d['lat'] !== '') ? $d['lat'] : $latDef;
        $lng = (isset($d['lng']) && $d['lng'] !== '') ? $d['lng'] : $lngDef;
        $date = ($d['date'] ?? '') ?: date('Y-m-d');
        $time = ($d['timeReported'] ?? '') ?: '12:00:00';
        if (strlen($time) === 5) $time .= ':00';
        $hour = (int)explode(':', $time)[0];

        $stmt = $mysqli->prepare(
            'UPDATE incidents SET incident_date=?, time_reported=?, hour=?, zone_id=?, location=?, lat=?, lng=?, category=?, description=?, reporter=?, officer=?, priority=?, status=? WHERE id=?'
        );
        $hourStr = (string)$hour; $latStr = (string)$lat; $lngStr = (string)$lng; $idStr = (string)$id;
        $location = $d['location'] ?? ''; $category = $d['category'] ?? 'Other'; $desc = $d['description'] ?? '';
        $reporter = $d['reporter'] ?? ''; $officer = $d['officer'] ?? ''; $priority = $d['priority'] ?? 'Medium'; $status = $d['status'] ?? 'Under Investigation';
        $stmt->bind_param('sssssssssssssi', $date, $time, $hourStr, $zoneId, $location, $latStr, $lngStr, $category, $desc, $reporter, $officer, $priority, $status, $id);
        $stmt->execute();
        json_response(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM incidents WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json_response(['ok' => true]);
    }
}

// ---------------- BLOTTER ----------------
if ($type === 'blotter') {
    if ($method === 'GET') {
        // Preview-only: same look-ahead as incidents' peek above, for the
        // "New Blotter Entry" form's Docket No. field.
        if (!empty($_GET['peek'])) {
            json_response(['seqNo' => nextSeqNo($mysqli, 'blotter_records', 'docket_no', 'BLT')]);
        }
        $res = $mysqli->query('SELECT * FROM blotter_records ORDER BY date_filed DESC, id DESC');
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        $d = body();
        $complainant = $d['complainant'] ?? ''; $complainantAddr = $d['complainantAddr'] ?? '';
        $complainantId = !empty($d['complainantId']) ? (int)$d['complainantId'] : null;
        $respondent = $d['respondent'] ?? ''; $respondentAddr = $d['respondentAddr'] ?? '';
        $respondentId = !empty($d['respondentId']) ? (int)$d['respondentId'] : null;
        $nature = $d['nature'] ?? ''; $caseType = $d['type'] ?? 'CRIM'; $status = $d['status'] ?? 'Pending';
        $zoneId = $d['zone'] ?? null;
        $dateFiled = $d['dateFiled'] ?? date('Y-m-d');

        // Prefer the real linked resident id (set when picked from the
        // search bar) over a name-text match — an id is exact, while
        // matching by name text is ambiguous the moment two residents
        // share a name. Fall back to name-matching only for callers that
        // don't provide an id (e.g. older API consumers, or import rows).
        $complainantIsResident = $complainantId
            ? (bool)$mysqli->query("SELECT 1 FROM census_records WHERE id = $complainantId")->fetch_assoc()
            : isNameACensusResident($mysqli, $complainant);
        $respondentIsResident = $respondentId
            ? (bool)$mysqli->query("SELECT 1 FROM census_records WHERE id = $respondentId")->fetch_assoc()
            : isNameACensusResident($mysqli, $respondent);
        if (!$complainantIsResident && !$respondentIsResident) {
            json_error('At least one party (complainant or respondent) must be a registered resident in Census before a blotter record can be filed.');
        }
        $sameCensusPerson = $complainantId && $respondentId && $complainantId === $respondentId;
        $sameNameTyped = $complainant !== '' && $respondent !== '' && strtolower(trim($complainant)) === strtolower(trim($respondent));
        if ($sameCensusPerson || $sameNameTyped) {
            json_error('Complainant and respondent cannot be the same person.');
        }

        $docketNo = ($d['docketNo'] ?? '') ?: nextSeqNo($mysqli, 'blotter_records', 'docket_no', 'BLT');
        $stmt = $mysqli->prepare(
            'INSERT INTO blotter_records (docket_no, date_filed, complainant, complainant_id, complainant_addr, respondent, respondent_id, respondent_addr, nature, case_type, status, zone_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('sssississsss', $docketNo, $dateFiled, $complainant, $complainantId, $complainantAddr, $respondent, $respondentId, $respondentAddr, $nature, $caseType, $status, $zoneId);
        $stmt->execute();
        json_response(['ok' => true, 'id' => $mysqli->insert_id], 201);
    }
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $d = body();
        $stmt = $mysqli->prepare(
            'UPDATE blotter_records SET date_filed=?, complainant=?, complainant_id=?, complainant_addr=?, respondent=?, respondent_id=?, respondent_addr=?, nature=?, case_type=?, status=?, zone_id=? WHERE id=?'
        );
        $dateFiled = $d['dateFiled'] ?? date('Y-m-d');
        $complainant = $d['complainant'] ?? ''; $complainantAddr = $d['complainantAddr'] ?? '';
        $complainantId = !empty($d['complainantId']) ? (int)$d['complainantId'] : null;
        $respondent = $d['respondent'] ?? ''; $respondentAddr = $d['respondentAddr'] ?? '';
        $respondentId = !empty($d['respondentId']) ? (int)$d['respondentId'] : null;
        $nature = $d['nature'] ?? ''; $caseType = $d['type'] ?? 'CRIM'; $status = $d['status'] ?? 'Pending';
        $zoneId = $d['zone'] ?? null;
        $sameCensusPerson = $complainantId && $respondentId && $complainantId === $respondentId;
        $sameNameTyped = $complainant !== '' && $respondent !== '' && strtolower(trim($complainant)) === strtolower(trim($respondent));
        if ($sameCensusPerson || $sameNameTyped) {
            json_error('Complainant and respondent cannot be the same person.');
        }
        $stmt->bind_param('ssississsssi', $dateFiled, $complainant, $complainantId, $complainantAddr, $respondent, $respondentId, $respondentAddr, $nature, $caseType, $status, $zoneId, $id);
        $stmt->execute();
        json_response(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM blotter_records WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json_response(['ok' => true]);
    }
}

// ---------------- SETTLEMENTS ----------------
if ($type === 'settlements') {
    if ($method === 'GET') {
        // Preview-only: same look-ahead as incidents'/blotter's peek above,
        // for the "New Settlement Record" form's Case No. field.
        if (!empty($_GET['peek'])) {
            json_response(['seqNo' => nextSeqNo($mysqli, 'settlements', 'case_no', 'STL')]);
        }
        $res = $mysqli->query(
            'SELECT s.*, b.docket_no AS blotter_docket_no, b.complainant, b.complainant_addr,
                    b.respondent, b.respondent_addr, b.case_type AS blotter_case_type
             FROM settlements s
             JOIN blotter_records b ON b.id = s.blotter_id
             ORDER BY s.date_filed DESC, s.id DESC'
        );
        json_response($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($method === 'POST') {
        $d = body();
        $blotterId = (int)($d['blotterId'] ?? 0);
        if (!$blotterId) json_error('A settlement must be linked to an existing blotter record.');

        $blotter = $mysqli->prepare('SELECT docket_no, complainant, respondent, nature, case_type, date_filed FROM blotter_records WHERE id = ?');
        $blotter->bind_param('i', $blotterId);
        $blotter->execute();
        $b = $blotter->get_result()->fetch_assoc();
        if (!$b) json_error('That blotter record does not exist.', 404);

        $caseNo = ($d['caseNo'] ?? '') ?: nextSeqNo($mysqli, 'settlements', 'case_no', 'STL');
        $caseTitle = $b['complainant'] . ' vs. ' . $b['respondent'];
        $complaintTitle = $b['nature'];
        $nature = $b['case_type'] === 'CRIM' ? 'Criminal' : 'Civil';
        $dateFiled = $b['date_filed'];
        $dateConf = ($d['dateConfrontation'] ?? '') ?: null; $action = $d['actionTaken'] ?? '';
        $dateSettlement = ($d['dateSettlement'] ?? '') ?: null; $dateExecution = ($d['dateExecution'] ?? '') ?: null;
        $mainPoint = $d['mainPoint'] ?? ''; $status = $d['status'] ?? 'Pending';
        $remarks = $d['remarks'] ?? '';

        $stmt = $mysqli->prepare(
            'INSERT INTO settlements (blotter_id, case_no, case_title, complaint_title, nature, date_filed, date_confrontation, action_taken, date_settlement, date_execution, main_point, status, remarks)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $blotterIdStr = (string)$blotterId;
        $stmt->bind_param('sssssssssssss', $blotterIdStr, $caseNo, $caseTitle, $complaintTitle, $nature, $dateFiled, $dateConf, $action, $dateSettlement, $dateExecution, $mainPoint, $status, $remarks);
        $stmt->execute();
        json_response(['ok' => true, 'id' => $mysqli->insert_id], 201);
    }
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $d = body();
        // The blotter link itself is not editable — a settlement can't be moved to a
        // different case after creation. Only the settlement's own progress fields are.
        $stmt = $mysqli->prepare(
            'UPDATE settlements SET date_confrontation=?, action_taken=?, date_settlement=?, date_execution=?, main_point=?, status=?, remarks=? WHERE id=?'
        );
        $dateConf = ($d['dateConfrontation'] ?? '') ?: null; $action = $d['actionTaken'] ?? '';
        $dateSettlement = ($d['dateSettlement'] ?? '') ?: null; $dateExecution = ($d['dateExecution'] ?? '') ?: null;
        $mainPoint = $d['mainPoint'] ?? ''; $status = $d['status'] ?? 'Pending'; $remarks = $d['remarks'] ?? '';
        $stmt->bind_param('sssssssi', $dateConf, $action, $dateSettlement, $dateExecution, $mainPoint, $status, $remarks, $id);
        $stmt->execute();
        json_response(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error('id required');
        $stmt = $mysqli->prepare('DELETE FROM settlements WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json_response(['ok' => true]);
    }
}

json_error('Unknown type or method', 404);
