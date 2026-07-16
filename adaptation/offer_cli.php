<?php
/**
 * offer_cli.php — запуск генерации оффера из CLI и из Bitrix.
 * Пример CLI:
 *   php /home/bitrix/www/pub/apps/offer_cli.php 3513128 3513128_123.pdf
 */

$IS_CLI = (php_sapi_name() === 'cli');

// CLI флаги — до загрузки ядра
if ($IS_CLI) {
    define("ADMIN_SECTION", true);
    define("NO_KEEP_STATISTIC", true);
    define("NO_AGENT_CHECK", true);
    define("DisableEventsCheck", true);
    define("BX_CRONTAB", true);
    define("NOT_CHECK_PERMISSIONS", true);
    define("BX_NO_ACCELERATOR_RESET", true);
}

if ($IS_CLI) {
    if ($argc < 3) {
        fwrite(STDERR,
            "Usage: php offer_cli.php <offerId> <outputFilename.pdf>\n".
            "Example: php offer_cli.php 3513128 3513128_123.pdf\n"
        );
        exit(1);
    }

    $offerId = intval(trim($argv[1]));
    $outputFilename = trim(basename($argv[2]));   // защита + trim
}

// DOCUMENT_ROOT
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www/";

// Загружаем ядро Bitrix
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Теперь класс CUser доступен → авторизация здесь !
if ($IS_CLI) {
    global $USER;
    $USER->Authorize(1);
}

if (!CModule::IncludeModule("iblock")) {
    die("Модуль инфоблоков не подключен");
}



// ------------------------------------------------------------
// ПАПКА ДЛЯ СОХРАНЕНИЯ PDF
// ------------------------------------------------------------
$saveDir = $_SERVER["DOCUMENT_ROOT"] . "/upload/application/offers/pdf/";
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0775, true);
}

$savePath = $saveDir . $outputFilename;
$logDir = $_SERVER["DOCUMENT_ROOT"] . "/upload/logs/";
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
$logFile = $logDir . "offer_cli.log";

