<?php
/**
 * /forms/staff_recruitment/json_update.php
 *
 * Оснастка для заполнения JSON-свойства у существующих заявок ИБ 201,
 * где JSON ещё пустой.
 *
 * Режимы:
 * - просмотр: открыть страницу без параметра run=Y
 * - выполнение: ?run=Y
 * - ограничение: ?run=Y&limit=100
 */

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

global $APPLICATION;
$APPLICATION->SetTitle('Обновление JSON у заявок на подбор');

const JR_IBLOCK_RECRUIT = 201;

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

function jr_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jr_date_to_input($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : '';
}

function jr_json_filter_filled($value)
{
    if (is_array($value)) {
        $filtered = [];
        foreach ($value as $k => $v) {
            $fv = jr_json_filter_filled($v);
            $isEmptyString = is_string($fv) && trim($fv) === '';
            $isEmptyArray = is_array($fv) && empty($fv);
            if ($fv === null || $isEmptyString || $isEmptyArray) {
                continue;
            }
            $filtered[$k] = $fv;
        }
        return $filtered;
    }

    return is_string($value) ? trim($value) : $value;
}

function jr_extract_equipment_comment($equipmentText, $equipmentName)
{
    $equipmentText = trim((string)$equipmentText);
    $equipmentName = trim((string)$equipmentName);

    if ($equipmentText === '') {
        return '';
    }
    if ($equipmentName === '') {
        return $equipmentText;
    }

    if (mb_stripos($equipmentText, $equipmentName) === 0) {
        $tail = trim(mb_substr($equipmentText, mb_strlen($equipmentName)));
        return trim((string)preg_replace('/^[\s\r\n]+/u', '', $tail));
    }

    return $equipmentText;
}

