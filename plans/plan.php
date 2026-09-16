<?
// version 2.0 — batch выборки задач + пакетная загрузка ФИО (ускорение)

// version 1.7 — 2-колонки + ФИО (user_*) + удаляем логины (Login) ФИО

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require_once('/home/bitrix/www/tcpdf/tcpdf.php');

// Забираем данные пользователя и руководителя

global $USER;

CModule::IncludeModule("intranet");
CModule::IncludeModule('highloadblock');

// --- perf helpers: global FIO cache + prefill + fast FIO lookup
if (!isset($GLOBALS['__fio_cache'])) { $GLOBALS['__fio_cache'] = array(); }

if (!function_exists('prefill_user_fio_cache')) {
    function prefill_user_fio_cache($ids) {
        if (!is_array($ids) || empty($ids)) { return; }
        $ids = array_unique(array_map('intval', $ids));
        $ids = array_filter($ids, function($i){ return $i > 0; });
        if (empty($ids)) { return; }
        $filter = array("ID" => implode("|", $ids));
        $by = "id"; $order = "asc";
        $rsUsers = CUser::GetList($by, $order, $filter, array("FIELDS" => array("ID","NAME","LAST_NAME","SECOND_NAME")));
        while ($u = $rsUsers->Fetch()) {
            $fio = trim($u["LAST_NAME"]." ".$u["NAME"]." ".$u["SECOND_NAME"]);
            $GLOBALS['__fio_cache'][intval($u["ID"])] = $fio;
        }
    }
}

if (!function_exists('get_user_fio_by_id_fast')) {
    function get_user_fio_by_id_fast($id) {
        static $cache = array();
        $id = intval($id);
        if ($id <= 0) { return ''; }
        if (isset($GLOBALS['__fio_cache'][$id])) { return $GLOBALS['__fio_cache'][$id]; }
        if (isset($cache[$id])) { return $cache[$id]; }
        $fio = get_user_fio_by_id($id);
        $cache[$id] = $fio;
        return $fio;
    }
}


// --- perf: cached wrapper for user FIO lookups
if (!function_exists('get_user_fio_by_id_fast')) {
    function get_user_fio_by_id_fast($id) {
        static $cache = array();
        $id = intval($id);
        if ($id <= 0) { return ''; }
        if (isset($cache[$id])) { return $cache[$id]; }
        $fio = get_user_fio_by_id_fast($id);
        $cache[$id] = $fio;
        return $fio;
    }
}



if (isset($_GET['id_plan'])) {
    $id_plan = intval($_GET['id_plan']); // Приводим значение к целому числу
} else {
    $id_plan = 3360975; // Значение по умолчанию, если параметр не передан
}


$fio='Иванов Иван Иванович';
$fio_chief = 'Петров Петр Петрович';
$department = 'Отдел документооборота и бюджетирования';
$work_position = 'Менеджер';
$date_begin = '25.09.2024';
$date_end = '25.12.2024';
$control_meetings = '1 раз в 3 недели';
$fio_recruiter = 'Сидорова Дарья Даниловна';

// Инициализация массивов
$tasks1 = [];
$tasks2 = []; 
$table_main = [];
$table2_main = [];


////////////////////////
// Забираем ID задач
$arSelect = Array("ID", "NAME", "PROPERTY_ZADACHI_PO_PLANU_VVODA_V_DOLZHNOST","PROPERTY_ZADACHI_KPI","PROPERTY_KARTOCHKA_SOTRUDNIKA","PROPERTY_RUKOVODITEL");
$arFilter = Array(
"ID"=>$id_plan,
);

$res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize"=>500), $arSelect);

while ($ob = $res->GetNext()) {
    // Добавляем уникальные значения в массив tasks1
    if (!empty($ob['PROPERTY_ZADACHI_PO_PLANU_VVODA_V_DOLZHNOST_VALUE'])) {
        $task1_value = $ob['PROPERTY_ZADACHI_PO_PLANU_VVODA_V_DOLZHNOST_VALUE'];
        if (!in_array($task1_value, $tasks1)) {
            $tasks1[] = $task1_value;
        }
    }

    // Добавляем уникальные значения в массив tasks2
    if (!empty($ob['PROPERTY_ZADACHI_KPI_VALUE'])) {
        $kpi_value = $ob['PROPERTY_ZADACHI_KPI_VALUE'];
        if (!in_array($kpi_value, $tasks2)) {
            $tasks2[] = $kpi_value;
        }
    }

    // Забираем дополнительные данные
    $employee_profile_id = $ob['PROPERTY_KARTOCHKA_SOTRUDNIKA_VALUE'];
    $fio = $ob["NAME"];
    $fio_chief = get_user_fio_by_id_fast($ob['PROPERTY_RUKOVODITEL_VALUE']);
}



