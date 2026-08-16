<?php
// ============================================================
// xlsx_reader.php — a minimal, dependency-free .xlsx reader.
// Reads the OOXML spreadsheet format directly (a zip of XML
// parts) using PHP's built-in ZipArchive + SimpleXML, so no
// Composer/PhpSpreadsheet install is needed — the read-side
// counterpart to xlsx_writer.php, for importing the same
// official-form spreadsheets that get exported from this app.
// ============================================================

class SimpleXlsxReader {
    /**
     * Read the first sheet of $path and return it as an array of rows,
     * each row an array of cell strings (empty string for blank cells).
     * Rows are 0-indexed and padded so every row has the same column
     * count as the widest row seen.
     */
    public static function read(string $path): array {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the file as a .xlsx workbook.');
        }

        // Shared strings: text cells reference an index into this table
        // rather than storing the text inline, per the OOXML spec.
        $sharedStrings = [];
        $sstXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sstXml !== false) {
            $sst = @simplexml_load_string($sstXml);
            if ($sst !== false) {
                foreach ($sst->si as $si) {
                    // A shared string can be plain <t> or split across
                    // multiple <r><t> runs (rich text) — concatenate all of it.
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } else {
                        $text = '';
                        foreach ($si->r as $run) $text .= (string)$run->t;
                        $sharedStrings[] = $text;
                    }
                }
            }
        }

        // Find the first sheet's path via workbook.xml + the rels file,
        // rather than assuming sheet1.xml (robust to however the workbook
        // was actually saved, e.g. by real Excel vs. this app's own writer).
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml !== false && $relsXml !== false) {
            $wb = @simplexml_load_string($wbXml);
            $rels = @simplexml_load_string($relsXml);
            if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
                $rId = (string)$wb->sheets->sheet[0]->attributes('r', true)['id'];
                foreach ($rels->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rId) {
                        $target = (string)$rel['Target'];
                        $sheetPath = 'xl/' . ltrim($target, '/');
                        break;
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('Could not find worksheet data in this file.');
        }

        $sheet = @simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new RuntimeException('This file is not a valid .xlsx workbook.');
        }

        $rows = [];
        $maxCol = 0;
        foreach ($sheet->sheetData->row as $rowXml) {
            $rowIndex = (int)$rowXml['r'] - 1; // OOXML rows are 1-indexed
            $rowData = [];
            foreach ($rowXml->c as $cellXml) {
                $ref = (string)$cellXml['r'];
                $colIndex = self::colLetterToIndex(preg_replace('/[0-9]/', '', $ref));
                $type = (string)$cellXml['t'];
                $raw = isset($cellXml->v) ? (string)$cellXml->v : (isset($cellXml->is->t) ? (string)$cellXml->is->t : '');
                if ($type === 's' && $raw !== '') {
                    $value = $sharedStrings[(int)$raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $raw;
                } else {
                    $value = $raw; // number or plain string
                }
                $rowData[$colIndex] = $value;
                if ($colIndex > $maxCol) $maxCol = $colIndex;
            }
            $rows[$rowIndex] = $rowData;
        }

        // Normalize into a dense 0-indexed array of rows, each padded to
        // the same width, with missing cells as ''.
        $maxRow = $rows ? max(array_keys($rows)) : -1;
        $result = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $row[] = $rows[$r][$c] ?? '';
            }
            $result[] = $row;
        }
        return $result;
    }

    private static function colLetterToIndex(string $letters): int {
        $index = 0;
        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }
        return $index - 1; // 0-indexed
    }
}
