<?php
// ============================================================
// xlsx_writer.php — a minimal, dependency-free .xlsx writer.
// Builds the OOXML spreadsheet format directly (a zip of XML
// parts) using PHP's built-in ZipArchive, so no Composer/
// PhpSpreadsheet install is needed — consistent with how this
// project bundles TCPDF instead of using a package manager.
//
// Supports what the three official-form exports need: a title
// row, a styled multi-row header (with cell merges for grouped
// column headers like "SETTLEMENT OR AWARD"), column widths,
// borders, background fills, wrapped header text, and plain
// data rows below.
// ============================================================

class SimpleXlsxWriter {
    private array $rows = [];       // ['cells' => [...], 'height' => float|null]
    private array $merges = [];     // 'A1:C1' strings
    private array $colWidths = [];  // 1-indexed column => width
    private array $styleDefs = [];  // styleId => xf definition array
    private array $styleCache = []; // serialized style key => styleId

    public function setColumnWidth(int $col, float $width): void {
        $this->colWidths[$col] = $width;
    }

    /**
     * Add a row of cells.
     * $cells: list of ['value' => string, 'bold' => bool, 'bg' => 'RRGGBB'|null,
     *                   'color' => 'RRGGBB'|null, 'size' => int|null, 'align' => 'center'|'left'|'right',
     *                   'wrap' => bool, 'border' => bool, 'italic' => bool]
     */
    public function addRow(array $cells, ?float $height = null): int {
        $this->rows[] = ['cells' => $cells, 'height' => $height];
        return count($this->rows); // 1-indexed row number just written
    }

    /** Merge a rectangular range, e.g. mergeCells(1, 4, 1, 5) merges D1:E1. */
    public function mergeCells(int $rowStart, int $colStart, int $rowEnd, int $colEnd): void {
        $this->merges[] = $this->colLetter($colStart) . $rowStart . ':' . $this->colLetter($colEnd) . $rowEnd;
    }

