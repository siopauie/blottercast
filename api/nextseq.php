<?php
// ============================================================
// nextseq.php — shared sequential-number generator used for
// docket numbers, report numbers, and case numbers. Extracted
// out of records.php so blotter_import.php can use the exact
// same logic rather than duplicating it.
// ============================================================

if (!function_exists('nextSeqNo')) {
    /**
     * Next sequential number for the year, e.g. nextSeqNo($mysqli, 'incidents',
     * 'report_no', 'INC', 4) -> "INC-2026-0007". Based on the highest existing
     * number for that prefix+year, not a row count — a plain COUNT(*)+1
     * collides the moment the sequence has any gap (e.g. a deleted row, or
     * seed data that doesn't start each year at 001), since a missing number
     * makes the count one lower than the true highest number in use.
     */
    function nextSeqNo($mysqli, string $table, string $column, string $prefix, int $digits = 3): string {
        $year = date('Y');
        $stmt = $mysqli->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
        $like = "$prefix-$year-%";
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $n = 1;
        if ($row) {
            $parts = explode('-', $row[$column]);
            $n = (int)end($parts) + 1;
        }
        // Guard against a collision even after this (e.g. a concurrent insert
        // between the SELECT above and the INSERT that uses this number) by
        // advancing past any number that's already taken.
        $checkStmt = $mysqli->prepare("SELECT 1 FROM $table WHERE $column = ?");
        while (true) {
            $candidate = sprintf('%s-%s-%0' . $digits . 'd', $prefix, $year, $n);
            $checkStmt->bind_param('s', $candidate);
            $checkStmt->execute();
            if (!$checkStmt->get_result()->fetch_assoc()) return $candidate;
            $n++;
        }
    }
}

if (!function_exists('isNameACensusResident')) {
    /**
     * Does this free-text name match an existing Census resident? Blotter
     * entries store complainant/respondent as plain text rather than a
     * resident_id foreign key (unlike Clearance/Indigency/Residency, which
     * require a real linked resident), so this checks "does a resident
     * exist whose last name AND first name both appear in this string" —
     * the same tolerant match used by the blotter-record warning shown
     * before issuing a Clearance/Indigency/Residency certificate.
     */
    function isNameACensusResident($mysqli, string $name): bool {
        $name = trim($name);
        if ($name === '') return false;
        $stmt = $mysqli->prepare('SELECT last_name, first_name FROM census_records');
        $stmt->execute();
        $residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($residents as $r) {
            if (stripos($name, $r['last_name']) !== false && stripos($name, $r['first_name']) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('findCensusResidentIdByName')) {
    /**
     * Same tolerant match as isNameACensusResident(), but returns the
     * resident's id when there's exactly one match, or null otherwise
     * (no match, or more than one same-named resident — in the ambiguous
     * case we deliberately don't guess which one, and the row is saved
     * with the id left blank rather than silently picking one of them).
     */
    function findCensusResidentIdByName($mysqli, string $name): ?int {
        $name = trim($name);
        if ($name === '') return null;
        $stmt = $mysqli->prepare('SELECT id, last_name, first_name FROM census_records');
        $stmt->execute();
        $residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $matches = array_filter($residents, fn($r) =>
            stripos($name, $r['last_name']) !== false && stripos($name, $r['first_name']) !== false
        );
        return count($matches) === 1 ? (int)array_values($matches)[0]['id'] : null;
    }
}