//echo 'tasks1'; print_r ($tasks1);
//echo 'tasks2'; print_r ($tasks2);

//exit();





////////////////////////
// Забираем должность, подразделение, рекрутера из карточки сотрудника, даты ИС

$arSelect = Array("ID", "NAME", "PROPERTY_OTVETSTVENNYY_MENEDZHER_OPIA","PROPERTY_DOLZHNOST","PROPERTY_OTDEL","PROPERTY_DATA_PRIEMA","PROPERTY_DATA_OKONCHANIYA_IS");
$arFilter = Array(
"ID"=>$employee_profile_id,
);

$res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize"=>500), $arSelect);

while ($ob=$res->GetNext()){

$fio_recruiter=get_user_fio_by_id_fast($ob['PROPERTY_OTVETSTVENNYY_MENEDZHER_OPIA_VALUE']);


$work_position=$ob['PROPERTY_DOLZHNOST_VALUE'];
$department=$ob['PROPERTY_OTDEL_VALUE'];
$date_begin=$ob['PROPERTY_DATA_PRIEMA_VALUE'];
$date_end=$ob['PROPERTY_DATA_OKONCHANIYA_IS_VALUE'];
}


$counter=1;

//Перебираем основные задачи

if (!function_exists('format_position_relationship_result')) {
    function format_position_relationship_result($result) {
        $lines = preg_split('/\r\n|\r|\n/', (string)$result);
        $html = '<table cellpadding="2" border="0" width="100%">';
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $tokens = preg_split('/\s*,\s*/u', trim($parts[1]), -1, PREG_SPLIT_NO_EMPTY);
                $values = array();
                foreach ($tokens as $token) {
                    $token = trim($token);
                    if ($token === '') { continue; }
                    if (preg_match('/^user_(\d+)$/i', $token, $matches)) { $values[] = get_user_fio_by_id_fast($matches[1]); continue; }
                    if (preg_match('/^\(([^)]+)\)\s*(.+)$/u', $token, $matches)) {
                        $name = trim($matches[2]);
                        if ($name !== '') { $values[] = $name; }
                        continue;
                    }
                    $values[] = $token;
                }
                $html .= '<tr><td width="45%"><b>' . htmlspecialchars(trim($parts[0])) . '</b></td><td width="55%">' . htmlspecialchars(implode(', ', $values)) . '</td></tr>';
            } elseif (trim($line) !== '') {
                $html .= '<tr><td colspan="2">' . htmlspecialchars(trim($line)) . '</td></tr>';
            }
        }
        return $html . '</table>';
    }
}

