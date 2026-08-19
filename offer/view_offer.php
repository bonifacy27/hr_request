<?php
/**
 * Просмотр заявки на оффер.
 * URL: /offer/view_offer.php?id=12345
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Просмотр заявки на оффер');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
Loader::includeModule('bizproc');

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
const OFFER_PROP_SALARY_NDFL = 1166;
const OFFER_PROP_ISN_NDFL = 1167;
const OFFER_PROP_BONUS_RUB_NDFL = 1171;
const OFFER_PROP_MONTH_INCOME_AVG_NDFL = 1173;
const OFFER_PROP_SALARY = 1165;
const OFFER_PROP_ISN = 1184;
const OFFER_PROP_BONUS_TYPE = 1998;
const OFFER_PROP_BONUS_PERCENT = 1186;
const OFFER_PROP_TRIAL_PERIOD = 2001;
const OFFER_PROP_TRIAL_PERIOD_OTHER_TEXT = 1176;
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
const OFFER_PROP_HOUSING_COMPENSATION = 3147;
const OFFER_PROP_HOUSING_COMPENSATION_CODE = 'KOMPENSATSIYA_ARENDY_ZHILYA';
const OFFER_PROP_REGION_LOCATION = 1767;
const OFFER_PROP_PERSONAL_ALLOWANCE = 1234;
const OFFER_PROP_PERSONAL_ALLOWANCE_CODE = 'PERSONALNAYA_NADBAVKA';
const OFFER_PROP_RAYON_COEFFICIENT = 1235;
const OFFER_PROP_RECRUITER = 1190;
const OFFER_PROP_REQUEST_ID = 1601;
const OFFER_PROP_CANDIDATE_ID = 1603;
const OFFER_PROP_FW_CANDIDATE_ID = 1602;
const OFFER_PROP_COMMENT = 2857;
const CB_GLOBAL_VAR_ID = 'Variable1722502594854';
const RECRUIT_HEAD_GLOBAL_VAR_ID = 'Variable1722503621093';

const DEFAULT_EQUIPMENT = '3263612';
const DEFAULT_CONTRACT = '3263600';
const DEFAULT_ORGANIZATION = '3197820';
const OFFER_LIST_URL = '/forms/staff_recruitment/offer/list.php';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function normalizeMoneyForStorage($value): string
{
    $value = str_replace(["\xc2\xa0", ' '], '', trim((string)$value));
    $value = str_replace(',', '.', $value);
    return $value;
}

function formatMoneyForDisplay($value): string
{
    $normalized = normalizeMoneyForStorage($value);
    if ($normalized === '' || !is_numeric($normalized)) {
        return trim((string)$value);
    }
    $number = (float)$normalized;
    $decimals = floor($number) == $number ? 0 : 2;
    return number_format($number, $decimals, ',', ' ');
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

function getGlobalVarUserList(string $varId): array
{
    $users = [];
    try {
        $conn = \Bitrix\Main\Application::getConnection();
        $sqlVarId = $conn->getSqlHelper()->forSql($varId);
        $row = $conn->query("
            SELECT PROPERTY_VALUE
            FROM b_bp_global_var
            WHERE ID = '{$sqlVarId}'
            LIMIT 1
        ")->fetch();
        if ($row && !empty($row['PROPERTY_VALUE'])) {
            $decoded = @unserialize($row['PROPERTY_VALUE'], ['allowed_classes' => false]);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') {
                        $users[] = mb_strtolower($item);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        return [];
    }
    return array_values(array_unique($users));
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
            'NAME' => html_entity_decode((string)$row['NAME'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
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


function getOfferPropertyValue(int $offerId, array $filter): string
{
    $rs = CIBlockElement::GetProperty(IBL_OFFERS, $offerId, ['sort' => 'asc'], $filter);
    $values = [];
    while ($p = $rs->Fetch()) {
        $value = $p['VALUE'] ?? '';
        if ((is_array($value) || $value === '' || $value === null) && isset($p['VALUE_ENUM'])) {
            $value = $p['VALUE_ENUM'];
        }
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value), static function ($part) {
                return trim($part) !== '';
            }));
        }
        $value = trim((string)$value);
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return implode(', ', $values);
}

function getOfferById(int $offerId): ?array
{
    $row = CIBlockElement::GetList([], [
        'IBLOCK_ID' => IBL_OFFERS,
        'ID' => $offerId,
        'CHECK_PERMISSIONS' => 'Y',
        'MIN_PERMISSION' => 'R',
    ], false, ['nTopCount' => 1], ['ID', 'NAME', 'PREVIEW_TEXT'])->GetNext();

    if (!$row) {
        return null;
    }

    $propIds = [
        OFFER_PROP_CANDIDATE_FIO,
        OFFER_PROP_CANDIDATE_PHONE,
        OFFER_PROP_PLANNED_SEND_DATE,
        OFFER_PROP_IS_CHIEF_POSITION,
        OFFER_PROP_POSITION,
        OFFER_PROP_DIRECTION,
        OFFER_PROP_DEPARTMENT,
        OFFER_PROP_CHIEF_FIO_FROM_LIST,
        OFFER_PROP_CHIEF_POSITION,
        OFFER_PROP_BONUS_RUB_GROSS,
        OFFER_PROP_MONTH_INCOME_AVG_GROSS,
        OFFER_PROP_SALARY_NDFL,
        OFFER_PROP_ISN_NDFL,
        OFFER_PROP_BONUS_RUB_NDFL,
        OFFER_PROP_MONTH_INCOME_AVG_NDFL,
        OFFER_PROP_SALARY,
        OFFER_PROP_ISN,
        OFFER_PROP_BONUS_TYPE,
        OFFER_PROP_BONUS_PERCENT,
        OFFER_PROP_TRIAL_PERIOD,
        OFFER_PROP_TRIAL_PERIOD_OTHER_TEXT,
        OFFER_PROP_PLANNED_START_DATE,
        OFFER_PROP_BENEFITS,
        OFFER_PROP_WORK_FORMAT,
        OFFER_PROP_OFFICE,
        OFFER_PROP_WORK_SCHEDULE,
        OFFER_PROP_WORK_START,
        OFFER_PROP_EQUIPMENT,
        OFFER_PROP_EQUIPMENT_TEXT,
        OFFER_PROP_CONTRACT_TYPE,
        OFFER_PROP_ORGANIZATION,
        OFFER_PROP_HOUSING_COMPENSATION,
        OFFER_PROP_REGION_LOCATION,
        OFFER_PROP_PERSONAL_ALLOWANCE,
        OFFER_PROP_RAYON_COEFFICIENT,
        OFFER_PROP_RECRUITER,
        OFFER_PROP_REQUEST_ID,
        OFFER_PROP_CANDIDATE_ID,
        OFFER_PROP_FW_CANDIDATE_ID,
        OFFER_PROP_COMMENT,
    ];

    $values = [];
    foreach ($propIds as $propId) {
        $values[$propId] = getOfferPropertyValue((int)$row['ID'], ['ID' => $propId]);
    }
    if ($values[OFFER_PROP_PERSONAL_ALLOWANCE] === '') {
        $values[OFFER_PROP_PERSONAL_ALLOWANCE] = getOfferPropertyValue((int)$row['ID'], ['CODE' => OFFER_PROP_PERSONAL_ALLOWANCE_CODE]);
    }
    if ($values[OFFER_PROP_HOUSING_COMPENSATION] === '') {
        $values[OFFER_PROP_HOUSING_COMPENSATION] = getOfferPropertyValue((int)$row['ID'], ['CODE' => OFFER_PROP_HOUSING_COMPENSATION_CODE]);
    }

    return [
        'ID' => (int)$row['ID'],
        'NAME' => html_entity_decode((string)$row['NAME'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'PREVIEW_TEXT' => (string)($row['PREVIEW_TEXT'] ?? ''),
        'PROPS' => $values,
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

function appendHistory($old, $add): string
{
    $old = trim((string)$old);
    $add = trim((string)$add);
    if ($old === '') {
        return $add;
    }
    if ($add === '') {
        return $old;
    }
    return $old . "\n\n" . $add;
}

function dateToInputFormat(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return $value;
}

function dateToStorageFormat(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $value;
}

function calcNetAfterNdfl(float $gross): array
{
    if ($gross <= 0) {
        return ['net' => 0.0, 'rates' => '13%', 'effective_rate' => 0.0];
    }

    $annualGross = $gross * 12;
    $brackets = [
        ['limit' => 2400000.0, 'rate' => 0.13, 'label' => '13%'],
        ['limit' => 5000000.0, 'rate' => 0.15, 'label' => '15%'],
        ['limit' => 20000000.0, 'rate' => 0.18, 'label' => '18%'],
        ['limit' => 50000000.0, 'rate' => 0.20, 'label' => '20%'],
        ['limit' => INF, 'rate' => 0.22, 'label' => '22%'],
    ];

    $annualTax = 0.0;
    $prevLimit = 0.0;
    $usedRates = [];
    foreach ($brackets as $bracket) {
        if ($annualGross <= $prevLimit) {
            break;
        }
        $upperLimit = min($annualGross, (float)$bracket['limit']);
        $slice = max(0.0, $upperLimit - $prevLimit);
        if ($slice > 0) {
            $annualTax += $slice * (float)$bracket['rate'];
            $usedRates[] = (string)$bracket['label'];
        }
        $prevLimit = (float)$bracket['limit'];
    }

    $monthlyTax = $annualTax / 12;
    $net = $gross - $monthlyTax;
    $effectiveRate = ($monthlyTax / $gross) * 100;
    $rates = implode(' + ', $usedRates);

    return ['net' => $net, 'rates' => $rates, 'effective_rate' => $effectiveRate];
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

$request = Context::getCurrent()->getRequest();
$offerId = (int)($request->getQuery('ID') ?: $request->getQuery('id'));
if ($offerId <= 0) {
    $offerId = (int)($request->get('ID') ?: $request->get('id'));
}
$errors = [];
$saveMessage = null;

if (!$USER->IsAuthorized()) {
    ShowError('Требуется авторизация');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
if ($offerId <= 0) {
    ShowError('Не указан ID оффера');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$offerItem = getOfferById($offerId);
if (!$offerItem) {
    ShowError('Оффер не найден или нет прав на просмотр');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

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
    'salary_ndfl' => '',
    'isn_ndfl' => '',
    'bonus_rub_ndfl' => '',
    'month_income_avg_ndfl' => '',
    'trial_period' => '',
    'trial_period_other_text' => '',
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

$props = $offerItem['PROPS'];
$formData['candidate_fio'] = (string)$props[OFFER_PROP_CANDIDATE_FIO];
$formData['candidate_phone'] = (string)$props[OFFER_PROP_CANDIDATE_PHONE];
$formData['planned_send_date'] = dateToInputFormat((string)$props[OFFER_PROP_PLANNED_SEND_DATE]);
$formData['is_chief_position'] = ((string)$props[OFFER_PROP_IS_CHIEF_POSITION] !== '' ? (string)$props[OFFER_PROP_IS_CHIEF_POSITION] : '1160');
$formData['position'] = (string)$props[OFFER_PROP_POSITION];
$formData['direction'] = (string)$props[OFFER_PROP_DIRECTION];
$formData['department'] = (string)$props[OFFER_PROP_DEPARTMENT];
$formData['chief'] = (string)userIdFromValue($props[OFFER_PROP_CHIEF_FIO_FROM_LIST]);
$formData['chief_position'] = (string)$props[OFFER_PROP_CHIEF_POSITION];
$formData['salary'] = (string)$props[OFFER_PROP_SALARY];
$formData['isn'] = (string)$props[OFFER_PROP_ISN];
$formData['bonus_type'] = (string)$props[OFFER_PROP_BONUS_TYPE];
$formData['bonus_percent'] = ((string)$props[OFFER_PROP_BONUS_PERCENT] !== '' ? (string)$props[OFFER_PROP_BONUS_PERCENT] : '0');
$formData['bonus_rub_gross'] = (string)$props[OFFER_PROP_BONUS_RUB_GROSS];
$formData['month_income_avg_gross'] = (string)$props[OFFER_PROP_MONTH_INCOME_AVG_GROSS];
$formData['salary_ndfl'] = (string)$props[OFFER_PROP_SALARY_NDFL];
$formData['isn_ndfl'] = (string)$props[OFFER_PROP_ISN_NDFL];
$formData['bonus_rub_ndfl'] = (string)$props[OFFER_PROP_BONUS_RUB_NDFL];
$formData['month_income_avg_ndfl'] = (string)$props[OFFER_PROP_MONTH_INCOME_AVG_NDFL];
$formData['trial_period'] = (string)$props[OFFER_PROP_TRIAL_PERIOD];
$formData['trial_period_other_text'] = (string)$props[OFFER_PROP_TRIAL_PERIOD_OTHER_TEXT];
$formData['planned_start_date'] = dateToInputFormat((string)$props[OFFER_PROP_PLANNED_START_DATE]);
$formData['benefits'] = ((string)$props[OFFER_PROP_BENEFITS] !== '' ? (string)$props[OFFER_PROP_BENEFITS] : $formData['benefits']);
$formData['work_format'] = (string)$props[OFFER_PROP_WORK_FORMAT];
$formData['office'] = (string)$props[OFFER_PROP_OFFICE];
$formData['work_schedule'] = (string)$props[OFFER_PROP_WORK_SCHEDULE];
$formData['work_start'] = (string)$props[OFFER_PROP_WORK_START];
$formData['equipment'] = ((string)$props[OFFER_PROP_EQUIPMENT] !== '' ? (string)$props[OFFER_PROP_EQUIPMENT] : DEFAULT_EQUIPMENT);
$formData['equipment_text'] = (string)$props[OFFER_PROP_EQUIPMENT_TEXT];
$formData['contract_type'] = ((string)$props[OFFER_PROP_CONTRACT_TYPE] !== '' ? (string)$props[OFFER_PROP_CONTRACT_TYPE] : DEFAULT_CONTRACT);
$formData['organization'] = ((string)$props[OFFER_PROP_ORGANIZATION] !== '' ? (string)$props[OFFER_PROP_ORGANIZATION] : DEFAULT_ORGANIZATION);
$formData['housing_compensation'] = (string)$props[OFFER_PROP_HOUSING_COMPENSATION];
$formData['region_location'] = (string)$props[OFFER_PROP_REGION_LOCATION];
$formData['personal_allowance'] = ((string)$props[OFFER_PROP_PERSONAL_ALLOWANCE] !== '' ? (string)$props[OFFER_PROP_PERSONAL_ALLOWANCE] : '0');
$formData['rayon_coefficient'] = (string)$props[OFFER_PROP_RAYON_COEFFICIENT];
$formData['recruiter'] = (string)userIdFromValue($props[OFFER_PROP_RECRUITER]);
$formData['request_id'] = (string)$props[OFFER_PROP_REQUEST_ID];
$formData['candidate_id'] = (string)$props[OFFER_PROP_CANDIDATE_ID];
$formData['fw_candidate_id'] = (string)$props[OFFER_PROP_FW_CANDIDATE_ID];
$formData['comment'] = (string)$props[OFFER_PROP_COMMENT];

$currentUserId = (int)$USER->GetID();
$currentUserTag = mb_strtolower('user_' . $currentUserId);
$isAdmin = $USER->IsAdmin();
$cbUsers = getGlobalVarUserList(CB_GLOBAL_VAR_ID);
$recruitHeads = getGlobalVarUserList(RECRUIT_HEAD_GLOBAL_VAR_ID);
$isCbManager = in_array($currentUserTag, $cbUsers, true);
$isRecruitHead = in_array($currentUserTag, $recruitHeads, true);
$isOfferRecruiter = ((int)$formData['recruiter'] > 0 && (int)$formData['recruiter'] === $currentUserId);

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
$trialPeriodNameById = $nameById($trialPeriodList);

$sourceSnapshot = $formData;
$recruiterDisplayName = getUserDisplayNameById((int)$formData['recruiter']);


function displayOrDash($value): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : '—';
}

function formatDateForView(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $value;
}

function optionName(array $map, string $id): string
{
    return (string)($map[$id] ?? $id);
}

$viewSections = [
    [
        'title' => 'Общие сведения',
        'rows' => [
            ['label' => 'ID оффера', 'value' => (string)$offerId],
            ['label' => 'Юридическое лицо', 'value' => optionName($organizationNameById, $formData['organization'])],
            ['label' => 'ФИО кандидата', 'value' => $formData['candidate_fio']],
            ['label' => 'Контактный телефон кандидата', 'value' => $formData['candidate_phone']],
            ['label' => 'Должность', 'value' => $formData['position']],
            ['label' => 'Подразделение', 'value' => $formData['department']],
            ['label' => 'Дирекция', 'value' => $formData['direction']],
            ['label' => 'ФИО руководителя', 'value' => getUserDisplayNameById((int)$formData['chief'])],
            ['label' => 'Должность руководителя', 'value' => $formData['chief_position']],
            ['label' => 'Кандидат на руководящую должность', 'value' => ($formData['is_chief_position'] === '1159' ? 'Да' : 'Нет')],
        ],
    ],
    [
        'title' => 'Расчет оффера',
        'rows' => [
            ['label' => 'Регион-локация кандидата', 'value' => optionName($regionNameById, $formData['region_location'])],
            ['label' => 'Районный коэффициент', 'value' => $formData['rayon_coefficient']],
            ['label' => 'Северная надбавка %%', 'value' => $formData['personal_allowance']],
            ['label' => 'Оклад, руб.', 'value' => formatMoneyForDisplay($formData['salary'])],
            ['label' => 'Оклад, руб. (после вычета НДФЛ)', 'value' => formatMoneyForDisplay($formData['salary_ndfl'])],
            ['label' => 'ИСН, руб.', 'value' => formatMoneyForDisplay($formData['isn'])],
            ['label' => 'ИСН, руб. (после вычета НДФЛ)', 'value' => formatMoneyForDisplay($formData['isn_ndfl'])],
            ['label' => 'Тип премирования', 'value' => optionName($bonusTypeNameById, $formData['bonus_type'])],
            ['label' => 'Процент премии', 'value' => $formData['bonus_percent']],
            ['label' => 'Премиальная часть, руб. Гросс', 'value' => formatMoneyForDisplay($formData['bonus_rub_gross'])],
            ['label' => 'Премиальная часть, руб. (после вычета НДФЛ)', 'value' => formatMoneyForDisplay($formData['bonus_rub_ndfl'])],
            ['label' => 'Доход в месяц в среднем, руб. Гросс', 'value' => formatMoneyForDisplay($formData['month_income_avg_gross'])],
            ['label' => 'Доход в месяц в среднем, руб. (после вычета НДФЛ)', 'value' => formatMoneyForDisplay($formData['month_income_avg_ndfl'])],
        ],
    ],
    [
        'title' => 'Условия',
        'rows' => [
            ['label' => 'Тип трудового договора', 'value' => optionName($contractNameById, $formData['contract_type'])],
            ['label' => 'Испытательный срок', 'value' => optionName($trialPeriodNameById, $formData['trial_period'])],
            ['label' => 'Планируемая дата отправки оффера кандидату', 'value' => formatDateForView($formData['planned_send_date'])],
            ['label' => 'Планируемая дата выхода на работу', 'value' => formatDateForView($formData['planned_start_date'])],
            ['label' => 'Льготы', 'value' => $formData['benefits'], 'wide' => true],
            ['label' => 'Офис', 'value' => optionName($officeNameById, $formData['office'])],
            ['label' => 'Формат работы', 'value' => optionName($formatNameById, $formData['work_format'])],
            ['label' => 'График работы', 'value' => optionName($scheduleNameById, $formData['work_schedule'])],
            ['label' => 'Начало рабочего дня', 'value' => optionName($startNameById, $formData['work_start'])],
            ['label' => 'Оборудование', 'value' => optionName($equipmentNameById, $formData['equipment'])],
            ['label' => 'Оборудование для работы (текст)', 'value' => $formData['equipment_text'], 'wide' => true],
            ['label' => 'Компенсация аренды жилья', 'value' => formatMoneyForDisplay($formData['housing_compensation'])],
        ],
    ],
    [
        'title' => 'Связи',
        'rows' => [
            ['label' => 'ID заявки на подбор', 'value' => $formData['request_id']],
            ['label' => 'ID анкеты кандидата', 'value' => $formData['candidate_id']],
            ['label' => 'ID кандидата Friendwork', 'value' => $formData['fw_candidate_id']],
            ['label' => 'Рекрутер', 'value' => $recruiterDisplayName !== '' ? $recruiterDisplayName : ($formData['recruiter'] ? ('ID ' . $formData['recruiter']) : '')],
            ['label' => 'Путь создания оффера', 'value' => $formData['comment'], 'wide' => true],
        ],
    ],
];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<style>
.offer-page .offer-section { border: 0; border-radius: 14px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); overflow: hidden; }
.offer-page .offer-section-blue { background: #eff6ff; }
.offer-page .offer-section-yellow { background: #fefce8; }
.offer-page .offer-section-green { background: #f0fdf4; }
.offer-page .offer-section-gray { background: #f8fafc; }
.offer-page .offer-section .card-header { background: transparent; border-bottom: 1px solid rgba(15, 23, 42, 0.08); color: #0f172a; font-weight: 700; letter-spacing: .01em; }
.offer-page .offer-section .card-body { background: transparent; }
.offer-page .offer-field { height: 100%; background: rgba(255, 255, 255, 0.72); border: 1px solid rgba(148, 163, 184, 0.35); border-radius: 10px; padding: 12px; }
.offer-page .offer-label { color: #64748b; font-size: 13px; font-weight: 400; margin-bottom: 6px; }
.offer-page .offer-value { color: #111827; font-size: 16px; font-weight: 600; white-space: pre-wrap; }
.offer-page .offer-value-empty { color: #94a3b8; }
</style>
<div class="container my-4 offer-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h3 mb-0">Просмотр заявки на оффер</h1>
        <div>
            <a href="/forms/staff_recruitment/offer/edit_offer.php?id=<?=h($offerId)?>" class="btn btn-primary">Редактировать</a>
            <a href="<?=h(OFFER_LIST_URL)?>" class="btn btn-link">К списку офферов</a>
        </div>
    </div>
    <div class="alert alert-info" role="alert">Оффер ID: <strong><?=h($offerId)?></strong>.</div>

    <?php $sectionColors = ['offer-section-blue', 'offer-section-yellow', 'offer-section-green', 'offer-section-gray']; ?>
    <?php foreach ($viewSections as $sectionIndex => $section): ?>
        <div class="card offer-section <?=h($sectionColors[$sectionIndex % count($sectionColors)])?> mb-3">
            <div class="card-header"><?=h($section['title'])?></div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($section['rows'] as $row): ?>
                        <?php $value = displayOrDash((string)($row['value'] ?? '')); ?>
                        <div class="col-md-<?=!empty($row['wide']) ? '12' : '4'?> mb-3">
                            <div class="offer-field">
                                <div class="offer-label"><?=h($row['label'])?></div>
                                <div class="offer-value <?=$value === '—' ? 'offer-value-empty' : ''?>"><?=h($value)?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