function offerLog($message) {
    global $logFile;
    $line = "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}



/*************************************************************
 *  BITRIX PROLOG
 *************************************************************/
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

if (!CModule::IncludeModule("iblock")) {
    die("Модуль инфоблоков не подключен");
}


/*************************************************************
 *                  GLOBAL SETTINGS
 *************************************************************/

// PDF size (landscape)
$PDF_WIDTH  = 395;
$PDF_HEIGHT = 223;

// Background images
$BG_PAGE1 = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/page1.jpg';
$BG_PAGE2 = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/page2.jpg';
$BG_PAGE3 = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/page3.jpg';
$BG_PAGE4 = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/page4.jpg';
$BG_PAGE5 = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/page4_edo.jpg';
$BG_PAGE6 = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/page6.jpg';

// Icons
$ICON_USER  = $_SERVER["DOCUMENTORY_ROOT"] . '/upload/application/offers/images/phone.png';
$ICON_PHONE = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/phone.png';
$ICON_EMAIL = $_SERVER["DOCUMENT_ROOT"] . '/upload/application/offers/images/email.png';

// Column positions
$COL1_X = 12;
$COL2_X = 142;
$COL3_X = 272;

// Header Y
$COL_HEADER_Y = 40;

// Column body area
$COL_TOP    = 55;
$COL_BOTTOM = 190;

// Column widths
$COL_WIDTH  = 112;
$COL3_WIDTH = 112;

// Column 3 spacing & block style
$GAP_DEFAULT = 10;
$GAP_MIN     = 3;

$BLOCK_RADIUS = 6;
$BLOCK_HEIGHT_MIN = 11;

$BLOCK_PADDING       = 4;
$BLOCK_PADDING_COL3  = 2;

$BLOCK_BG = [80,130,255];


//$offerId = 3455871;

//$offerId=3513128;


/*************************************************************
 *                  LOAD TCPDF
 *************************************************************/
require_once($_SERVER["DOCUMENT_ROOT"] . '/tcpdf/tcpdf.php');


/*************************************************************
 *          LOAD FONTS
 *************************************************************/
$font_regular = TCPDF_FONTS::addTTFfont('/home/bitrix/fonts/Montserrat-Regular.ttf', 'TrueTypeUnicode', '', 96);
$font_bold    = TCPDF_FONTS::addTTFfont('/home/bitrix/fonts/Montserrat-Bold.ttf',    'TrueTypeUnicode', '', 96);


/*************************************************************
 *                  CUSTOM PDF CLASS
 *************************************************************/
class OFFERPDF extends TCPDF {

    public $backgroundFile = '';

    public function Header() {
        if ($this->backgroundFile) {
            global $PDF_WIDTH, $PDF_HEIGHT;

            $auto   = $this->AutoPageBreak;
            $margin = $this->getBreakMargin();

            $this->SetAutoPageBreak(false, 0);

            $this->Image(
                $this->backgroundFile,
                0, 0,
                $PDF_WIDTH,
                $PDF_HEIGHT,
                '',
                '',
                '',
                false,
                300
            );

            $this->SetAutoPageBreak($auto, $margin);
            $this->setPageMark();
        }
    }

    /**
     * Draw blue block with auto vertical text centering
     */
    public function drawBlueBlock(
        $x, $y, $w, $h,
        $text,
        $paddingOverride = null,
        $isBold = false,
        $fontSize = 10,
        $bottomNote = "",
        $bottomNoteFontSize = 4
    ) {
        global $BLOCK_RADIUS, $BLOCK_PADDING, $BLOCK_BG, $font_regular, $font_bold;

        $padding = ($paddingOverride !== null) ? $paddingOverride : $BLOCK_PADDING;

        // Blue rectangle
        $this->SetFillColor($BLOCK_BG[0], $BLOCK_BG[1], $BLOCK_BG[2]);
        $this->SetDrawColor(255,255,255);
        $this->RoundedRect($x, $y, $w, $h, $BLOCK_RADIUS, '1111', 'DF');

        // Font selection
        $fontToUse = $isBold ? $font_bold : $font_regular;
        $this->SetFont($fontToUse, "", $fontSize);
        $this->SetTextColor(255,255,255);

        $noteH = 0;
        if ($bottomNote !== "") {
            $this->SetFont($font_regular, "", $bottomNoteFontSize);
            $noteH = $this->getStringHeight($w - $padding * 2, $bottomNote);
            $this->SetFont($fontToUse, "", $fontSize);
        }

        // Text height → vertical center in area above bottom note
        $textH = $this->getStringHeight($w - $padding * 2, $text);
        $textAreaH = max(1, $h - $noteH - $padding);
        $topPad = max(1, ($textAreaH - $textH) / 2);

        $this->SetXY($x + $padding, $y + $topPad);
        $this->MultiCell($w - $padding*2, 5, $text, 0, "L");

        if ($bottomNote !== "") {
            $this->SetFont($font_regular, "", $bottomNoteFontSize);
            $this->SetXY($x + $padding, $y + $h - $noteH - 1);
            $this->MultiCell($w - $padding*2, 2, $bottomNote, 0, "R");
        }

        $this->SetTextColor(0,0,0);
    }
}


/*************************************************************
 *  Helpers
 *************************************************************/

function fmtRub($num) {
    return number_format($num, 0, ',', ' ') . " рублей";
}

function fmtPercentValue($value) {
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    return (strpos($value, "%") !== false) ? $value : $value . "%";
}

function calcBlockHeight($pdf, $text, $width, $padding = null, $fontSize = 10, $bottomNote = "", $bottomNoteFontSize = 4)
{
    global $BLOCK_HEIGHT_MIN, $BLOCK_PADDING;

    $pad = ($padding !== null) ? $padding : $BLOCK_PADDING;

    $pdf->SetFont("montserrat", "", $fontSize);
    $h = $pdf->getStringHeight($width - $pad * 2, $text);

    if ($bottomNote !== "") {
        $pdf->SetFont("montserrat", "", $bottomNoteFontSize);
        $h += $pdf->getStringHeight($width - $pad * 2, $bottomNote) + 1;
    }

    return max($BLOCK_HEIGHT_MIN, $h + $pad * 2);
}


/*************************************************************
 *               LOAD OFFER DATA FROM IBLOCK 218
 *************************************************************/



$offer = CIBlockElement::GetList(
    [],
    ["IBLOCK_ID" => 218, "ID" => $offerId],
    false,
    false,
    ["ID", "NAME"]
)->GetNext();

$res = CIBlockElement::GetProperty(218, $offerId, ["sort" => "asc"], []);
$props = [];

while ($p = $res->Fetch()) {
    $props[$p["CODE"]] = $p;
}

function propStr($code) {
    global $props;
    return trim($props[$code]["VALUE"]);
}
function propNum($code) {
    global $props;
    return floatval($props[$code]["VALUE"]);
}
function propEnumName($code) {
    global $props;
    $id = $props[$code]["VALUE"];
    if (!$id) return "";
    $el = CIBlockElement::GetByID($id)->GetNext();
    return $el ? trim($el["NAME"]) : "";
}


/*************************************************************
 * PREPARE VALUES FOR PAGE 1
 *************************************************************/

$CANDIDATE_FIO   = propStr("POLNOE_FIO_KANDIDATA");
$CANDIDATE_PHONE = propStr("KONTAKTNYY_TELEFON_KANDIDATA_7_");

$dateSend = propStr("PLANIRUEMAYA_DATA_OTPRAVKI_OFFERA_KANDIDATU");
$DATE_SEND = $dateSend ? date("d.m.Y", strtotime($dateSend)) : "";


/*************************************************************
 * COLUMN 1 DATA
 *************************************************************/

$EMPLOYEE_NAME = propStr("POLNOE_FIO_KANDIDATA");

$ruk = trim(
    propStr("FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE") . "\n" .
    propStr("DOLZHNOST_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE")
);

$col1Data = [
    ["label" => "Должность",    "value" => propStr("DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE")],
    ["label" => "Подразделение","value" => propStr("POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE")],
    ["label" => "Руководитель", "value" => $ruk],
];

/*************************************************************
 * COLUMN 2 — SALARY LOGIC
 *************************************************************/

$col2Data = [];

// Premium type (enum)
$premiaType = propEnumName("PREMIALNAYA_CHAST_");

// ISN
$isn = propNum("ISN_RUB_GROSS");

// RK / SN
$rk = propStr("RAYONNYY_KOEFFITSIENT");
$rkValue = propNum("RAYONNYY_KOEFFITSIENT");
$rk_is_one = ($rkValue <= 1);
$sn = propStr("PERSONALNAYA_NADBAVKA");
$snValue = propNum("PERSONALNAYA_NADBAVKA");
$hasSn = ($snValue > 0);
$hasRkAndSn = (!$rk_is_one && $hasSn);

// Fixed salary
$fix = fmtRub(propNum("ZARABOTNAYA_PLATA_FIKSIROVANNAYA_CHAST_ZA_MESYATS_"));
$col2Data[] = [
    "label"    => "Фиксированная часть за месяц*",
    "value"    => $fix,
    "footnote" => "",
    "taxNote"  => true
];

// Premium
if ($premiaType == "Ежемесячно" || $premiaType == "Ежеквартально") {

    $premRub = fmtRub(propNum("PREMIALNAYA_CHAST_RUB_GROSS"));
    $premPct = propStr("PREMIALNAYA_CHAST_PREMII_OT_OKLADA") . "% от оклада";

    $premOut = $premRub . " / " . $premPct . " / " . $premiaType;

    $col2Data[] = [
        "label"    => "Премиальная часть по итогам работы за период до*",
        "value"    => $premOut,
        "footnote" => "",
        "taxNote"  => true
    ];
}

// ISN
if ($isn > 0) {
    $col2Data[] = [
        "label"    => "ИСН за месяц*",
        "value"    => fmtRub($isn),
        "footnote" => "",
        "taxNote"  => true
    ];
}

// RK / SN
if (!$rk_is_one) {
    $col2Data[] = [
        "label"    => $hasRkAndSn
            ? "Региональный коэффициента (РК) / Северная надбавка (СН)"
            : "Региональный коэффициент",
        "value"    => $hasRkAndSn ? $rk . " / " . fmtPercentValue($sn) : $rk,
        "footnote" => ""
    ];
}

// Average income
$dohod = fmtRub(propNum("DOKHOD_V_MESYATS_V_SREDNEM_RUB_GROSS"));

$hasRk = !$rk_is_one;
$hasRkOrSn = ($hasRk || $hasSn);
$avgFootnoteMark = $hasRkOrSn ? "**" : "*";

if ($hasRkAndSn && $isn > 0)
    $labelAvg = "Доход в среднем в месяц, с учетом РК, СН и ИСН до" . $avgFootnoteMark;
elseif ($hasRk && $isn > 0)
    $labelAvg = "Доход в среднем в месяц, с учетом РК и ИСН до" . $avgFootnoteMark;
elseif ($hasRkAndSn)
    $labelAvg = "Доход в среднем в месяц, с учетом РК и СН до" . $avgFootnoteMark;
elseif ($hasRk)
    $labelAvg = "Доход в среднем в месяц, с учетом РК до" . $avgFootnoteMark;
elseif ($isn > 0)
    $labelAvg = "Доход в среднем в месяц, с учетом ИСН до" . $avgFootnoteMark;
else
    $labelAvg = "Доход в среднем в месяц до" . $avgFootnoteMark;

$col2Data[] = [
    "label" => $labelAvg,
    "value" => $dohod,
    "footnote" => "",
    "taxNote" => true
];


/*************************************************************
 * COLUMN 3 — CONDITIONS (Variant B optimized)
 *************************************************************/

function normalizeEquipment($text)
{
    $text = trim($text);

    if (stripos($text, "Оборудование сотрудника") !== false)
        return "Оборудование сотрудника";

    if (stripos($text, "Стационарный ПК в офисе и личное оборудование") !== false)
        return "Стационарный ПК в офисе";

    if (stripos($text, "Стационарный ПК в офисе") !== false)
        return "Стационарный ПК в офисе";

    if (stripos($text, "Корпоративный ноутбук") !== false)
        return "Корпоративный ноутбук";

    return $text;
}


// Build column 3
$col3Data = [
    [
        "label"  => "Испытательный срок",
        "label2" => "Испытательный\nсрок",
        "value"  => propStr("ISPYTATELNYY_SROK_ESLI_VYBRANO_INOE_")
    ],
    [
        "label"  => "Планируемая дата выхода на работу",
        "label2" => "Планируемая дата\nвыхода на работу",
        "value"  => propStr("PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU")
    ],
    [
        "label"  => "Тип договора",
        "label2" => "Тип договора",
        "value"  => propEnumName("DOGOVOR_S_SOTRUDNIKOM_LINK")
    ],
    [
        "label"  => "Согласно политикам Компании",
        "label2" => "Согласно политикам Компании",
        "value"  => propStr("SOGLASNO_POLITIKAM_KOMPANII")
    ],
    [
        "label"  => "Офис и формат работы",
        "label2" => "Офис и формат работы",
        "value"  => propEnumName("ADRES_OFISA_LST") . "\n" . propEnumName("FORMAT_RABOTY_NEW")
    ],
    [
        "label"  => "График работы,\nначало рабочего дня,\nналичие гибкого часа",
        "label2" => "График работы, начало рабочего дня",
        "value"  => propEnumName("GRAFIK_RABOTY_NEW") . ", " . propEnumName("NACHALO_RABOCHEGO_DNYA_NEW")
    ],
    [
        "label"  => "Оборудование для работы",
        "label2" => "Оборудование для работы (сотрудника или работодателя)",
        "value"  => normalizeEquipment(propEnumName("OBORUDOVANIE_DLYA_RABOTY_LINK"))
    ],
];


/*************************************************************
 * RENDER COLUMN 1 & 2
 *************************************************************/

function renderColumnStatic(
    $pdf,
    $items,
    $x,
    $top,
    $bottom,
    $w,
    $isCol2 = false
){
    $GAP = 12;

    foreach ($items as $row) {

        $pdf->SetFont("montserrat", "", 12);
        $labelH = $pdf->getStringHeight($w, $row["label"]);

        $blockFontSize = $isCol2 ? 14 : 13;
        $blockBold     = $isCol2;

        $taxNote = !empty($row["taxNote"]) ? "Сумма до вычета НДФЛ" : "";

        $blockH = calcBlockHeight(
            $pdf,
            $row["value"],
            $w,
            null,
            $blockFontSize,
            $taxNote,
            3.8
        );

        // label
        $pdf->SetXY($x, $top);
        $pdf->MultiCell($w, 6, $row["label"], 0, "L");

        $top += $labelH + 2;

        // block
        $pdf->drawBlueBlock(
            $x,
            $top,
            $w,
            $blockH,
            $row["value"],
            null,
            $blockBold,
            $blockFontSize,
            !empty($row["taxNote"]) ? "Сумма до вычета НДФЛ" : "",
            3.8
        );

        // footnote
        if (!empty($row["footnote"])) {
            $pdf->SetFont("montserrat", "", 8);
            $pdf->SetXY($x, $top + $blockH + 2);
            $pdf->MultiCell($w, 4, $row["footnote"], 0, "L");
        }

        $top += $blockH + $GAP;
    }
}

function prepareColumn2Rows($pdf, $items, $w, $labelFontSize, $blockFontSize, $footnoteFontSize, $labelGap, $footnoteGap)
{
    $rows = [];
    $totalRowsHeight = 0;

    foreach ($items as $row) {
        $pdf->SetFont("montserrat", "", $labelFontSize);
        $labelH = $pdf->getStringHeight($w, $row["label"]);

        $taxNote = !empty($row["taxNote"]) ? "Сумма до вычета НДФЛ" : "";

        $blockH = calcBlockHeight(
            $pdf,
            $row["value"],
            $w,
            null,
            $blockFontSize,
            $taxNote,
            3.8
        );

        $footnoteH = 0;
        if (!empty($row["footnote"])) {
            $pdf->SetFont("montserrat", "", $footnoteFontSize);
            $footnoteH = $pdf->getStringHeight($w, $row["footnote"]);
        }

        $rowHeight = $labelH + $labelGap + $blockH + ($footnoteH > 0 ? ($footnoteGap + $footnoteH) : 0);

        $rows[] = [
            "data" => $row,
            "labelH" => $labelH,
            "blockH" => $blockH,
            "footnoteH" => $footnoteH,
            "rowH" => $rowHeight
        ];
        $totalRowsHeight += $rowHeight;
    }

    return [$rows, $totalRowsHeight];
}

function renderColumn2Dynamic(
    $pdf,
    $items,
    $x,
    $top,
    $bottom,
    $w
){
    $GAP_DEFAULT = 12;
    $GAP_MIN = 0;
    $LABEL_GAP = 2;
    $FOOTNOTE_GAP = 2;

    $fontVariants = [
        ["label" => 11, "block" => 14, "footnote" => 8],
        ["label" => 10, "block" => 13, "footnote" => 7.5],
        ["label" => 9.5, "block" => 12.5, "footnote" => 7],
        ["label" => 9, "block" => 12, "footnote" => 7],
    ];

    $rows = [];
    $totalRowsHeight = 0;
    $labelFontSize = $fontVariants[0]["label"];
    $blockFontSize = $fontVariants[0]["block"];
    $footnoteFontSize = $fontVariants[0]["footnote"];
    $blockBold = true;
    $count = count($items);
    $available = $bottom - $top;

    foreach ($fontVariants as $variant) {
        [$candidateRows, $candidateTotalHeight] = prepareColumn2Rows(
            $pdf,
            $items,
            $w,
            $variant["label"],
            $variant["block"],
            $variant["footnote"],
            $LABEL_GAP,
            $FOOTNOTE_GAP
        );

        $rows = $candidateRows;
        $totalRowsHeight = $candidateTotalHeight;
        $labelFontSize = $variant["label"];
        $blockFontSize = $variant["block"];
        $footnoteFontSize = $variant["footnote"];

        if ($totalRowsHeight <= $available || $count <= 1) {
            break;
        }
    }

    $requiredWithDefaultGap = $totalRowsHeight + max(0, $count - 1) * $GAP_DEFAULT;

    $gap = $GAP_DEFAULT;
    if ($requiredWithDefaultGap > $available && $count > 1) {
        $gap = max($GAP_MIN, ($available - $totalRowsHeight) / ($count - 1));
    }

    // Отрисовка колонки 2 строго в рамках доступной высоты
    foreach ($rows as $idx => $rowMeta) {
        $row = $rowMeta["data"];

        $pdf->SetFont("montserrat", "", $labelFontSize);
        $pdf->SetXY($x, $top);
        $pdf->MultiCell($w, 6, $row["label"], 0, "L");
        $top += $rowMeta["labelH"] + $LABEL_GAP;

        $pdf->drawBlueBlock(
            $x,
            $top,
            $w,
            $rowMeta["blockH"],
            $row["value"],
            null,
            $blockBold,
            $blockFontSize,
            !empty($row["taxNote"]) ? "Сумма до вычета НДФЛ" : "",
            3.8
        );

        $top += $rowMeta["blockH"];

        if ($rowMeta["footnoteH"] > 0) {
            $pdf->SetFont("montserrat", "", $footnoteFontSize);
            $pdf->SetXY($x, $top + $FOOTNOTE_GAP);
            $pdf->MultiCell($w, 4, $row["footnote"], 0, "L");
            $top += $FOOTNOTE_GAP + $rowMeta["footnoteH"];
        }

        if ($idx < $count - 1) {
            $top += $gap;
        }
    }

    return $top;
}



/*************************************************************
 * RENDER COLUMN 3 — Variant B optimized
 *************************************************************/

function renderColumn3(
    $pdf,
    $items,
    $x,
    $top,
    $bottom,
    $w
){
    global $GAP_DEFAULT, $GAP_MIN, $BLOCK_PADDING_COL3;

    // Smaller fonts for col3
    $LABEL_FONT_SIZE = 10;
    $VALUE_FONT_SIZE = 11;
    $PAD = 2;
    $HEIGHT_MIN = 11;

    // First two items: side-by-side
    $first  = $items[0];
    $second = $items[1];
    $rest   = array_slice($items, 2);

    $halfW = ($w - 6) / 2;

    // Labels on 2 lines (label2 is forced)
    $label1 = $first["label2"];
    $label2 = $second["label2"];

    $pdf->SetFont("montserrat", "", $LABEL_FONT_SIZE);

    $lh1 = $pdf->getStringHeight($halfW, $label1);
    $lh2 = $pdf->getStringHeight($halfW, $label2);
    $lh  = max($lh1, $lh2);

    // Render labels
    $pdf->SetXY($x, $top);
    $pdf->MultiCell($halfW, 5, $label1, 0, "L");

    $pdf->SetXY($x + $halfW + 6, $top);
    $pdf->MultiCell($halfW, 5, $label2, 0, "L");

    $cursor = $top + $lh + 2;

    // Blue blocks for first row
    $bh1 = calcBlockHeight($pdf, $first["value"],  $halfW, $PAD, $VALUE_FONT_SIZE);
    $bh2 = calcBlockHeight($pdf, $second["value"], $halfW, $PAD, $VALUE_FONT_SIZE);
    $bh  = max($bh1, $bh2);

    $pdf->drawBlueBlock($x, $cursor, $halfW, $bh, $first["value"],  $PAD, false, $VALUE_FONT_SIZE);
    $pdf->drawBlueBlock($x + $halfW + 6, $cursor, $halfW, $bh, $second["value"], $PAD, false, $VALUE_FONT_SIZE);

    $cursor += $bh;

    /******** Remaining blocks ********/

    $labelHeights = [];
    $blockHeights = [];
    $sum = 0;

    foreach ($rest as $i => $row) {

        $labelText = $row["label2"] ?? $row["label"];

        $pdf->SetFont("montserrat", "", $LABEL_FONT_SIZE);
        $lh = $pdf->getStringHeight($w, $labelText);

        $bh = calcBlockHeight($pdf, $row["value"], $w, $PAD, $VALUE_FONT_SIZE);
        if ($bh < $HEIGHT_MIN) $bh = $HEIGHT_MIN;

        $labelHeights[$i] = $lh;
        $blockHeights[$i] = $bh;

        $sum += ($lh + 2 + $bh);
    }

    $N = count($rest);
    $available = $bottom - $cursor;
    $required = $sum + ($N + 1) * $GAP_DEFAULT;

    $GAP = ($required <= $available)
        ? $GAP_DEFAULT
        : max($GAP_MIN, ($available - $sum) / ($N + 1));

    // Render remaining rows
    foreach ($rest as $i => $row) {

        $cursor += $GAP;

        $labelText = $row["label2"] ?? $row["label"];

        $pdf->SetFont("montserrat", "", $LABEL_FONT_SIZE);
        $pdf->SetXY($x, $cursor);
        $pdf->MultiCell($w, 5, $labelText, 0, "L");

        $cursor += $labelHeights[$i] + 2;

        $pdf->drawBlueBlock(
            $x,
            $cursor,
            $w,
            $blockHeights[$i],
            $row["value"],
            $PAD,
            false,
            $VALUE_FONT_SIZE
        );

        $cursor += $blockHeights[$i];
    }
}

/*************************************************************
 * INIT PDF
 *************************************************************/
$pdf = new OFFERPDF('L', 'mm', [$PDF_HEIGHT, $PDF_WIDTH], true, 'UTF-8', false);
$pdf->setPrintFooter(false);

// Load fonts
$font_regular = TCPDF_FONTS::addTTFfont(
    '/home/bitrix/fonts/Montserrat-Regular.ttf',
    'TrueTypeUnicode','',96
);
$font_bold = TCPDF_FONTS::addTTFfont(
    '/home/bitrix/fonts/Montserrat-Bold.ttf',
    'TrueTypeUnicode','',96
);


/*************************************************************
 * PAGE 1 — COVER
 *************************************************************/
$pdf->backgroundFile = $BG_PAGE1;
$pdf->AddPage();

// Title
$pdf->SetFont($font_bold, "", 46);
$pdf->SetTextColor(255,255,255);
$pdf->SetXY(15, 70);
$pdf->Write(0, "Предложение о работе");

// Candidate FIO
$pdf->SetFont($font_bold, "", 26);
$pdf->SetXY(15, 105);
$pdf->Write(0, $EMPLOYEE_NAME);

// Contact phone
$phone = propStr("KONTAKTNYY_TELEFON_KANDIDATA_7_");
if ($phone) {
    $pdf->SetFont($font_regular, "", 16);
    $pdf->SetXY(15, 125);
    $pdf->Write(0, $phone);
}

// Planned offer date
$offerDateRaw = propStr("PLANIRUEMAYA_DATA_OTPRAVKI_OFFERA_KANDIDATU");
$offerDateFmt = "";
if ($offerDateRaw) {
    $offerDateFmt = date("d.m.Y", strtotime($offerDateRaw));

    $pdf->SetFont($font_regular, "", 26);
    $pdf->SetXY(15, 140);
    $pdf->Write(0, $offerDateFmt);
}

// Confidential note bottom-center
$pdf->SetFont($font_regular, "", 12);
$pdf->SetTextColor(255,255,255);

$txt = "Строго конфиденциально";
$txtWidth = $pdf->GetStringWidth($txt);
$pdf->SetXY(($PDF_WIDTH - $txtWidth) / 2, 185);
$pdf->Write(0, $txt);


/*************************************************************
 * PAGE 2 — MAIN CONTENT (table)
 *************************************************************/
$pdf->backgroundFile = $BG_PAGE2;
$pdf->AddPage();

// ===== Title "Наше предложение" in the top-right corner =====
$pdf->SetFont($font_bold, "", 20);        // Montserrat Bold
$pdf->SetTextColor(255, 255, 255);        // White text

$title = "Наше предложение";

// Вычисляем ширину текста, чтобы выровнять по правому краю с отступом 15 мм
$titleWidth = $pdf->GetStringWidth($title);
$x = 15;       // правый угол, 15 мм отступ
$y = 10;                                  // верхний отступ

$pdf->SetXY($x, $y);
$pdf->Write(0, $title);

// вернём текстовый цвет в чёрный, чтобы не повлияло на дальнейший вывод
$pdf->SetTextColor(0, 0, 0);



// Column headers
$pdf->SetFont($font_bold, "", 14);
$pdf->SetTextColor(0,0,0);

// Column 1 header
$pdf->SetXY($COL1_X, $COL_HEADER_Y);
$pdf->MultiCell($COL_WIDTH, 7, $EMPLOYEE_NAME, 0, "L");

// Column 2 header
$pdf->SetXY($COL2_X, $COL_HEADER_Y);
$pdf->MultiCell($COL_WIDTH, 7, "Заработная плата*", 0, "L");

// Column 3 header
$pdf->SetXY($COL3_X, $COL_HEADER_Y);
$pdf->MultiCell($COL3_WIDTH, 7, "Условия", 0, "L");



/*************************************************************
 * RENDER COLUMNS
 *************************************************************/

// Column 1
renderColumnStatic(
    $pdf,
    $col1Data,
    $COL1_X,
    $COL_TOP,
    $COL_BOTTOM,
    $COL_WIDTH,
    false
);

// Column 2
$col2EndY = renderColumn2Dynamic(
    $pdf,
    $col2Data,
    $COL2_X,
    $COL_TOP,
    186,
    $COL_WIDTH
);

// Footnote under column 2
$pdf->SetFont($font_regular, "", 4.5);
$pdf->SetTextColor(0,0,0);
$col2BottomFootnoteY = max(187, min(190, $col2EndY + 1));
$pdf->SetXY($COL2_X, $col2BottomFootnoteY);
$footnoteText = "* Суммы до вычета НДФЛ. С 01.01.2025 вступил в силу закон о прогрессивной шкале НДФЛ, где ставка НДФЛ может меняться от 13% до 22%.";
if ($hasRkAndSn) {
    $footnoteText .= "\n** С учетом регионального коэффициента и северной надбавки";
} elseif ($hasRk) {
    $footnoteText .= "\n** С учетом регионального коэффициента";
} elseif ($hasSn) {
    $footnoteText .= "\n** С учетом северной надбавки";
}

$pdf->MultiCell(
    $COL_WIDTH,
    2,
    $footnoteText,
    0,
    "L"
);

// Column 3
renderColumn3(
    $pdf,
    $col3Data,
    $COL3_X,
    $COL_TOP,
    $COL_BOTTOM,
    $COL3_WIDTH
);

/*************************************************************
 * PAGE 3 — EMPTY PAGE WITH BACKGROUND
 *************************************************************/
$pdf->backgroundFile = $BG_PAGE3;
$pdf->AddPage();


/*************************************************************
 * PAGE 4 — EMPTY PAGE WITH BACKGROUND
 *************************************************************/
$pdf->backgroundFile = $BG_PAGE4;
$pdf->AddPage();


/*************************************************************
 * PAGE 5 — EMPTY PAGE WITH BACKGROUND
 *************************************************************/
$pdf->backgroundFile = $BG_PAGE5;
$pdf->AddPage();


/*************************************************************
 * PAGE 6 — FINAL PAGE
 *************************************************************/
$pdf->backgroundFile = $BG_PAGE6;
$pdf->AddPage();

// ===== White text =====
$pdf->SetTextColor(255,255,255);

/******** HEADER ********/
$pdf->SetFont($font_bold, "", 32);
$pdf->SetXY(20, 40);
$pdf->Write(0, "Стань частью нашей команды!");


/******** MAIN TEXT ********/
$pdf->SetFont($font_regular, "", 18);
$pdf->SetXY(20, 60);
$pdf->MultiCell(
    250,
    10,
    "Условия данного предложения действуют\nв течение 3 календарных дней.\nЖдем вашего ответа!",
    0,
    "L"
);


// ======================================================
// RECRUITER INFORMATION
// ======================================================
$recruiterId = $props["REKRUTER"]["VALUE"];
$recruiter = CUser::GetByID($recruiterId)->Fetch();

$rekFio  = trim($recruiter["LAST_NAME"] . " " . $recruiter["NAME"]);
$rekPos  = trim($recruiter["WORK_POSITION"]);
$rekPhone = trim($recruiter["PERSONAL_MOBILE"]);
$rekEmail = trim($recruiter["EMAIL"]);

$baseY = 120;

/******** NAME ********/
$pdf->Image($ICON_USER, 20, $baseY + 18, 7, 7, '', '', '', false, 300);
$pdf->SetFont($font_bold, "", 20);
$pdf->SetXY(20, $baseY);
$pdf->Write(0, $rekFio);


/******** POSITION ********/
$pdf->SetFont($font_regular, "", 14);
$pdf->SetXY(20, $baseY + 8);
$pdf->Write(0, $rekPos);


/******** PHONE ********/
if ($rekPhone) {
    $pdf->Image($ICON_PHONE, 20, $baseY + 22, 7, 7, '', '', '', false, 300);
    $pdf->SetFont($font_regular, "", 14);
    $pdf->SetXY(32, $baseY + 22);
    $pdf->Write(0, $rekPhone);
}


/******** EMAIL ********/
if ($rekEmail) {
    $pdf->Image($ICON_EMAIL, 20, $baseY + 36, 7, 7, '', '', '', false, 300);
    $pdf->SetFont($font_regular, "", 14);
    $pdf->SetXY(32, $baseY + 36);
    $pdf->Write(0, $rekEmail);
}


// ======================================================
// WHITE STRIP WITH LINK (оставляем белую)
// ======================================================
//$pdf->SetFillColor(255,255,255);
//$pdf->Rect(0, 200, $PDF_WIDTH, 18, 'F');

$pdf->SetFont($font_bold, "", 14);
$pdf->SetTextColor(255,255,255); // ссылка белая
$link = "https://tricolor.ru/";

$linkWidth = $pdf->GetStringWidth($link);
$pdf->SetXY(($PDF_WIDTH - $linkWidth) / 2, 190);
$pdf->Write(0, $link, $link);



/*************************************************************
 * OUTPUT PDF
 *************************************************************/
offerLog("START offerId={$offerId}; outputFilename={$outputFilename}; savePath={$savePath}");

try {
    $pdf->Output($savePath, "F"); // F = save to file
} catch (Exception $e) {
    offerLog("ERROR PDF output failed: " . $e->getMessage());
    if ($IS_CLI) {
        fwrite(STDERR, "PDF generation failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    die("PDF generation failed");
}

if (file_exists($savePath)) {
    offerLog("SUCCESS file exists; size=" . filesize($savePath) . "; path={$savePath}");
} else {
    offerLog("ERROR file not found after output; path={$savePath}");
}

if ($IS_CLI) {
    echo "PDF saved: $savePath\n";
    exit(0);
} else {
    // если вызвали через веб, можно скачать
    header("Content-Type: application/pdf");
    header("Content-Disposition: attachment; filename=\"{$outputFilename}\"");
    readfile($savePath);
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");