// --- batch fetch tasks1
$arSelect1 = array("ID", "NAME", "PROPERTY_PLANIRUEMYY_REZULTAT", "PROPERTY_FAKTICHESKIY_REZULTAT", "PROPERTY_PLANIRUEMYY_SROK_ISPOLNENIYA", "PROPERTY_FAKTICHESKIY_SROK", "PROPERTY_OTVETSTVENNYY", "PROPERTY_TIP_ZADACHI");
$arFilter1 = array("ID" => $tasks1);
$byId1 = array();
$userIdsToPrefill = array();
$res1 = CIBlockElement::GetList(array(), $arFilter1, false, array("nTopCount"=>count($tasks1)), $arSelect1);
while ($ob = $res1->GetNext()) {
    $byId1[$ob['ID']] = $ob;
    if (!empty($ob['PROPERTY_OTVETSTVENNYY_VALUE'])) { $userIdsToPrefill[] = intval($ob['PROPERTY_OTVETSTVENNYY_VALUE']); }
    $planRawTmp = (string)$ob['PROPERTY_PLANIRUEMYY_REZULTAT_VALUE'];
    $factRawTmp = (string)$ob['PROPERTY_FAKTICHESKIY_REZULTAT_VALUE'];
    if (preg_match_all('/user_(\d+)/i', $planRawTmp, $mm)) { foreach ($mm[1] as $uidx) { $userIdsToPrefill[] = intval($uidx); } }
    if (preg_match_all('/user_(\d+)/i', $factRawTmp, $mm)) { foreach ($mm[1] as $uidx) { $userIdsToPrefill[] = intval($uidx); } }
}
if (!empty($userIdsToPrefill)) { prefill_user_fio_cache($userIdsToPrefill); }
$tasks_array = array();
$counter = 1;
foreach ($tasks1 as $id) {
    if (empty($byId1[$id])) { continue; }
    $ob = $byId1[$id];
    $planRaw = $ob['PROPERTY_PLANIRUEMYY_REZULTAT_VALUE'];
    $factRaw = $ob['PROPERTY_FAKTICHESKIY_REZULTAT_VALUE'];
    if ((int)$ob['PROPERTY_TIP_ZADACHI_VALUE'] === 3347541) {
        $planHtml = format_position_relationship_result($planRaw);
        $factHtml = format_position_relationship_result($factRaw);
    } else {
        $planHtml = preg_replace('/(\r\n|\r|\n)+/', '<br/>', (string)$planRaw);
        $factHtml = preg_replace('/(\r\n|\r|\n)+/', '<br/>', (string)$factRaw);
    }
    $tasks_array[] = array(
        'number' => $counter,
        'description' => $ob['NAME'],
        'executor' => get_user_fio_by_id_fast($ob['PROPERTY_OTVETSTVENNYY_VALUE']),
        'plan' => $planHtml,
        'fact' => $factHtml,
        'date1' => $ob['PROPERTY_PLANIRUEMYY_SROK_ISPOLNENIYA_VALUE'],
        'date2' => $ob['PROPERTY_FAKTICHESKIY_SROK_VALUE']
    );
    $counter++;
}
// --- batch fetch tasks2
$arSelect2 = array("ID","NAME","PROPERTY_TIP_ZADACHI_KPI","PROPERTY_SROK","PROPERTY_FAKTICHESKIY_SROK","PROPERTY_PLANIRUEMY_REZULTAT","PROPERTY_FAKTICHESKIY_REZULTAT","PROPERTY_OTVETSTVENNYY","PROPERTY_FAKTICHESKIY_VYPOLNENIYA","PROPERTY_VES");
$arFilter2 = array("ID" => $tasks2, "IBLOCK_ID" => 363);
$byId2 = array();
$userIdsToPrefill2 = array();
$res2 = CIBlockElement::GetList(array(), $arFilter2, false, array("nTopCount"=>count($tasks2)), $arSelect2);
while ($ob = $res2->GetNext()) {
    $byId2[$ob['ID']] = $ob;
    if (!empty($ob['PROPERTY_OTVETSTVENNYY_VALUE'])) { $userIdsToPrefill2[] = intval($ob['PROPERTY_OTVETSTVENNYY_VALUE']); }
}
if (!empty($userIdsToPrefill2)) { prefill_user_fio_cache($userIdsToPrefill2); }
$tasks2_array = array();
$counter = 1;
foreach ($tasks2 as $id) {
    if (empty($byId2[$id])) { continue; }
    $ob = $byId2[$id];
    $tasks2_array[] = array(
        'number' => $counter,
        'description' => $ob['PROPERTY_TIP_ZADACHI_KPI_VALUE'],
        'executor' => get_user_fio_by_id_fast($ob['PROPERTY_OTVETSTVENNYY_VALUE']),
        'plan' => preg_replace('/(\r\n|\r|\n)+/', '<br/>', $ob['PROPERTY_PLANIRUEMY_REZULTAT_VALUE']),
        'weight' => $ob['PROPERTY_VES_VALUE'],
        'fact' => preg_replace('/(\r\n|\r|\n)+/', '<br/>', $ob['PROPERTY_FAKTICHESKIY_REZULTAT_VALUE']),
        'fact_percent' => $ob['PROPERTY_FAKTICHESKIY_VYPOLNENIYA_VALUE'],
        'date1' => $ob['PROPERTY_SROK_VALUE'],
        'date2' => $ob['PROPERTY_FAKTICHESKIY_SROK_VALUE']
    );
    $counter++;
}
//echo '<pre>';print_r($tasks_array);

/////////////////////////////////////////////////////////////////////////////////
/////Создаем PDF, инициализация

// create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Bitrix24');
$pdf->SetTitle('План-отчет ввода в должность');
$pdf->SetSubject('Tricolor reports');
$pdf->SetKeywords('Bitrix24');

