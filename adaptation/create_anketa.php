<?php
/**
 * Форма создания анкеты нового сотрудника.
 * URL: /forms/staff_recruiting/adaptation/create_anketa.php
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты нового сотрудника');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

Extension::load(['main.core', 'ui.entity-selector']);

const IBL_ADAPTATION = 196;
const IBL_ORGANIZATION = 308;
const IBL_WORK_FORMAT = 234;
const IBL_OFFICE = 233;
const IBL_LOCATION = 224;
const IBL_WORK_START = 237;
const IBL_REQUESTS = 201;
const IBL_OFFERS = 218;
const IBL_CANDIDATES = 207;
const NEWS_REQUIRED_ORGANIZATION = 'НАО "Национальная спутниковая компания"';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeDate(string $value): string
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

function parseCheckbox($value): string
{
    return in_array((string)$value, ['Y', '1', 'on'], true) ? 'Y' : 'N';
}

function getIblockElementsById(int $iblockId, array $sort = ['SORT' => 'ASC', 'NAME' => 'ASC']): array
{
    $res = [];
    $rs = CIBlockElement::GetList($sort, ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME']);
    while ($row = $rs->GetNext()) {
        $res[] = ['ID' => (string)$row['ID'], 'NAME' => (string)$row['NAME']];
    }
    return $res;
}

function getPropertyEnums(int $iblockId, string $propertyCode): array
{
    $res = [];
    $rs = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]);
    while ($row = $rs->GetNext()) {
        $res[] = ['ID' => (string)$row['ID'], 'VALUE' => (string)$row['VALUE']];
    }
    return $res;
}

function getPropertyEnumIdByValue(int $iblockId, string $propertyCode, string $value): string
{
    foreach (getPropertyEnums($iblockId, $propertyCode) as $option) {
        if (trim($option['VALUE']) === trim($value)) {
            return $option['ID'];
        }
    }
    return '';
}


function decodeName(string $name): string
{
    return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function getElementById(int $iblockId, int $id, array $select): ?array
{
    $row = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $id, 'ACTIVE' => 'Y'], false, ['nTopCount' => 1], $select)->GetNext();
    return $row ?: null;
}

function getElementNameById(int $iblockId, int $id): string
{
    if ($id <= 0) {
        return '';
    }
    $element = getElementById($iblockId, $id, ['ID', 'NAME']);
    return $element ? decodeName((string)$element['NAME']) : '';
}

function splitFio(string $fio): array
{
    $parts = preg_split('/\s+/', trim($fio));
    return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
}

function getUserFullFioById(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return '';
    }
    $fio = trim(implode(' ', array_filter([
        trim((string)($user['LAST_NAME'] ?? '')),
        trim((string)($user['NAME'] ?? '')),
        trim((string)($user['SECOND_NAME'] ?? '')),
    ], static function ($part) {
        return $part !== '';
    })));
    if ($fio !== '') {
        return $fio;
    }
    return trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
}

if ((string)($_GET['ajax'] ?? '') === 'get_user_fio') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    $userId = (int)($_GET['user_id'] ?? 0);
    echo json_encode([
        'ok' => ($userId > 0),
        'user_id' => $userId,
        'fio' => getUserFullFioById($userId),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ((string)($_GET['ajax'] ?? '') === 'get_photo_deadline') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    $startDate = normalizeDate((string)($_GET['date'] ?? ''));
    $deadline = '';
    if ($startDate !== '' && function_exists('addworkday_1c')) {
        $deadline = (string)addworkday_1c($startDate, -1);
    }
    echo json_encode([
        'ok' => $deadline !== '',
        'deadline' => $deadline,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$fields = [
    ['id' => 951, 'code' => 'FAMILIYA', 'label' => 'Фамилия', 'type' => 'S'],
    ['id' => 952, 'code' => 'IMYA', 'label' => 'Имя', 'type' => 'S'],
    ['id' => 953, 'code' => 'OTCHESTVO', 'label' => 'Отчество', 'type' => 'S'],
    ['id' => 954, 'code' => 'STATUS_SOTRUDNIKA', 'label' => 'Статус сотрудника', 'type' => 'L'],
    ['id' => 1835, 'code' => 'ORGANIZATSIYA', 'label' => 'Организация', 'type' => 'E', 'link_iblock' => IBL_ORGANIZATION],
    ['id' => 955, 'code' => 'POL', 'label' => 'Пол', 'type' => 'L'],
    ['id' => 956, 'code' => 'DIREKTSIYA', 'label' => 'Дирекция', 'type' => 'S'],
    ['id' => 957, 'code' => 'OTDEL', 'label' => 'Отдел', 'type' => 'S'],
    ['id' => 958, 'code' => 'DOLZHNOST', 'label' => 'Должность', 'type' => 'S'],
    ['id' => 959, 'code' => 'RUKOVODITEL', 'label' => 'Руководитель', 'type' => 'USER'],
    ['id' => 3161, 'code' => 'FIO_RUKOVODITELYA', 'label' => 'ФИО руководителя', 'type' => 'S'],
    ['id' => 961, 'code' => 'OTVETSTVENNYY_MENEDZHER_OPIA', 'label' => 'Рекрутер', 'type' => 'USER'],
    ['id' => 963, 'code' => 'DATA_PRIEMA', 'label' => 'Дата приема', 'type' => 'DATE'],
    ['id' => 964, 'code' => 'DATA_OKONCHANIYA_IS', 'label' => 'Дата окончания ИС', 'type' => 'DATE'],
    ['id' => 1059, 'code' => 'KONTAKTNYY_NOMER_TELEFONA', 'label' => 'Контактный номер телефона', 'type' => 'S'],
    ['id' => 1421, 'code' => 'FORMAT_RABOTY_', 'label' => 'Формат работы', 'type' => 'E', 'link_iblock' => IBL_WORK_FORMAT],
    ['id' => 1420, 'code' => 'ADRES_OFISA_LST', 'label' => 'Офис', 'type' => 'E', 'link_iblock' => IBL_OFFICE],
    ['id' => 1198, 'code' => 'KABINET_SPISOK', 'label' => 'Местоположение сотрудника', 'type' => 'E', 'link_iblock' => IBL_LOCATION],
    ['id' => 967, 'code' => 'NOMER_KABINETA', 'label' => 'Номер кабинета', 'type' => 'S'],
    ['id' => 1623, 'code' => 'NACHALO_RABOCHEGO_DNYA', 'label' => 'Начало рабочего дня', 'type' => 'E', 'link_iblock' => IBL_WORK_START],
    ['id' => 989, 'code' => 'EST_LI_OBYAZATELSTVO_LST', 'label' => 'Есть обязательства', 'type' => 'L'],
    ['id' => 1076, 'code' => 'SODERZHANIE_OBYAZATELSTV', 'label' => 'Содержимое обязательств', 'type' => 'S'],
    ['id' => 969, 'code' => 'FOTO_SOTRUDNIKA', 'label' => 'Фото сотрудника (jpg, png)', 'type' => 'FILE'],
    ['id' => null, 'code' => 'EST_REKOMENDATSIYA', 'label' => 'Принят по рекомендации?', 'type' => 'YESNO', 'virtual' => true],
    ['id' => 3108, 'code' => 'REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET', 'label' => 'По чьей рекомендации принят сотрудник?', 'type' => 'S'],
    ['id' => 970, 'code' => 'FIO_V_DATELNOM_PADEZHE', 'label' => 'ФИО в дательном падеже', 'type' => 'S'],
    ['id' => 971, 'code' => 'FIO_V_RODITELNOM_PADEZHE', 'label' => 'ФИО в винительном падеже', 'type' => 'S'],
    ['id' => 2864, 'code' => 'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI', 'label' => 'Основные обязанности (для новости)', 'type' => 'S'],
    ['id' => 2865, 'code' => 'DOLZHNOST_DLYA_NOVOSTI', 'label' => 'Должность (для новости)', 'type' => 'S'],
    ['id' => 976, 'code' => 'RABOCHEE_MESTO', 'label' => 'Рабочее место', 'type' => 'L'],
    ['id' => 988, 'code' => 'PROPUSK_NUZHEN', 'label' => 'Пропуск нужен', 'type' => 'YESNO'],
    ['id' => 990, 'code' => 'NEOBKHODIMAYA_MEBEL', 'label' => 'Необходимая мебель', 'type' => 'L'],
    ['id' => 991, 'code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI', 'label' => 'Комментарии к заявке на создание учетной записи', 'type' => 'S'],
    ['id' => 992, 'code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA', 'label' => 'Комментарии к заявке на создание АРМ сотрудника', 'type' => 'S'],
    ['id' => 994, 'code' => 'OPISANIE_K_ZAYAVKE_NA_PROPUSK', 'label' => 'Комментарии к заявке на пропуск', 'type' => 'S'],
    ['id' => 993, 'code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH', 'label' => 'Комментарии к заявке на создание рабочего места (АХС)', 'type' => 'S'],
    ['id' => 1901, 'code' => 'DOSTUPY', 'label' => 'Доступы', 'type' => 'L'],
    ['id' => 1109, 'code' => 'VDI_VERSIYA_OS_NA_LICHNOM_PK_NOUTBUKE', 'label' => 'VDI: версия ОС на личном ПК/ноутбуке', 'type' => 'L'],
    ['id' => 1108, 'code' => 'LICHNAYA_POCHTA_KANDIDATA', 'label' => 'Личная почта кандидата', 'type' => 'S'],
];


$requiredFields = [
    'FAMILIYA','IMYA','OTCHESTVO','POL','ORGANIZATSIYA','DOLZHNOST','OTDEL','DIREKTSIYA','RUKOVODITEL','FIO_RUKOVODITELYA','OTVETSTVENNYY_MENEDZHER_OPIA',
    'DATA_PRIEMA','DATA_OKONCHANIYA_IS','FORMAT_RABOTY_','ADRES_OFISA_LST','NACHALO_RABOCHEGO_DNYA','KABINET_SPISOK','NOMER_KABINETA',
    'KONTAKTNYY_NOMER_TELEFONA','EST_LI_OBYAZATELSTVO_LST','EST_REKOMENDATSIYA','FIO_V_DATELNOM_PADEZHE','FIO_V_RODITELNOM_PADEZHE','RABOCHEE_MESTO','DOSTUPY','PROPUSK_NUZHEN','NEOBKHODIMAYA_MEBEL'
];

$sections = [
 '1'=>['title'=>'1. Основные данные','fields'=>['FAMILIYA','IMYA','OTCHESTVO','POL','ORGANIZATSIYA','DOLZHNOST','OTDEL','DIREKTSIYA','RUKOVODITEL','FIO_RUKOVODITELYA','OTVETSTVENNYY_MENEDZHER_OPIA']],
 '2'=>['title'=>'2. Условия выхода','fields'=>['DATA_PRIEMA','DATA_OKONCHANIYA_IS','FORMAT_RABOTY_','ADRES_OFISA_LST','NACHALO_RABOCHEGO_DNYA','KABINET_SPISOK','NOMER_KABINETA']],
 '3'=>['title'=>'3. Обязательства','fields'=>['EST_LI_OBYAZATELSTVO_LST','SODERZHANIE_OBYAZATELSTV']],
 '4'=>['title'=>'4. Контакты','fields'=>['KONTAKTNYY_NOMER_TELEFONA','LICHNAYA_POCHTA_KANDIDATA','FOTO_SOTRUDNIKA']],
 '5'=>['title'=>'5. Новость и ФИО','fields'=>['EST_REKOMENDATSIYA','REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET','OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI','FIO_V_DATELNOM_PADEZHE','FIO_V_RODITELNOM_PADEZHE']],
 '6'=>['title'=>'6. Рабочее место и доступы','fields'=>['RABOCHEE_MESTO','DOSTUPY','VDI_VERSIYA_OS_NA_LICHNOM_PK_NOUTBUKE','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA','PROPUSK_NUZHEN','OPISANIE_K_ZAYAVKE_NA_PROPUSK','NEOBKHODIMAYA_MEBEL','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH']]
];

$mode = 'manual';
$selectedOfferId = (int)($_GET['id_offer'] ?? 0);
$selectedRequestId = (int)($_GET['id_request'] ?? 0);
if ($selectedOfferId > 0) { $mode = 'offer'; }
elseif ($selectedRequestId > 0) { $mode = 'request'; }

if (isset($_POST['MODE'])) {
    $mode = in_array($_POST['MODE'], ['manual','offer','request'], true) ? $_POST['MODE'] : 'manual';
    $selectedOfferId = (int)($_POST['SOURCE_OFFER_ID'] ?? $selectedOfferId);
    $selectedRequestId = (int)($_POST['SOURCE_REQUEST_ID'] ?? $selectedRequestId);
}

$formData = [];
foreach ($fields as $f) {
    $formData[$f['code']] = '';
}

$errors = [];
$saveMessage = null;
$offerList = getIblockElementsById(IBL_OFFERS, ['ID' => 'DESC']);
$requestList = getIblockElementsById(IBL_REQUESTS, ['ID' => 'DESC']);



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fromRequest = [];
    if ($mode === 'request' && $selectedRequestId > 0) {
            $rq = getElementById(IBL_REQUESTS, $selectedRequestId, ['ID','PROPERTY_DIREKTSIYA','PROPERTY_PODRAZDELENIE','PROPERTY_DOLZHNOST','PROPERTY_NEPOSREDSTVENNYY_RUKOVODITEL','PROPERTY_1035','PROPERTY_FORMAT_RABOTY_PRIVYAZKA','PROPERTY_OFIS_PRIVYAZKA','PROPERTY_NACHALO_RABOCHEGO_DNYA_PRIVYAZKA','PROPERTY_OBYAZANNOSTI','PROPERTY_YURIDICHESKOE_LITSO']);
        if ($rq) {
            $fromRequest = [
                'DIREKTSIYA' => (string)($rq['PROPERTY_DIREKTSIYA_VALUE'] ?? ''),
                'OTDEL' => (string)($rq['PROPERTY_PODRAZDELENIE_VALUE'] ?? ''),
                'DOLZHNOST' => (string)($rq['PROPERTY_DOLZHNOST_VALUE'] ?? ''),
                'RUKOVODITEL' => (string)($rq['PROPERTY_NEPOSREDSTVENNYY_RUKOVODITEL_VALUE'] ?? ''),
                'OTVETSTVENNYY_MENEDZHER_OPIA' => (string)($rq['PROPERTY_1035_VALUE'] ?? ''),
                'FORMAT_RABOTY_' => (string)($rq['PROPERTY_FORMAT_RABOTY_PRIVYAZKA_VALUE'] ?? ''),
                'ADRES_OFISA_LST' => (string)($rq['PROPERTY_OFIS_PRIVYAZKA_VALUE'] ?? ''),
                'NACHALO_RABOCHEGO_DNYA' => (string)($rq['PROPERTY_NACHALO_RABOCHEGO_DNYA_PRIVYAZKA_VALUE'] ?? ''),
                'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI' => (string)($rq['PROPERTY_OBYAZANNOSTI_VALUE'] ?? ''),
                'ORGANIZATSIYA' => (string)($rq['PROPERTY_YURIDICHESKOE_LITSO_VALUE'] ?? ''),
            ];
        }
    }
    if ($mode === 'offer' && $selectedOfferId > 0) {
        $of = getElementById(IBL_OFFERS, $selectedOfferId, ['ID','PROPERTY_POLNOE_FIO_KANDIDATA','PROPERTY_DIREKTSIYA','PROPERTY_POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE','PROPERTY_DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE','PROPERTY_FIO_RUKOVODITELYA_IZ_SPISKA','PROPERTY_FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE','PROPERTY_REKRUTER','PROPERTY_FORMAT_RABOTY_NEW','PROPERTY_ADRES_OFISA_LST','PROPERTY_NACHALO_RABOCHEGO_DNYA_NEW','PROPERTY_1158','PROPERTY_1174','PROPERTY_1601','PROPERTY_1603','PROPERTY_2753']);
        if ($of) {
            $selectedRequestId = (int)($of['PROPERTY_1601_VALUE'] ?? $selectedRequestId);
            if ($selectedRequestId > 0 && !$fromRequest) { $_GET['id_request']=$selectedRequestId; }
            $fio = splitFio((string)($of['PROPERTY_POLNOE_FIO_KANDIDATA_VALUE'] ?? ''));
            $formData['FAMILIYA'] = $fio[0]; $formData['IMYA'] = $fio[1]; $formData['OTCHESTVO'] = $fio[2];
            $formData['DIREKTSIYA'] = (string)($of['PROPERTY_DIREKTSIYA_VALUE'] ?? '');
            $formData['OTDEL'] = (string)($of['PROPERTY_POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE_VALUE'] ?? '');
            $formData['DOLZHNOST'] = (string)($of['PROPERTY_DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE_VALUE'] ?? '');
            $formData['RUKOVODITEL'] = (string)($of['PROPERTY_FIO_RUKOVODITELYA_IZ_SPISKA_VALUE'] ?? '');
            $formData['FIO_RUKOVODITELYA'] = (string)($of['PROPERTY_FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE_VALUE'] ?? '');
            $formData['OTVETSTVENNYY_MENEDZHER_OPIA'] = (string)($of['PROPERTY_REKRUTER_VALUE'] ?? '');
            $formData['FORMAT_RABOTY_'] = (string)($of['PROPERTY_FORMAT_RABOTY_NEW_VALUE'] ?? '');
            $formData['ADRES_OFISA_LST'] = (string)($of['PROPERTY_ADRES_OFISA_LST_VALUE'] ?? '');
            $formData['NACHALO_RABOCHEGO_DNYA'] = (string)($of['PROPERTY_NACHALO_RABOCHEGO_DNYA_NEW_VALUE'] ?? '');
            $formData['KONTAKTNYY_NOMER_TELEFONA'] = (string)($of['PROPERTY_1158_VALUE'] ?? '');
            $formData['ORGANIZATSIYA'] = (string)($of['PROPERTY_2753_VALUE'] ?? '');
            $date = (string)($of['PROPERTY_1174_VALUE'] ?? '');
            $formData['DATA_PRIEMA'] = $date;
            if ($date) { $formData['DATA_OKONCHANIYA_IS'] = date('d.m.Y', strtotime($date.' +90 days')); }

            $candidateId = (int)($of['PROPERTY_1603_VALUE'] ?? 0);
            if ($candidateId > 0) {
                $candidate = getElementById(IBL_CANDIDATES, $candidateId, ['ID', 'PROPERTY_ODOBRENIE_SB', 'PROPERTY_KOMMENTARIY_SB_PO_OGRANICHENIYAM']);
                $approval = trim((string)($candidate['PROPERTY_ODOBRENIE_SB_VALUE'] ?? ''));
                if ($approval === 'Согласован СБ') {
                    $formData['EST_LI_OBYAZATELSTVO_LST'] = getPropertyEnumIdByValue(IBL_ADAPTATION, 'EST_LI_OBYAZATELSTVO_LST', 'Нет');
                    $formData['SODERZHANIE_OBYAZATELSTV'] = 'Нет обязательств';
                } elseif ($approval === 'Согласован СБ с ограничениями') {
                    $formData['EST_LI_OBYAZATELSTVO_LST'] = getPropertyEnumIdByValue(IBL_ADAPTATION, 'EST_LI_OBYAZATELSTVO_LST', 'Да');
                    $formData['SODERZHANIE_OBYAZATELSTV'] = trim((string)($candidate['PROPERTY_KOMMENTARIY_SB_PO_OGRANICHENIYAM_VALUE'] ?? ''));
                }
            }
        }
    }
    foreach ($fromRequest as $k => $v) {
        if (empty($formData[$k])) { $formData[$k] = $v; }
    }
    if ($formData['FIO_RUKOVODITELYA'] === '' && (int)$formData['RUKOVODITEL'] > 0) {
        $formData['FIO_RUKOVODITELYA'] = getUserFullFioById((int)$formData['RUKOVODITEL']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $propertyValues = [];
    foreach ($fields as $f) {
        $code = $f['code'];
        $value = $_POST[$code] ?? '';
        if ($f['type'] === 'FILE') {
            $value = $_FILES[$code] ?? null;
        }

        if ($f['type'] === 'YESNO') {
            $value = in_array((string)$value, ['Y', 'N'], true) ? (string)$value : '';
        } elseif ($f['type'] === 'DATE') {
            $value = normalizeDate((string)$value);
        } elseif ($f['type'] === 'USER') {
            $value = trim((string)$value);
            $value = (string)(int)preg_replace('/\D+/', '', $value);
            if ($value === '0') {
                $value = '';
            }
        } else {
            $value = trim((string)$value);
        }

        $formData[$code] = $value;
        if ($f['type'] === 'FILE') {
            if (is_array($value) && !empty($value['name'])) {
                $propertyValues[$code] = $value;
            }
        } elseif (empty($f['virtual']) && $value !== '') {
            $propertyValues[$code] = $value;
        }
    }

    $yesObligationId = getPropertyEnumIdByValue(IBL_ADAPTATION, 'EST_LI_OBYAZATELSTVO_LST', 'Да');
    $noObligationId = getPropertyEnumIdByValue(IBL_ADAPTATION, 'EST_LI_OBYAZATELSTVO_LST', 'Нет');
    if (!in_array($formData['EST_LI_OBYAZATELSTVO_LST'], [$yesObligationId, $noObligationId], true)) {
        $formData['EST_LI_OBYAZATELSTVO_LST'] = '';
        unset($propertyValues['EST_LI_OBYAZATELSTVO_LST']);
    }
    if ($formData['EST_LI_OBYAZATELSTVO_LST'] === $yesObligationId) {
        if ($formData['SODERZHANIE_OBYAZATELSTV'] === '') {
            $errors[] = 'Заполните обязательное поле: Содержимое обязательств';
        }
    } else {
        $formData['SODERZHANIE_OBYAZATELSTV'] = 'Нет обязательств';
        $propertyValues['SODERZHANIE_OBYAZATELSTV'] = 'Нет обязательств';
    }
    if ($formData['EST_REKOMENDATSIYA'] === 'Y') {
        if ($formData['REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET'] === '') {
            $errors[] = 'Укажите, по чьей рекомендации принят сотрудник.';
        }
    } else {
        $formData['REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET'] = 'Принят без рекомендации';
        $propertyValues['REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET'] = 'Принят без рекомендации';
    }

    $lastName = trim((string)($formData['FAMILIYA'] ?? ''));
    $firstName = trim((string)($formData['IMYA'] ?? ''));
    $middleName = trim((string)($formData['OTCHESTVO'] ?? ''));
    $name = trim($lastName . ' ' . $firstName . ' ' . $middleName);

    if ($name === '') {
        $errors[] = 'Заполните минимум Фамилию и Имя.';
    }
    foreach ($requiredFields as $rf) {
        if (trim((string)($formData[$rf] ?? '')) === '') {
            $errors[] = 'Не заполнено обязательное поле: ' . $rf;
        }
    }
    $organizationName = getElementNameById(IBL_ORGANIZATION, (int)($formData['ORGANIZATSIYA'] ?? 0));
    if ($organizationName === NEWS_REQUIRED_ORGANIZATION && trim((string)($formData['OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI'] ?? '')) === '') {
        $errors[] = 'Не заполнено обязательное поле: Основные обязанности (для новости)';
    }

    if (!$errors) {
        $el = new CIBlockElement();
        $newId = $el->Add([
            'IBLOCK_ID' => IBL_ADAPTATION,
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'PROPERTY_VALUES' => $propertyValues,
        ]);

        if (!$newId) {
            $errors[] = (string)$el->LAST_ERROR;
        } else {
            $saveMessage = 'Анкета успешно создана. ID: ' . (int)$newId;
            foreach ($fields as $f) {
                $formData[$f['code']] = '';
            }
        }
    }
}

?><style>
.anketa-wrap{max-width:960px;margin:24px auto;padding:0 12px}.anketa-title{font-size:24px;font-weight:600;margin:0 0 18px}
.anketa-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}.anketa-field{display:flex;flex-direction:column;gap:6px}
.anketa-field label{font-size:13px;color:#525c69}.anketa-field input,.anketa-field select{height:38px;padding:0 10px;border:1px solid #c6cdd3;border-radius:6px}
.anketa-field textarea{min-height:110px;padding:10px;border:1px solid #c6cdd3;border-radius:6px;resize:vertical}
.anketa-full{grid-column:1/-1}.anketa-actions{margin-top:18px}.anketa-msg{padding:10px 12px;border-radius:6px;margin-bottom:14px}
.anketa-msg-ok{background:#e8f7e8;color:#1f7a1f}.anketa-msg-err{background:#ffe9e9;color:#9f2f2f}
.anketa-mode-box{border:1px solid #dfe5eb;border-radius:8px;padding:12px 14px;background:#fafcff}
.anketa-source-select{max-width:560px;width:100%}
.anketa-mode-row{display:flex;gap:14px;align-items:end;flex-wrap:wrap}.anketa-section{border:1px solid #e6eaef;border-radius:8px;padding:12px;margin-top:14px}.anketa-section-title{font-weight:600;margin:0 0 10px}.anketa-hint{font-size:12px;color:#7a869a;line-height:1.35}.req{color:#d95757}
.anketa-warning{padding:10px 12px;border-radius:6px;background:#fff4ce;color:#735c0f;line-height:1.4}
</style>
<div class="anketa-wrap">
    <h1 class="anketa-title">Создание анкеты нового сотрудника</h1>

    <?php if ($saveMessage): ?>
        <div class="anketa-msg anketa-msg-ok"><?= h($saveMessage) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="anketa-msg anketa-msg-err"><?= h(implode("\n", $errors)) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= bitrix_sessid_post() ?>
        <div class="anketa-field anketa-full anketa-mode-box">
            <label>Режим создания</label>
            <div>
                <label><input type="radio" name="MODE" value="manual" <?= $mode === 'manual' ? 'checked' : '' ?>> Создать без заявок</label>
                <label style="margin-left:12px"><input type="radio" name="MODE" value="offer" <?= $mode === 'offer' ? 'checked' : '' ?>> Создать из оффера</label>
                <label style="margin-left:12px"><input type="radio" name="MODE" value="request" <?= $mode === 'request' ? 'checked' : '' ?>> Создать из заявки на подбор</label>
            </div>
            <div class="anketa-mode-row">
                <div id="offer_block" style="display:<?= $mode === 'offer' ? 'block':'none' ?>">
                    <label for="SOURCE_OFFER_ID">Оффер</label>
                    <select class="anketa-source-select" name="SOURCE_OFFER_ID" id="SOURCE_OFFER_ID">
                        <option value="">— выбрать оффер —</option>
                        <?php foreach ($offerList as $it): ?><option value="<?= h($it['ID']) ?>" <?= ((string)$selectedOfferId === (string)$it['ID'])?'selected':'' ?>><?= h($it['ID'].' — '.decodeName($it['NAME'])) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div id="request_block" style="display:<?= $mode === 'request' ? 'block':'none' ?>">
                    <label for="SOURCE_REQUEST_ID">Заявка на подбор</label>
                    <select class="anketa-source-select" name="SOURCE_REQUEST_ID" id="SOURCE_REQUEST_ID">
                        <option value="">— выбрать заявку —</option>
                        <?php foreach ($requestList as $it): ?><option value="<?= h($it['ID']) ?>" <?= ((string)$selectedRequestId === (string)$it['ID'])?'selected':'' ?>><?= h($it['ID'].' — '.decodeName($it['NAME'])) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="anketa-grid">
            <?php $fieldMap = []; foreach ($fields as $ff) { $fieldMap[$ff['code']] = $ff; } ?>
            <?php foreach ($sections as $sec): ?>
                <div class="anketa-section anketa-full">
                    <h3 class="anketa-section-title"><?= h($sec['title']) ?></h3>
                    <div class="anketa-grid">
                    <?php foreach ($sec['fields'] as $code): $f = $fieldMap[$code]; ?>
                        <div id="field_<?= h($code) ?>" class="anketa-field <?= in_array($code, ['OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI','SODERZHANIE_OBYAZATELSTV','REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA','OPISANIE_K_ZAYAVKE_NA_PROPUSK','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH'], true) ? 'anketa-full' : '' ?>">
                        <label for="<?= h($code) ?>"><?= h($f['label']) ?><?= (in_array($code, $requiredFields, true) || in_array($code, ['SODERZHANIE_OBYAZATELSTV', 'REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET'], true)) ? '<span class="req">*</span>' : '' ?></label>
                        <?php if ($f['type'] === 'L'): ?>
                            <?php $options = getPropertyEnums(IBL_ADAPTATION, $code); ?>
                            <select name="<?= h($code) ?>" id="<?= h($code) ?>">
                                <option value="">— не выбрано —</option>
                                <?php foreach ($options as $opt): ?><option value="<?= h($opt['ID']) ?>" <?= ((string)$formData[$code] === (string)$opt['ID']) ? 'selected' : '' ?>><?= h($opt['VALUE']) ?></option><?php endforeach; ?>
                            </select>
                        <?php elseif ($f['type'] === 'YESNO'): ?>
                            <select name="<?= h($code) ?>" id="<?= h($code) ?>">
                                <option value="">— не выбрано —</option>
                                <option value="Y" <?= $formData[$code] === 'Y' ? 'selected' : '' ?>>Да</option>
                                <option value="N" <?= $formData[$code] === 'N' ? 'selected' : '' ?>>Нет</option>
                            </select>
                        <?php elseif ($f['type'] === 'E'): ?>
                            <?php $options = getIblockElementsById((int)$f['link_iblock']); ?>
                            <select name="<?= h($code) ?>" id="<?= h($code) ?>" <?= $code === 'KABINET_SPISOK' ? 'data-role="location"' : '' ?>>
                                <option value="">— не выбрано —</option>
                                <?php foreach ($options as $opt): ?><option value="<?= h($opt['ID']) ?>" data-name="<?= h(decodeName($opt['NAME'])) ?>" <?= ((string)$formData[$code] === (string)$opt['ID']) ? 'selected' : '' ?>><?= h(decodeName($opt['NAME'])) ?></option><?php endforeach; ?>
                            </select>
                        <?php elseif ($f['type'] === 'DATE'): ?>
                            <input type="date" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                        <?php elseif ($f['type'] === 'USER'): ?>
                            <input type="hidden" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>"><div id="<?= h($code) ?>_selector"></div>
                        <?php elseif ($f['type'] === 'CHK'): ?>
                            <input type="checkbox" name="<?= h($code) ?>" id="<?= h($code) ?>" value="Y" <?= ($formData[$code] === 'Y') ? 'checked' : '' ?>>
                        <?php elseif ($f['type'] === 'FILE'): ?>
                            <input type="file" name="<?= h($code) ?>" id="<?= h($code) ?>" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                        <?php elseif (in_array($code, ['OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI', 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI', 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA', 'OPISANIE_K_ZAYAVKE_NA_PROPUSK'], true)): ?>
                            <textarea name="<?= h($code) ?>" id="<?= h($code) ?>"><?= h($formData[$code]) ?></textarea>
                        <?php elseif (in_array($code, ['FIO_V_DATELNOM_PADEZHE', 'FIO_V_RODITELNOM_PADEZHE'], true)): ?>
                            <div style="display:flex; gap:8px; align-items:center;"><input type="text" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>"><?php if ($code === 'FIO_V_DATELNOM_PADEZHE'): ?><button type="button" id="fill_fio_cases_btn" class="ui-btn ui-btn-light-border ui-btn-xs">Заполнить склонения ФИО</button><?php endif; ?></div>
                        <?php else: ?>
                            <input type="text" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                        <?php endif; ?>
                        <?php if ($code === 'RUKOVODITEL'): ?>
                            <small class="anketa-hint">Выбранному руководителю будет отправлено задание на заполнение плана ввода в должность.</small>
                        <?php elseif ($code === 'FIO_RUKOVODITELYA'): ?>
                            <small class="anketa-hint">Поле можно редактировать. Это ФИО будет использовано для создания заявки на учетную запись.</small>
                        <?php elseif ($code === 'REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET'): ?>
                            <small class="anketa-hint">Укажите, по чьей рекомендации принят этот сотрудник.</small>
                        <?php elseif ($code === 'FOTO_SOTRUDNIKA'): ?>
                            <div id="photo_deadline_warning" class="anketa-warning" style="display:none"></div>
                        <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div></div>
        <div class="anketa-actions">
            <button class="ui-btn ui-btn-success" type="submit">Сохранить</button>
        </div>
    </form>
</div>
<script>
BX.ready(function () {
    function loadUserFullFio(userId, input) {
        if (!input || userId <= 0) {
            return;
        }
        BX.ajax({
            url: window.location.pathname + '?ajax=get_user_fio&user_id=' + encodeURIComponent(userId),
            method: 'GET',
            dataType: 'json',
            onsuccess: function (response) {
                if (response && response.ok && String(BX('RUKOVODITEL').value) === String(userId)) {
                    input.value = response.fio || '';
                }
            }
        });
    }

    function initUserSelector(code) {
        const hidden = BX(code);
        const container = BX(code + '_selector');
        if (!hidden || !container || !BX.UI || !BX.UI.EntitySelector) {
            return;
        }
        const preselected = [];
        if (hidden.value) {
            const userId = parseInt(String(hidden.value).replace('user_', ''), 10);
            if (userId > 0) {
                preselected.push(['user', userId]);
            }
        }
        const tagSelector = new BX.UI.EntitySelector.TagSelector({
            multiple: false,
            textBoxWidth: '100%',
            placeholder: 'Выберите сотрудника',
            dialogOptions: {
                context: code + '_context',
                entities: [{id: 'user'}],
                multiple: false,
                enableSearch: true,
                dropdownMode: true,
                preselectedItems: preselected
            },
            events: {
                onAfterTagAdd: function () {
                    const tags = tagSelector.getTags();
                    hidden.value = (tags.length > 0) ? String(tags[0].getId()) : '';
                },
                onAfterTagRemove: function () {
                    hidden.value = '';
                }
            }
        });
        tagSelector.renderTo(container);
        if (code === 'RUKOVODITEL') {
            const fioInput = BX('FIO_RUKOVODITELYA');
            const dialog = tagSelector.getDialog();
            dialog.subscribe('Item:onSelect', function (event) {
                const item = event.getData().item;
                if (fioInput && item && typeof item.getTitle === 'function') {
                    fioInput.value = String(item.getTitle() || '').trim();
                    const userId = parseInt(String(item.getId()).replace(/\D+/g, ''), 10) || 0;
                    loadUserFullFio(userId, fioInput);
                }
            });
            dialog.subscribe('Item:onDeselect', function () {
                if (fioInput) {
                    fioInput.value = '';
                }
            });
        }
    }

    initUserSelector('RUKOVODITEL');
    initUserSelector('OTVETSTVENNYY_MENEDZHER_OPIA');

    const newsRequiredOrganization = <?= json_encode(NEWS_REQUIRED_ORGANIZATION, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let photoDeadlineRequest = 0;

    function selectedDataName(select) {
        if (!select || select.selectedIndex < 0) { return ''; }
        const option = select.options[select.selectedIndex];
        return option && option.dataset ? String(option.dataset.name || '').trim() : '';
    }

    function updateOrganizationDependentFields() {
        const requestId = ++photoDeadlineRequest;
        const organization = BX('ORGANIZATSIYA');
        const duties = BX('OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI');
        const dutiesLabel = document.querySelector('label[for="OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI"]');
        const photo = BX('FOTO_SOTRUDNIKA');
        const warning = BX('photo_deadline_warning');
        const startDate = BX('DATA_PRIEMA');
        const requiresNews = selectedDataName(organization) === newsRequiredOrganization;
        if (duties) { duties.required = requiresNews; }
        if (dutiesLabel) {
            dutiesLabel.classList.toggle('organization-required', requiresNews);
            let mark = dutiesLabel.querySelector('[data-role="organization-required"]');
            if (requiresNews && !mark) {
                mark = document.createElement('span');
                mark.className = 'req';
                mark.dataset.role = 'organization-required';
                mark.textContent = '*';
                dutiesLabel.appendChild(mark);
            } else if (!requiresNews && mark) {
                mark.remove();
            }
        }
        if (!warning) { return; }
        const hasPhoto = photo && photo.files && photo.files.length > 0;
        if (!requiresNews || hasPhoto || !startDate || !startDate.value) {
            warning.style.display = 'none';
            warning.textContent = '';
            return;
        }
        warning.style.display = 'none';
        warning.textContent = '';
        BX.ajax({
            url: window.location.pathname + '?ajax=get_photo_deadline&date=' + encodeURIComponent(startDate.value),
            method: 'GET',
            dataType: 'json',
            onsuccess: function (response) {
                if (requestId !== photoDeadlineRequest || !response || !response.ok) { return; }
                warning.textContent = 'Если до ' + response.deadline + ' 18:00 не загрузить фото сотрудника, поздравление на портале опубликуется без фото.';
                warning.style.display = '';
            }
        });
    }

    ['ORGANIZATSIYA', 'DATA_PRIEMA', 'FOTO_SOTRUDNIKA'].forEach(function (code) {
        const input = BX(code);
        if (input) { input.addEventListener('change', updateOrganizationDependentFields); }
    });
    updateOrganizationDependentFields();

    function selectedOptionText(select) {
        return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex].text.trim() : '';
    }

    function toggleConditionalFields() {
        const pass = BX('PROPUSK_NUZHEN');
        const passCommentRow = BX('field_OPISANIE_K_ZAYAVKE_NA_PROPUSK');
        if (pass && passCommentRow) {
            passCommentRow.style.display = pass.value === 'Y' ? '' : 'none';
        }

        const obligation = BX('EST_LI_OBYAZATELSTVO_LST');
        const obligationContent = BX('SODERZHANIE_OBYAZATELSTV');
        const hasObligations = selectedOptionText(obligation) === 'Да';
        if (obligationContent) {
            obligationContent.readOnly = !hasObligations;
            obligationContent.required = hasObligations;
            if (!hasObligations) {
                obligationContent.value = 'Нет обязательств';
            } else if (obligationContent.value === 'Нет обязательств') {
                obligationContent.value = '';
            }
        }

        const recommendation = BX('EST_REKOMENDATSIYA');
        const recommendationRow = BX('field_REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET');
        const recommendationText = BX('REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET');
        const hasRecommendation = recommendation && recommendation.value === 'Y';
        if (recommendationRow) {
            recommendationRow.style.display = hasRecommendation ? '' : 'none';
        }
        if (recommendationText) {
            recommendationText.required = hasRecommendation;
            if (!hasRecommendation) {
                recommendationText.value = 'Принят без рекомендации';
            } else if (recommendationText.value === 'Принят без рекомендации') {
                recommendationText.value = '';
            }
        }
    }

    ['PROPUSK_NUZHEN', 'EST_LI_OBYAZATELSTVO_LST', 'EST_REKOMENDATSIYA'].forEach(function (code) {
        const input = BX(code);
        if (input) { input.addEventListener('change', toggleConditionalFields); }
    });
    toggleConditionalFields();

    document.querySelectorAll('input[name="MODE"]').forEach(function (el) {
        el.addEventListener('change', function () {
            BX('offer_block').style.display = (this.value === 'offer') ? 'block' : 'none';
            BX('request_block').style.display = (this.value === 'request') ? 'block' : 'none';
        });
    });

    function redirectWithSource(mode, id) {
        const url = new URL(window.location.href);
        url.searchParams.delete('id_offer');
        url.searchParams.delete('id_request');
        if (mode === 'offer' && id > 0) {
            url.searchParams.set('id_offer', String(id));
        }
        if (mode === 'request' && id > 0) {
            url.searchParams.set('id_request', String(id));
        }
        window.location.href = url.toString();
    }

    const offerInput = BX('SOURCE_OFFER_ID');
    if (offerInput) {
        offerInput.addEventListener('change', function () {
            const id = parseInt(this.value, 10) || 0;
            if (id > 0) {
                redirectWithSource('offer', id);
            }
        });
    }

    const requestInput = BX('SOURCE_REQUEST_ID');
    if (requestInput) {
        requestInput.addEventListener('change', function () {
            const id = parseInt(this.value, 10) || 0;
            if (id > 0) {
                redirectWithSource('request', id);
            }
        });
    }

    function addThreeMonths() {
        const start = BX('DATA_PRIEMA');
        const end = BX('DATA_OKONCHANIYA_IS');
        if (!start || !end || !start.value) { return; }
        const date = new Date(start.value);
        if (isNaN(date.getTime())) { return; }
        date.setMonth(date.getMonth() + 3);
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        end.value = `${y}-${m}-${d}`;
    }
    const startInput = BX('DATA_PRIEMA');
    if (startInput) { startInput.addEventListener('change', addThreeMonths); }

    const locationSelect = document.querySelector('select[data-role="location"]');
    if (locationSelect) {
        locationSelect.addEventListener('change', function () {
            const opt = this.options[this.selectedIndex];
            const text = (opt && opt.dataset && opt.dataset.name) ? opt.dataset.name : '';
            const m = text.match(/каб\.?\s*([^|,\s]+[а-яa-z0-9-]*)/i);
            if (m && BX('NOMER_KABINETA')) { BX('NOMER_KABINETA').value = m[1]; }
        });
    }

    function detectGender(last, first, middle) {
        const m = (middle || '').toLowerCase();
        if (m.endsWith('ич')) { return 'm'; }
        if (m.endsWith('на')) { return 'f'; }
        const f = (first || '').toLowerCase();
        if (f.endsWith('а') || f.endsWith('я')) { return 'f'; }
        return 'm';
    }
    function inflectFirstName(name, gramCase, gender) {
        const n = (name || '').trim();
        if (!n) return '';
        const low = n.toLowerCase();
        if (gramCase === 'dat') {
            if (low.endsWith('й')) return n.slice(0, -1) + 'ю';
            if (low.endsWith('ь')) return n.slice(0, -1) + (gender === 'f' ? 'и' : 'ю');
            if (low.endsWith('а')) return n.slice(0, -1) + 'е';
            if (low.endsWith('я')) return n.slice(0, -1) + 'е';
            return n + 'у';
        }
        if (gramCase === 'acc') {
            if (gender === 'f') {
                if (low.endsWith('а')) return n.slice(0, -1) + 'у';
                if (low.endsWith('я')) return n.slice(0, -1) + 'ю';
                if (low.endsWith('ь')) return n;
                return n;
            }
            if (low.endsWith('й')) return n.slice(0, -1) + 'я';
            if (low.endsWith('ь')) return n.slice(0, -1) + 'я';
            if (low.endsWith('а')) return n.slice(0, -1) + 'у';
            if (low.endsWith('я')) return n.slice(0, -1) + 'ю';
            return n + 'а';
        }
        return n;
    }
    function inflectMiddleName(name, gramCase) {
        const n = (name || '').trim();
        if (!n) return '';
        const low = n.toLowerCase();
        if (gramCase === 'dat') {
            if (low.endsWith('ич')) return n + 'у';
            if (low.endsWith('на')) return n.slice(0, -1) + 'е';
        }
        if (gramCase === 'acc') {
            if (low.endsWith('ич')) return n + 'а';
            if (low.endsWith('на')) return n.slice(0, -1) + 'у';
            return n;
        }
        return n;
    }
    function inflectLastName(name, gramCase, gender) {
        const n = (name || '').trim();
        if (!n) return '';
        const low = n.toLowerCase();
        if (gramCase === 'dat') {
            if (low.endsWith('ов') || low.endsWith('ев') || low.endsWith('ин')) return n + 'у';
            if (low.endsWith('ий') || low.endsWith('ый') || low.endsWith('ой')) return n.slice(0, -2) + 'ому';
            if (low.endsWith('а')) return n.slice(0, -1) + 'ой';
            if (low.endsWith('я')) return n.slice(0, -1) + 'е';
            if (gender === 'm' && /[бвгджзклмнпрстфхцчшщ]$/i.test(low)) return n + 'у';
            return n;
        }
        if (gramCase === 'acc') {
            if (gender === 'f') {
                if (low.endsWith('а')) return n.slice(0, -1) + 'у';
                if (low.endsWith('я')) return n.slice(0, -1) + 'ю';
            }
            if (gender === 'm') {
                if (low.endsWith('ий') || low.endsWith('ый') || low.endsWith('ой')) return n.slice(0, -2) + 'ого';
                if (low.endsWith('ов') || low.endsWith('ев') || low.endsWith('ин')) return n + 'а';
                if (/[бвгджзклмнпрстфхцчшщ]$/i.test(low)) return n + 'а';
            }
            return n;
        }
        return n;
    }
    function composeFio() {
        return [BX('FAMILIYA')?.value || '', BX('IMYA')?.value || '', BX('OTCHESTVO')?.value || ''].join(' ').trim();
    }
    const fillBtn = BX('fill_fio_cases_btn');
    if (fillBtn) {
        fillBtn.addEventListener('click', function () {
            const last = BX('FAMILIYA')?.value || '';
            const first = BX('IMYA')?.value || '';
            const middle = BX('OTCHESTVO')?.value || '';
            const gender = detectGender(last, first, middle);
            BX('FIO_V_DATELNOM_PADEZHE').value = [
                inflectLastName(last, 'dat', gender),
                inflectFirstName(first, 'dat', gender),
                inflectMiddleName(middle, 'dat')
            ].join(' ').replace(/\s+/g, ' ').trim();
            BX('FIO_V_RODITELNOM_PADEZHE').value = [
                inflectLastName(last, 'acc', gender),
                inflectFirstName(first, 'acc', gender),
                inflectMiddleName(middle, 'acc')
            ].join(' ').replace(/\s+/g, ' ').trim();
        });
    }
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
