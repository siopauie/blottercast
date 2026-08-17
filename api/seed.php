<?php
// ============================================================
// seed.php — populates MySQL with a synthetic ~18-month blotter
// dataset that has real spatio-temporal structure (weekend/payday
// surges, category-specific hours, zone hotspots). This is what
// the Python ML service learns from.
//
// Run once via browser: http://localhost/blottercast/api/seed.php
// or CLI: php seed.php
// Safe to re-run — it wipes and regenerates all transactional data.
// ============================================================
require __DIR__ . '/config.php';

$mysqli = db();

// First-run setup (no users table populated yet) is allowed without a
// session, since there's no account to log in with at that point. Once
// accounts exist, resetting the demo dataset requires System Settings
// permission (System Admin / Barangay Captain) so any signed-in user
// can't casually wipe the database from a script or crafted request.
$existingUsers = (int)($mysqli->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'] ?? 0);
if ($existingUsers > 0) {
    require_login();
    require __DIR__ . '/permissions.php';
    require_permission('system_settings');
}

// ---- deterministic PRNG (mulberry32) so the dataset is reproducible ----
class Rng {
    private int $a;
    public function __construct(int $seed) { $this->a = $seed & 0xFFFFFFFF; }
    public function next(): float {
        $this->a = ($this->a + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->a;
        $t = (($t ^ ($t >> 15)) * (1 | $t)) & 0xFFFFFFFF;
        $t = ($t + ((($t ^ ($t >> 7)) * (61 | $t)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $t ^= $t + ((($t ^ ($t >> 7)) * (61 | $t)) & 0xFFFFFFFF);
        $r = ($t ^ ($t >> 14)) & 0xFFFFFFFF;
        return $r / 4294967296.0;
    }
}
// Use PHP's own well-tested mt_rand with a fixed seed instead — simpler & reliable
mt_srand(20260707);
function rnd(): float { return mt_rand() / mt_getrandmax(); }
function pick(array $arr) { return $arr[array_rand($arr)]; }
function weightedPick(array $weights) {
    $total = array_sum($weights);
    $r = rnd() * $total;
    foreach ($weights as $k => $w) { $r -= $w; if ($r <= 0) return $k; }
    return array_key_first($weights);
}

$ZONES = [
    'Zone 1' => ['lat'=>14.8836,'lng'=>120.9655,'weight'=>0.20],
    'Zone 2' => ['lat'=>14.8824,'lng'=>120.9648,'weight'=>0.11],
    'Zone 3' => ['lat'=>14.8845,'lng'=>120.9663,'weight'=>0.18],
    'Zone 4' => ['lat'=>14.8818,'lng'=>120.9660,'weight'=>0.06],
    'Zone 5' => ['lat'=>14.8852,'lng'=>120.9650,'weight'=>0.10],
    'Zone 6' => ['lat'=>14.8830,'lng'=>120.9636,'weight'=>0.05],
    'Zone 7' => ['lat'=>14.8842,'lng'=>120.9641,'weight'=>0.16],
    'Zone 8' => ['lat'=>14.8826,'lng'=>120.9670,'weight'=>0.14],
];
$CATEGORIES = ['Physical Assault','Theft','Domestic Dispute','Vandalism','Trespassing','Drug-Related Activity','Public Disturbance','Other'];
$CAT_BASE = ['Physical Assault'=>.20,'Theft'=>.18,'Domestic Dispute'=>.16,'Vandalism'=>.08,'Trespassing'=>.06,'Drug-Related Activity'=>.10,'Public Disturbance'=>.15,'Other'=>.07];
$ZONE_CAT_BIAS = [
    'Zone 1' => ['Physical Assault'=>4.0,'Public Disturbance'=>2.0,'Theft'=>0.4,'Drug-Related Activity'=>0.3],
    'Zone 3' => ['Drug-Related Activity'=>5.0,'Theft'=>3.0,'Domestic Dispute'=>0.3,'Physical Assault'=>0.5],
    'Zone 7' => ['Theft'=>3.5,'Public Disturbance'=>2.5,'Domestic Dispute'=>0.3,'Drug-Related Activity'=>0.3],
    'Zone 5' => ['Domestic Dispute'=>4.5,'Physical Assault'=>0.4,'Theft'=>0.4],
    'Zone 8' => ['Vandalism'=>4.0,'Theft'=>2.0,'Domestic Dispute'=>0.4],
    'Zone 2' => ['Domestic Dispute'=>3.0,'Trespassing'=>2.0,'Drug-Related Activity'=>0.3],
    'Zone 4' => ['Trespassing'=>3.0,'Vandalism'=>2.0],
    'Zone 6' => ['Other'=>2.5,'Domestic Dispute'=>2.0],
];
function hourProfile(string $cat): array {
    $night   = [4,2,1,0,0,0,0,0,0,0,0,0,0,0,0,0,1,2,4,7,11,14,14,8];
    $day     = [0,0,0,0,0,1,2,6,9,10,10,9,7,9,11,11,9,6,4,2,1,0,0,0];
    $evening = [1,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,5,8,12,14,12,8,5,2];
    $late    = [10,8,6,4,2,1,0,0,0,0,0,0,0,0,0,0,0,1,2,4,6,9,13,14];
    switch ($cat) {
        case 'Physical Assault': case 'Public Disturbance': return $night;
        case 'Theft': case 'Trespassing': return $day;
        case 'Domestic Dispute': return $evening;
        case 'Drug-Related Activity': case 'Vandalism': return $late;
        default: return $day;
    }
}
$LOCATIONS = [
    'Zone 1'=>['Near Barangay Hall','Plaza frontage','Health center vicinity'],
    'Zone 2'=>['Purok 4 interior','Chapel side street','South alley'],
    'Zone 3'=>['Market area','Rizal Street stalls','Jeepney terminal'],
    'Zone 4'=>['Back road','Southeast homes','Creek-side path'],
    'Zone 5'=>['North residential cluster','Northern access road','Purok 1 corner'],
    'Zone 6'=>['West interior road','Rice field boundary','Water station area'],
    'Zone 7'=>['Basketball court','Covered court perimeter','School fence line'],
    'Zone 8'=>['East road junction','Roadside sari-sari stores','Tricycle stop'],
];
$FIRST = ['Jonald','Maria','Ana','Roberto','Luisa','Carlos','Nena','Felix','Pedro','Elena','Ramon','Josefa','Marco','Liza','Danilo','Grace','Ernesto','Cely','Bong','Aida','Rico','Tess','Noel','Vilma'];
$LAST  = ['Santos','Reyes','Garcia','Dela Cruz','Fernandez','Villanueva','Aquino','Bautista','Torres','Mendoza','Castillo','Navarro','Ramos','Lim','Cruz','Flores','Borreta','Atchacoso','Domingo','Salazar'];
$OFFICERS = ['PO1 Cruz','PO2 Lim','PO3 Ramos','Tanod Dizon','Tanod Ocampo'];
$NATURES_CRIM = ['Pag-aaway','Pananakit','Pananakot','Basag-ulo','Maling Akusasyon','Pagnanakaw'];
$NATURES_CIVIL = ['Utang','Ingay','Away Kapitbahay','Hangganan ng Lupa','Alitan sa Paupahan'];
function personName($FIRST,$LAST){ return pick($FIRST).' '.pick($LAST); }

// ---- wipe transactional tables (keep zones/users) ----
$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach (['incidents','blotter_records','settlements','ml_runs','generated_reports','audit_logs'] as $t) {
    $mysqli->query("TRUNCATE TABLE $t");
}
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

// ---- demo accounts across all roles (password shown after each) ----
$demoAccounts = [
    ['admin',    'admin123',    'Juan Dela Cruz',     'admin@mapulanglupa.gov.ph',    '0917-000-0001', 'System Admin',     'Active'],
    ['kapitan',  'kapitan123',  'Kapitan Jose Reyes',  'kapitan@mapulanglupa.gov.ph', '0917-000-0002', 'Barangay Captain', 'Active'],
    ['jdelacuz', 'officer123',  'Juan Dela Cruz II',   'jdelacruz@mapulanglupa.gov.ph','0917-000-0003', 'Desk Officer',     'Active'],
    ['msantos',  'officer123',  'Maria Santos',        'msantos@mapulanglupa.gov.ph', '0917-000-0004', 'Desk Officer',     'Active'],
    ['pencoder', 'encoder123',  'Pedro Encoder',       'pencoder@mapulanglupa.gov.ph','0917-000-0005', 'Data Encoder',     'Active'],
];
$insertUser = $mysqli->prepare(
    "INSERT INTO users (username, password, full_name, email, contact_no, role, status)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE password=VALUES(password), full_name=VALUES(full_name), email=VALUES(email), contact_no=VALUES(contact_no), role=VALUES(role), status=VALUES(status)"
);
foreach ($demoAccounts as [$username, $plainPw, $fullName, $email, $contact, $role, $status]) {
    $hash = password_hash($plainPw, PASSWORD_BCRYPT);
    $insertUser->bind_param('sssssss', $username, $hash, $fullName, $email, $contact, $role, $status);
    $insertUser->execute();
}

// ---- sample audit log rows ----
$insertAudit = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details, created_at) VALUES (?,?,?,?,?)");
$auditSeed = [
    ['admin', 'Login', 'System', 'Successful login', '-2 hours'],
    ['jdelacuz', 'Created', 'Blotter', 'New blotter entry added', '-5 hours'],
    ['msantos', 'Updated', 'Incident', 'Incident status changed', '-1 day'],
    ['kapitan', 'Exported', 'Reports', 'Incident Summary Report generated', '-2 days'],
    ['pencoder', 'Imported', 'Data', 'Demo dataset seeded', '-3 days'],
];
foreach ($auditSeed as [$user, $action, $module, $details, $when]) {
    $ts = (new DateTime())->modify($when)->format('Y-m-d H:i:s');
    $insertAudit->bind_param('sssss', $user, $action, $module, $details, $ts);
    $insertAudit->execute();
}

// ---- generate incidents over the last 545 days ----
$end = new DateTime('today');
$start = (clone $end)->modify('-545 days');
$DOW_MULT = [1=>2.35, 2=>0.45, 3=>0.40, 4=>0.45, 5=>0.75, 6=>1.90, 0=>1.35]; // PHP: 0=Sun..6=Sat -> map like JS Sun..Sat
// PHP N/w(): 0(Sun)..6(Sat); align directly with JS array index
$DOW_MULT = [0=>1.35, 1=>0.45, 2=>0.40, 3=>0.45, 4=>0.75, 5=>1.90, 6=>2.35];
$BASE_RATE = 1.45;

$insertInc = $mysqli->prepare(
    "INSERT INTO incidents (report_no, incident_date, time_reported, hour, zone_id, location, lat, lng, category, description, reporter, officer, priority, status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

$seqByYear = [];
$allIncidents = []; // keep in PHP memory for blotter/settlement derivation

for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $dow = (int)$d->format('w');
    $dom = (int)$d->format('j');
    $month = (int)$d->format('n') - 1;
    $lambda = $BASE_RATE * $DOW_MULT[$dow];
    if (in_array($dom, [15,30,31,1])) $lambda *= 1.65;
    $lambda *= 1 + 0.18 * cos(($month - 4) / 12 * 2 * M_PI);

    // Poisson draw via sequential thinning
    $L = exp(-$lambda); $p = 1.0; $n = 0;
    do { $p *= rnd(); $n++; } while ($p > $L);
    $n -= 1;

    for ($k = 0; $k < $n; $k++) {
        $zoneWeights = array_map(fn($z) => $z['weight'], $ZONES);
        $zoneId = weightedPick($zoneWeights);
        $zone = $ZONES[$zoneId];
        $catW = [];
        foreach ($CATEGORIES as $c) $catW[$c] = $CAT_BASE[$c] * ($ZONE_CAT_BIAS[$zoneId][$c] ?? 1);
        $category = weightedPick($catW);
        $hp = hourProfile($category);
        $hourWeights = array_combine(range(0,23), $hp);
        $hour = (int)weightedPick($hourWeights);
        $minute = mt_rand(0, 59);
        $jitter = fn() => (rnd() - 0.5) * 0.0011;
        $lat = round($zone['lat'] + $jitter(), 6);
        $lng = round($zone['lng'] + $jitter(), 6);

        $priority = in_array($category, ['Drug-Related Activity','Physical Assault'])
            ? (rnd() < 0.7 ? 'High' : 'Medium')
            : (rnd() < 0.25 ? 'High' : (rnd() < 0.7 ? 'Medium' : 'Low'));

        $ageDays = ($end->getTimestamp() - $d->getTimestamp()) / 86400;
        if ($ageDays < 14) {
            $status = rnd() < 0.6 ? 'Under Investigation' : 'Referred';
        } else {
            $pool = rnd() < 0.85 ? ['Resolved','Closed'] : ['Resolved','Closed','Under Investigation','Referred'];
            $status = pick($pool);
        }

        $incYear = $d->format('Y');
        $seqByYear[$incYear] = ($seqByYear[$incYear] ?? 0) + 1;
        $reportNo = 'INC-' . $incYear . '-' . str_pad((string)$seqByYear[$incYear], 4, '0', STR_PAD_LEFT);
        $timeStr = sprintf('%02d:%02d:00', $hour, $minute);
        $location = $zoneId . ', ' . pick($LOCATIONS[$zoneId]);
        $reporter = personName($FIRST, $LAST);
        $officer = pick($OFFICERS);
        $desc = "$category reported at $zoneId. Logged from barangay blotter.";
        $dateStr = $d->format('Y-m-d');

        $hourStr = (string)$hour; $latStr = (string)$lat; $lngStr = (string)$lng;
        $insertInc->bind_param('ssssssssssssss',
            $reportNo, $dateStr, $timeStr, $hourStr, $zoneId, $location, $latStr, $lngStr,
            $category, $desc, $reporter, $officer, $priority, $status
        );
        $insertInc->execute();

        $allIncidents[] = [
            'id' => $mysqli->insert_id, 'date' => $dateStr, 'zone' => $zoneId,
            'reporter' => $reporter,
        ];
    }
}

// ---- blotter book: sample every ~7th incident, newest first, cap 60 ----
$rev = array_reverse($allIncidents);
$blotterRows = [];
for ($i = 0; $i < count($rev) && count($blotterRows) < 60; $i += 7) {
    $inc = $rev[$i];
    $civil = rnd() < 0.35;
    $blotterRows[] = [
        'date' => $inc['date'],
        'complainant' => $inc['reporter'],
        'complainant_addr' => 'Blk ' . mt_rand(1,9) . ' Lot ' . mt_rand(1,9) . ', ' . $inc['zone'],
        'respondent' => personName($FIRST, $LAST),
        'respondent_addr' => 'Blk ' . mt_rand(1,9) . ' Lot ' . mt_rand(1,9) . ', ' . pick(array_keys($ZONES)),
        'nature' => $civil ? pick($NATURES_CIVIL) : pick($NATURES_CRIM),
        'case_type' => $civil ? 'CIVIL' : 'CRIM',
        'status' => pick(['Ongoing','Pending','Resolved','Resolved']),
        'zone_id' => $inc['zone'],
    ];
}
$blotterRows = array_reverse($blotterRows);
$insertBlt = $mysqli->prepare(
    "INSERT INTO blotter_records (docket_no, date_filed, complainant, complainant_addr, respondent, respondent_addr, nature, case_type, status, zone_id)
     VALUES (?,?,?,?,?,?,?,?,?,?)"
);
$bseqByYear = [];
$blotterIds = [];
foreach ($blotterRows as $b) {
    $year = substr($b['date'], 0, 4);
    $bseqByYear[$year] = ($bseqByYear[$year] ?? 0) + 1;
    $docketNo = 'BLT-' . $year . '-' . str_pad((string)$bseqByYear[$year], 3, '0', STR_PAD_LEFT);
    $insertBlt->bind_param('ssssssssss', $docketNo, $b['date'], $b['complainant'], $b['complainant_addr'],
        $b['respondent'], $b['respondent_addr'], $b['nature'], $b['case_type'], $b['status'], $b['zone_id']);
    $insertBlt->execute();
    $blotterIds[] = ['id' => $mysqli->insert_id, 'date' => $b['date'], 'complainant' => $b['complainant'],
        'respondent' => $b['respondent'], 'nature' => $b['nature'], 'case_type' => $b['case_type'], 'status' => $b['status']];
}

// ---- settlements from resolved blotter cases (cap 18) ----
// A settlement can only exist for a real blotter case, so we seed
// settlements strictly against blotterIds captured above.
$resolved = array_values(array_filter($blotterIds, fn($b) => $b['status'] === 'Resolved'));
$resolved = array_slice($resolved, 0, 18);
$insertStl = $mysqli->prepare(
    "INSERT INTO settlements (blotter_id, case_no, case_title, complaint_title, nature, date_filed, date_confrontation, action_taken, date_settlement, date_execution, main_point, status, remarks)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$sseqByYear = [];
foreach ($resolved as $b) {
    $filed = new DateTime($b['date']);
    $conf = (clone $filed)->modify('+7 days');
    $settled = rnd() < 0.7;
    $st = (clone $conf)->modify('+7 days');
    $ex = (clone $st)->modify('+5 days');
    $stlYear = $filed->format('Y');
    $sseqByYear[$stlYear] = ($sseqByYear[$stlYear] ?? 0) + 1;
    $caseNo = 'STL-' . $stlYear . '-' . str_pad((string)$sseqByYear[$stlYear], 3, '0', STR_PAD_LEFT);
    $lastComp = explode(' ', $b['complainant']); $lastComp = end($lastComp);
    $lastResp = explode(' ', $b['respondent']); $lastResp = end($lastResp);
    $caseTitle = "$lastComp vs. $lastResp";
    $nature = $b['case_type'] === 'CRIM' ? 'Criminal' : 'Civil';
    $action = pick(['Mediation (M)','Conciliation (C)','Arbitration (A)']);
    $dateSettlement = $settled ? $st->format('Y-m-d') : null;
    $dateExecution = $settled ? $ex->format('Y-m-d') : null;
    $mainPoint = $settled
        ? 'Parties reached an amicable settlement; terms recorded in the Katarungang Pambarangay logbook.'
        : 'Parties still in discussion. Follow-up hearing scheduled.';
    $status = $settled ? (rnd() < 0.8 ? 'Complied' : 'Not Complied') : 'Pending';
    $remarks = $status === 'Not Complied' ? 'Referred to Lupong Tagapamayapa for further action.' : '';
    $confStr = $conf->format('Y-m-d'); $filedStr = $filed->format('Y-m-d');
    $blotterIdStr = (string)$b['id'];
    $insertStl->bind_param('sssssssssssss', $blotterIdStr, $caseNo, $caseTitle, $b['nature'], $nature, $filedStr, $confStr,
        $action, $dateSettlement, $dateExecution, $mainPoint, $status, $remarks);
    $insertStl->execute();
}

$incCount = $mysqli->query('SELECT COUNT(*) c FROM incidents')->fetch_assoc()['c'];
$bltCount = $mysqli->query('SELECT COUNT(*) c FROM blotter_records')->fetch_assoc()['c'];
$stlCount = $mysqli->query('SELECT COUNT(*) c FROM settlements')->fetch_assoc()['c'];

$result = ['ok' => true, 'incidents' => (int)$incCount, 'blotter' => (int)$bltCount, 'settlements' => (int)$stlCount,
    'demoAccounts' => array_map(fn($a) => ['username' => $a[0], 'password' => $a[1], 'role' => $a[5]], $demoAccounts)];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} else {
    json_response($result);
}
