<?php
/**
 * Скрипт: /forms/recruiting/create_recruitment_request.php
 * Назначение: Форма заявки на подбор персонала с сохранением в ИБ и хранением JSON-копии
 * Версия: v0.6.0 (2026-03-25)
 * Автор: ChatGPT
 *
 * Добавлено:
 * - Сохранение всех значений формы в свойство JSON (PROPERTY_3036) в формате JSON
 * - Это позволяет в будущем реализовать редактирование с восстановлением списочных значений
 */
use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\UI\Extension;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Entity;

// ===== Константы списков и полей =====
const IBLOCK_LEGAL      = 308;
const IBLOCK_DEP0       = 358;
const IBLOCK_POSITIONS  = 197;
const IBLOCK_SCHEDULE   = 236;
const IBLOCK_STARTTIME  = 237;
const IBLOCK_FORMAT     = 234;
const IBLOCK_OFFICE     = 233;
const IBLOCK_CONTRACT   = 325;
const IBLOCK_EQUIPMENT  = 326;
const IBLOCK_FURNITURE  = 400;
const IBLOCK_RECRUIT    = 201;

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}
if (!Loader::includeModule('highloadblock')) {
    ShowError('Не удалось подключить модуль highloadblock.');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}

Extension::load([
    'ui.hint',
    'ui.forms',
    'ui.buttons',
    'ui.notification',
    'ui.layout-form',
]);

$APPLICATION->SetTitle('Заявка на подбор персонала');

// ===== Вспомогательные функции =====
function getIblockOptions($iblockId, $selectFields = array()) {
    $res = [];
    $select = array_merge(['ID', 'NAME'], $selectFields);
    $rs = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
        false,
        false,
        $select
    );
    while ($row = $rs->GetNext()) {
        $res[] = $row;
    }
    return $res;
}

function getNameById($list, $id) {
    $id = (string)(int)$id;
    foreach ($list as $o) {
        if ((string)$o['ID'] === $id) return (string)$o['NAME'];
    }
    return '';
}

function fr_fmt_date($ymd) {
    $ymd = trim((string)$ymd);
    if ($ymd === '') return '';
    $ts = strtotime($ymd);
    return $ts ? date('d.m.Y', $ts) : $ymd;
}

function fr_log($label, $data = null) {
    $path = $_SERVER['DOCUMENT_ROOT'].'/upload/logs/forms.log';
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (is_array($data) || is_object($data)) { $data = print_r($data, true); }
    $line = date('Y-m-d H:i:s').' '.$label.': '.(string)$data."\n";
    file_put_contents($path, $line, FILE_APPEND);
}

/**
 * Рекурсивно оставить только заполненные значения для JSON-копии формы.
 * Считаем пустыми: null, пустую строку, пустой массив.
 * Значения "0"/0/false сохраняем.
 */
function fr_json_filter_filled($value) {
    if (is_array($value)) {
        $filtered = [];
        foreach ($value as $k => $v) {
            $fv = fr_json_filter_filled($v);
            $isEmptyString = is_string($fv) && trim($fv) === '';
            $isEmptyArray = is_array($fv) && empty($fv);
            if ($fv === null || $isEmptyString || $isEmptyArray) {
                continue;
            }
            $filtered[$k] = $fv;
        }
        return $filtered;
    }
    if (is_string($value)) {
        return trim($value);
    }
    return $value;
}

/**
 * Сформировать краткую "разницу" между исходным текстом обязанностей (из должности)
 * и отредактированным пользователем текстом.
 *
 * Формат:
 * - Если без изменений: "Без изменений"
 * - Иначе: блоки "Добавлено" / "Удалено" по строкам.
 */
function fr_build_duties_diff($original, $edited) {
    $original = str_replace(["\r\n", "\r"], "\n", (string)$original);
    $edited   = str_replace(["\r\n", "\r"], "\n", (string)$edited);
    $origTrim = trim($original);
    $editTrim = trim($edited);
    if ($origTrim === $editTrim) {
        return 'Без изменений';
    }

    $origLines = array_values(array_filter(array_map('trim', explode("\n", $origTrim)), static function($v){
        return $v !== '';
    }));
    $editLines = array_values(array_filter(array_map('trim', explode("\n", $editTrim)), static function($v){
        return $v !== '';
    }));

    // Простой построчный diff (без учета порядка и повторов). Для задач согласования обычно достаточно.
    $added   = array_values(array_diff($editLines, $origLines));
    $removed = array_values(array_diff($origLines, $editLines));

    if (!$added && !$removed) {
        // Изменения есть, но они не сводятся к добавлению/удалению строк (например, правка внутри строки)
        return 'Текст изменён (правки внутри строк)';
    }

    $out = [];
    if ($added) {
        $out[] = "Добавлено:";
        foreach (array_slice($added, 0, 80) as $line) {
            $out[] = "- {$line}";
        }
        if (count($added) > 80) {
            $out[] = "… (ещё " . (count($added) - 80) . ")";
        }
    }
    if ($removed) {
        $out[] = "Удалено:";
        foreach (array_slice($removed, 0, 80) as $line) {
            $out[] = "- {$line}";
        }
        if (count($removed) > 80) {
            $out[] = "… (ещё " . (count($removed) - 80) . ")";
        }
    }
    return implode("\n", $out);
}