$PDF_HEADER_LOGO = 'tricolor_logo.jpg';//any image file. check correct path.
$PDF_HEADER_LOGO_WIDTH = "20";
$PDF_HEADER_TITLE = "Триколор";
$PDF_HEADER_STRING = "Корпоративный портал";

// set default header data
//$pdf->SetHeaderData($PDF_HEADER_LOGO, $PDF_HEADER_LOGO_WIDTH);

// set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
$pdf->setPrintFooter(true);
$pdf->setPrintHeader(false);
$pdf->setFontSubsetting(false);

// set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// set margins
//$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
//$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// set some language-dependent strings (optional)
if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
	require_once(dirname(__FILE__).'/lang/eng.php');
	$pdf->setLanguageArray($l);
}

$pdf->setFontSubsetting(true);
$pdf->AddPage('L', 'A4');

/////////////////////////////////////////////////////////////////////////////////
/////Создаем PDF, заполняем шапку
$text='';
$strings = explode(PHP_EOL, $text);

$pdf->setFormDefaultProp(array('lineWidth'=>1, 'borderStyle'=>'solid', 'fillColor'=>array(255, 255, 200), 'strokeColor'=>array(255, 128, 128)));

$pdf->Ln(1);

$pdf->SetFont('dejavusans', '', 10);

$pdf->writeHTML('<b>План-отчет ввода в должность</b>');
$pdf->Ln(1);


