<?php
/**
 * Script: create_tasks.php
 * Version: v1.3 (2026-02-24)
 *
 * ДОРАБОТКИ v1.3:
 * 1) Поля 1–6 обязательные:
 *    - 1–3: чекбоксы обязаны быть отмечены (уже было) + добавлена HTML-валидация и явная проверка (осталась).
 *    - 4.1–4.3, 5, 6: обязательные текстовые поля (уже было) — оставлено + усилена проверка.
 * 2) Должна быть добавлена хотя бы 1 задача в таблице KPI:
 *    - Проверка на фронте: нельзя сохранить без хотя бы 1 непустой строки KPI.
 *    - Проверка на бэке: reject, если после фильтрации нет ни одной KPI-задачи.
 *
 * FIX v1.2 сохранён:
 *  - Предзаполнение 4.1/4.2/4.3 при повторном заходе (берём из задачи №4).
 */

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

Loader::includeModule('iblock');
Loader::includeModule('lists');
Loader::includeModule('bizproc');

global $USER, $APPLICATION;

CJSCore::Init(['main.core', 'ui', 'ui.entity-selector', 'ajax', 'popup']);

if (!$USER->IsAuthorized()) {
    header('Content-Type: text/html; charset=utf-8');
    die('Ошибка: пользователь не авторизован.');
}
$currentUserId = (int)$USER->GetID();

/* ==============================
 * Helpers
 * ============================== */