function jr_build_json_from_props(array $props)
{
    $reasonText = trim((string)($props['PRICHINA_OTKRYTIYA_VAKANSII_TEKST']['VALUE'] ?? ''));
    $reasonMap = [
        'Новая штатная единица' => 'new_unit',
        'Декретная ставка' => 'maternity',
        'Замещение увольняющегося сотрудника' => 'replacement',
        'Перевод сотрудника' => 'transfer',
    ];
    $reasonKey = $reasonMap[$reasonText] ?? '';
    $reasonDetails = trim((string)($props['PRICHINA_ZAYAVKI_NA_PODBOR']['VALUE'] ?? ''));

    $maternityFio = '';
    $replacementFio = '';
    $transferFioWhere = '';
    if ($reasonKey === 'maternity' && preg_match('/^\s*Декрет\s+с\s+[0-9\.\-]+\s+(.*)$/u', $reasonDetails, $m)) {
        $maternityFio = trim((string)$m[1]);
    } elseif ($reasonKey === 'replacement' && preg_match('/^\s*Увольнение\s+с\s+[0-9\.\-]+\s+(.*)$/u', $reasonDetails, $m)) {
        $replacementFio = trim((string)$m[1]);
    } elseif ($reasonKey === 'transfer' && preg_match('/^\s*Перевод\s+с\s+[0-9\.\-]+\s+(.*)$/u', $reasonDetails, $m)) {
        $transferFioWhere = trim((string)$m[1]);
    }

    $businessTrips = trim((string)($props['KOMANDIROVKI_TEKST']['VALUE'] ?? ''));

    $equipmentId = (string)($props['OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA']['VALUE'] ?? '');
    $equipmentName = trim((string)($props['OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA']['VALUE_ENUM'] ?? ''));
    $equipmentText = trim((string)($props['OBORUDOVANIE_DLYA_RABOTY_TEKST']['VALUE'] ?? ''));

    $managerId = (int)($props['NEPOSREDSTVENNYY_RUKOVODITEL']['VALUE'] ?? 0);
    $managerName = trim((string)($props['NEPOSREDSTVENNYY_RUKOVODITEL']['VALUE_ENUM'] ?? ''));

    $payload = [
        'legal' => (string)($props['YURIDICHESKOE_LITSO']['VALUE'] ?? ''),
        'dep0' => (string)($props['PODRAZDELENIE_0_UROVNYA']['VALUE'] ?? ''),
        'position_custom' => trim((string)($props['DOLZHNOST']['VALUE'] ?? '')),
        'directorate' => trim((string)($props['DIREKTSIYA']['VALUE'] ?? '')),
        'employee_id' => $managerId > 0 ? (string)$managerId : '',
        'employee_name' => $managerName,
        'stake' => (string)($props['STAVKA']['VALUE'] ?? ''),

        'reason' => $reasonKey,
        'reason_expand' => $reasonKey === 'new_unit'
            ? $reasonDetails
            : '',
        'maternity_date' => $reasonKey === 'maternity'
            ? jr_date_to_input($props['DATA_DEKRETA']['VALUE'] ?? '')
            : '',
        'maternity_fio' => $maternityFio,
        'replacement_date' => $reasonKey === 'replacement'
            ? jr_date_to_input($props['DATA_UVOLNENIYA']['VALUE'] ?? '')
            : '',
        'replacement_fio' => $replacementFio,
        'transfer_date' => $reasonKey === 'transfer'
            ? jr_date_to_input($props['DATA_PEREVODA']['VALUE'] ?? '')
            : '',
        'transfer_fio_where' => $transferFioWhere,

        'duties_original' => trim((string)($props['DOLZHNOSTNYE_OBYAZANNOSTI_1C']['VALUE'] ?? '')),
        'duties' => trim((string)($props['OBYAZANNOSTI']['VALUE'] ?? '')),
        'gender' => trim((string)($props['POL_STROKA']['VALUE'] ?? '')),
        'education' => trim((string)($props['OBRAZOVANIE_TEKST']['VALUE'] ?? '')),
        'lang' => trim((string)($props['VLADENIE_INOSTRANNYM_YAZYKOM_TEKST']['VALUE'] ?? '')),
        'experience' => trim((string)($props['OPYT_RABOTY']['VALUE'] ?? '')),
        'softskills' => trim((string)($props['DELOVYE_KACHESTVA']['VALUE'] ?? '')),
        'softwares' => trim((string)($props['ZNANIE_SPETSIALNYKH_PROGRAMM']['VALUE'] ?? '')),
        'requirements_extra' => trim((string)($props['DOPOLNITELNYE_TREBOVANIYA']['VALUE'] ?? '')),
        'driver_license' => trim((string)($props['NALICHIE_VODITELSKIKH_PRAV_TEKST']['VALUE'] ?? '')),
        'from_positions' => trim((string)($props['ZHELAEMAYA_SPETSIALNOST']['VALUE'] ?? '')),

        'schedule' => (string)($props['GRAFIK_RABOTY_PRIVYAZKA']['VALUE'] ?? ''),
        'start_time' => (string)($props['NACHALO_RABOCHEGO_DNYA_PRIVYAZKA']['VALUE'] ?? ''),
        'format' => (string)($props['FORMAT_RABOTY_PRIVYAZKA']['VALUE'] ?? ''),
        'office' => (string)($props['OFIS_PRIVYAZKA']['VALUE'] ?? ''),
        'business_trips' => $businessTrips,
        'trip_duration' => (mb_strpos($businessTrips, 'Да') === 0)
            ? trim((string)($props['KOMANDIROVKI_PRODOLZHITELNOST']['VALUE'] ?? ''))
            : '',

        'contract' => (string)($props['TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA']['VALUE'] ?? ''),
        'equipment' => $equipmentId,
        'equipment_comment' => jr_extract_equipment_comment($equipmentText, $equipmentName),
        'confidential' => trim((string)($props['KONFIDENTSIALNYY_POISK']['VALUE'] ?? 'Нет')),
        'internal_candidate' => trim((string)($props['EST_LI_VNUTRENNIY_KANDIDAT_NA_DANNUYU_DOLZHNOST']['VALUE'] ?? '')),
        'internal_departments' => trim((string)($props['OTDELY_DLYA_POISKA_VNUTRENNIKH_KANDIDATOV']['VALUE'] ?? '')),
    ];

    return jr_json_filter_filled($payload);
}