$pdf->SetFont('dejavusans', '', 8);
$pdf->Ln(1);
$pdf->writeHTML('ФИО сотрудника: <b>'.$fio.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('Служба, подразделение: <b>'.$department.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('Должность: <b>'.$work_position.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('Дата трудоустройства: <b>'.$date_begin.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('Дата окончания испытательного срока: <b>'.$date_end.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('ФИО руководителя: <b>'.$fio_chief.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('Контрольные встречи по Плану (1to1): <b>'.$control_meetings.'</b>');
$pdf->Ln(1);
$pdf->writeHTML('Менеджер по персоналу: <b>'.$fio_recruiter.'</b>');
$pdf->Ln(1);



$pdf->Ln(4);
$pdf->SetFont('dejavusans', '', 8);
$pdf->writeHTML('<b>Задачи на испытательный срок</b>');


$count=1;
/////////////////////////////////////////////////////////////////////////////////
//Тело таблицы
//Заполняем ФИО

// Table with rowspans and THEAD
$tbl_header = <<<EOD
<table cellspacing="0" cellpadding="1" border="1">
<thead>
 <tr style="background-color:#F3F3F3;color:#000000;">
  <td width="20" align="center"><b>N</b></td>
  <td width="180" align="center"><b>Задача</b></td>
  <td width="100" align="center"><b>Ответственный</b></td>
  <td width="245" align="center"> <b>Планируемый результат</b></td>
  <td width="90" align="center"><b>Планируемый срок</b></td>
  <td width="245" align="center"><b>Фактический результат</b></td>
  <td width="90" align="center"><b>Фактический срок</b></td>
 </tr>
</thead>
EOD;


$tbl_footer = <<<EOD
</table>
EOD;


foreach ($tasks_array as $key=>$value) {

	//echo $value['number'];



$table_main[] = <<<EOD
<tr style="background-color:#FFFFFF;color:#000000;">
  <td width="20" align="left">{$value['number']}</td>
  <td width="180" align="left">{$value['description']}</td>
  <td width="100" align="left">{$value['executor']}</td>
  <td width="245" align="left">{$value['plan']}</td>
  <td width="90" align="left">{$value['date1']}</td>
  <td width="245" align="left">{$value['fact']}</td>
  <td width="90" align="left">{$value['date2']}</td>
 </tr>
EOD;


} 

foreach ($tasks2_array as $key=>$value) {

	//echo $value['number'];



$table2_main[] = <<<EOD
<tr style="background-color:#FFFFFF;color:#000000;">
  <td width="20" align="left">{$value['number']}</td>
  <td width="120" align="left">{$value['description']}</td>
  <td width="100" align="left">{$value['executor']}</td>
  <td width="240" align="left">{$value['plan']}</td>
  <td width="30" align="left">{$value['weight']}</td>
  <td width="90" align="left">{$value['date1']}</td>
  <td width="240" align="left">{$value['fact']}</td>
  <td width="40" align="left">{$value['fact_percent']}</td>
  <td width="90" align="left">{$value['date2']}</td>
 </tr>
EOD;


} 


//$table=$tbl_header.implode("",$table_main)$tbl_footer;
$pdf->SetFont('dejavusans', '', 7);

$table=$tbl_header.implode("",$table_main).$tbl_footer;

$pdf->writeHTML($table, true, false, false, false, '');


$pdf->SetFont('dejavusans', '', 8);
$pdf->Ln(1);
$pdf->writeHTML('<b>Выполнение KPI / Функциональной задачи/ Самостоятельное ведение проекта/задачи</b>');

$pdf->SetFont('dejavusans', '', 7);
$tbl2_header = <<<EOD
<table cellspacing="0" cellpadding="1" border="1">
<thead>
 <tr style="background-color:#F3F3F3;color:#000000;">
  <td width="20" align="center"><b>N</b></td>
  <td width="120" align="center"><b>Задача</b></td>
  <td width="100" align="center"><b>Ответственный</b></td>
  <td width="240" align="center"> <b>Планируемый результат</b></td>
  <td width="30" align="center"> <b>Вес %</b></td>
  <td width="90" align="center"><b>Планируемый срок</b></td>
  <td width="240" align="center"><b>Фактический результат</b></td>
  <td width="40" align="center"><b>Фактический %% выполнения</b></td>
  <td width="90" align="center"><b>Фактический срок</b></td>
 </tr>
</thead>
EOD;


$table2=$tbl2_header.implode("",$table2_main).$tbl_footer;



$pdf->writeHTML($table2, true, false, false, false, '');


$pdf->Output('Day_report_.pdf', 'I');

///////////////////////////////////////////
//////КОНЕЦ ТАБЛИЦЫ


/*
$pdf->SetXY($x, $y);

$pdf->Ln(7);



$pdf->SetFont('dejavusans', '', 7);
$pdf->writeHTML('Обозначения:<br> "В" - выходной день, "Д" - работа выполняется дистанционно, "Я" - работа выполняется в офисе, "О" - отпуск.<br>
Продолжительность ежедневной работы (кол-во часов) указывается в столбце, соответствующему дате.<br>
Перерывы для отдыха и питания предоставляются общей продолжительностью 60 минут.');
$pdf->Ln(3);

$pdf->SetFont('dejavusans', '', 7);

$pdf->Cell(20, 0, $position_chief,0,0,'L');
$pdf->Cell(120);
$pdf->Cell(150, 0, $fio_chief,0,0,'L');


$pdf->Ln(3);


$pdf->SetFont('dejavusans', 'I', 5);
$pdf->Cell(100, 0, '(Наименование должности руководителя)','T');
$pdf->Cell(20);
$pdf->Cell(15, 0, '(Подпись)','T');
$pdf->Cell(5);
$pdf->Cell(52, 0, '(Расшифровка подписи)','T');
$pdf->Ln(10);

*/





function get_user_fio_by_id_fast($uid)
{
    // Фильтр для поиска пользователя по ID
    $filter = array(
        "ID" => $uid,
        "ACTIVE" => 'Y'
    );

    // Получаем список пользователей
    $rsUsers = CUser::GetList(($by = "personal_country"), ($order = "desc"), $filter);

    // Если пользователь найден
    if ($arUser = $rsUsers->Fetch()) {
        // Формируем ФИО
        $fio = trim($arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME']);

        // Если ФИО не пустое, возвращаем его
        if (!empty($fio)) {
            return $fio;
        }
    }

    // Если ФИО не найдено, возвращаем "Не найдено ФИО"
    return "Не найдено ФИО";
}
	
	
function get_chief_id($userId)
{
	$num = 1;
if ($userId > 0)
{

 $dbUser = CUser::GetList(($by="id"), ($order="asc"), array("ID_EQUAL_EXACT"=>$userId), array("SELECT" => array("UF_*")));
 $arUser = $dbUser->Fetch();
 $i = 0;
 while ($i < $num)
 {
  $i++;
  $arManagers = CIntranetUtils::GetDepartmentManager($arUser["UF_DEPARTMENT"], $arUser["ID"], true);
  foreach ($arManagers as $key => $value)
  {
   $arUser = $value;
   break;
  }
	}
 return $arUser["ID"];

	}

}