function sendJsonResponse(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level()) { ob_end_clean(); }
    die(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function getUserNameById($userId): string
{
    $userId = (int)$userId;
    if ($userId <= 0) return '';
    $rs = CUser::GetByID($userId);
    if ($ar = $rs->Fetch()) {
        $fio = trim(($ar['LAST_NAME'] ?? '') . ' ' . ($ar['NAME'] ?? '') . ' ' . ($ar['SECOND_NAME'] ?? ''));
        return $fio !== '' ? $fio : ('ID ' . $userId);
    }
    return '';
}

function getBPTaskId($documentId, $userId): int
{
    $iblockId = 359; // ПВД
    $documentType = ['lists', 'Bitrix\Lists\BizprocDocumentLists', "iblock_" . $iblockId];
    $documentId = ['lists', 'Bitrix\Lists\BizprocDocumentLists', (int)$documentId];

    $states = CBPDocument::GetDocumentStates($documentType, $documentId);
    foreach ($states as $state) {
        if (($state['STATE_NAME'] ?? '') === 'InProgress') {
            $tasks = CBPDocument::GetUserTasksForWorkflow((int)$userId, $state['ID']);
            if (!empty($tasks) && !empty($tasks[0]['ID'])) {
                return (int)$tasks[0]['ID'];
            }
        }
    }
    return 0;
}

function addWorkdaysFallback(string $dateBeginDMY, int $days): string
{
    $dt = \DateTime::createFromFormat('d.m.Y', $dateBeginDMY);
    if (!$dt) return $dateBeginDMY;

    $step = $days >= 0 ? 1 : -1;
    $remain = abs($days);

    while ($remain > 0) {
        $dt->modify(($step > 0 ? '+1 day' : '-1 day'));
        $w = (int)$dt->format('N'); // 1..7
        if ($w <= 5) $remain--;
    }
    return $dt->format('d.m.Y');
}

function addworkday_safe(string $dateBeginDMY, int $days): string
{
    if (function_exists('addworkday_1c')) {
        try {
            $res = addworkday_1c($dateBeginDMY, $days);
            if (is_string($res) && preg_match('~^\d{2}\.\d{2}\.\d{4}$~', $res)) return $res;
        } catch (\Throwable $e) {}
    }
    return addWorkdaysFallback($dateBeginDMY, $days);
}

function getPropertyMap(int $iblockId, int $elementId): array
{
    $map = [];
    $it = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc', 'id' => 'asc'], []);
    while ($p = $it->Fetch()) {
        $map[$p['CODE']] = $p;
    }
    return $map;
}

function detectEmployeePropCode(int $iblockId, array $preferredCodes = [], array $preferredNamesContains = []): string
{
    $preferredCodes = array_filter(array_map('strval', $preferredCodes));
    $preferredNamesContains = array_filter(array_map('strval', $preferredNamesContains));

    $props = [];
    $rs = CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
    while ($p = $rs->Fetch()) $props[] = $p;

    foreach ($props as $p) {
        $code = (string)($p['CODE'] ?? '');
        if ($code !== '' && in_array($code, $preferredCodes, true)) return $code;
    }

    foreach ($props as $p) {
        $name = mb_strtolower((string)($p['NAME'] ?? ''));
        foreach ($preferredNamesContains as $needle) {
            if ($needle !== '' && mb_strpos($name, mb_strtolower($needle)) !== false) {
                return (string)($p['CODE'] ?? '');
            }
        }
    }

    foreach ($props as $p) {
        if ((string)($p['PROPERTY_TYPE'] ?? '') === 'S' && (string)($p['USER_TYPE'] ?? '') === 'employee') {
            $code = (string)($p['CODE'] ?? '');
            if ($code !== '') return $code;
        }
    }

    return '';
}

function getEmployeeValueFromElement(int $iblockId, int $elementId, ?string $code, ?int $fallbackPropId): int
{
    if ($code) {
        $props = getPropertyMap($iblockId, $elementId);
        if (!empty($props[$code]['VALUE'])) return (int)$props[$code]['VALUE'];
    }

    if ($fallbackPropId) {
        $it = CIBlockElement::GetProperty($iblockId, $elementId, [], ['ID' => $fallbackPropId]);
        if ($p = $it->Fetch()) {
            if (!empty($p['VALUE'])) return (int)$p['VALUE'];
        }
    }

    return 0;
}

/**
 * Парсинг полей 4.1/4.2/4.3 из текста результата задачи №4.
 */
function parseTask4Contacts(string $text): array
{
    $out = ['org' => '', 'mentors' => '', 'internal' => '', 'other' => ''];
    $t = str_replace(["\r\n", "\r"], "\n", $text);

    $patterns = [
        'org'      => '~(?:^|\n)\s*Организационные вопросы:\s*(.+?)\s*(?=\n\S|$)~u',
        'mentors'  => '~(?:^|\n)\s*Наставники/коллеги,\s*по вопросам выполнения задач на ИС:\s*(.+?)\s*(?=\n\S|$)~u',
        'internal' => '~(?:^|\n)\s*Внутренние заказчики:\s*(.+?)\s*(?=\n\S|$)~u',
        'other'    => '~(?:^|\n)\s*Прочие контакты:\s*(.+?)\s*(?=\n\S|$)~u',
    ];

    foreach ($patterns as $k => $re) {
        if (preg_match($re, $t, $m)) {
            $val = trim((string)($m[1] ?? ''));
            $val = preg_replace('~\n.*$~u', '', $val);
            $out[$k] = trim($val);
        }
    }
    return $out;
}

/* ==============================
 * IDs / constants
 * ============================== */

$planIblockId      = 359; // ПВД
$baseTasksIblockId = 360; // Основные задачи ПВД
$kpiTasksIblockId  = 363; // Задачи KPI

$propCodeBaseTasksLink = 'ZADACHI_PO_PLANU_VVODA_V_DOLZHNOST'; // PROPERTY_2761
$propCodeKpiTasksLink  = 'ZADACHI_KPI';                        // PROPERTY_2769

$STATUS_INIT = 3396791;

$KONTROL_YES = 6182;
$KONTROL_NO  = 6183;

$TIP_TASK_1 = 3347538;
$TIP_TASK_2 = 3347539;
$TIP_TASK_3 = 3347540;
$TIP_TASK_4 = 3347541;
$TIP_TASK_5 = 3347542;
$TIP_TASK_6 = 3347602;

/* ==============================
 * Input
 * ============================== */

$planId = 0;
if (isset($_GET['id'])) $planId = (int)$_GET['id'];
if (!$planId && isset($_GET['ID'])) $planId = (int)$_GET['ID'];

if ($planId <= 0) {
    header('Content-Type: text/html; charset=utf-8');
    die('Ошибка: ID плана ввода в должность не указан. Используйте ?id=1234');
}

$planElement = CIBlockElement::GetByID($planId)->Fetch();
if (!$planElement) {
    header('Content-Type: text/html; charset=utf-8');
    die('Ошибка: план ввода в должность не найден.');
}

$planProps = getPropertyMap($planIblockId, $planId);

$employeeUserId = getEmployeeValueFromElement($planIblockId, $planId, 'UZ_SOTRUDNIKA', 2787);

$rukoUserId     = (int)($planProps['RUKOVODITEL']['VALUE'] ?? 0);
$rekrUserId     = (int)($planProps['REKRUTER']['VALUE'] ?? 0);
$dateHireRaw    = (string)($planProps['DATA_TRUDOUSTROYSTVA']['VALUE'] ?? '');
$dateIsEndRaw   = (string)($planProps['DATA_OKONCHANIYA_IS']['VALUE'] ?? '');
$pdfUrl         = '/pub/apps/plans/plan.php?id_plan=' . $planId;

if ($rukoUserId <= 0) { header('Content-Type: text/html; charset=utf-8'); die('Ошибка: не указан руководитель (RUKOVODITEL) в ПВД.'); }
if ($dateHireRaw === '') { header('Content-Type: text/html; charset=utf-8'); die('Ошибка: не указана дата трудоустройства (DATA_TRUDOUSTROYSTVA) в ПВД.'); }
if ($dateIsEndRaw === '') { header('Content-Type: text/html; charset=utf-8'); die('Ошибка: не указана дата окончания ИС (DATA_OKONCHANIYA_IS) в ПВД.'); }

$dateHireDMY = '';
{
    $t = strtotime($dateHireRaw);
    $dateHireDMY = $t ? date('d.m.Y', $t) : $dateHireRaw;
    if (!preg_match('~^\d{2}\.\d{2}\.\d{4}$~', $dateHireDMY)) {
        $dt = \DateTime::createFromFormat('d.m.Y H:i:s', $dateHireRaw) ?: \DateTime::createFromFormat('d.m.Y', $dateHireRaw);
        if ($dt) $dateHireDMY = $dt->format('d.m.Y');
    }
}

$employeeName = $employeeUserId > 0 ? getUserNameById($employeeUserId) : ($planElement['NAME'] ?? '');

$ddl_3  = addworkday_safe($dateHireDMY, 3);
$ddl_5  = addworkday_safe($dateHireDMY, 5);
$ddl_10 = addworkday_safe($dateHireDMY, 10);
$ddl_22 = addworkday_safe($dateHireDMY, 22);

$probationEndTs = strtotime($dateIsEndRaw);
$maxDueDateYMD = $probationEndTs ? date('Y-m-d', strtotime(date('Y-m-d', $probationEndTs) . ' -15 days')) : '';
$maxDueDateFormatted = $maxDueDateYMD ? date('d.m.Y', strtotime($maxDueDateYMD)) : '';

$kpiTaskTypes = [];
$rsEnum = CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $kpiTasksIblockId, 'CODE' => 'TIP_ZADACHI_KPI']);
while ($e = $rsEnum->Fetch()) {
    $kpiTaskTypes[] = ['id' => (int)$e['ID'], 'name' => (string)$e['VALUE'], 'xml_id' => (string)$e['XML_ID']];
}
if (empty($kpiTaskTypes)) { header('Content-Type: text/html; charset=utf-8'); die('Ошибка: не удалось загрузить справочник типов KPI (TIP_ZADACHI_KPI).'); }

