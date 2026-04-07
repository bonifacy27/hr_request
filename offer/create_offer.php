<?php
/**
 * Форма создания заявки на оффер.
 * URL: /forms/staff_recruiting/offer/create_offer.php?id_ankety=12345
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание заявки на оффер');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

Extension::load([
    'main.core',
    'ui.entity-selector',
]);

const IBL_CANDIDATES = 207;
const IBL_REQUESTS = 201;
const IBL_OFFERS = 218;
const PROP_REQ_OFFERS_MULTI = 3128;

const OFFER_PROP_CANDIDATE_FIO = 1157;
const OFFER_PROP_CANDIDATE_PHONE = 1158;
const OFFER_PROP_PLANNED_SEND_DATE = 1159;
const OFFER_PROP_IS_CHIEF_POSITION = 1618;
const OFFER_PROP_POSITION = 1161;
const OFFER_PROP_DIRECTION = 1996;
const OFFER_PROP_DEPARTMENT = 1163;
const OFFER_PROP_CHIEF_FIO_FROM_LIST = 1164;
const OFFER_PROP_CHIEF_POSITION = 1169;
const OFFER_PROP_BONUS_RUB_GROSS = 1170;
const OFFER_PROP_MONTH_INCOME_AVG_GROSS = 1172;
const OFFER_PROP_SALARY = 1165;
const OFFER_PROP_ISN = 1184;
const OFFER_PROP_BONUS_TYPE = 1998;
const OFFER_PROP_BONUS_PERCENT = 1186;
const OFFER_PROP_TRIAL_PERIOD = 2001;
const OFFER_PROP_PLANNED_START_DATE = 1174;
const OFFER_PROP_BENEFITS = 1177;
const OFFER_PROP_WORK_FORMAT = 1327;
const OFFER_PROP_OFFICE = 1326;
const OFFER_PROP_WORK_SCHEDULE = 1328;
const OFFER_PROP_WORK_START = 1329;
const OFFER_PROP_EQUIPMENT = 2070;
const OFFER_PROP_EQUIPMENT_TEXT = 3130;
const OFFER_PROP_CONTRACT_TYPE = 2002;
const OFFER_PROP_ORGANIZATION = 2753;
const OFFER_PROP_HOUSING_COMPENSATION = 2755;
const OFFER_PROP_REGION_LOCATION = 1767;
const OFFER_PROP_PERSONAL_ALLOWANCE = 1234;
const OFFER_PROP_RAYON_COEFFICIENT = 1235;
const OFFER_PROP_RECRUITER = 1190;
const OFFER_PROP_REQUEST_ID = 1601;
const OFFER_PROP_CANDIDATE_ID = 1603;
const OFFER_PROP_FW_CANDIDATE_ID = 1602;
const OFFER_PROP_COMMENT = 2857;

const DEFAULT_EQUIPMENT = '3263612';
const DEFAULT_CONTRACT = '3263600';
const DEFAULT_ORGANIZATION = '3197820';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function userIdFromValue($raw): int
{
    $value = trim((string)$raw);
    if ($value === '') {
        return 0;
    }
    if (stripos($value, 'user_') === 0) {
        return (int)substr($value, 5);
    }
    if (preg_match('/(\\d+)/', $value, $m)) {
        return (int)$m[1];
    }
    return (int)$value;
}

function getIblockOptions(int $iblockId, array $selectFields = []): array
{
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
        $prepared = [
            'ID' => (string)$row['ID'],
            'NAME' => (string)$row['NAME'],
        ];
        foreach ($selectFields as $field) {
            $prepared[$field] = (string)($row[$field . '_VALUE'] ?? $row[$field] ?? '');
        }
        $res[] = $prepared;
    }
    return $res;
}

function getCandidateById(int $candidateId): ?array
{
    $select = [
        'ID',
        'PROPERTY_1083',
        'PROPERTY_1084',
        'PROPERTY_1085',
        'PROPERTY_1088',
        'PROPERTY_1596',
        'PROPERTY_1594',
        'PROPERTY_1323',
    ];

    $rs = CIBlockElement::GetList([], [
        'IBLOCK_ID' => IBL_CANDIDATES,
        'ACTIVE' => 'Y',
        'ID' => $candidateId,
        'CHECK_PERMISSIONS' => 'Y',
    ], false, ['nTopCount' => 1], $select);

    if (!($row = $rs->GetNext())) {
        return null;
    }

    $lastName = trim((string)($row['PROPERTY_1083_VALUE'] ?? ''));
    $firstName = trim((string)($row['PROPERTY_1084_VALUE'] ?? ''));
    $middleName = trim((string)($row['PROPERTY_1085_VALUE'] ?? ''));

    return [
        'ID' => (int)$row['ID'],
        'FIO' => trim($lastName . ' ' . $firstName . ' ' . $middleName),
        'PHONE' => trim((string)($row['PROPERTY_1088_VALUE'] ?? '')),
        'REQUEST_ID' => (int)($row['PROPERTY_1596_VALUE'] ?? 0),
        'FW_CANDIDATE_ID' => trim((string)($row['PROPERTY_1594_VALUE'] ?? '')),
        'RECRUITER_ID' => userIdFromValue($row['PROPERTY_1323_VALUE'] ?? ''),
    ];
}

function getRequestById(int $requestId): ?array
{
    $row = CIBlockElement::GetList([], [
        'IBLOCK_ID' => IBL_REQUESTS,
        'ACTIVE' => 'Y',
        'ID' => $requestId,
        'CHECK_PERMISSIONS' => 'Y',
    ], false, ['nTopCount' => 1], ['ID'])->GetNext();

    if (!$row) {
        return null;
    }

    $propCodes = [
        'RUKOVODYASHCHAYA_DOLZHNOST',
        'DOLZHNOST',
        'DIREKTSIYA',
        'PODRAZDELENIE',
        'NEPOSREDSTVENNYY_RUKOVODITEL',
        'DOLZHNOST_RUKOVODITELYA',
        'OKLAD',
        'ISN_RUB_GROSS',
        'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA',
        'PROTSENT_PREMII_',
        'FORMAT_RABOTY_PRIVYAZKA',
        'OFIS_PRIVYAZKA',
        'GRAFIK_RABOTY_PRIVYAZKA',
        'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA',
        'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA',
        'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA',
        'OBORUDOVANIE_DLYA_RABOTY_TEKST',
        'YURIDICHESKOE_LITSO',
    ];

    $props = [];
    CIBlockElement::GetPropertyValuesArray($props, IBL_REQUESTS, ['ID' => (int)$row['ID']], ['CODE' => $propCodes]);
    $p = $props[(int)$row['ID']] ?? [];

    $raw = static function (array $allProps, string $code): string {
        $v = $allProps[$code]['VALUE'] ?? '';
        if (is_array($v)) {
            $v = reset($v);
        }
        return trim((string)$v);
    };

    return [
        'CHIEF_POSITION_FLAG' => $raw($p, 'RUKOVODYASHCHAYA_DOLZHNOST'),
        'POSITION' => $raw($p, 'DOLZHNOST'),
        'DIRECTION' => $raw($p, 'DIREKTSIYA'),
        'DEPARTMENT' => $raw($p, 'PODRAZDELENIE'),
        'CHIEF' => $raw($p, 'NEPOSREDSTVENNYY_RUKOVODITEL'),
        'CHIEF_POSITION' => $raw($p, 'DOLZHNOST_RUKOVODITELYA'),
        'SALARY' => $raw($p, 'OKLAD'),
        'ISN' => $raw($p, 'ISN_RUB_GROSS'),
        'BONUS_TYPE' => $raw($p, 'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA'),
        'BONUS_PERCENT' => $raw($p, 'PROTSENT_PREMII_'),
        'WORK_FORMAT' => $raw($p, 'FORMAT_RABOTY_PRIVYAZKA'),
        'OFFICE' => $raw($p, 'OFIS_PRIVYAZKA'),
        'WORK_SCHEDULE' => $raw($p, 'GRAFIK_RABOTY_PRIVYAZKA'),
        'WORK_START' => $raw($p, 'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA'),
        'CONTRACT_TYPE' => $raw($p, 'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA'),
        'EQUIPMENT' => $raw($p, 'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA'),
        'EQUIPMENT_TEXT' => $raw($p, 'OBORUDOVANIE_DLYA_RABOTY_TEKST'),
        'ORGANIZATION' => $raw($p, 'YURIDICHESKOE_LITSO'),
    ];
}

function normalizeChiefPosition(string $flag): string
{
    if ($flag === 'Y') {
        return '1159';
    }
    return '1160';
}

function parseUserSelectorId($value): int
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return userIdFromValue($value);
}

function parseNumericInput($value): float
{
    $normalized = str_replace([' ', ','], ['', '.'], trim((string)$value));
    if ($normalized === '') {
        return 0.0;
    }
    return (float)$normalized;
}

function getUserWorkPosition(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return '';
    }
    return trim((string)($user['WORK_POSITION'] ?? ''));
}

function getUserDisplayNameById(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return (string)$userId;
    }
    $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
    return $name !== '' ? $name : (string)$userId;
}

function createRegionLocation(int $iblockId, string $name, float $rkValue, string $candidateFio, string $createdByFio = ''): array
{
    $name = trim($name);
    if ($name === '') {
        return ['id' => 0, 'error' => 'Пустое название региона.'];
    }
    $el = new CIBlockElement();
    $createdByFio = trim($createdByFio);
    $comment = 'Создан из оффера ' . trim($candidateFio);
    if ($createdByFio !== '') {
        $comment .= '. Добавил: ' . $createdByFio;
    }
    $id = $el->Add([
        'IBLOCK_ID' => $iblockId,
        'NAME' => $name,
        'ACTIVE' => 'Y',
        'PROPERTY_VALUES' => [
            1784 => ($rkValue > 1 ? 'Y' : 'N'),
            1765 => (string)$rkValue,
            1783 => $comment,
        ],
    ]);
    if (!$id) {
        return ['id' => 0, 'error' => (string)($el->LAST_ERROR ?: 'Не удалось создать регион-локацию.')];
    }
    return ['id' => (int)$id, 'error' => ''];
}

function appendOfferToRequest(int $requestId, int $offerId): void
{
    if ($requestId <= 0 || $offerId <= 0) {
        return;
    }

    $values = [];
    $rs = CIBlockElement::GetProperty(IBL_REQUESTS, $requestId, ['sort' => 'asc'], ['ID' => PROP_REQ_OFFERS_MULTI]);
    while ($p = $rs->Fetch()) {
        $v = (int)($p['VALUE'] ?? 0);
        if ($v > 0) {
            $values[] = $v;
        }
    }

    $values[] = $offerId;
    $values = array_values(array_unique(array_map('intval', $values)));

    CIBlockElement::SetPropertyValuesEx($requestId, IBL_REQUESTS, [
        PROP_REQ_OFFERS_MULTI => $values,
    ]);
}

if ((string)($_GET['ajax'] ?? '') === 'get_user_position') {
    header('Content-Type: application/json; charset=UTF-8');
    $userId = userIdFromValue($_GET['user_id'] ?? '');
    $position = getUserWorkPosition($userId);
    echo json_encode([
        'ok' => ($userId > 0),
        'user_id' => $userId,
        'position' => $position,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ((string)($_GET['ajax'] ?? '') === 'create_region') {
    global $USER;
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    $name = trim((string)($_POST['name'] ?? ''));
    $rk = (float)str_replace(',', '.', (string)($_POST['rk'] ?? '0'));
    $candidateFio = trim((string)($_POST['candidate_fio'] ?? ''));
    $debug = [
        'name' => $name,
        'rk' => $rk,
        'candidate_fio' => $candidateFio,
    ];
    $createdBy = '';
    if (is_object($USER) && method_exists($USER, 'GetFullName')) {
        $createdBy = trim((string)$USER->GetFullName());
        if ($createdBy === '' && method_exists($USER, 'GetID')) {
            $createdBy = 'ID ' . (int)$USER->GetID();
        }
    }
    if ($name === '' || $rk <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Некорректные параметры создания региона.', 'debug' => $debug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $created = createRegionLocation(293, $name, $rk, $candidateFio, $createdBy);
    if ((int)$created['id'] <= 0) {
        echo json_encode(['ok' => false, 'error' => (string)$created['error'], 'debug' => $debug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'id' => (int)$created['id'],
        'name' => $name,
        'rk' => $rk,
        'debug' => $debug,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$candidateId = (int)Context::getCurrent()->getRequest()->getQuery('id_ankety');
$candidate = null;
$requestItem = null;
$errors = [];
$saveMessage = null;

$formData = [
    'candidate_fio' => '',
    'candidate_phone' => '',
    'planned_send_date' => '',
    'is_chief_position' => '1160',
    'position' => '',
    'direction' => '',
    'department' => '',
    'chief' => '',
    'chief_position' => '',
    'salary' => '',
    'isn' => '',
    'bonus_type' => '',
    'bonus_percent' => '0',
    'bonus_rub_gross' => '',
    'month_income_avg_gross' => '',
    'trial_period' => '',
    'planned_start_date' => '',
    'region_location' => '',
    'rayon_coefficient' => '',
    'region_not_in_list' => '',
    'new_region_name' => '',
    'manual_region_rk' => '',
    'benefits' => 'ДМС по истечению испытательного срока',
    'work_format' => '',
    'office' => '',
    'work_schedule' => '',
    'work_start' => '',
    'equipment' => DEFAULT_EQUIPMENT,
    'equipment_text' => '',
    'contract_type' => DEFAULT_CONTRACT,
    'organization' => DEFAULT_ORGANIZATION,
    'housing_compensation' => '',
    'personal_allowance' => '0',
    'recruiter' => '',
    'request_id' => '',
    'candidate_id' => '',
    'fw_candidate_id' => '',
    'comment' => '',
];

if ($candidateId > 0) {
    $candidate = getCandidateById($candidateId);
    if (!$candidate) {
        $errors[] = 'Анкета кандидата не найдена.';
    } else {
        $formData['candidate_fio'] = $candidate['FIO'];
        $formData['candidate_phone'] = $candidate['PHONE'];
        $formData['recruiter'] = (string)$candidate['RECRUITER_ID'];
        $formData['request_id'] = (string)$candidate['REQUEST_ID'];
        $formData['candidate_id'] = (string)$candidate['ID'];
        $formData['fw_candidate_id'] = (string)$candidate['FW_CANDIDATE_ID'];
        $formData['comment'] = 'Из анкеты кандидата ' . (int)$candidate['ID'];

        if ((int)$candidate['REQUEST_ID'] > 0) {
            $requestItem = getRequestById((int)$candidate['REQUEST_ID']);
            if ($requestItem) {
                $formData['is_chief_position'] = normalizeChiefPosition((string)$requestItem['CHIEF_POSITION_FLAG']);
                $formData['position'] = (string)$requestItem['POSITION'];
                $formData['direction'] = (string)$requestItem['DIRECTION'];
                $formData['department'] = (string)$requestItem['DEPARTMENT'];
                $formData['chief'] = (string)$requestItem['CHIEF'];
                $formData['chief_position'] = (string)$requestItem['CHIEF_POSITION'];
                $formData['salary'] = (string)$requestItem['SALARY'];
                $formData['isn'] = (string)$requestItem['ISN'];
                $formData['bonus_type'] = (string)$requestItem['BONUS_TYPE'];
                $formData['bonus_percent'] = (string)($requestItem['BONUS_PERCENT'] !== '' ? $requestItem['BONUS_PERCENT'] : '0');
                $formData['work_format'] = (string)$requestItem['WORK_FORMAT'];
                $formData['office'] = (string)$requestItem['OFFICE'];
                $formData['work_schedule'] = (string)$requestItem['WORK_SCHEDULE'];
                $formData['work_start'] = (string)$requestItem['WORK_START'];
                $formData['equipment'] = (string)($requestItem['EQUIPMENT'] ?: DEFAULT_EQUIPMENT);
                $formData['equipment_text'] = (string)$requestItem['EQUIPMENT_TEXT'];
                $formData['contract_type'] = (string)($requestItem['CONTRACT_TYPE'] ?: DEFAULT_CONTRACT);
                $formData['organization'] = (string)($requestItem['ORGANIZATION'] ?: DEFAULT_ORGANIZATION);
            }
        }
    }
}
if ((int)$formData['chief'] > 0 && $formData['chief_position'] === '') {
    $formData['chief_position'] = getUserWorkPosition((int)$formData['chief']);
}

$formatList = getIblockOptions(234);
$officeList = getIblockOptions(233);
$scheduleList = getIblockOptions(236);
$startTimeList = getIblockOptions(237);
$equipmentList = getIblockOptions(326);
$contractList = getIblockOptions(325);
$organizationList = getIblockOptions(308);
$trialPeriodList = getIblockOptions(324);
$regionLocationList = getIblockOptions(293, ['PROPERTY_1765', 'PROPERTY_1832']);
$bonusTypeList = getIblockOptions(327);
$bonusTypeNameById = [];
foreach ($bonusTypeList as $bonusTypeRow) {
    $bonusTypeNameById[(string)$bonusTypeRow['ID']] = (string)$bonusTypeRow['NAME'];
}
$regionCalcById = [];
foreach ($regionLocationList as $regionRow) {
    $rid = (string)$regionRow['ID'];
    $regionCalcById[$rid] = [
        'rayon_coefficient' => (string)$regionRow['PROPERTY_1765'],
        'personal_allowance' => (string)$regionRow['PROPERTY_1832'],
    ];
}
$nameById = static function(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['ID']] = (string)$row['NAME'];
    }
    return $map;
};
$organizationNameById = $nameById($organizationList);
$contractNameById = $nameById($contractList);
$officeNameById = $nameById($officeList);
$formatNameById = $nameById($formatList);
$scheduleNameById = $nameById($scheduleList);
$startNameById = $nameById($startTimeList);
$equipmentNameById = $nameById($equipmentList);
$regionNameById = $nameById($regionLocationList);

$sourceSnapshot = null;
if ($candidateId > 0 && $candidate && $requestItem) {
    $sourceSnapshot = [
        'organization' => (string)$requestItem['ORGANIZATION'],
        'candidate_fio' => (string)$candidate['FIO'],
        'position' => (string)$requestItem['POSITION'],
        'department' => (string)$requestItem['DEPARTMENT'],
        'direction' => (string)$requestItem['DIRECTION'],
        'chief' => (string)$requestItem['CHIEF'],
        'is_chief_position' => normalizeChiefPosition((string)$requestItem['CHIEF_POSITION_FLAG']),
        'contract_type' => (string)$requestItem['CONTRACT_TYPE'],
        'salary' => (string)$requestItem['SALARY'],
        'isn' => (string)$requestItem['ISN'],
        'bonus_type' => (string)$requestItem['BONUS_TYPE'],
        'bonus_percent' => (string)$requestItem['BONUS_PERCENT'],
        'office' => (string)$requestItem['OFFICE'],
        'work_format' => (string)$requestItem['WORK_FORMAT'],
        'work_schedule' => (string)$requestItem['WORK_SCHEDULE'],
        'work_start' => (string)$requestItem['WORK_START'],
        'equipment' => (string)$requestItem['EQUIPMENT'],
        'equipment_text' => (string)$requestItem['EQUIPMENT_TEXT'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && (string)($_POST['action'] ?? '') === 'save') {
    foreach ($formData as $key => $defaultValue) {
        $formData[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $formData['region_not_in_list'] = (isset($_POST['region_not_in_list']) ? 'Y' : '');
    $formData['chief'] = (string)parseUserSelectorId($_POST['chief'] ?? '');
    if ((int)$formData['chief'] > 0 && $formData['chief_position'] === '') {
        $formData['chief_position'] = getUserWorkPosition((int)$formData['chief']);
    }

    if ($formData['candidate_fio'] === '') {
        $errors[] = 'Заполните поле «ФИО кандидата».';
    }
    if ($formData['position'] === '') {
        $errors[] = 'Заполните поле «Должность».';
    }
    if ($formData['planned_send_date'] === '') {
        $errors[] = 'Заполните поле «Планируемая дата отправки оффера кандидату».';
    }
    if ($formData['department'] === '') {
        $errors[] = 'Заполните поле «Подразделение».';
    }
    if ((int)$formData['chief'] <= 0) {
        $errors[] = 'Заполните поле «ФИО руководителя (из списка)».';
    }
    if ($formData['chief_position'] === '') {
        $errors[] = 'Заполните поле «Должность руководителя».';
    }
    if ($formData['region_not_in_list'] === 'Y') {
        $errors[] = 'Сначала нажмите кнопку «Добавить новый регион-локацию», затем выберите созданный регион в списке.';
    } elseif ($formData['region_location'] === '') {
        $errors[] = 'Заполните поле «Регион-локация кандидата».';
    } elseif (isset($regionCalcById[$formData['region_location']])) {
        $selectedRk = parseNumericInput($regionCalcById[$formData['region_location']]['rayon_coefficient']);
        if ($selectedRk <= 0) {
            $errors[] = 'Для региона с РК=0 нажмите «Добавить РК», затем выберите созданный регион.';
        }
    }
    if ($formData['salary'] === '') {
        $errors[] = 'Заполните поле «Оклад».';
    }
    if ($formData['bonus_type'] === '') {
        $errors[] = 'Заполните поле «Тип премирования».';
    }
    $bonusTypeName = mb_strtolower((string)($bonusTypeNameById[$formData['bonus_type']] ?? ''));
    if ((strpos($bonusTypeName, 'ежекварт') !== false || strpos($bonusTypeName, 'ежемесяч') !== false) && $formData['bonus_percent'] === '') {
        $errors[] = 'Поле «Процент премии» обязательно для выбранного типа премирования.';
    }
    if ($formData['trial_period'] === '') {
        $errors[] = 'Заполните поле «Испытательный срок».';
    }
    if ($formData['planned_start_date'] === '') {
        $errors[] = 'Заполните поле «Планируемая дата выхода на работу».';
    }
    if ($formData['benefits'] === '') {
        $errors[] = 'Заполните поле «Льготы».';
    }
    if ($formData['work_format'] === '') {
        $errors[] = 'Заполните поле «Формат работы».';
    }
    if ($formData['office'] === '') {
        $errors[] = 'Заполните поле «Офис».';
    }
    if ($formData['work_schedule'] === '') {
        $errors[] = 'Заполните поле «График работы».';
    }
    if ($formData['work_start'] === '') {
        $errors[] = 'Заполните поле «Начало рабочего дня».';
    }
    if ($formData['equipment'] === '') {
        $errors[] = 'Заполните поле «Оборудование».';
    }
    if ($formData['contract_type'] === '') {
        $errors[] = 'Заполните поле «Тип трудового договора».';
    }
    if ($formData['organization'] === '') {
        $errors[] = 'Заполните поле «Юридическое лицо».';
    }
    if ($formData['candidate_phone'] !== '' && !preg_match('/^\+7[0-9\\s\\-\\(\\)]{10,20}$/', $formData['candidate_phone'])) {
        $errors[] = 'Поле «Контактный телефон кандидата» должно быть в формате +7....';
    }
    if ($formData['personal_allowance'] !== '') {
        $personalAllowance = (float)$formData['personal_allowance'];
        if ($personalAllowance < 0 || $personalAllowance > 100) {
            $errors[] = 'Поле «Северная надбавка %%» должно быть в диапазоне от 0 до 100.';
        }
    }
    if ($formData['region_location'] === '0') {
        $formData['region_location'] = '';
    }
    if ($formData['region_location'] !== '' && isset($regionCalcById[$formData['region_location']])) {
        $formData['rayon_coefficient'] = (string)$regionCalcById[$formData['region_location']]['rayon_coefficient'];
    }

    $salaryNum = parseNumericInput($formData['salary']);
    $bonusPercentNum = parseNumericInput($formData['bonus_percent']);
    $isnNum = parseNumericInput($formData['isn']);
    $rayonNum = parseNumericInput($formData['rayon_coefficient']);
    $northPercentNum = parseNumericInput($formData['personal_allowance']);
    $bonusRubGross = round($salaryNum * $bonusPercentNum / 100);
    $monthlyBonusForIncome = $bonusRubGross;
    if (strpos($bonusTypeName, 'ежекварт') !== false) {
        $monthlyBonusForIncome = $bonusRubGross / 3;
    }
    $baseIncome = $salaryNum + $monthlyBonusForIncome + $isnNum;
    $monthIncomeAvg = round(($baseIncome * $rayonNum) + ($baseIncome * ($northPercentNum / 100)));
    $formData['bonus_rub_gross'] = (string)$bonusRubGross;
    $formData['month_income_avg_gross'] = (string)$monthIncomeAvg;

    if (empty($errors)) {
        $props = [
            OFFER_PROP_CANDIDATE_FIO => $formData['candidate_fio'],
            OFFER_PROP_CANDIDATE_PHONE => $formData['candidate_phone'],
            OFFER_PROP_PLANNED_SEND_DATE => $formData['planned_send_date'],
            OFFER_PROP_IS_CHIEF_POSITION => $formData['is_chief_position'],
            OFFER_PROP_POSITION => $formData['position'],
            OFFER_PROP_DIRECTION => $formData['direction'],
            OFFER_PROP_DEPARTMENT => $formData['department'],
            OFFER_PROP_CHIEF_FIO_FROM_LIST => parseUserSelectorId($_POST['chief'] ?? $formData['chief']),
            OFFER_PROP_CHIEF_POSITION => $formData['chief_position'],
            OFFER_PROP_BONUS_RUB_GROSS => $formData['bonus_rub_gross'],
            OFFER_PROP_MONTH_INCOME_AVG_GROSS => $formData['month_income_avg_gross'],
            OFFER_PROP_SALARY => $formData['salary'],
            OFFER_PROP_ISN => $formData['isn'],
            OFFER_PROP_BONUS_TYPE => $formData['bonus_type'],
            OFFER_PROP_BONUS_PERCENT => $formData['bonus_percent'],
            OFFER_PROP_TRIAL_PERIOD => $formData['trial_period'],
            OFFER_PROP_PLANNED_START_DATE => $formData['planned_start_date'],
            OFFER_PROP_BENEFITS => $formData['benefits'],
            OFFER_PROP_WORK_FORMAT => $formData['work_format'],
            OFFER_PROP_OFFICE => $formData['office'],
            OFFER_PROP_WORK_SCHEDULE => $formData['work_schedule'],
            OFFER_PROP_WORK_START => $formData['work_start'],
            OFFER_PROP_EQUIPMENT => ($formData['equipment'] !== '' ? $formData['equipment'] : DEFAULT_EQUIPMENT),
            OFFER_PROP_EQUIPMENT_TEXT => $formData['equipment_text'],
            OFFER_PROP_CONTRACT_TYPE => ($formData['contract_type'] !== '' ? $formData['contract_type'] : DEFAULT_CONTRACT),
            OFFER_PROP_ORGANIZATION => ($formData['organization'] !== '' ? $formData['organization'] : DEFAULT_ORGANIZATION),
            OFFER_PROP_HOUSING_COMPENSATION => $formData['housing_compensation'],
            OFFER_PROP_REGION_LOCATION => $formData['region_location'],
            OFFER_PROP_PERSONAL_ALLOWANCE => $formData['personal_allowance'],
            OFFER_PROP_RAYON_COEFFICIENT => $formData['rayon_coefficient'],
            OFFER_PROP_RECRUITER => (int)$formData['recruiter'],
            OFFER_PROP_REQUEST_ID => (int)$formData['request_id'],
            OFFER_PROP_CANDIDATE_ID => (int)$formData['candidate_id'],
            OFFER_PROP_FW_CANDIDATE_ID => $formData['fw_candidate_id'],
            OFFER_PROP_COMMENT => $formData['comment'],
        ];

        $el = new CIBlockElement();
        $offerId = $el->Add([
            'IBLOCK_ID' => IBL_OFFERS,
            'NAME' => 'Оффер: ' . $formData['candidate_fio'],
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => $props,
        ]);

        if ($offerId) {
            appendOfferToRequest((int)$formData['request_id'], (int)$offerId);

            if ($sourceSnapshot !== null) {
                $changes = [];
                $labelMap = [
                    'organization' => 'Юридическое лицо',
                    'candidate_fio' => 'ФИО кандидата',
                    'position' => 'Должность',
                    'department' => 'Подразделение',
                    'direction' => 'Дирекция',
                    'chief' => 'ФИО руководителя (из списка)',
                    'is_chief_position' => 'Кандидат на руководящую должность',
                    'contract_type' => 'Тип трудового договора',
                    'salary' => 'Оклад, руб.',
                    'isn' => 'ИСН, руб.',
                    'bonus_type' => 'Тип премирования',
                    'bonus_percent' => 'Процент премии',
                    'office' => 'Офис',
                    'work_format' => 'Формат работы',
                    'work_schedule' => 'График работы',
                    'work_start' => 'Начало рабочего дня',
                    'equipment' => 'Оборудование',
                    'equipment_text' => 'Оборудование для работы (текст)',
                ];
                foreach ($labelMap as $key => $label) {
                    $old = trim((string)($sourceSnapshot[$key] ?? ''));
                    $new = trim((string)($formData[$key] ?? ''));
                    if ($old === $new) {
                        continue;
                    }

                    $oldDisplay = $old;
                    $newDisplay = $new;
                    if ($key === 'organization') {
                        $oldDisplay = $organizationNameById[$old] ?? $old;
                        $newDisplay = $organizationNameById[$new] ?? $new;
                    } elseif ($key === 'contract_type') {
                        $oldDisplay = $contractNameById[$old] ?? $old;
                        $newDisplay = $contractNameById[$new] ?? $new;
                    } elseif ($key === 'bonus_type') {
                        $oldDisplay = $bonusTypeNameById[$old] ?? $old;
                        $newDisplay = $bonusTypeNameById[$new] ?? $new;
                    } elseif ($key === 'office') {
                        $oldDisplay = $officeNameById[$old] ?? $old;
                        $newDisplay = $officeNameById[$new] ?? $new;
                    } elseif ($key === 'work_format') {
                        $oldDisplay = $formatNameById[$old] ?? $old;
                        $newDisplay = $formatNameById[$new] ?? $new;
                    } elseif ($key === 'work_schedule') {
                        $oldDisplay = $scheduleNameById[$old] ?? $old;
                        $newDisplay = $scheduleNameById[$new] ?? $new;
                    } elseif ($key === 'work_start') {
                        $oldDisplay = $startNameById[$old] ?? $old;
                        $newDisplay = $startNameById[$new] ?? $new;
                    } elseif ($key === 'equipment') {
                        $oldDisplay = $equipmentNameById[$old] ?? $old;
                        $newDisplay = $equipmentNameById[$new] ?? $new;
                    } elseif ($key === 'chief') {
                        $oldDisplay = getUserDisplayNameById((int)$old);
                        $newDisplay = getUserDisplayNameById((int)$new);
                    } elseif ($key === 'is_chief_position') {
                        $oldDisplay = ($old === '1159' ? 'Да' : 'Нет');
                        $newDisplay = ($new === '1159' ? 'Да' : 'Нет');
                    }
                    $changes[] = $label . ': ' . $oldDisplay . ' → ' . $newDisplay;
                }

                if (!empty($changes) && Loader::includeModule('bizproc')) {
                    $documentId = ['lists', 'BizprocDocument', (int)$offerId];
                    $bpParams = [
                        'par_Changes_type' => 'recruiter',
                        'par_Changes' => implode("\n", $changes),
                    ];
                    $bpErrors = [];
                    CBPDocument::StartWorkflow(1323, $documentId, $bpParams, $bpErrors);
                }
            }
            LocalRedirect('/services/lists/218/view/0/?list_section_id=');
        } else {
            $errors[] = 'Не удалось создать запись в списке офферов: ' . ($el->LAST_ERROR ?: 'неизвестная ошибка');
        }
    }

    if (!empty($errors)) {
        $saveMessage = [
            'type' => 'danger',
            'text' => implode(' ', $errors),
        ];
    }
}

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<div class="container my-4">
    <div class="d-flex align-items-center mb-3">
        <h1 class="h3 mb-0">Заявка на оффер</h1>
    </div>

    <?php if ($candidateId > 0): ?>
        <div class="alert alert-info" role="alert">
            Предзаполнение выполнено по анкете кандидата ID: <strong><?=h($candidateId)?></strong>.
        </div>
    <?php endif; ?>

    <?php if ($saveMessage): ?>
        <div class="alert alert-<?=h($saveMessage['type'])?>" role="alert"><?=h($saveMessage['text'])?></div>
    <?php endif; ?>

    <form method="post">
        <?=bitrix_sessid_post()?>

        <div class="card mb-3">
            <div class="card-header">Общие сведения</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Юридическое лицо <span class="text-danger">*</span></label>
                        <select class="form-control" name="organization" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($organizationList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['organization'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>ФИО кандидата <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="candidate_fio" value="<?=h($formData['candidate_fio'])?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Контактный телефон кандидата (+7...)</label>
                        <input type="text" class="form-control" name="candidate_phone" value="<?=h($formData['candidate_phone'])?>" placeholder="+7 ...">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Должность <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="position" value="<?=h($formData['position'])?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Подразделение <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="department" value="<?=h($formData['department'])?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Дирекция</label>
                        <input type="text" class="form-control" name="direction" value="<?=h($formData['direction'])?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>ФИО руководителя (из списка) <span class="text-danger">*</span></label>
                        <input type="hidden" name="chief" id="chiefInputHidden" value="<?=h($formData['chief'])?>">
                        <div id="chiefSelector"></div>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Должность руководителя <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="chief_position" value="<?=h($formData['chief_position'])?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Кандидат на руководящую должность</label>
                        <select name="is_chief_position" class="form-control">
                            <option value="1160" <?=$formData['is_chief_position'] === '1160' ? 'selected' : ''?>>Нет</option>
                            <option value="1159" <?=$formData['is_chief_position'] === '1159' ? 'selected' : ''?>>Да</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Расчет оффера</div>
            <div class="card-body">
                <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Регион-локация кандидата <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm mb-2" id="regionLocationSearch" placeholder="Поиск по вхождению...">
                                <select class="form-control" name="region_location" required>
                                    <option value="" <?=$formData['region_location'] === '' ? 'selected' : ''?>>— Выберите —</option>
                                    <?php foreach ($regionLocationList as $o): ?>
                                        <option value="<?=h($o['ID'])?>" <?=$formData['region_location'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="Y" id="regionNotInList" name="region_not_in_list" <?=$formData['region_not_in_list'] === 'Y' ? 'checked' : ''?>>
                                    <label class="form-check-label" for="regionNotInList">Нет в списке</label>
                                </div>
                                <div id="newRegionWrap" style="display:none;" class="mt-2">
                                    <label>Регион-локация кандидата (новый)</label>
                                    <input type="text" class="form-control mb-2" name="new_region_name" id="newRegionName" value="<?=h($formData['new_region_name'])?>" placeholder="Введите регион-локацию кандидата">
                                    <small class="form-text text-muted">Внесите регион-локацию кандидата в текстовом виде.</small>
                                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addNewRegionBtn" style="display:none;">Добавить новый регион-локацию</button>
                                </div>
                                <div id="manualRkWrap" style="display:none;" class="mt-2">
                                    <label>Региональный коэффициент</label>
                                    <input type="number" step="0.01" class="form-control" name="manual_region_rk" id="manualRegionRk" value="<?=h($formData['manual_region_rk'])?>">
                                    <div class="alert alert-warning py-2 px-3 mt-2 mb-0"><strong>Важно:</strong> уточните коэффициент в отделе кадрового администрирования и внесите здесь.</div>
                                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addRkBtn" style="display:none;">Добавить РК</button>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label>Районный коэффициент</label>
                                <input type="number" step="0.01" class="form-control" name="rayon_coefficient" value="<?=h($formData['rayon_coefficient'])?>" readonly>
                            </div>
                            <div class="form-group col-md-4">
                                <label>Северная надбавка %%</label>
                                <input type="number" min="0" max="100" step="0.01" class="form-control" name="personal_allowance" value="<?=h($formData['personal_allowance'])?>">
                            </div>
                        </div>
                <div class="form-row">
                            <div class="form-group col-md-4">
                        <label>Оклад, руб. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="salary" value="<?=h($formData['salary'])?>" required>
                            </div>
                            <div class="form-group col-md-4">
                                <label>ИСН, руб.</label>
                                <input type="text" class="form-control" name="isn" value="<?=h($formData['isn'])?>">
                            </div>
                            <div class="form-group col-md-4">
                        <label>Тип премирования <span class="text-danger">*</span></label>
                                <select class="form-control" name="bonus_type" required>
                                    <option value="">— Выберите —</option>
                                    <?php foreach ($bonusTypeList as $o): ?>
                                        <option value="<?=h($o['ID'])?>" <?=$formData['bonus_type'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                <div class="form-row">
                            <div class="form-group col-md-4">
                        <label>Процент премии</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="bonus_percent" value="<?=h($formData['bonus_percent'])?>">
                                    <div class="input-group-append"><span class="input-group-text">%</span></div>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label>Премиальная часть, руб. Гросс</label>
                                <input type="text" class="form-control" name="bonus_rub_gross" value="<?=h($formData['bonus_rub_gross'])?>" readonly>
                            </div>
                            <div class="form-group col-md-4" id="monthIncomeWrap">
                                <label>Доход в месяц в среднем, руб. Гросс</label>
                                <input type="text" class="form-control border border-warning font-weight-bold" name="month_income_avg_gross" value="<?=h($formData['month_income_avg_gross'])?>" readonly>
                            </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Условия</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Тип трудового договора <span class="text-danger">*</span></label>
                        <select class="form-control" name="contract_type" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($contractList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['contract_type'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Испытательный срок <span class="text-danger">*</span></label>
                        <select class="form-control" name="trial_period" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($trialPeriodList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['trial_period'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Планируемая дата выхода на работу <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="planned_start_date" value="<?=h($formData['planned_start_date'])?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Планируемая дата отправки оффера кандидату <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="planned_send_date" value="<?=h($formData['planned_send_date'])?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Льготы <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="benefits" rows="2" required><?=h($formData['benefits'])?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Офис <span class="text-danger">*</span></label>
                        <select class="form-control" name="office" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($officeList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['office'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Формат работы <span class="text-danger">*</span></label>
                        <select class="form-control" name="work_format" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($formatList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['work_format'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>График работы <span class="text-danger">*</span></label>
                        <select class="form-control" name="work_schedule" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($scheduleList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['work_schedule'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Начало рабочего дня <span class="text-danger">*</span></label>
                        <select class="form-control" name="work_start" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($startTimeList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['work_start'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label>Оборудование <span class="text-danger">*</span></label>
                        <select class="form-control" name="equipment" required>
                            <option value="">— Выберите —</option>
                            <?php foreach ($equipmentList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['equipment'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Оборудование для работы (текст)</label>
                    <textarea class="form-control" name="equipment_text" rows="2"><?=h($formData['equipment_text'])?></textarea>
                </div>

                <div class="form-group">
                    <label>Компенсация аренды жилья</label>
                    <input type="number" step="1" class="form-control" name="housing_compensation" value="<?=h($formData['housing_compensation'])?>">
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Связи</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>ID заявки на подбор</label>
                        <input type="number" class="form-control" name="request_id" value="<?=h($formData['request_id'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>ID анкеты кандидата</label>
                        <input type="number" class="form-control" name="candidate_id" value="<?=h($formData['candidate_id'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>ID кандидата Friendwork</label>
                        <input type="text" class="form-control" name="fw_candidate_id" value="<?=h($formData['fw_candidate_id'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Рекрутер (ID пользователя)</label>
                        <input type="number" class="form-control" name="recruiter" value="<?=h($formData['recruiter'])?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Комментарий</label>
                    <textarea class="form-control" name="comment" rows="2"><?=h($formData['comment'])?></textarea>
                </div>
            </div>
        </div>

        <div class="text-right">
            <button type="submit" class="btn btn-primary" name="action" value="save">Создать оффер</button>
            <a href="/services/lists/218/view/0/?list_section_id=" class="btn btn-link">К списку офферов</a>
        </div>
    </form>
</div>
<script>
BX.ready(function () {
    var searchInput = document.getElementById('regionLocationSearch');
    var regionSelect = document.querySelector('select[name=\"region_location\"]');
    var regionNotInListCheckbox = document.getElementById('regionNotInList');
    var newRegionWrap = document.getElementById('newRegionWrap');
    var manualRkWrap = document.getElementById('manualRkWrap');
    var newRegionNameInput = document.getElementById('newRegionName');
    var manualRegionRkInput = document.getElementById('manualRegionRk');
    var addNewRegionBtn = document.getElementById('addNewRegionBtn');
    var addRkBtn = document.getElementById('addRkBtn');
    var rayonInput = document.querySelector('input[name=\"rayon_coefficient\"]');
    var allowanceInput = document.querySelector('input[name=\"personal_allowance\"]');
    var salaryInput = document.querySelector('input[name=\"salary\"]');
    var bonusTypeSelect = document.querySelector('select[name=\"bonus_type\"]');
    var bonusPercentInput = document.querySelector('input[name=\"bonus_percent\"]');
    var isnInput = document.querySelector('input[name=\"isn\"]');
    var chiefInput = document.getElementById('chiefInputHidden');
    var chiefSelectorNode = document.getElementById('chiefSelector');
    var chiefPositionInput = document.querySelector('input[name=\"chief_position\"]');
    var bonusRubGrossInput = document.querySelector('input[name=\"bonus_rub_gross\"]');
    var monthIncomeAvgInput = document.querySelector('input[name=\"month_income_avg_gross\"]');
    var monthIncomeWrap = document.getElementById('monthIncomeWrap');
    var regionCalc = <?=CUtil::PhpToJSObject($regionCalcById, false, true)?>;
    var regionNameById = <?=CUtil::PhpToJSObject($regionNameById, false, true)?>;
    if (!searchInput || !regionSelect) {
        return;
    }

    searchInput.addEventListener('input', function () {
        var needle = (searchInput.value || '').toLowerCase();
        var options = regionSelect.querySelectorAll('option');
        options.forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }
            var text = (option.textContent || '').toLowerCase();
            option.hidden = (needle !== '' && text.indexOf(needle) === -1);
        });
    });

    regionSelect.addEventListener('change', function () {
        var value = regionSelect.value || '';
        if (value === '' || !regionCalc[value]) {
            if (rayonInput) rayonInput.value = '';
            if (allowanceInput) allowanceInput.value = '0';
            recalcIncomeFields();
            syncRegionExtraFields();
            return;
        }
        if (rayonInput) rayonInput.value = regionCalc[value].rayon_coefficient || '';
        if (allowanceInput) allowanceInput.value = regionCalc[value].personal_allowance || '0';
        syncRegionExtraFields();
        recalcIncomeFields();
    });

    function syncRegionExtraFields() {
        var selectedRegionId = regionSelect ? (regionSelect.value || '') : '';
        var selectedRk = selectedRegionId && regionCalc[selectedRegionId] ? toNum(regionCalc[selectedRegionId].rayon_coefficient || '') : 0;
        var noRegion = regionNotInListCheckbox && regionNotInListCheckbox.checked;
        var needManualRk = noRegion || (selectedRegionId !== '' && selectedRk <= 0);

        if (newRegionWrap) newRegionWrap.style.display = noRegion ? '' : 'none';
        if (manualRkWrap) manualRkWrap.style.display = needManualRk ? '' : 'none';
        if (regionSelect) regionSelect.required = !noRegion;
        if (newRegionNameInput) newRegionNameInput.required = !!noRegion;
        if (manualRegionRkInput) manualRegionRkInput.required = !!needManualRk;
        if (addNewRegionBtn) {
            addNewRegionBtn.style.display = (noRegion && newRegionNameInput && newRegionNameInput.value.trim() !== '' && manualRegionRkInput && toNum(manualRegionRkInput.value) > 0) ? '' : 'none';
        }
        if (addRkBtn) {
            addRkBtn.style.display = (!noRegion && needManualRk && manualRegionRkInput && toNum(manualRegionRkInput.value) > 0) ? '' : 'none';
        }

        if (noRegion) {
            if (regionSelect) regionSelect.value = '';
            if (rayonInput) rayonInput.value = manualRegionRkInput ? (manualRegionRkInput.value || '') : '';
        } else if (needManualRk) {
            if (rayonInput) rayonInput.value = manualRegionRkInput ? (manualRegionRkInput.value || '') : '';
        }
    }

    function upsertRegionOption(regionId, regionName, rkValue) {
        if (!regionSelect) return;
        var idStr = String(regionId);
        var existing = null;
        Array.prototype.forEach.call(regionSelect.options, function(opt) {
            if (String(opt.value) === idStr) {
                existing = opt;
            }
        });
        if (!existing) {
            existing = document.createElement('option');
            existing.value = idStr;
            existing.textContent = regionName;
            regionSelect.appendChild(existing);
        } else {
            existing.textContent = regionName;
        }
        regionSelect.value = idStr;
        regionCalc[idStr] = {
            rayon_coefficient: String(rkValue),
            personal_allowance: '0'
        };
        regionNameById[idStr] = regionName;
        if (regionNotInListCheckbox) regionNotInListCheckbox.checked = false;
        if (newRegionNameInput) newRegionNameInput.value = '';
        if (manualRegionRkInput) manualRegionRkInput.value = '';
        syncRegionExtraFields();
        regionSelect.dispatchEvent(new Event('change'));
    }

    function createRegionByAjax(name, rkValue) {
        BX.ajax({
            url: window.location.pathname + '?ajax=create_region',
            method: 'POST',
            data: {
                name: name,
                rk: rkValue,
                candidate_fio: document.querySelector('input[name=\"candidate_fio\"]') ? document.querySelector('input[name=\"candidate_fio\"]').value : ''
            },
            onsuccess: function(response) {
                var rawResponse = response;
                if (typeof response === 'string') {
                    try {
                        response = JSON.parse(response);
                    } catch (e) {
                        response = null;
                    }
                }
                if (!response || !response.ok) {
                    alert((response && response.error) ? response.error : 'Не удалось создать регион');
                    return;
                }
                upsertRegionOption(response.id, response.name, response.rk);
            },
            onfailure: function() {
                alert('Ошибка создания региона');
            }
        });
    }

    function toNum(value) {
        var normalized = String(value || '').replace(/\s+/g, '').replace(',', '.');
        var num = parseFloat(normalized);
        return isNaN(num) ? 0 : num;
    }

    function formatNumberRu(value) {
        if (String(value || '').trim() === '') {
            return '';
        }
        var num = toNum(value);
        var rounded = Math.round(num);
        return String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function recalcIncomeFields() {
        var salary = toNum(salaryInput && salaryInput.value);
        var bonusPercent = toNum(bonusPercentInput && bonusPercentInput.value);
        var isn = toNum(isnInput && isnInput.value);
        var rayon = toNum(rayonInput && rayonInput.value);
        var northPercent = toNum(allowanceInput && allowanceInput.value);

        var bonusRub = Math.round(salary * bonusPercent / 100);
        var bonusTypeText = '';
        if (bonusTypeSelect && bonusTypeSelect.options.length > 0 && bonusTypeSelect.selectedIndex >= 0) {
            bonusTypeText = (bonusTypeSelect.options[bonusTypeSelect.selectedIndex].text || '').toLowerCase();
        }
        var bonusForMonthIncome = bonusRub;
        if (bonusTypeText.indexOf('ежекварт') !== -1) {
            bonusForMonthIncome = bonusRub / 3;
        }
        var baseIncome = salary + bonusForMonthIncome + isn;
        var monthIncome = Math.round((baseIncome * rayon) + (baseIncome * (northPercent / 100)));
        var canShowMonthIncome = (rayon > 0);

        if (bonusRubGrossInput) bonusRubGrossInput.value = formatNumberRu(bonusRub);
        if (monthIncomeAvgInput) monthIncomeAvgInput.value = canShowMonthIncome ? formatNumberRu(monthIncome) : '';
        if (monthIncomeWrap) monthIncomeWrap.style.display = canShowMonthIncome ? '' : 'none';
    }

    function syncBonusPercentRequired() {
        if (!bonusTypeSelect || !bonusPercentInput) return;
        var selectedText = '';
        if (bonusTypeSelect.options.length > 0 && bonusTypeSelect.selectedIndex >= 0) {
            selectedText = (bonusTypeSelect.options[bonusTypeSelect.selectedIndex].text || '').toLowerCase();
        }
        var mustRequire = selectedText.indexOf('ежекварт') !== -1 || selectedText.indexOf('ежемесяч') !== -1;
        bonusPercentInput.required = mustRequire;
    }

    function parseUserId(raw) {
        var value = String(raw || '').trim();
        if (!value) return 0;
        if (value.indexOf('user_') === 0) {
            value = value.substring(5);
        }
        var match = value.match(/\d+/);
        if (match && match[0]) {
            value = match[0];
        }
        var id = parseInt(value, 10);
        return isNaN(id) ? 0 : id;
    }

    function loadChiefPositionByUser(userRawValue) {
        if (!chiefPositionInput) return;
        var userId = parseUserId(userRawValue);
        if (userId <= 0) {
            chiefPositionInput.value = '';
            return;
        }

        BX.ajax({
            url: window.location.pathname + '?ajax=get_user_position&user_id=' + encodeURIComponent(userId),
            method: 'GET',
            dataType: 'json',
            onsuccess: function (response) {
                if (!response || !response.ok) return;
                chiefPositionInput.value = response.position || '';
            }
        });
    }

    function extractChiefPositionFromItem(item) {
        if (!item) return '';
        var position = '';
        try {
            if (typeof item.getCustomData === 'function') {
                var customData = item.getCustomData();
                if (customData && typeof customData.get === 'function') {
                    position = customData.get('workPosition')
                        || customData.get('WORK_POSITION')
                        || customData.get('position')
                        || customData.get('POST')
                        || '';
                }
            }
            if (!position && typeof item.getSubtitle === 'function') {
                position = item.getSubtitle() || '';
            }
        } catch (e) {
            position = '';
        }
        return String(position || '').trim();
    }

    function setChiefValue(userId) {
        if (!chiefInput) return;
        chiefInput.value = String(userId || '');
    }

    [salaryInput, bonusPercentInput, isnInput, allowanceInput].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', recalcIncomeFields);
    });
    [salaryInput, isnInput].forEach(function (el) {
        if (!el) return;
        el.addEventListener('blur', function () {
            el.value = formatNumberRu(el.value);
            recalcIncomeFields();
        });
    });
    if (manualRegionRkInput) {
        manualRegionRkInput.addEventListener('input', function () {
            if (rayonInput) rayonInput.value = manualRegionRkInput.value || '';
            recalcIncomeFields();
            syncRegionExtraFields();
        });
    }
    if (newRegionNameInput) {
        newRegionNameInput.addEventListener('input', syncRegionExtraFields);
    }
    if (addNewRegionBtn) {
        addNewRegionBtn.addEventListener('click', function () {
            if (!newRegionNameInput || !manualRegionRkInput) return;
            var name = newRegionNameInput.value.trim();
            var rk = toNum(manualRegionRkInput.value);
            if (!name || rk <= 0) return;
            createRegionByAjax(name, rk);
        });
    }
    if (addRkBtn) {
        addRkBtn.addEventListener('click', function () {
            if (!regionSelect || !manualRegionRkInput) return;
            var selectedId = regionSelect.value || '';
            var baseName = regionNameById[selectedId] || '';
            var rk = toNum(manualRegionRkInput.value);
            if (!selectedId || !baseName || rk <= 0) return;
            createRegionByAjax(baseName + ' (РК ' + rk + ')', rk);
        });
    }
    if (regionNotInListCheckbox) {
        regionNotInListCheckbox.addEventListener('change', function () {
            syncRegionExtraFields();
            recalcIncomeFields();
        });
    }
    if (bonusTypeSelect) {
        bonusTypeSelect.addEventListener('change', syncBonusPercentRequired);
    }

    if (chiefSelectorNode && chiefInput && BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.TagSelector) {
        var preselectedChiefId = parseUserId(chiefInput.value);
        var chiefTagSelector = new BX.UI.EntitySelector.TagSelector({
            multiple: false,
            textBoxWidth: '100%',
            placeholder: 'Выберите руководителя',
            dialogOptions: {
                context: 'OFFER_CHIEF_SELECTOR',
                entities: [{id: 'user'}],
                enableSearch: true,
                dropdownMode: true,
                preselectedItems: preselectedChiefId > 0 ? [['user', preselectedChiefId]] : []
            }
        });
        chiefTagSelector.renderTo(chiefSelectorNode);

        var chiefDialog = chiefTagSelector.getDialog();
        chiefDialog.subscribe('Item:onSelect', function (event) {
            var item = event.getData().item;
            var userId = parseUserId(item.getId());
            if (isNaN(userId) || userId <= 0) {
                setChiefValue('');
                loadChiefPositionByUser('');
                return;
            }
            setChiefValue(userId);
            var instantPosition = extractChiefPositionFromItem(item);
            if (instantPosition && chiefPositionInput) {
                chiefPositionInput.value = instantPosition;
            } else {
                loadChiefPositionByUser(userId);
            }
        });
        chiefDialog.subscribe('Item:onDeselect', function () {
            setChiefValue('');
            loadChiefPositionByUser('');
        });
    }

    regionSelect.dispatchEvent(new Event('change'));
    syncRegionExtraFields();
    syncBonusPercentRequired();
    recalcIncomeFields();
});
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