$run = (string)($_GET['run'] ?? '') === 'Y';
$limit = (int)($_GET['limit'] ?? 0);
if ($limit < 0) {
    $limit = 0;
}

$stats = [
    'checked' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
    'items' => [],
];

$rs = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => JR_IBLOCK_RECRUIT, 'ACTIVE' => 'Y'],
    false,
    $limit > 0 ? ['nTopCount' => $limit] : false,
    ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_JSON', 'PROPERTY_3036']
);

while ($item = $rs->GetNext()) {
    $stats['checked']++;

    $jsonCurrent = trim((string)($item['PROPERTY_JSON_VALUE'] ?? ''));
    $jsonAlt = trim((string)($item['PROPERTY_3036_VALUE'] ?? ''));

    if ($jsonCurrent !== '' || $jsonAlt !== '') {
        $stats['skipped']++;
        continue;
    }

    $props = [];
    $pr = CIBlockElement::GetProperty(JR_IBLOCK_RECRUIT, (int)$item['ID'], ['sort' => 'asc'], []);
    while ($p = $pr->Fetch()) {
        $code = (string)($p['CODE'] ?? '');
        if ($code === '') {
            continue;
        }
        $props[$code] = $p;
    }

    $payload = jr_build_json_from_props($props);
    $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($jsonData === false || $jsonData === '' || $jsonData === '{}') {
        $stats['errors']++;
        $stats['items'][] = [
            'id' => (int)$item['ID'],
            'name' => (string)$item['NAME'],
            'status' => 'error',
            'message' => 'Не удалось собрать непустой JSON',
        ];
        continue;
    }

    if ($run) {
        CIBlockElement::SetPropertyValuesEx((int)$item['ID'], JR_IBLOCK_RECRUIT, ['JSON' => $jsonData]);
    }

    $stats['updated']++;
    $stats['items'][] = [
        'id' => (int)$item['ID'],
        'name' => (string)$item['NAME'],
        'status' => $run ? 'updated' : 'preview',
        'json_length' => mb_strlen($jsonData),
    ];
}
?>
<div class="container" style="margin:20px 0;max-width:1200px;">
    <h2>Оснастка обновления JSON заявок</h2>
    <p>
        Статус: <b><?= $run ? 'выполнение (run=Y)' : 'предпросмотр (без записи)' ?></b><br>
        Для запуска обновления используйте параметр <code>?run=Y</code>.
        <?= $limit > 0 ? '<br>Ограничение выборки: <code>' . (int)$limit . '</code>' : '' ?>
    </p>

    <ul>
        <li>Проверено заявок: <b><?= (int)$stats['checked'] ?></b></li>
        <li>Кандидатов на обновление: <b><?= (int)$stats['updated'] ?></b></li>
        <li>Пропущено (JSON уже заполнен): <b><?= (int)$stats['skipped'] ?></b></li>
        <li>Ошибки сборки JSON: <b><?= (int)$stats['errors'] ?></b></li>
    </ul>

    <?php if (!empty($stats['items'])): ?>
        <table class="table table-bordered table-sm">
            <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Статус</th>
                <th>Комментарий</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($stats['items'] as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= jr_h($row['name']) ?></td>
                    <td><?= jr_h($row['status']) ?></td>
                    <td>
                        <?php if (!empty($row['message'])): ?>
                            <?= jr_h($row['message']) ?>
                        <?php else: ?>
                            JSON length: <?= (int)($row['json_length'] ?? 0) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