$existingBaseTaskIds = [];
$existingKpiTaskIds  = [];

$it = CIBlockElement::GetProperty($planIblockId, $planId, [], ['CODE' => $propCodeBaseTasksLink]);
while ($p = $it->Fetch()) if (!empty($p['VALUE'])) $existingBaseTaskIds[] = (int)$p['VALUE'];

$it = CIBlockElement::GetProperty($planIblockId, $planId, [], ['CODE' => $propCodeKpiTasksLink]);
while ($p = $it->Fetch()) if (!empty($p['VALUE'])) $existingKpiTaskIds[] = (int)$p['VALUE'];

$existingKpiTasks = [];
if (!empty($existingKpiTaskIds)) {
    $rs = CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $kpiTasksIblockId, 'ID' => $existingKpiTaskIds], false, false, ['ID', 'NAME', 'PREVIEW_TEXT']);
    while ($el = $rs->Fetch()) {
        $pid = (int)$el['ID'];
        $props = getPropertyMap($kpiTasksIblockId, $pid);
        $existingKpiTasks[] = [
            'id' => $pid,
            'type' => (int)($props['TIP_ZADACHI_KPI']['VALUE'] ?? 0),
            'planned_result' => (string)($props['PLANIRUEMY_REZULTAT']['VALUE'] ?? $el['PREVIEW_TEXT'] ?? ''),
            'weight' => (int)($props['VES']['VALUE'] ?? 0),
            'due_date' => (string)($props['SROK']['VALUE'] ?? ''),
        ];
    }
}

$baseTasksByTip = [];
if (!empty($existingBaseTaskIds)) {
    $rs = CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $baseTasksIblockId, 'ID' => $existingBaseTaskIds], false, false, ['ID', 'NAME', 'PREVIEW_TEXT']);
    while ($el = $rs->Fetch()) {
        $tid = (int)$el['ID'];
        $props = getPropertyMap($baseTasksIblockId, $tid);
        $tip = (int)($props['TIP_ZADACHI']['VALUE'] ?? 0);
        if ($tip > 0) {
            $baseTasksByTip[$tip] = [
                'id' => $tid,
                'name' => (string)$el['NAME'],
                'result' => (string)($props['PLANIRUEMYY_REZULTAT']['VALUE'] ?? $el['PREVIEW_TEXT'] ?? ''),
                'due' => (string)($props['PLANIRUEMYY_SROK_ISPOLNENIYA']['VALUE'] ?? ''),
            ];
        }
    }
}

$pref_41 = '';
$pref_42 = '';
$pref_43 = '';

$pref_5  = "Положение о добровольном медицинском страховании\n"
         . "Инструкция по охране труда для дистанционных работников\n"
         . "Положение об обучении и развитии персонала\n"
         . "Памятка пользователю по информационной безопасности";
$pref_6  = '';

if (!empty($baseTasksByTip[$TIP_TASK_4]['result'])) {
    $parsed = parseTask4Contacts((string)$baseTasksByTip[$TIP_TASK_4]['result']);
    $pref_41 = trim((string)($parsed['org'] ?? ''));
    $pref_42 = trim((string)($parsed['mentors'] ?? ''));
    $pref_43 = trim((string)($parsed['internal'] ?? ''));
    if ($pref_43 === '') $pref_43 = trim((string)($parsed['other'] ?? ''));
}

if (!empty($baseTasksByTip[$TIP_TASK_5]['result'])) $pref_5 = $baseTasksByTip[$TIP_TASK_5]['result'];
if (!empty($baseTasksByTip[$TIP_TASK_6]['result'])) $pref_6 = $baseTasksByTip[$TIP_TASK_6]['result'];

$baseExecutorPropCode = detectEmployeePropCode($baseTasksIblockId, ['UZ_SOTRUDNIKA', 'ISPOLNITEL', 'SOTRUDNIK', 'EXECUTOR'], ['исполнитель', 'сотрудник']);
if ($baseExecutorPropCode === '') $baseExecutorPropCode = 'UZ_SOTRUDNIKA';

