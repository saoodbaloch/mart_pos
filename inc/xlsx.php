<?php
declare(strict_types=1);

/**
 * Chhota sa .xlsx reader (pehli sheet). Kisi library ki zaroorat nahi,
 * sirf PHP ka ZipArchive chahiye jo har hosting par hota hai.
 * @return array<int, array<int, string>>
 */
function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not available on the server. Save the Excel file as CSV and upload that.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }

    // Shared strings
    $strings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = simplexml_load_string($ss);
        if ($x) {
            foreach ($x->si as $si) {
                if (isset($si->t)) {
                    $strings[] = (string)$si->t;
                } else {
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                    $strings[] = $t;
                }
            }
        }
    }

    // Pehli sheet ka path workbook se
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = simplexml_load_string($wb);
        $rx = simplexml_load_string($rels);
        if ($wbx && $rx && isset($wbx->sheets->sheet[0])) {
            $rid = (string)$wbx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rx->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
    }

    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('Could not read the Excel sheet.');
    }
    $sx = simplexml_load_string($xml);
    if (!$sx) {
        throw new RuntimeException('The Excel sheet is damaged.');
    }

    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = $ref !== '' ? col_index(preg_replace('/\d+/', '', $ref)) : count($cells);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $strings[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($c->is->t ?? '');
            } else {
                $val = (string)$c->v;
                // Excel lambe barcode ko 8.96E+12 bana deta hai — wapas poora number
                if ($val !== '' && is_numeric($val) && stripos($val, 'e') !== false) {
                    $val = number_format((float)$val, 0, '', '');
                }
            }
            $cells[$col] = $val;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }
    }
    return $rows;
}

function col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function read_csv_rows(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('Could not open the file.');
    }
    $first = fgets($fh);
    rewind($fh);
    $delim = ($first !== false && substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $rows[] = array_map(fn($v) => (string)$v, $r);
    }
    fclose($fh);
    if ($rows && isset($rows[0][0])) {
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]); // BOM
    }
    return $rows;
}