// ===== HL-блоки =====
function getHLData($hlId, $select = array('*'), $filter = array(), $order = array('ID' => 'ASC')) {
    $hlblock = HL\HighloadBlockTable::getById($hlId)->fetch();
    if (!$hlblock) return [];
    $entity = HL\HighloadBlockTable::compileEntity($hlblock);
    $dataClass = $entity->getDataClass();
    $rows = [];
    $rs = $dataClass::getList(['select' => $select, 'filter' => $filter, 'order' => $order]);
    while ($row = $rs->fetch()) { $rows[] = $row; }
    return $rows;
}

// ===== Загрузка данных =====
$legalList     = getIblockOptions(IBLOCK_LEGAL);
$dep0List      = getIblockOptions(IBLOCK_DEP0);

$scheduleRows = getHLData(12, ['ID','UF_SCHEDULE_NAME','UF_POSITION_ID']);
$positionIds = [];
foreach ($scheduleRows as $r) {
    if (!empty($r['UF_POSITION_ID'])) $positionIds[] = $r['UF_POSITION_ID'];
}
$positionIds = array_values(array_unique($positionIds));
$dutiesByPosId = [];
if ($positionIds) {
    $posRows = getHLData(11, ['UF_POSITION_ID','UF_POSITION_DUTIES'], ['UF_POSITION_ID' => $positionIds]);
    foreach ($posRows as $p) {
        $dutiesByPosId[$p['UF_POSITION_ID']] = (string)$p['UF_POSITION_DUTIES'];
    }
}
$positionOptions = [];
foreach ($scheduleRows as $r) {
    $raw = (string)$r['UF_SCHEDULE_NAME'];
    $posName = trim($raw);
    $deptName = '';
    if (preg_match('/^(.*?)\s*\/\s*(.*?)\/?$/u', $raw, $m)) {
        $posName = trim($m[1]);
        $deptName = trim($m[2]);
    }
    $pid = (string)$r['UF_POSITION_ID'];
    $duties = $pid !== '' && isset($dutiesByPosId[$pid]) ? $dutiesByPosId[$pid] : '';
    $positionOptions[] = [
        'ROW_ID'          => (int)$r['ID'],
        'POSITION_ID'     => $pid,
        'POSITION_NAME'   => $posName,
        'DEPARTMENT_NAME' => $deptName,
        'DUTIES'          => $duties,
    ];
}
if (class_exists('Collator')) {
    $coll = new Collator('ru_RU');
    usort($positionOptions, function($a,$b) use ($coll){ return $coll->compare($a['POSITION_NAME'], $b['POSITION_NAME']); });
} else {
    usort($positionOptions, function($a,$b){ return strnatcasecmp(mb_strtolower($a['POSITION_NAME']), mb_strtolower($b['POSITION_NAME'])); });
}

$scheduleList  = getIblockOptions(IBLOCK_SCHEDULE);
$startTimeList = getIblockOptions(IBLOCK_STARTTIME);
$formatList    = getIblockOptions(IBLOCK_FORMAT);
$officeList    = getIblockOptions(IBLOCK_OFFICE);
$contractList  = getIblockOptions(IBLOCK_CONTRACT);
$equipList     = getIblockOptions(IBLOCK_EQUIPMENT);
$furnitureRows = getIblockOptions(IBLOCK_FURNITURE, ['PROPERTY_MULT_SELECT']);