/* ==============================
 * POST handler
 * ============================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payload'])) {
    $resp = [
        'success' => true,
        'messages' => [],
        'base_created' => [],
        'base_updated' => [],
        'kpi_created' => [],
        'kpi_updated' => [],
        'kpi_deleted' => [],
    ];

    try {
        $payload = json_decode((string)$_POST['payload'], true);
        if (!is_array($payload)) {
            throw new Exception('Некорректные данные формы (payload).');
        }

        // 1–3 обязательные чекбоксы
        $cb1 = !empty($payload['cb1']);
        $cb2 = !empty($payload['cb2']);
        $cb3 = !empty($payload['cb3']);
        if (!$cb1 || !$cb2 || !$cb3) {
            throw new Exception('Поля 1–3 обязательны: отметьте все чек-боксы.');
        }

        // 4.1–4.3, 5, 6 обязательные текстовые
        $t41 = trim((string)($payload['contacts_41'] ?? ''));
        $t42 = trim((string)($payload['contacts_42'] ?? ''));
        $t43 = trim((string)($payload['contacts_43'] ?? ''));
        $t5  = trim((string)($payload['lna_5'] ?? ''));
        $t6  = trim((string)($payload['systems_6'] ?? ''));

        if ($t41 === '' || $t42 === '' || $t43 === '' || $t5 === '' || $t6 === '') {
            throw new Exception('Поля 4.1–4.3, 5 и 6 обязательны: заполните все значения.');
        }

        // KPI tasks
        $kpiTasks = $payload['kpi_tasks'] ?? [];
        if (!is_array($kpiTasks)) $kpiTasks = [];

        // v1.3: обязательно хотя бы 1 KPI задача (непустая строка)
        $nonEmptyKpiRows = [];
        foreach ($kpiTasks as $row) {
            if (!is_array($row)) continue;
            $typeId = (int)($row['type'] ?? 0);
            $plannedResult = trim((string)($row['planned_result'] ?? ''));
            $weight = (int)($row['weight'] ?? 0);
            $dueDate = trim((string)($row['due_date'] ?? ''));

            if ($typeId > 0 || $plannedResult !== '' || $weight > 0 || $dueDate !== '') {
                $nonEmptyKpiRows[] = $row;
            }
        }
        if (count($nonEmptyKpiRows) < 1) {
            throw new Exception('Добавьте хотя бы 1 задачу в таблицу KPI.');
        }

        // актуальные привязки
        $currentBaseIds = [];
        $it = CIBlockElement::GetProperty($planIblockId, $planId, [], ['CODE' => $propCodeBaseTasksLink]);
        while ($p = $it->Fetch()) if (!empty($p['VALUE'])) $currentBaseIds[] = (int)$p['VALUE'];

        $currentKpiIds = [];
        $it = CIBlockElement::GetProperty($planIblockId, $planId, [], ['CODE' => $propCodeKpiTasksLink]);
        while ($p = $it->Fetch()) if (!empty($p['VALUE'])) $currentKpiIds[] = (int)$p['VALUE'];

        // карта KPI типов
        $kpiTypeNameById = [];
        foreach ($kpiTaskTypes as $tt) $kpiTypeNameById[(int)$tt['id']] = (string)$tt['name'];

        /* ==============================
         * Upsert base tasks 1-6
         * ============================== */

        $baseDefs = [
            $TIP_TASK_1 => [
                'name' => 'Ознакомиться с планом ввода в должность',
                'result' => "Ознакомлен с планом ввода в должность.\nПознакомился со всей командой.",
                'due' => $ddl_3,
                'kontrol' => $KONTROL_NO,
                'btn' => 'Ознакомлен с планом ввода в должность',
                'otv' => $rukoUserId,
            ],
            $TIP_TASK_2 => [
                'name' => '2. Ознакомиться с KPI должности',
                'result' => 'Знаю KPI и понимаю, как на них влиять, какие задачи необходимо выполнить для достижения максимального результата.',
                'due' => $ddl_3,
                'kontrol' => $KONTROL_NO,
                'btn' => 'Ознакомлен с KPI должности',
                'otv' => $rukoUserId,
            ],
            $TIP_TASK_3 => [
                'name' => '3. Пройти Welcome-тренинг',
                'result' => 'Welcome-тренинг пройден',
                'due' => $ddl_10,
                'kontrol' => $KONTROL_YES,
                'btn' => 'Welcome-тренинг пройден',
                'otv' => $rekrUserId > 0 ? $rekrUserId : $rukoUserId,
            ],
            $TIP_TASK_4 => [
                'name' => '4. Взаимосвязи по должности',
                'result' =>
                    "Техническая поддержка: заявка в Jira или позвонить по добавочному\n" .
                    "Организационные вопросы: " . $t41 . "\n" .
                    "Наставники/коллеги, по вопросам выполнения задач на ИС: " . $t42 . "\n" .
                    "Внутренние заказчики: " . $t43 . "\n" .
                    "Прочие контакты: " . $t43,
                'due' => $ddl_5,
                'kontrol' => $KONTROL_YES,
                'btn' => 'Выполнить',
                'otv' => $rukoUserId,
            ],
            $TIP_TASK_5 => [
                'name' => '5. Изучить ЛНА',
                'result' => $t5,
                'due' => $ddl_5,
                'kontrol' => $KONTROL_NO,
                'btn' => 'Ознакомлен с ЛНА',
                'otv' => $rukoUserId,
            ],
            $TIP_TASK_6 => [
                'name' => '6. Обучение работы с системами, отчетами',
                'result' => $t6,
                'due' => $ddl_22,
                'kontrol' => $KONTROL_YES,
                'btn' => 'Сохранить',
                'otv' => $rukoUserId,
            ],
        ];

        $existingBaseByTip = [];
        if (!empty($currentBaseIds)) {
            $rs = CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $baseTasksIblockId, 'ID' => $currentBaseIds], false, false, ['ID', 'NAME']);
            while ($row = $rs->Fetch()) {
                $eid = (int)$row['ID'];
                $props = getPropertyMap($baseTasksIblockId, $eid);
                $tip = (int)($props['TIP_ZADACHI']['VALUE'] ?? 0);
                if ($tip > 0) $existingBaseByTip[$tip] = $eid;
            }
        }

        $el = new CIBlockElement();
        $newBaseIds = [];

        foreach ($baseDefs as $tipId => $def) {
            $properties = [
                'PLAN_VVODA_V_DOLZHNOST' => $planId,
                'PLANIRUEMYY_REZULTAT' => $def['result'],
                'PLANIRUEMYY_SROK_ISPOLNENIYA' => $def['due'],
                'TREBUETSYA_KONTROL' => $def['kontrol'],
                'TIP_ZADACHI' => (int)$tipId,
                'STATUS_ZADACHI' => $STATUS_INIT,
                'NAZVANIE_KNOPKI_ZADANIYA' => $def['btn'],
                'OTVETSTVENNYY' => (int)$def['otv'],
            ];

            if ($employeeUserId > 0) {
                $properties[$baseExecutorPropCode] = $employeeUserId;
                if ($baseExecutorPropCode !== 'UZ_SOTRUDNIKA') $properties['UZ_SOTRUDNIKA'] = $employeeUserId;
            }

            if (!empty($existingBaseByTip[$tipId])) {
                $taskId = (int)$existingBaseByTip[$tipId];
                $ok = $el->Update($taskId, [
                    'NAME' => $def['name'],
                    'PREVIEW_TEXT' => $def['result'],
                    'PROPERTY_VALUES' => $properties,
                ]);
                if (!$ok) throw new Exception('Ошибка обновления основной задачи (ID: ' . $taskId . '): ' . $el->LAST_ERROR);
                $resp['base_updated'][] = $taskId;
                $newBaseIds[] = $taskId;
            } else {
                $taskId = (int)$el->Add([
                    'IBLOCK_ID' => $baseTasksIblockId,
                    'NAME' => $def['name'],
                    'PREVIEW_TEXT' => $def['result'],
                    'PROPERTY_VALUES' => $properties,
                ]);
                if ($taskId <= 0) throw new Exception('Ошибка создания основной задачи: ' . $el->LAST_ERROR);
                $resp['base_created'][] = $taskId;
                $newBaseIds[] = $taskId;
            }
        }

        CIBlockElement::SetPropertyValuesEx($planId, $planIblockId, [
            $propCodeBaseTasksLink => $newBaseIds
        ]);

        /* ==============================
         * KPI tasks: upsert + delete removed
         * ============================== */

        $submittedKpiIds = [];
        $allKpiIds = [];

        foreach ($kpiTasks as $row) {
            if (!is_array($row)) continue;

            $id = (int)($row['id'] ?? 0);
            $typeId = (int)($row['type'] ?? 0);
            $plannedResult = trim((string)($row['planned_result'] ?? ''));
            $weight = (int)($row['weight'] ?? 0);
            $dueDate = trim((string)($row['due_date'] ?? ''));

            // пропускаем полностью пустые строки
            if ($typeId <= 0 && $plannedResult === '' && $weight <= 0 && $dueDate === '') {
                continue;
            }

            // строгая валидация KPI строк
            if ($typeId <= 0) throw new Exception('В KPI-таблице есть строка без "Тип задачи".');
            if ($plannedResult === '') throw new Exception('В KPI-таблице есть строка без "Планируемый результат".');
            if ($weight <= 0) throw new Exception('В KPI-таблице есть строка без "Вес (%)".');
            if ($dueDate === '') throw new Exception('В KPI-таблице есть строка без "Планируемая дата исполнения".');

            if ($maxDueDateYMD && preg_match('~^\d{2}\.\d{2}\.\d{4}$~', $dueDate)) {
                $dt = \DateTime::createFromFormat('d.m.Y', $dueDate);
                if ($dt) {
                    $rowYmd = $dt->format('Y-m-d');
                    if ($rowYmd > $maxDueDateYMD) {
                        $resp['messages'][] = 'Предупреждение: KPI-дата ' . $dueDate . ' позже рекомендованной ' . $maxDueDateFormatted . '.';
                    }
                }
            }

            $taskTypeName = $kpiTypeNameById[$typeId] ?? ('Тип #' . $typeId);

            $properties = [
                'PLAN_VVODA_V_DOLZHNOST' => $planId,
                'PLANIRUEMY_REZULTAT' => $plannedResult,
                'SROK' => $dueDate,
                'VES' => $weight,
                'OTVETSTVENNYY' => $rukoUserId,
                'TIP_ZADACHI_KPI' => $typeId,
                'STATUS' => $STATUS_INIT
            ];

            $el = new CIBlockElement();

            if ($id > 0 && in_array($id, $currentKpiIds, true)) {
                $ok = $el->Update($id, [
                    'NAME' => $taskTypeName,
                    'PREVIEW_TEXT' => $plannedResult,
                    'PROPERTY_VALUES' => $properties
                ]);
                if (!$ok) throw new Exception('Ошибка обновления KPI-задачи (ID: ' . $id . '): ' . $el->LAST_ERROR);
                $resp['kpi_updated'][] = $id;
                $allKpiIds[] = $id;
                $submittedKpiIds[] = $id;
            } else {
                $newId = (int)$el->Add([
                    'IBLOCK_ID' => $kpiTasksIblockId,
                    'NAME' => $taskTypeName,
                    'PREVIEW_TEXT' => $plannedResult,
                    'PROPERTY_VALUES' => $properties
                ]);
                if ($newId <= 0) throw new Exception('Ошибка создания KPI-задачи: ' . $el->LAST_ERROR);
                $resp['kpi_created'][] = $newId;
                $allKpiIds[] = $newId;
                $submittedKpiIds[] = $newId;
            }
        }

        // v1.3: защитная проверка, что реально что-то создали/обновили после пропуска пустых
        if (count($allKpiIds) < 1) {
            throw new Exception('Добавьте хотя бы 1 задачу KPI (строка не должна быть пустой).');
        }

        $toDelete = array_values(array_diff($currentKpiIds, $submittedKpiIds));
        if (!empty($toDelete)) {
            foreach ($toDelete as $delId) {
                $delId = (int)$delId;
                if ($delId <= 0) continue;
                CIBlockElement::Delete($delId);
                $resp['kpi_deleted'][] = $delId;
            }
        }

        CIBlockElement::SetPropertyValuesEx($planId, $planIblockId, [
            $propCodeKpiTasksLink => $allKpiIds
        ]);

        $resp['messages'][] = 'План ввода в должность сохранён.';
        sendJsonResponse($resp);

    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => $e->getMessage(),
            'details' => $resp
        ]);
    }
}

