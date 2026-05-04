<?php
/**
 * Создание анкеты нового сотрудника на основании оффера/заявки/анкеты кандидата.
 * URL: /forms/staff_recruiting/adaptation/create_anketa.php?id_offer=1234
 * URL: /forms/staff_recruiting/adaptation/create_anketa.php?id_request=1234
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты нового сотрудника');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const IBL_NEW_EMPLOYEE_FORM = 196;
const IBL_REQUESTS = 201;
const IBL_CANDIDATES = 207;
const IBL_OFFERS = 218;

const OFFER_PROP_REQUEST_ID = 1601;
const OFFER_PROP_CANDIDATE_ID = 1603;

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cleanValue($v): string
{
    if (is_array($v)) {
        $v = reset($v);
    }
    return trim((string)$v);
}

function loadElementById(int $iblockId, int $id, array $fields = ['ID']): ?array
{
    if ($id <= 0) {
        return null;
    }

    $row = CIBlockElement::GetList([], [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE' => 'Y',
        'ID' => $id,
        'CHECK_PERMISSIONS' => 'Y',
    ], false, ['nTopCount' => 1], $fields)->GetNext();

    return $row ?: null;
}

function loadPropsByCodes(int $iblockId, int $id, array $codes): array
{
    $props = [];
    CIBlockElement::GetPropertyValuesArray($props, $iblockId, ['ID' => $id], ['CODE' => $codes]);
    return $props[$id] ?? [];
}

function propValue(array $props, string $code): string
{
    $value = $props[$code]['VALUE'] ?? '';
    if (is_array($value)) {
        $value = reset($value);
    }
    return trim((string)$value);
}

function plus3months(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('d.m.Y', $date);
    if (!$dt) {
        return '';
    }
    $dt->modify('+3 months');
    return $dt->format('d.m.Y');
}

$idOffer = (int)($_GET['id_offer'] ?? 0);
$idRequest = (int)($_GET['id_request'] ?? 0);

$offerProps = [];
$requestProps = [];
$candidateProps = [];

if ($idOffer > 0) {
    $offer = loadElementById(IBL_OFFERS, $idOffer, ['ID']);
    if ($offer) {
        $offerProps = loadPropsByCodes(IBL_OFFERS, (int)$offer['ID'], [
            'FAMILIYA', 'IMYA', 'OTCHESTVO', 'ORGANIZATSIYA', 'OTDEL', 'DOLZHNOST', 'RUKOVODITEL',
            'OTVETSTVENNYY_MENEDZHER_OPIA', 'DATA_PRIEMA', 'KONTAKTNYY_NOMER_TELEFONA', 'FORMAT_RABOTY_',
            'ADRES_OFISA_LST', 'NACHALO_RABOCHEGO_DNYA', 'DOLZHNOST_DLYA_NOVOSTI',
        ]);

        if ($idRequest <= 0) {
            $idRequest = (int)($offerProps['ID_ZAYAVKI_NA_PODBOR']['VALUE'] ?? 0);
            if ($idRequest <= 0) {
                $idRequest = (int)($offerProps['REQUEST_ID']['VALUE'] ?? 0);
            }
            if ($idRequest <= 0) {
                $idRequest = (int)($offerProps[(string)OFFER_PROP_REQUEST_ID]['VALUE'] ?? 0);
            }
        }

        $candidateId = (int)($offerProps['ID_ANKETY_KANDIDATA']['VALUE'] ?? 0);
        if ($candidateId <= 0) {
            $candidateId = (int)($offerProps['CANDIDATE_ID']['VALUE'] ?? 0);
        }
        if ($candidateId <= 0) {
            $candidateId = (int)($offerProps[(string)OFFER_PROP_CANDIDATE_ID]['VALUE'] ?? 0);
        }

        if ($candidateId > 0) {
            $candidateProps = loadPropsByCodes(IBL_CANDIDATES, $candidateId, ['EST_LI_OBYAZATELSTVO_LST', 'SODERZHANIE_OBYAZATELSTV', 'LICHNAYA_POCHTA_KANDIDATA']);
        }
    }
}

if ($idRequest > 0) {
    $request = loadElementById(IBL_REQUESTS, $idRequest, ['ID']);
    if ($request) {
        $requestProps = loadPropsByCodes(IBL_REQUESTS, (int)$request['ID'], [
            'POL', 'DIREKTSIYA', 'OTDEL', 'DOLZHNOST', 'RUKOVODITEL', 'OTVETSTVENNYY_MENEDZHER_OPIA',
            'FORMAT_RABOTY_', 'ADRES_OFISA_LST', 'NACHALO_RABOCHEGO_DNYA', 'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI',
            'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA', 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH', 'ORGANIZATSIYA'
        ]);
    }
}

$prefill = [
    'FAMILIYA' => propValue($offerProps, 'FAMILIYA'),
    'IMYA' => propValue($offerProps, 'IMYA'),
    'OTCHESTVO' => propValue($offerProps, 'OTCHESTVO'),
    'ORGANIZATSIYA' => propValue($offerProps, 'ORGANIZATSIYA') ?: propValue($requestProps, 'ORGANIZATSIYA'),
    'POL' => propValue($requestProps, 'POL'),
    'DIREKTSIYA' => propValue($requestProps, 'DIREKTSIYA'),
    'OTDEL' => propValue($offerProps, 'OTDEL') ?: propValue($requestProps, 'OTDEL'),
    'DOLZHNOST' => propValue($offerProps, 'DOLZHNOST') ?: propValue($requestProps, 'DOLZHNOST'),
    'RUKOVODITEL' => propValue($offerProps, 'RUKOVODITEL') ?: propValue($requestProps, 'RUKOVODITEL'),
    'OTVETSTVENNYY_MENEDZHER_OPIA' => propValue($offerProps, 'OTVETSTVENNYY_MENEDZHER_OPIA') ?: propValue($requestProps, 'OTVETSTVENNYY_MENEDZHER_OPIA'),
    'DATA_PRIEMA' => propValue($offerProps, 'DATA_PRIEMA'),
    'DATA_OKONCHANIYA_IS' => plus3months(propValue($offerProps, 'DATA_PRIEMA')),
    'KONTAKTNYY_NOMER_TELEFONA' => propValue($offerProps, 'KONTAKTNYY_NOMER_TELEFONA'),
    'FORMAT_RABOTY_' => propValue($offerProps, 'FORMAT_RABOTY_') ?: propValue($requestProps, 'FORMAT_RABOTY_'),
    'ADRES_OFISA_LST' => propValue($offerProps, 'ADRES_OFISA_LST') ?: propValue($requestProps, 'ADRES_OFISA_LST'),
    'NACHALO_RABOCHEGO_DNYA' => propValue($offerProps, 'NACHALO_RABOCHEGO_DNYA') ?: propValue($requestProps, 'NACHALO_RABOCHEGO_DNYA'),
    'EST_LI_OBYAZATELSTVO_LST' => propValue($candidateProps, 'EST_LI_OBYAZATELSTVO_LST'),
    'SODERZHANIE_OBYAZATELSTV' => propValue($candidateProps, 'SODERZHANIE_OBYAZATELSTV'),
    'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI' => propValue($requestProps, 'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI'),
    'DOLZHNOST_DLYA_NOVOSTI' => propValue($offerProps, 'DOLZHNOST_DLYA_NOVOSTI') ?: propValue($offerProps, 'DOLZHNOST'),
    'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA' => propValue($requestProps, 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA'),
    'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH' => propValue($requestProps, 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH'),
    'LICHNAYA_POCHTA_KANDIDATA' => propValue($candidateProps, 'LICHNAYA_POCHTA_KANDIDATA'),
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $name = trim($_POST['NAME'] ?? '');
    if ($name === '') {
        $name = trim(($_POST['FAMILIYA'] ?? '') . ' ' . ($_POST['IMYA'] ?? '') . ' ' . ($_POST['OTCHESTVO'] ?? ''));
    }
    if ($name === '') {
        $errors[] = 'Заполните ФИО сотрудника.';
    }

    if (!$errors) {
        $propValues = [];
        foreach ($_POST as $code => $value) {
            if (!preg_match('/^[A-Z0-9_]+$/', (string)$code)) {
                continue;
            }
            $propValues[$code] = cleanValue($value);
        }

        $el = new CIBlockElement();
        $newId = $el->Add([
            'IBLOCK_ID' => IBL_NEW_EMPLOYEE_FORM,
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'PROPERTY_VALUES' => $propValues,
        ]);

        if ($newId) {
            LocalRedirect('/services/lists/196/view/' . (int)$newId . '/?list_section_id=');
        } else {
            $errors[] = 'Не удалось создать анкету: ' . $el->LAST_ERROR;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_POST = $prefill;
}
?>
<div class="ui-alert ui-alert-info" style="margin-bottom:16px;">
    <span class="ui-alert-message">
        Источник: оффер #<?= h($idOffer) ?>, заявка #<?= h($idRequest) ?>.
        Приоритет заполнения: оффер → заявка на подбор → анкета кандидата.
    </span>
</div>

<?php foreach ($errors as $e): ?>
    <div class="ui-alert ui-alert-danger" style="margin-bottom:8px;"><span class="ui-alert-message"><?= h($e) ?></span></div>
<?php endforeach; ?>

<form method="post">
    <?= bitrix_sessid_post() ?>
    <div class="ui-form">
        <?php
        $fields = [
            'FAMILIYA' => 'Фамилия', 'IMYA' => 'Имя', 'OTCHESTVO' => 'Отчество', 'STATUS_SOTRUDNIKA' => 'Статус сотрудника',
            'ORGANIZATSIYA' => 'Организация', 'POL' => 'Пол', 'DIREKTSIYA' => 'Дирекция', 'OTDEL' => 'Отдел',
            'DOLZHNOST' => 'Должность', 'RUKOVODITEL' => 'Руководитель', 'OTVETSTVENNYY_MENEDZHER_OPIA' => 'Рекрутер',
            'DATA_PRIEMA' => 'Дата приема', 'DATA_OKONCHANIYA_IS' => 'Дата окончания ИС', 'KONTAKTNYY_NOMER_TELEFONA' => 'Контактный номер телефона',
            'FORMAT_RABOTY_' => 'Формат работы', 'ADRES_OFISA_LST' => 'Офис', 'NACHALO_RABOCHEGO_DNYA' => 'Начало рабочего дня',
            'EST_LI_OBYAZATELSTVO_LST' => 'Есть ли обязательство?', 'SODERZHANIE_OBYAZATELSTV' => 'Содержание обязательств',
            'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI' => 'Основные обязанности (для новости)', 'DOLZHNOST_DLYA_NOVOSTI' => 'Должность (для новости)',
            'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA' => 'Комментарии к заявке на создание АРМ сотрудника',
            'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH' => 'Комментарии к заявке на создание рабочего места (АХС)',
            'LICHNAYA_POCHTA_KANDIDATA' => 'Личная почта кандидата',
        ];
        foreach ($fields as $code => $label): ?>
            <div class="ui-form-row">
                <div class="ui-form-label"><label for="<?= h($code) ?>"><?= h($label) ?></label></div>
                <div class="ui-form-content"><input class="ui-ctl-element" type="text" id="<?= h($code) ?>" name="<?= h($code) ?>" value="<?= h($_POST[$code] ?? '') ?>"></div>
            </div>
        <?php endforeach; ?>

        <div class="ui-form-row">
            <div class="ui-form-label"></div>
            <div class="ui-form-content">
                <button type="submit" class="ui-btn ui-btn-success">Создать анкету</button>
                <a href="/services/lists/196/view/0/?list_section_id=" class="ui-btn ui-btn-link">К списку анкет</a>
            </div>
        </div>
    </div>
</form>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