// ===== Обработка сохранения =====
$saveMessage = null; $createdId = null; $formState = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && ($_POST['action'] ?? '') === 'save') {
    global $USER;
    $post = $_POST;
    $formState = $post;
    fr_log('POST', $_POST);

    // === Подготовка JSON-копии ===
    $postForJson = $post;
    unset($postForJson['action'], $postForJson['sessid']);
    $postForJson = fr_json_filter_filled($postForJson);
    $jsonData = json_encode($postForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonData === false) {
        $jsonData = '';
        fr_log('JSON encode error', json_last_error_msg());
    }

    // === Обработка основных полей ===
    $employeeField = $post['employee_id'] ?? null;
    $employeeRaw = is_array($employeeField) ? (string)reset($employeeField) : (string)$employeeField;
    $managerId = (preg_match('/([0-9]+)/', $employeeRaw, $m) ? (int)$m[1] : 0);

    $positionText = '';
    $positionId = trim((string)($post['position'] ?? ''));
    if (!empty($post['position_custom'])) {
        $positionText = trim((string)$post['position_custom']);
    } else if ($positionId !== '') {
        foreach ($positionOptions as $opt) {
            if ((string)$opt['POSITION_ID'] === $positionId) {
                $positionText = trim($opt['POSITION_NAME']);
                break;
            }
        }
    }

    // Базовая серверная валидация обязательных полей:
    // важно дублировать проверки с фронта, т.к. UI-компоненты могут обойти required.
    if ($managerId <= 0) {
        $saveMessage = [
            'type' => 'danger',
            'text' => 'Поле «Непосредственный руководитель» обязательно для заполнения.',
        ];
    }

    $scheduleName  = getNameById($scheduleList, (int)($post['schedule'] ?? 0));
    $startName     = getNameById($startTimeList, (int)($post['start_time'] ?? 0));
    $formatName    = getNameById($formatList, (int)($post['format'] ?? 0));
    $officeName    = getNameById($officeList, (int)($post['office'] ?? 0));
    $contractName  = getNameById($contractList, (int)($post['contract'] ?? 0));
    $equipName     = getNameById($equipList, (int)($post['equipment'] ?? 0));
    $equipComment  = trim((string)($post['equipment_comment'] ?? ''));
    $equipCombined = trim($equipName . ($equipComment !== '' ? PHP_EOL . $equipComment : ''));

    $furnitureNameById = [];
    $furnitureMultiById = [];
    foreach ($furnitureRows as $furnitureRow) {
        $furnitureId = (string)$furnitureRow['ID'];
        $furnitureNameById[$furnitureId] = (string)$furnitureRow['NAME'];
        $furnitureMultiById[$furnitureId] = ((string)($furnitureRow['PROPERTY_MULT_SELECT_VALUE'] ?? '') === 'Y');
    }
    $furnitureSelected = $post['furniture'] ?? [];
    if (!is_array($furnitureSelected)) {
        $furnitureSelected = [$furnitureSelected];
    }
    $furnitureSelected = array_values(array_unique(array_filter(array_map(static function($v){
        return (string)(int)$v;
    }, $furnitureSelected), static function($v){
        return $v !== '0' && $v !== '';
    })));
    $singleFurniture = [];
    $multiFurniture = [];
    foreach ($furnitureSelected as $furnitureId) {
        if (!isset($furnitureNameById[$furnitureId])) {
            continue;
        }
        if (!empty($furnitureMultiById[$furnitureId])) {
            $multiFurniture[] = $furnitureId;
        } else {
            $singleFurniture[] = $furnitureId;
        }
    }
    $furnitureIdsForText = $multiFurniture ?: (empty($singleFurniture) ? [] : [reset($singleFurniture)]);
    $furnitureNames = [];
    foreach ($furnitureIdsForText as $furnitureId) {
        $furnitureNames[] = $furnitureNameById[$furnitureId];
    }
    $furnitureText = implode(', ', $furnitureNames);

    $reasonMap = [
        'new_unit'   => 'Новая штатная единица',
        'maternity'  => 'Декретная ставка',
        'replacement'=> 'Замещение увольняющегося сотрудника',
        'transfer'   => 'Перевод сотрудника',
    ];
    $reasonKey = (string)($post['reason'] ?? '');
    $reasonText = $reasonMap[$reasonKey] ?? '';
    $reasonDetails = '';
    if ($reasonKey === 'new_unit') {
        $reasonDetails = trim((string)($post['reason_expand'] ?? ''));
    } elseif ($reasonKey === 'maternity') {
        $reasonDetails = 'Декрет с ' . fr_fmt_date($post['maternity_date'] ?? '') . '  ' . trim((string)($post['maternity_fio'] ?? ''));
    } elseif ($reasonKey === 'replacement') {
        $reasonDetails = 'Увольнение с ' . fr_fmt_date($post['replacement_date'] ?? '') . '  ' . trim((string)($post['replacement_fio'] ?? ''));
    } elseif ($reasonKey === 'transfer') {
        $reasonDetails = 'Перевод с ' . fr_fmt_date($post['transfer_date'] ?? '') . '  ' . trim((string)($post['transfer_fio_where'] ?? ''));
    }

    $bt = trim((string)($post['business_trips'] ?? ''));
    $tripDuration = '';
    if ($bt !== '' && mb_strpos($bt, 'Да') === 0) {
        $tripDuration = trim((string)($post['trip_duration'] ?? ''));
    }

    $managerName = trim((string)($post['employee_name'] ?? ''));
    $managerPosition = '';
    if ($managerId > 0) {
        $rsManager = CUser::GetByID($managerId);
        if ($arManager = $rsManager->Fetch()) {
            $managerPosition = trim((string)($arManager['WORK_POSITION'] ?? ''));
        }
    }

    // === Должностные обязанности: исходные (из должности) + отредактированные + разница ===
    $dutiesEdited   = trim((string)($post['duties'] ?? ''));
    $dutiesOriginal = trim((string)($post['duties_original'] ?? ''));
    $dutiesDiff = '';
    if ($dutiesOriginal !== '') {
        $dutiesDiff = fr_build_duties_diff($dutiesOriginal, $dutiesEdited);
        // Если "Без изменений" — всё равно сохраняем, чтобы было понятно согласующим.
    }

    // === Ставка ===
    $stakeRaw = str_replace(',', '.', trim((string)($post['stake'] ?? '1')));
    $stake = (float)$stakeRaw;
    if ($stake <= 0) { $stake = 1.0; }
    // Ограничим диапазон и шаг (0.1..1.0)
    $stake = max(0.1, min(1.0, $stake));
    $stake = round($stake, 1);

    // === Свойства элемента ===
    $props = [
        'DOLZHNOST'                                     => $positionText,
        'YURIDICHESKOE_LITSO'                           => (int)($post['legal'] ?? 0),
        'PODRAZDELENIE_0_UROVNYA'                       => (int)($post['dep0'] ?? 0),
        'DIREKTSIYA'                                    => trim((string)($post['directorate'] ?? '')),
        'NEPOSREDSTVENNYY_RUKOVODITEL'                  => $managerId,
        'DOLZHNOST_RUKOVODITELYA'                      => $managerPosition,
        'OBRAZOVANIE_TEKST'                             => trim((string)($post['education'] ?? '')),
        'PRICHINA_OTKRYTIYA_VAKANSII_TEKST'             => $reasonText,
        'PRICHINA_ZAYAVKI_NA_PODBOR'                    => $reasonDetails,
        'DATA_DEKRETA'                                  => fr_fmt_date($post['maternity_date'] ?? ''),
        'DATA_UVOLNENIYA'                               => fr_fmt_date($post['replacement_date'] ?? ''),
        'DATA_PEREVODA'                                 => fr_fmt_date($post['transfer_date'] ?? ''),
        'EST_LI_VNUTRENNIY_KANDIDAT_NA_DANNUYU_DOLZHNOST' => trim((string)($post['internal_candidate'] ?? '')),
        'OTDELY_DLYA_POISKA_VNUTRENNIKH_KANDIDATOV'     => trim((string)($post['internal_departments'] ?? '')),
        'OBYAZANNOSTI'                                  => $dutiesEdited,
        // Исходные обязанности из карточки должности (не отредактированные)
        'DOLZHNOSTNYE_OBYAZANNOSTI_1C'                  => $dutiesOriginal,
        // Разница между исходными и введенными обязанностями (для согласования)
        'DOLZHNOSTNYE_OBYAZANNOSTI_RAZNITSA'            => $dutiesDiff,
        // Новое поле "Ставка"
        'STAVKA'                                        => $stake,
        'POL_STROKA'                                    => trim((string)($post['gender'] ?? '')),
        'ZHELAEMAYA_SPETSIALNOST'                       => trim((string)($post['from_positions'] ?? '')),
        'OPYT_RABOTY'                                   => trim((string)($post['experience'] ?? '')),
        'DELOVYE_KACHESTVA'                             => trim((string)($post['softskills'] ?? '')),
        'VLADENIE_INOSTRANNYM_YAZYKOM_TEKST'            => trim((string)($post['lang'] ?? '')),
        'ZNANIE_SPETSIALNYKH_PROGRAMM'                  => trim((string)($post['softwares'] ?? '')),
        'NALICHIE_VODITELSKIKH_PRAV_TEKST'              => trim((string)($post['driver_license'] ?? '')),
        'DOPOLNITELNYE_TREBOVANIYA'                     => trim((string)($post['requirements_extra'] ?? '')),
        'KOMANDIROVKI_TEKST'                            => $bt,
        'KOMANDIROVKI_PRODOLZHITELNOST'                 => $tripDuration,
        'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA'          => (int)($post['contract'] ?? 0),
        'TIP_DOGOVORA_S_SOTRUDNIKOM'                    => $contractName,
        'OFIS_TEKST'                                    => $officeName,
        'OFIS_PRIVYAZKA'                                => (int)($post['office'] ?? 0),
        'GRAFIK_RABOTY_TEKST'                           => $scheduleName,
        'GRAFIK_RABOTY_PRIVYAZKA'                       => (int)($post['schedule'] ?? 0),
        'FORMAT_RABOTY_PRIVYAZKA'                       => (int)($post['format'] ?? 0),
        'FORMAT_RABOTY_TEKST'                           => $formatName,
        'NACHALO_RABOCHEGO_DNYA_TEKST'                  => $startName,
        'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA'              => (int)($post['start_time'] ?? 0),
        'KONFIDENTSIALNYY_POISK'                        => trim((string)($post['confidential'] ?? 'Нет')),
        'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA'            => (int)($post['equipment'] ?? 0),
        'OBORUDOVANIE_DLYA_RABOTY_TEKST'                => $equipCombined,
        'NEOBKHODIMAYA_MEBEL'                           => $furnitureText,
        // === НОВОЕ СВОЙСТВО ===
        'JSON'                                          => $jsonData,
    ];

    $elementName = 'Заявка на подбор: ' . ($positionText ?: 'без должности');
    if (!empty($post['directorate'])) $elementName .= ' — ' . trim((string)$post['directorate']);

    if (!$saveMessage) {
        $el = new CIBlockElement();
        $fields = [
            'IBLOCK_ID'       => IBLOCK_RECRUIT,
            'ACTIVE'          => 'Y',
            'NAME'            => $elementName,
            'PROPERTY_VALUES' => $props,
            'CREATED_BY'      => is_object($USER) ? (int)$USER->GetID() : 1,
        ];

        $newId = $el->Add($fields);
        if ($newId) {
            $createdId = (int)$newId;
            fr_log('ADD OK', $createdId);

            if (Loader::includeModule('bizproc') && Loader::includeModule('lists')) {
                $docId = ['lists','BizprocDocument',$createdId];
                $wfParams = ['TargetUser' => (is_object($USER) ? 'user_'.(int)$USER->GetID() : 'user_1')];
                $wfErrors = [];
                $wfId = CBPDocument::StartWorkflow(1269, $docId, $wfParams, $wfErrors);
                if (!empty($wfErrors)) {
                    fr_log('BP ERR', $wfErrors);
                } else {
                    fr_log('BP STARTED', ['TEMPLATE_ID' => 1269, 'WF_ID' => $wfId]);
                }
            }

            echo '<script>BX.ready(function(){window.location.href = "/forms/staff_recruitment/staffing/list.php";});</script>';
        } else {
            fr_log('ADD ERR', $el->LAST_ERROR);
            $saveMessage = ['type' => 'danger', 'text' => 'Ошибка создания: ' . htmlspecialcharsbx($el->LAST_ERROR)];
        }
    } else {
        fr_log('VALIDATION ERR', $saveMessage['text']);
    }
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<div class="container my-4">
  <?php if ($saveMessage): ?>
  <div class="alert alert-<?= $saveMessage['type'] ?>" role="alert"><?= $saveMessage['text'] ?></div>
  <?php endif; ?>
  <div class="d-flex align-items-center mb-3">
    <h1 class="h3 mb-0">Заявка на подбор персонала</h1>
    <span class="ml-3 badge badge-secondary" title="Версия скрипта">v0.6.0</span>
  </div>
  <form id="recruitForm" method="post">
    <?= bitrix_sessid_post(); ?>
    <div class="card mb-3">
      <div class="card-header">Общие сведения</div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Юридическое лицо <span class="text-danger">*</span></label>
            <select name="legal" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($legalList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-6">
            <label>Подразделение 0 уровня <span class="text-danger">*</span></label>
            <select name="dep0" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($dep0List as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row align-items-end">
          <div class="form-group col-md-6">
            <label>Должность <span class="text-danger">*</span></label>
            <div class="mb-2" style="max-width:520px">
              <input type="text" class="form-control form-control-sm" id="positionSearch" placeholder="Поиск по первым буквам должности…">
            </div>
            <select name="position" id="positionSelect" class="form-control" style="max-width:520px" required>
              <option value="">— Выберите —</option>
              <?php foreach ($positionOptions as $opt): ?>
                <option value="<?= htmlspecialcharsbx($opt['POSITION_ID']) ?>"
                        data-name="<?= htmlspecialcharsbx($opt['POSITION_NAME']) ?>"
                        data-dept="<?= htmlspecialcharsbx($opt['DEPARTMENT_NAME']) ?>"
                        data-duties="<?= htmlspecialcharsbx($opt['DUTIES']) ?>">
                  <?= htmlspecialcharsbx($opt['POSITION_NAME']) ?> — <?= htmlspecialcharsbx($opt['DEPARTMENT_NAME']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="positionNotInList">
              <label class="form-check-label" for="positionNotInList">Нет в списке</label>
            </div>
          </div>
          <div class="form-group col-md-6" id="positionCustomWrap" style="display:none;">
            <label>Должность (если нет в списке) <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="position_custom" id="positionCustom">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Дирекция <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="directorate" required>
          </div>
          <div class="form-group col-md-6">
            <label>Непосредственный руководитель <span class="text-danger">*</span></label>
            <?php
            $APPLICATION->IncludeComponent(
                'bitrix:intranet.user.selector',
                '',
                [
                    'INPUT_NAME'          => 'employee_id',
                    'INPUT_NAME_STRING'   => 'employee_name',
                    'INPUT_VALUE'         => [],
                    'MULTIPLE'            => 'N',
                    'NAME_TEMPLATE'       => '#LAST_NAME# #NAME# #SECOND_NAME#',
                    'SHOW_EXTRANET_USERS' => 'NONE',
                    'EXTERNAL'            => 'A',
                    'POPUP'               => 'Y',
                ],
                false,
                ['HIDE_ICONS' => 'Y']
            );
            ?>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-3">
            <label>Ставка <span class="text-danger">*</span></label>
            <select name="stake" class="form-control" required>
              <?php for ($i = 1; $i <= 10; $i++): $v = $i/10; ?>
                <option value="<?= htmlspecialcharsbx((string)$v) ?>" <?= ($i===10 ? 'selected' : '') ?>><?= htmlspecialcharsbx(number_format($v, 1, '.', '')) ?></option>
              <?php endfor; ?>
            </select>
            <small class="form-text text-muted">От 0.1 до 1.0 (по умолчанию 1.0)</small>
          </div>
        </div>
        <div class="form-group">
          <label>Причина открытия вакансии <span class="text-danger">*</span></label>
          <select name="reason" id="reasonSelect" class="form-control" required>
            <option value="">— Выберите —</option>
            <option value="new_unit">Новая штатная единица</option>
            <option value="maternity">Декретная ставка</option>
            <option value="replacement">Замещение увольняющегося сотрудника</option>
            <option value="transfer">Перевод сотрудника</option>
          </select>
        </div>
        <!-- Блоки зависящие от причины -->
        <div id="reason_new_unit" class="reason-block card bg-light mb-3" style="display:none;">
          <div class="card-body">
            <label>Причина расширения штата <span class="text-danger">*</span></label>
            <textarea class="form-control" name="reason_expand" rows="3"></textarea>
          </div>
        </div>
        <div id="reason_maternity" class="reason-block card bg-light mb-3" style="display:none;">
          <div class="card-body">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Дата ухода в декрет <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="maternity_date">
              </div>
              <div class="form-group col-md-6">
                <label>ФИО уходящего в декрет <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="maternity_fio">
              </div>
            </div>
          </div>
        </div>
        <div id="reason_replacement" class="reason-block card bg-light mb-3" style="display:none;">
          <div class="card-body">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Дата увольнения <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="replacement_date">
              </div>
              <div class="form-group col-md-6">
                <label>ФИО увольняющегося <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="replacement_fio">
              </div>
            </div>
          </div>
        </div>
        <div id="reason_transfer" class="reason-block card bg-light mb-3" style="display:none;">
          <div class="card-body">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Дата перевода <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="transfer_date">
              </div>
              <div class="form-group col-md-6">
                <label>ФИО переводящегося и куда <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="transfer_fio_where">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Требования и обязанности</div>
      <div class="card-body">
        <div class="form-group">
          <label>Должностные обязанности <span class="text-danger">*</span></label>
          <input type="hidden" name="duties_original" id="dutiesOriginal" value="">
          <textarea class="form-control" name="duties" id="duties" rows="8" placeholder="Опишите ключевые обязанности..." required></textarea>
          <small class="form-text text-muted">Если выбрана должность из списка, поле может быть предзаполнено из карточки должности.</small>
        </div>
        <div class="form-row">
          <div class="form-group col-md-4">
            <label>Пол</label>
            <select name="gender" class="form-control">
              <option>Не имеет значения</option>
              <option>Мужчина</option>
              <option>Женщина</option>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Образование</label>
            <select name="education" class="form-control">
              <option>Высшее</option>
              <option>Неоконченное высшее и выше</option>
              <option>Среднее профессиональное и выше</option>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Владение иностранным языком</label>
            <select name="lang" class="form-control">
              <option>Не имеет значения</option>
              <option>Свободно</option>
              <option>Средний уровень</option>
              <option>Удовлетворительно</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Опыт работы <span class="text-danger">*</span></label>
          <textarea class="form-control" name="experience" rows="3" required></textarea>
        </div>
        <div class="form-group">
          <label>Деловые качества, навыки и знания <span class="text-danger">*</span></label>
          <textarea class="form-control" name="softskills" rows="3" required></textarea>
        </div>
        <div class="form-group">
          <label>Знание специальных программ <span class="text-danger">*</span></label>
          <textarea class="form-control" name="softwares" rows="3" required></textarea>
        </div>
        <div class="form-group">
          <label>Дополнительные требования <small class="text-muted">(необязательно)</small></label>
          <textarea class="form-control" name="requirements_extra" rows="3"></textarea>
        </div>
        <div class="form-row">
          <div class="form-group col-md-4">
            <label>Наличие водительских прав</label>
            <select name="driver_license" class="form-control">
              <option>Не имеет значения</option>
              <option>Да</option>
              <option>Нет</option>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>График работы <span class="text-danger">*</span></label>
            <select name="schedule" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($scheduleList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Начало работы <span class="text-danger">*</span></label>
            <select name="start_time" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($startTimeList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-4">
            <label>Формат работы <span class="text-danger">*</span></label>
            <select name="format" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($formatList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Офис <span class="text-danger">*</span></label>
            <select name="office" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($officeList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-4">
            <label>Командировки</label>
            <select name="business_trips" id="businessTrips" class="form-control">
              <option>Нет</option>
              <option>Да, несколько раз в месяц</option>
              <option>Да, ежемесячно</option>
              <option>Да, раз в квартал</option>
              <option>Да, раз в полгода</option>
              <option>Да, раз в год</option>
            </select>
          </div>
        </div>
        <div class="form-group" id="tripDurationWrap" style="display:none;">
          <label>Продолжительность командировок <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="trip_duration" id="tripDuration" placeholder="например: 2-3 дня, до недели и т.д.">
        </div>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Тип трудового договора <span class="text-danger">*</span></label>
            <select name="contract" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($contractList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-6">
            <label>Оборудование для работы <span class="text-danger">*</span></label>
            <select name="equipment" class="form-control" required>
              <option value="">— Выберите —</option>
              <?php foreach ($equipList as $o): ?>
                <option value="<?= (int)$o['ID'] ?>"><?= htmlspecialcharsbx($o['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Комментарии к оборудованию для работы <small class="text-muted">(необязательно)</small></label>
          <textarea class="form-control" name="equipment_comment" rows="2" placeholder="Если есть доп. требования к оборудованию, указать здесь: например, к ноутбуку дополнительно нужен монитор/мышка/наушники или такие-то требования к ПК и т.д."></textarea>
        </div>
        <div class="form-group">
          <label>Необходима ли мебель? <span class="text-danger">*</span></label>
          <input type="hidden" name="furniture_required" id="furnitureRequired" required>
          <div class="card p-2" id="furnitureWrap">
            <div class="small text-muted mb-2">Можно выбрать несколько вариантов только у позиций с признаком множественного выбора.</div>
            <?php foreach ($furnitureRows as $row): ?>
              <?php
              $fId = (int)$row['ID'];
              $fName = (string)$row['NAME'];
              $isMulti = (string)($row['PROPERTY_MULT_SELECT_VALUE'] ?? '') === 'Y';
              ?>
              <div class="form-check">
                <input
                  class="form-check-input furniture-input <?= $isMulti ? 'furniture-multi' : 'furniture-single' ?>"
                  type="<?= $isMulti ? 'checkbox' : 'radio' ?>"
                  name="furniture[]"
                  value="<?= $fId ?>"
                  id="furniture_<?= $fId ?>"
                >
                <label class="form-check-label" for="furniture_<?= $fId ?>"><?= htmlspecialcharsbx($fName) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Конфиденциальный поиск
				<span class="bx-helpdesk ml-1" data-hint="Если укажете 'Да', то вакансия не будет размещена на сторонних сайтах"></span>
            </label>
            <select name="confidential" class="form-control">
              <option>Нет</option>
              <option>Да</option>
            </select>
          </div>
          <div class="form-group col-md-6">
            <label>Есть ли внутренний кандидат на данную должность? <span class="text-danger">*</span>
              <span class="bx-helpdesk ml-1" data-hint="Укажите, есть ли внутренний кандидат на эту должность?"></span>
            </label>
            <input type="text" class="form-control" name="internal_candidate" required>
          </div>
        </div>
        <div class="form-group">
          <label>Отделы для поиска внутренних кандидатов <span class="text-danger">*</span>
            <span class="bx-helpdesk ml-1" data-hint="Укажите из каких отделов, направлений рекрутер может искать внутренних кандидатов. Если у вас есть готовый кандидат, то впишите его ФИО"></span>
          </label>
          <textarea class="form-control" name="internal_departments" rows="3" required></textarea>
        </div>
        <div class="form-group">
          <label>С каких должностей можем рассматривать кандидатов <span class="text-danger">*</span></label>
          <textarea class="form-control" name="from_positions" rows="3" required></textarea>
        </div>
      </div>
    </div>
    <div class="text-right">
      <button type="submit" class="btn btn-primary" name="action" value="save" id="saveBtn">Отправить заявку</button>
      <a href="/bizproc/processes/201/view/0/?list_section_id=" class="btn btn-link">К списку заявок</a>
    </div>
  </form>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var FORM_STATE = <?= CUtil::PhpToJSObject($formState, false, true) ?>;

BX.ready(function(){
  function restorePostedFormData(state){
    if (!state || typeof state !== 'object') return;

    Object.keys(state).forEach(function(key){
      var value = state[key];
      var $named = $('[name="' + key + '"]');
      var $namedArray = $('[name="' + key + '[]"]');

      if (Array.isArray(value)) {
        if ($namedArray.length) {
          $namedArray.each(function(){
            var current = $(this).val();
            var shouldCheck = value.map(String).indexOf(String(current)) !== -1;
            $(this).prop('checked', shouldCheck);
          });
        } else if ($named.length) {
          $named.each(function(){
            var current = $(this).val();
            var shouldCheck = value.map(String).indexOf(String(current)) !== -1;
            if ($(this).is(':checkbox, :radio')) {
              $(this).prop('checked', shouldCheck);
            } else if (value.length > 0) {
              $(this).val(value[0]);
            }
          });
        }
        return;
      }

      if ($named.length) {
        $named.each(function(){
          if ($(this).is(':checkbox, :radio')) {
            $(this).prop('checked', String($(this).val()) === String(value));
          } else {
            $(this).val(value);
          }
        });
      } else if ($namedArray.length) {
        $namedArray.each(function(){
          var current = $(this).val();
          var shouldCheck = String(current) === String(value);
          $(this).prop('checked', shouldCheck);
        });
      }
    });
  }

  restorePostedFormData(FORM_STATE);

  BX.UI.Hint.init();
  var $legal = $('select[name="legal"]');
  $legal.find('option').each(function(){
    var t = $(this).text();
    if (t && t.indexOf('&quot;') !== -1) {
      $(this).text(t.replace(/&quot;/g, '"'));
    }
  });
  function toggleReasonBlocks(){
    var v = $('#reasonSelect').val();
    $('.reason-block').hide().find('input,textarea').prop('required', false);
    if (!v) return;
    if (v === 'new_unit') {
      $('#reason_new_unit').show().find('textarea').prop('required', true);
    } else if (v === 'maternity') {
      $('#reason_maternity').show().find('input').prop('required', true);
    } else if (v === 'replacement') {
      $('#reason_replacement').show().find('input').prop('required', true);
    } else if (v === 'transfer') {
      $('#reason_transfer').show().find('input').prop('required', true);
    }
  }
  $('#reasonSelect').on('change', toggleReasonBlocks);
  toggleReasonBlocks();
  $('#positionNotInList').on('change', function(){
    var isChecked = $(this).is(':checked');
    $('#positionSelect').prop('disabled', isChecked).prop('required', !isChecked);
    $('#positionCustomWrap').toggle(isChecked);
    $('#positionCustom').prop('required', isChecked);

    // Если должность "не в списке" — очищаем исходные обязанности и текст
    if (isChecked) {
      $('#dutiesOriginal').val('');
      $('#duties').val('');
    }
  });
  $('#positionSelect').on('change', function(){
    var duties = $('#positionSelect option:selected').data('duties') || '';
    $('#duties').val(duties);
    $('#dutiesOriginal').val(duties);
  });
  function toggleTripDuration(){
    var v = $('#businessTrips').val();
    if (v && v.indexOf('Да') === 0){
      $('#tripDurationWrap').show();
      $('#tripDuration').prop('required', true);
    } else {
      $('#tripDurationWrap').hide();
      $('#tripDuration').prop('required', false).val('');
    }
  }
  $('#businessTrips').on('change', toggleTripDuration);
  toggleTripDuration();
  $('#positionSearch').on('input', function(){
    var q = $(this).val().trim().toLowerCase();
    var currentSelectedValue = $('#positionSelect').val();
    $('#positionSelect option').each(function(idx){
      if (idx === 0) return;
      $(this).prop('hidden', false);
    });
    if (q) {
      $('#positionSelect option').each(function(idx){
        if (idx === 0) return;
        var name = ($(this).data('name') || '').toString().toLowerCase();
        var dept = ($(this).data('dept') || '').toString().toLowerCase();
        if (name.indexOf(q) === -1 && dept.indexOf(q) === -1) {
          $(this).prop('hidden', true);
        }
      });
    }
    var selectedOption = $('#positionSelect option[value="' + currentSelectedValue + '"]');
    if (selectedOption.length > 0 && !selectedOption.prop('hidden')) {
      $('#positionSelect').val(currentSelectedValue);
    } else {
      if (currentSelectedValue) {
        $('#positionSelect').val('');
      }
    }
  });

  function syncFurnitureValue() {
    var hasMulti = $('.furniture-multi:checked').length > 0;
    var hasSingle = $('.furniture-single:checked').length > 0;
    if (hasMulti) {
      $('.furniture-single').prop('checked', false);
      hasSingle = false;
    }
    if (hasSingle) {
      $('.furniture-multi').prop('checked', false);
      hasMulti = false;
    }
    $('#furnitureRequired').val((hasMulti || hasSingle) ? 'Y' : '');
  }
  $('.furniture-input').on('change', syncFurnitureValue);
  syncFurnitureValue();
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>