/* ==============================
 * UI
 * ============================== */

$bpTaskId = getBPTaskId($planId, $currentUserId);
$backUrl = $bpTaskId
    ? ("/company/personal/bizproc/" . $bpTaskId . "/?back_url=%2Fcompany%2Fpersonal%2Fbizproc%2F")
    : "/company/personal/bizproc/";

$rukoName = $rukoUserId ? getUserNameById($rukoUserId) : '';
$rekrName = $rekrUserId ? getUserNameById($rekrUserId) : '';
$dateIsEndDMY = $probationEndTs ? date('d.m.Y', $probationEndTs) : $dateIsEndRaw;

$cb1Checked = !empty($baseTasksByTip[$TIP_TASK_1]['id']);
$cb2Checked = !empty($baseTasksByTip[$TIP_TASK_2]['id']);
$cb3Checked = !empty($baseTasksByTip[$TIP_TASK_3]['id']);

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>ПВД: создание задач (основные + KPI)</title>

    <?php
    $APPLICATION->ShowHead();
    $APPLICATION->ShowHeadStrings();
    $APPLICATION->ShowHeadScripts();
    ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Open Sans', Arial, sans-serif; background:#f5f7fb; margin:0; }
        .container { max-width: 1200px; margin: 20px auto; background:#fff; border-radius:10px; padding:22px; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
        h1 { margin: 0 0 10px; font-size: 20px; }
        h2 { margin: 22px 0 10px; font-size: 16px; }
        .info { background:#f8fafc; border:1px solid #e8eef6; border-radius:10px; padding:14px; }
        .info p { margin:6px 0; }
        .row { display:flex; gap:16px; flex-wrap:wrap; }
        .col { flex:1 1 320px; }
        .field { margin: 10px 0; }
        .hint { color:#6b778c; font-size: 12px; margin-top:6px; }
        input[type="text"], textarea, select, input[type="number"] {
            width:100%; border:1px solid #dfe6f0; border-radius:8px; padding:10px 12px; box-sizing:border-box;
            font-family: inherit; font-size: 14px;
        }
        textarea { min-height: 90px; resize: vertical; }
        .checkline { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border:1px solid #e8eef6; border-radius:10px; background:#fbfdff; margin:10px 0; }
        .checkline input { margin-top:3px; }
        .dead { color:#1f6feb; font-weight:600; }
        .subhead { margin: 14px 0 6px; font-weight:700; }

        .btn { display:inline-flex; align-items:center; justify-content:center; border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:700; }
        .btn-primary { background:#1f6feb; color:#fff; }
        .btn-secondary { background:#eef2ff; color:#1f2a44; }
        .btn-danger { background:#ffecec; color:#a31212; }
        .btn + .btn { margin-left:10px; }
        .actions { display:flex; justify-content:flex-end; margin-top:18px; gap:10px; flex-wrap:wrap; }

        table { width:100%; border-collapse:collapse; margin-top:10px; }
        th, td { border:1px solid #e8eef6; padding:10px; vertical-align:top; }
        th { background:#f8fafc; text-align:left; font-size: 13px; }
        .td-actions { width: 90px; text-align:center; }
        .note { margin-top:8px; color:#6b778c; font-size:12px; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .link { color:#1f6feb; text-decoration:none; }
        .link:hover { text-decoration:underline; }

        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; padding:16px; z-index:9999; }
        .modal { background:#fff; border-radius:14px; width:min(720px, 100%); padding:16px; box-shadow:0 12px 40px rgba(0,0,0,.25); }
        .modal h3 { margin:0 0 8px; }
        .modal .m-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:14px; }
    </style>
</head>

<body>
<div class="container">
    <div class="topbar">
        <h1>План ввода в должность — задачи (основные 1–6 + KPI)</h1>
        <div><a class="link" href="<?= htmlspecialchars($backUrl) ?>">← Назад</a></div>
    </div>

    <div class="info">
        <div class="row">
            <div class="col">
                <p><strong>Сотрудник:</strong> <?= htmlspecialchars($employeeName) ?></p>
                <p><strong>Руководитель:</strong> <?= htmlspecialchars($rukoName) ?></p>
                <p><strong>Рекрутер:</strong> <?= htmlspecialchars($rekrName) ?></p>
            </div>
            <div class="col">
                <p><strong>Дата трудоустройства:</strong> <?= htmlspecialchars($dateHireDMY) ?></p>
                <p><strong>Дата окончания ИС:</strong> <?= htmlspecialchars($dateIsEndDMY) ?></p>
                <p><strong>ПВД (PDF):</strong>
                    <?php if ($pdfUrl): ?>
                        <a class="link" href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank">Открыть PDF</a>
                    <?php else: ?>
                        <span style="color:#6b778c">не указан</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <h2>1–3. Обязательные действия (отметьте чек-боксами)</h2>

    <div class="checkline">
        <input type="checkbox" id="cb1" required <?= $cb1Checked ? 'checked' : '' ?> />
        <div>
            <label for="cb1" style="font-weight:700; margin:0;">1. Ознакомиться с планом ввода в должность до <span class="dead"><?= htmlspecialchars($ddl_3) ?></span></label>
            <div class="hint">Обязательный пункт.</div>
        </div>
    </div>

    <div class="checkline">
        <input type="checkbox" id="cb2" required <?= $cb2Checked ? 'checked' : '' ?> />
        <div>
            <label for="cb2" style="font-weight:700; margin:0;">2. Ознакомиться с KPI должности до <span class="dead"><?= htmlspecialchars($ddl_3) ?></span></label>
            <div class="hint">Обязательный пункт.</div>
        </div>
    </div>

    <div class="checkline">
        <input type="checkbox" id="cb3" required <?= $cb3Checked ? 'checked' : '' ?> />
        <div>
            <label for="cb3" style="font-weight:700; margin:0;">3. Пройти welcome-тренинг до <span class="dead"><?= htmlspecialchars($ddl_10) ?></span></label>
            <div class="hint">Обязательный пункт.</div>
        </div>
    </div>

    <h2>4. Взаимосвязи по должности (обязательно)</h2>

    <div class="subhead">4.1 Наставники/коллеги по вопросам выполнения задач на ИС</div>
    <div class="field">
        <button type="button" class="btn btn-secondary" id="pick41">Выбрать пользователей</button>
        <textarea id="contacts_41" required placeholder="ФИО через запятую"><?= htmlspecialchars($pref_41) ?></textarea>
        <div class="hint">Укажите коллег, с которыми будет взаимодействовать новый сотрудник</div>
    </div>

    <div class="subhead">4.2 Внутренние заказчики</div>
    <div class="field">
        <button type="button" class="btn btn-secondary" id="pick42">Выбрать пользователей</button>
        <textarea id="contacts_42" required placeholder="ФИО через запятую"><?= htmlspecialchars($pref_42) ?></textarea>
        <div class="hint">Укажите внутренних заказчиков</div>
    </div>

    <div class="subhead">4.3 Прочие контакты</div>
    <div class="field">
        <button type="button" class="btn btn-secondary" id="pick43">Выбрать пользователей</button>
        <textarea id="contacts_43" required placeholder="ФИО через запятую"><?= htmlspecialchars($pref_43) ?></textarea>
        <div class="hint">Укажите прочие контакты, с которыми будет взаимодействовать сотрудник</div>
    </div>

    <h2>5. Изучить локальные ЛНА до <span class="dead"><?= htmlspecialchars($ddl_5) ?></span> (обязательно)</h2>
    <div class="field">
        <textarea id="lna_5" required><?= htmlspecialchars($pref_5) ?></textarea>
    </div>

    <h2>6. Обучение работы с системами, отчетами до <span class="dead"><?= htmlspecialchars($ddl_22) ?></span> (обязательно)</h2>
    <div class="field">
        <textarea id="systems_6" required placeholder="Например: 1С, Directum, Confluence, отчет «____»"><?= htmlspecialchars($pref_6) ?></textarea>
    </div>

    <h2>KPI задачи (обязательно минимум 1 строка)</h2>
    <div class="note">
        <?php if ($maxDueDateFormatted): ?>
            Рекомендация: планируемая дата исполнения должна быть не позднее <strong><?= htmlspecialchars($maxDueDateFormatted) ?></strong> (окончание ИС − 15 дней).
        <?php endif; ?>
    </div>

    <div class="actions" style="justify-content:flex-start; margin-top:12px;">
        <button type="button" class="btn btn-secondary" id="addRow">+ Добавить строку</button>
    </div>

    <table id="kpiTable">
        <thead>
        <tr>
            <th style="width:220px;">Тип задачи</th>
            <th>Планируемый результат</th>
            <th style="width:120px;">Вес (%)</th>
            <th style="width:160px;">Планируемая дата</th>
            <th class="td-actions">Действие</th>
        </tr>
        </thead>
        <tbody id="kpiTbody"></tbody>
    </table>

    <div class="actions">
        <button type="button" class="btn btn-primary" id="saveBtn">Сохранить план ввода в должность</button>
    </div>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <h3>Подтверждение</h3>
        <div id="confirmText"></div>
        <div class="m-actions">
            <button class="btn btn-secondary" id="cancelSubmit">Отмена</button>
            <button class="btn btn-primary" id="confirmSubmit">Сохранить</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ru.js"></script>

<script>
    const BACK_URL = <?= json_encode($backUrl, JSON_UNESCAPED_UNICODE) ?>;
    const KpiTypes = <?= json_encode($kpiTaskTypes, JSON_UNESCAPED_UNICODE) ?>;
    const ExistingKpi = <?= json_encode($existingKpiTasks, JSON_UNESCAPED_UNICODE) ?>;
    const maxDueDateFormatted = <?= json_encode($maxDueDateFormatted, JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(m){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
        });
    }

    function buildTypeSelect(selectedId) {
        let html = `<select class="kpi-type" required>
            <option value="">— выберите —</option>`;
        KpiTypes.forEach(t => {
            const sel = (Number(selectedId) === Number(t.id)) ? 'selected' : '';
            html += `<option value="${t.id}" ${sel}>${escapeHtml(t.name)}</option>`;
        });
        html += `</select>`;
        return html;
    }

    function addRow(rowData = {}) {
        const tbody = document.getElementById('kpiTbody');
        const tr = document.createElement('tr');

        tr.dataset.id = rowData.id ? String(rowData.id) : '';

        tr.innerHTML = `
            <td>${buildTypeSelect(rowData.type || '')}</td>
            <td><textarea class="kpi-result" required>${escapeHtml(rowData.planned_result || '')}</textarea></td>
            <td><input type="number" class="kpi-weight" min="1" step="1" value="${rowData.weight ? Number(rowData.weight) : ''}" required></td>
            <td>
                <input type="text" class="kpi-date" placeholder="дд.мм.гггг" readonly required value="${escapeHtml(rowData.due_date || '')}">
                ${maxDueDateFormatted ? `<div class="note" style="margin-top:6px;">не позднее ${escapeHtml(maxDueDateFormatted)}</div>` : ``}
            </td>
            <td class="td-actions">
                <button type="button" class="btn btn-danger btn-del">Удалить</button>
            </td>
        `;

        tbody.appendChild(tr);

        const dateInput = tr.querySelector('.kpi-date');
        flatpickr(dateInput, {
            locale: "ru",
            dateFormat: "d.m.Y",
            allowInput: true,
            disableMobile: true
        });

        tr.querySelector('.btn-del').addEventListener('click', () => {
            tr.remove();
        });
    }

    if (Array.isArray(ExistingKpi) && ExistingKpi.length > 0) {
        ExistingKpi.forEach(r => addRow(r));
    } else {
        addRow(); // стартовая строка, чтобы было удобно
    }

    document.getElementById('addRow').addEventListener('click', () => addRow());

    function openUserSelector(targetTextareaId) {
        const ta = document.getElementById(targetTextareaId);

        if (typeof BX === 'undefined' || !BX.UI || !BX.UI.EntitySelector) {
            alert('Ошибка: BX/UI не загружены. Проверьте подключение core (ShowHeadScripts) и CJSCore::Init.');
            return;
        }

        const dialog = new BX.UI.EntitySelector.Dialog({
            title: "Выбор сотрудников",
            multiple: true,
            context: "PVD_CONTACTS_" + targetTextareaId,
            entities: [{ id: "user", options: { inviteGuestLink: false, emailUsers: false } }],
            events: {
                'Item:onSelect': function (event) {
                    const item = event.getData().item;
                    const title = item && item.getTitle ? item.getTitle() : '';
                    if (!title) return;

                    const cur = (ta.value || '').trim();
                    const list = cur ? cur.split(',').map(x => x.trim()).filter(Boolean) : [];
                    if (!list.includes(title.trim())) list.push(title.trim());
                    ta.value = list.join(', ');
                }
            }
        });

        dialog.show();
    }

    BX.ready(function () {
        document.getElementById('pick41').addEventListener('click', () => openUserSelector('contacts_41'));
        document.getElementById('pick42').addEventListener('click', () => openUserSelector('contacts_42'));
        document.getElementById('pick43').addEventListener('click', () => openUserSelector('contacts_43'));
    });

    const confirmModal = document.getElementById('confirmModal');
    const confirmText = document.getElementById('confirmText');
    const cancelSubmit = document.getElementById('cancelSubmit');
    const confirmSubmit = document.getElementById('confirmSubmit');

    function getNonEmptyKpiRows() {
        const rows = [];
        document.querySelectorAll('#kpiTbody tr').forEach(tr => {
            const id = tr.dataset.id ? Number(tr.dataset.id) : 0;
            const type = Number(tr.querySelector('.kpi-type').value || 0);
            const planned_result = (tr.querySelector('.kpi-result').value || '').trim();
            const weight = Number(tr.querySelector('.kpi-weight').value || 0);
            const due_date = (tr.querySelector('.kpi-date').value || '').trim();

            rows.push({id, type, planned_result, weight, due_date});
        });

        const nonEmpty = rows.filter(r => (r.type || r.planned_result || r.weight || r.due_date));
        return { rows, nonEmpty };
    }

    document.getElementById('saveBtn').addEventListener('click', () => {
        const cb1 = document.getElementById('cb1').checked;
        const cb2 = document.getElementById('cb2').checked;
        const cb3 = document.getElementById('cb3').checked;

        const t41 = (document.getElementById('contacts_41').value || '').trim();
        const t42 = (document.getElementById('contacts_42').value || '').trim();
        const t43 = (document.getElementById('contacts_43').value || '').trim();
        const t5  = (document.getElementById('lna_5').value || '').trim();
        const t6  = (document.getElementById('systems_6').value || '').trim();

        // v1.3: все 1–6 обязательные
        if (!cb1 || !cb2 || !cb3) { alert('Поля 1–3 обязательны: отметьте все чек-боксы.'); return; }
        if (!t41 || !t42 || !t43 || !t5 || !t6) { alert('Поля 4.1–4.3, 5 и 6 обязательны: заполните все значения.'); return; }

        const { rows, nonEmpty } = getNonEmptyKpiRows();

        // v1.3: минимум 1 KPI задача
        if (nonEmpty.length < 1) {
            alert('Добавьте хотя бы 1 задачу в таблицу KPI.');
            return;
        }

        const payload = {
            cb1: cb1 ? 1 : 0,
            cb2: cb2 ? 1 : 0,
            cb3: cb3 ? 1 : 0,
            contacts_41: t41,
            contacts_42: t42,
            contacts_43: t43,
            lna_5: t5,
            systems_6: t6,
            kpi_tasks: rows
        };

        confirmText.innerHTML = `
            Будут сохранены:
            <ul>
                <li>Основные задачи 1–6</li>
                <li>KPI задач: <strong>${nonEmpty.length}</strong> строк(и)</li>
            </ul>
            Продолжить?
        `;

        confirmModal.style.display = 'flex';

        confirmSubmit.onclick = function () {
            confirmModal.style.display = 'none';

            const body = 'payload=' + encodeURIComponent(JSON.stringify(payload));

            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body
            })
                .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t); }))
                .then(data => {
                    if (data.success) {
                        let msg = (data.messages && data.messages.length) ? data.messages.join("\n") : "Готово.";
                        msg += "\n\nОсновные задачи: создано " + (data.base_created?.length || 0) + ", обновлено " + (data.base_updated?.length || 0);
                        msg += "\nKPI: создано " + (data.kpi_created?.length || 0) + ", обновлено " + (data.kpi_updated?.length || 0) + ", удалено " + (data.kpi_deleted?.length || 0);
                        alert(msg);
                        window.location.href = BACK_URL;
                    } else {
                        alert('Ошибка: ' + (data.message || 'неизвестно'));
                    }
                })
                .catch(err => alert('Ошибка сохранения: ' + err.message));
        };
    });

    cancelSubmit.addEventListener('click', () => confirmModal.style.display = 'none');
    confirmModal.addEventListener('click', (e) => { if (e.target === confirmModal) confirmModal.style.display = 'none'; });
</script>
</body>
</html>