    private function colLetter(int $col): string {
        $letter = '';
        while ($col > 0) {
            $rem = ($col - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $col = intdiv($col - 1, 26);
        }
        return $letter;
    }

    private function styleIdFor(array $cell): int {
        $key = json_encode([
            $cell['bold'] ?? false, $cell['bg'] ?? null, $cell['color'] ?? null,
            $cell['size'] ?? null, $cell['align'] ?? null, $cell['wrap'] ?? false,
            $cell['border'] ?? false, $cell['italic'] ?? false,
        ]);
        if (isset($this->styleCache[$key])) return $this->styleCache[$key];
        $id = count($this->styleDefs);
        $this->styleDefs[] = $cell;
        $this->styleCache[$key] = $id;
        return $id;
    }

    private function xmlEscape(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Build the sheetN.xml sheetData + row heights */
    private function buildSheetXml(): string {
        $xml = '<sheetData>';
        foreach ($this->rows as $rIdx0 => $row) {
            $r = $rIdx0 + 1;
            $heightAttr = $row['height'] ? ' ht="' . $row['height'] . '" customHeight="1"' : '';
            $xml .= "<row r=\"$r\"$heightAttr>";
            foreach ($row['cells'] as $cIdx0 => $cell) {
                $c = $cIdx0 + 1;
                $ref = $this->colLetter($c) . $r;
                $styleId = $this->styleIdFor($cell);
                $value = $this->xmlEscape((string)($cell['value'] ?? ''));
                if ($value === '') {
                    $xml .= "<c r=\"$ref\" s=\"$styleId\"/>";
                } else {
                    $xml .= "<c r=\"$ref\" s=\"$styleId\" t=\"inlineStr\"><is><t xml:space=\"preserve\">$value</t></is></c>";
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        return $xml;
    }

    private function buildColsXml(): string {
        if (!$this->colWidths) return '';
        $xml = '<cols>';
        foreach ($this->colWidths as $col => $width) {
            $xml .= "<col min=\"$col\" max=\"$col\" width=\"$width\" customWidth=\"1\"/>";
        }
        $xml .= '</cols>';
        return $xml;
    }

    private function buildMergeXml(): string {
        if (!$this->merges) return '';
        $xml = '<mergeCells count="' . count($this->merges) . '">';
        foreach ($this->merges as $m) $xml .= "<mergeCell ref=\"$m\"/>";
        $xml .= '</mergeCells>';
        return $xml;
    }

    /** Build styles.xml: fonts, fills, borders, and cellXfs derived from styleDefs. */
    private function buildStylesXml(): string {
        $fonts = ['<font><sz val="10"/><name val="Calibri"/></font>']; // font 0 = default
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>']; // 0,1 reserved
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>']; // border 0 = none
        $thinBorder = '<border><left style="thin"><color rgb="FF999999"/></left><right style="thin"><color rgb="FF999999"/></right><top style="thin"><color rgb="FF999999"/></top><bottom style="thin"><color rgb="FF999999"/></bottom></border>';
        $borders[] = $thinBorder; // border 1 = thin all sides

        $fontCache = []; $fillCache = [];
        $cellXfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>']; // xf 0 = default

        foreach ($this->styleDefs as $cell) {
            $bold = $cell['bold'] ?? false;
            $italic = $cell['italic'] ?? false;
            $color = $cell['color'] ?? null;
            $size = $cell['size'] ?? 10;
            $fontKey = json_encode([$bold, $italic, $color, $size]);
            if (!isset($fontCache[$fontKey])) {
                $f = '<font>' . ($bold ? '<b/>' : '') . ($italic ? '<i/>' : '') . "<sz val=\"$size\"/>";
                if ($color) $f .= "<color rgb=\"FF" . strtoupper($color) . "\"/>";
                $f .= '<name val="Calibri"/></font>';
                $fonts[] = $f;
                $fontCache[$fontKey] = count($fonts) - 1;
            }
            $fontId = $fontCache[$fontKey];

            $bg = $cell['bg'] ?? null;
            $fillId = 0;
            if ($bg) {
                $fillKey = $bg;
                if (!isset($fillCache[$fillKey])) {
                    $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . strtoupper($bg) . '"/><bgColor indexed="64"/></patternFill></fill>';
                    $fillCache[$fillKey] = count($fills) - 1;
                }
                $fillId = $fillCache[$fillKey];
            }

            $borderId = !empty($cell['border']) ? 1 : 0;

            $align = $cell['align'] ?? null;
            $wrap = !empty($cell['wrap']);
            $alignXml = '';
            if ($align || $wrap) {
                $alignXml = '<alignment' . ($align ? " horizontal=\"$align\"" : '') . ' vertical="center"' . ($wrap ? ' wrapText="1"' : '') . '/>';
            }

            $cellXfs[] = "<xf numFmtId=\"0\" fontId=\"$fontId\" fillId=\"$fillId\" borderId=\"$borderId\" xfId=\"0\" applyFont=\"1\" applyFill=\"" . ($fillId ? 1 : 0) . "\" applyBorder=\"" . ($borderId ? 1 : 0) . "\"" . ($alignXml ? ' applyAlignment="1"' : '') . ">$alignXml</xf>";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>';
        $xml .= '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>';
        $xml .= '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>';
        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $xml .= '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>';
        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $xml .= '</styleSheet>';
        return $xml;
    }

    /** Write the complete .xlsx file to $path. Call after all rows/merges are added. */
    public function save(string $path, string $sheetName = 'Sheet1'): void {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>'
        );

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>'
        );

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>'
        );

        $safeSheetName = $this->xmlEscape(substr($sheetName, 0, 31));
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . $safeSheetName . '" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>'
        );

        // Build sheet XML (this also populates styleDefs via styleIdFor calls)
        $sheetDataXml = $this->buildSheetXml();
        $colsXml = $this->buildColsXml();
        $mergeXml = $this->buildMergeXml();

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            $colsXml . $sheetDataXml . $mergeXml .
            '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        // styles.xml must be built AFTER sheet XML so styleDefs is fully populated
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

        $zip->close();
    }
}
