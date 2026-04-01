<?php
/**
 * /forms/staff_recruitment/view_request.php
 *
 * Просмотр заявки на подбор (ИБ 201) — роли: C&B, рекрутер, руководитель подбора
 * Версия: v1.0.0 (2026-04-01)
 *
 * v1.0.5:
 * - Обновлена версия в шапке и добавлены поясняющие комментарии по ключевой логике расчётов/отображения
 *
 * v1.0.4:
 * - Визуально отделены группы: карточки, разные мягкие цвета фона, отступы
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

global $APPLICATION, $USER;

/* =========================================================
 * 1) CONFIG
 * =======================================================*/
const IBLOCK_RECRUIT = 201;
const CB_GLOBAL_VAR_ID = 'Variable1722502594854';
const RECRUIT_HEAD_GLOBAL_VAR_ID = 'Variable1722503621093';
const ROLE_LABEL_CB = 'Менеджер C&B';
const ROLE_LABEL_RECRUITER = 'Рекрутер/руководитель отдела подбора';

// Справочники (как в create_request.php)
const IBLOCK_LEGAL      = 308;
const IBLOCK_DEP0       = 358;
const IBLOCK_SCHEDULE   = 236;
const IBLOCK_STARTTIME  = 237;
const IBLOCK_FORMAT     = 234;
const IBLOCK_OFFICE     = 233;
const IBLOCK_CONTRACT   = 325;
const IBLOCK_EQUIPMENT  = 326;
const IBLOCK_BONUSTYPE  = 327;

if (!Loader::includeModule('iblock')) {
    ShowError('Не подключен модуль iblock');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}
Loader::includeModule('bizproc');

Extension::load([
    'ui.forms',
    'ui.buttons',
    'ui.alerts',
    'ui.hint',
]);


/**
 * Порядок групп
 */
$GROUP_ORDER = [
    'Оргструктура',
    'Требования',
    'Мотивация',
    'Условия работы',
    'Подбор',
];

/**
 * Стили групп (мягкие оттенки)
 */
$GROUP_STYLES = [
    'Оргструктура'   => ['BG' => '#F3F7FF', 'BORDER' => '#D7E3FF'],
    'Требования'     => ['BG' => '#F4FBF6', 'BORDER' => '#D9F2E1'],
    'Мотивация'      => ['BG' => '#FFF7EF', 'BORDER' => '#FFE3C7'],
    'Условия работы' => ['BG' => '#F7F3FF', 'BORDER' => '#E6DBFF'],
    'Подбор'         => ['BG' => '#F8FAFC', 'BORDER' => '#E6EEF7'],
];

/**
 * CODE => GROUP
 */
$GROUP_MAP = [
    // Оргструктура
    'DOLZHNOST' => 'Оргструктура',
    'YURIDICHESKOE_LITSO' => 'Оргструктура',
    'PODRAZDELENIE_0_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE_1_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE_2_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE_3_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE_4_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE_5_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE_6_UROVNYA' => 'Оргструктура',
    'PODRAZDELENIE' => 'Оргструктура',
    'DIREKTSIYA' => 'Оргструктура',
    'NEPOSREDSTVENNYY_RUKOVODITEL' => 'Оргструктура',
    'DOLZHNOST_RUKOVODITELYA' => 'Оргструктура',

    // Требования
    'OBRAZOVANIE_TEKST' => 'Требования',
    'OBYAZANNOSTI' => 'Требования',
    'RAZNITSA_TEKSTOV' => 'Требования',
    'DOLZHNOSTNYE_OBYAZANNOSTI_1C' => 'Требования',
    'POL_STROKA' => 'Требования',
    'ZHELAEMAYA_SPETSIALNOST' => 'Требования',
    'OPYT_RABOTY' => 'Требования',
    'DELOVYE_KACHESTVA' => 'Требования',
    'VLADENIE_INOSTRANNYM_YAZYKOM_TEKST' => 'Требования',
    'ZNANIE_SPETSIALNYKH_PROGRAMM' => 'Требования',
    'NALICHIE_VODITELSKIKH_PRAV' => 'Требования',
    'NALICHIE_VODITELSKIKH_PRAV_TEKST' => 'Требования',
    'DOPOLNITELNYE_TREBOVANIYA' => 'Требования',

    // Мотивация
    'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA' => 'Мотивация',
    'OKLAD' => 'Мотивация',
    'PROTSENT_PREMII_' => 'Мотивация',
    'ISN_RUB_GROSS' => 'Мотивация',
    'DOKHOD_V_MESYATS_V_SREDNEM_PRI_VYPOLNENII_KPI_RUB_' => 'Мотивация',
    'DOKHOD_V_MESYATS_V_SREDNEM_RUB_POSLE_VYCHETA_NDFL' => 'Мотивация',
    'UROVEN_DOKHODA_MEDIANA' => 'Мотивация',
    'VAKANSIYA_PODTVERZHDENA_C_B' => 'Мотивация',
    'PRIZNAK_PO_DOLZHNOSTI_TEKST' => 'Мотивация',
    'RUKOVODYASHCHAYA_DOLZHNOST' => 'Мотивация',
    'STAVKA' => 'Мотивация',
    'KOMMENTARII_C_B' => 'Мотивация',

    // Условия работы
    'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA' => 'Условия работы',
    'OFIS_PRIVYAZKA' => 'Условия работы',
    'GRAFIK_RABOTY_PRIVYAZKA' => 'Условия работы',
    'FORMAT_RABOTY_PRIVYAZKA' => 'Условия работы',
    'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA' => 'Условия работы',
    'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA' => 'Условия работы',
    'OBORUDOVANIE_DLYA_RABOTY_TEKST' => 'Условия работы',
    'NEOBKHODIMAYA_MEBEL' => 'Условия работы',
    'KOMANDIROVKI_TEKST' => 'Условия работы',
    'KOMANDIROVKI_PRODOLZHITELNOST' => 'Условия работы',

    // Подбор
    'PRICHINA_OTKRYTIYA_VAKANSII_TEKST' => 'Подбор',
    'PRICHINA_ZAYAVKI_NA_PODBOR' => 'Подбор',
    'DATA_DEKRETA' => 'Подбор',
    'DATA_UVOLNENIYA' => 'Подбор',
    'DATA_PEREVODA' => 'Подбор',
    'EST_LI_VNUTRENNIY_KANDIDAT_NA_DANNUYU_DOLZHNOST' => 'Подбор',
    'OTDELY_DLYA_POISKA_VNUTRENNIKH_KANDIDATOV' => 'Подбор',
    'KONFIDENTSIALNYY_POISK' => 'Подбор',
    'REKRUTER' => 'Подбор',
    'STATUS_ZAYAVKI' => 'Подбор',
    'KOMMENTARII_K_ZAYAVKE' => 'Подбор',
    'KOMMENTARII' => 'Подбор',
];

/**
 * Поля по XLS (показывать = Да/Показываем...)
 */
$FIELDS = [
    ["CODE" => "DOLZHNOST", "NAME" => "Должность", "EDITABLE" => true],
    ["CODE" => "YURIDICHESKOE_LITSO", "NAME" => "Юридическое лицо", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_0_UROVNYA", "NAME" => "Подразделение 0 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_1_UROVNYA", "NAME" => "Подразделение 1 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_2_UROVNYA", "NAME" => "Подразделение 2 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_3_UROVNYA", "NAME" => "Подразделение 3 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_4_UROVNYA", "NAME" => "Подразделение 4 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_5_UROVNYA", "NAME" => "Подразделение 5 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE_6_UROVNYA", "NAME" => "Подразделение 6 уровня", "EDITABLE" => true],
    ["CODE" => "PODRAZDELENIE", "NAME" => "Подразделение", "EDITABLE" => true],
    ["CODE" => "DIREKTSIYA", "NAME" => "Дирекция", "EDITABLE" => true],
    ["CODE" => "NEPOSREDSTVENNYY_RUKOVODITEL", "NAME" => "Непосредственный руководитель", "EDITABLE" => true],
    ["CODE" => "DOLZHNOST_RUKOVODITELYA", "NAME" => "Должность руководителя", "EDITABLE" => true],

    ["CODE" => "OBRAZOVANIE_TEKST", "NAME" => "Образование", "EDITABLE" => false],
    ["CODE" => "OBYAZANNOSTI", "NAME" => "Обязанности, заполненные руководителем", "EDITABLE" => false],
    ["CODE" => "RAZNITSA_TEKSTOV", "NAME" => "Разница текстов", "EDITABLE" => false],
    ["CODE" => "DOLZHNOSTNYE_OBYAZANNOSTI_1C", "NAME" => "Обязанности из 1с", "EDITABLE" => true],
    ["CODE" => "POL_STROKA", "NAME" => "Пол", "EDITABLE" => false],
    ["CODE" => "ZHELAEMAYA_SPETSIALNOST", "NAME" => "Желаемая специальность", "EDITABLE" => false],
    ["CODE" => "OPYT_RABOTY", "NAME" => "Опыт работы", "EDITABLE" => false],
    ["CODE" => "DELOVYE_KACHESTVA", "NAME" => "Деловые качества", "EDITABLE" => false],
    ["CODE" => "VLADENIE_INOSTRANNYM_YAZYKOM_TEKST", "NAME" => "Владение иностранным языком", "EDITABLE" => false],
    ["CODE" => "ZNANIE_SPETSIALNYKH_PROGRAMM", "NAME" => "Знание специальных программ", "EDITABLE" => false],
    ["CODE" => "NALICHIE_VODITELSKIKH_PRAV_TEKST", "NAME" => "Наличие водительских прав", "EDITABLE" => false],
    ["CODE" => "DOPOLNITELNYE_TREBOVANIYA", "NAME" => "Дополнительные требования", "EDITABLE" => false],

    ["CODE" => "PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA", "NAME" => "Предполагаемый тип премирования (привязка)", "EDITABLE" => true],
    ["CODE" => "OKLAD", "NAME" => "Оклад", "EDITABLE" => true],
    ["CODE" => "PROTSENT_PREMII_", "NAME" => "Процент премии", "EDITABLE" => true],
    ["CODE" => "ISN_RUB_GROSS", "NAME" => "ИСН (руб., gross)", "EDITABLE" => true],
    ["CODE" => "DOKHOD_V_MESYATS_V_SREDNEM_PRI_VYPOLNENII_KPI_RUB_", "NAME" => "Доход в месяц в среднем при выполнении KPI (руб.)", "EDITABLE" => true],
    ["CODE" => "DOKHOD_V_MESYATS_V_SREDNEM_RUB_POSLE_VYCHETA_NDFL", "NAME" => "Доход в месяц в среднем (руб.) после вычета НДФЛ", "EDITABLE" => true],

    ["CODE" => "TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA", "NAME" => "Тип договора с сотрудником (привязка)", "EDITABLE" => true],
    ["CODE" => "OFIS_PRIVYAZKA", "NAME" => "Офис (привязка)", "EDITABLE" => true],
    ["CODE" => "GRAFIK_RABOTY_PRIVYAZKA", "NAME" => "График работы (привязка)", "EDITABLE" => true],
    ["CODE" => "FORMAT_RABOTY_PRIVYAZKA", "NAME" => "Формат работы (привязка)", "EDITABLE" => true],
    ["CODE" => "NACHALO_RABOCHEGO_DNYA_PRIVYAZKA", "NAME" => "Начало рабочего дня (привязка)", "EDITABLE" => false],
    ["CODE" => "OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA", "NAME" => "Оборудование для работы (привязка)", "EDITABLE" => true],
    ["CODE" => "OBORUDOVANIE_DLYA_RABOTY_TEKST", "NAME" => "Доп. требования к оборудованию", "EDITABLE" => true],
    ["CODE" => "NEOBKHODIMAYA_MEBEL", "NAME" => "Необходимая мебель", "EDITABLE" => true],
    ["CODE" => "KOMANDIROVKI_TEKST", "NAME" => "Командировки", "EDITABLE" => true],
    ["CODE" => "KOMANDIROVKI_PRODOLZHITELNOST", "NAME" => "Командировки (продолжительность)", "EDITABLE" => true],

    ["CODE" => "PRICHINA_OTKRYTIYA_VAKANSII_TEKST", "NAME" => "Причина открытия вакансии", "EDITABLE" => true],
    ["CODE" => "PRICHINA_ZAYAVKI_NA_PODBOR", "NAME" => "Причина заявки на подбор", "EDITABLE" => true],
    ["CODE" => "DATA_DEKRETA", "NAME" => "Дата декрета", "EDITABLE" => true],
    ["CODE" => "DATA_UVOLNENIYA", "NAME" => "Дата увольнения", "EDITABLE" => true],
    ["CODE" => "DATA_PEREVODA", "NAME" => "Дата перевода", "EDITABLE" => true],
    ["CODE" => "EST_LI_VNUTRENNIY_KANDIDAT_NA_DANNUYU_DOLZHNOST", "NAME" => "Есть ли внутренний кандидат на данную должность", "EDITABLE" => true],
    ["CODE" => "OTDELY_DLYA_POISKA_VNUTRENNIKH_KANDIDATOV", "NAME" => "Отделы для поиска внутренних кандидатов", "EDITABLE" => true],
    ["CODE" => "PRIZNAK_PO_DOLZHNOSTI_TEKST", "NAME" => "Признак по должности", "EDITABLE" => true],
    ["CODE" => "RUKOVODYASHCHAYA_DOLZHNOST", "NAME" => "Руководящая должность", "EDITABLE" => true],
    ["CODE" => "STAVKA", "NAME" => "Ставка", "EDITABLE" => true],
    ["CODE" => "KOMMENTARII_C_B", "NAME" => "Комментарии C&B", "EDITABLE" => true],
    ["CODE" => "KONFIDENTSIALNYY_POISK", "NAME" => "Конфиденциальный поиск", "EDITABLE" => false],
    ["CODE" => "REKRUTER", "NAME" => "Рекрутер", "EDITABLE" => false],
    ["CODE" => "STATUS_ZAYAVKI", "NAME" => "Статус заявки", "EDITABLE" => false],
    ["CODE" => "KOMMENTARII_K_ZAYAVKE", "NAME" => "Комментарий к заявке", "EDITABLE" => false],
    ["CODE" => "KOMMENTARII", "NAME" => "История", "EDITABLE" => false],
];

$RECRUITER_ALLOWED_CODES = [
    'GRAFIK_RABOTY_PRIVYAZKA',
    'FORMAT_RABOTY_PRIVYAZKA',
    'OFIS_PRIVYAZKA',
    'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA',
    'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA',
    'OBORUDOVANIE_DLYA_RABOTY_TEKST',
    'NEOBKHODIMAYA_MEBEL',
];

/**
 * Карта справочников (селекты по названиям)
 */
$REFERENCE_IBLOCK_BY_CODE = [
    'YURIDICHESKOE_LITSO' => IBLOCK_LEGAL,
    'PODRAZDELENIE_0_UROVNYA' => IBLOCK_DEP0,

    'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA' => IBLOCK_CONTRACT,
    'OFIS_PRIVYAZKA' => IBLOCK_OFFICE,
    'GRAFIK_RABOTY_PRIVYAZKA' => IBLOCK_SCHEDULE,
    'FORMAT_RABOTY_PRIVYAZKA' => IBLOCK_FORMAT,
    'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA' => IBLOCK_STARTTIME,
    'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA' => IBLOCK_EQUIPMENT,
    'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA' => IBLOCK_BONUSTYPE, // если нужен справочник — добавьте IBLOCK_ID
];

/* =========================================================
 * 2) HELPERS
 * =======================================================*/
function normPropValue($v) {
    if (is_array($v) && array_key_exists('VALUE', $v)) return $v['VALUE'];
    return $v;
}
function appendHistory($old, $add) {
    $old = trim((string)$old);
    if ($old === '') return trim($add);
    return $old . "\n\n" . trim($add);
}
function getPropMetaMap($iblockId) {
    $map = [];
    $rs = CIBlockProperty::GetList(['SORT'=>'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
    while ($p = $rs->Fetch()) $map[$p['CODE']] = $p;
    return $map;
}
function getEnumsByPropId($propId) {
    $res = [];
    $rs = CIBlockPropertyEnum::GetList(['SORT'=>'ASC','VALUE'=>'ASC'], ['PROPERTY_ID' => $propId]);
    while ($e = $rs->Fetch()) $res[] = $e;
    return $res;
}
function getElementNameById($iblockId, $id) {
    $id = (int)$id; $iblockId = (int)$iblockId;
    if ($id <= 0 || $iblockId <= 0) return '';
    $row = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $id], false, false, ['ID','NAME'])->Fetch();
    return $row ? (string)$row['NAME'] : '';
}
function getIblockOptionsCached($iblockId) {
    static $cache = [];
    $iblockId = (int)$iblockId;
    if ($iblockId <= 0) return [];
    if (isset($cache[$iblockId])) return $cache[$iblockId];

    $res = [];
    $rs = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID','NAME']
    );
    while ($row = $rs->GetNext()) $res[] = $row;
    $cache[$iblockId] = $res;
    return $res;
}
function labelWithoutPrivyazka($name) {
    return trim(preg_replace('/\s*\(привязка\)\s*/ui', '', (string)$name));
}
function getGroupByCode($code, $groupMap) {
    return $groupMap[$code] ?? 'Подбор';
}
function getGlobalVarUserList($varId) {
    $users = [];
    try {
        $conn = \Bitrix\Main\Application::getConnection();
        $sqlVarId = $conn->getSqlHelper()->forSql((string)$varId);
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
function parseMoneyInput($value) {
    $v = trim((string)$value);
    if ($v === '') return 0.0;
    $v = str_replace(["\xc2\xa0", ' '], '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : 0.0;
}
function calcMonthlyNetByProgressiveNdfl($grossMonthly) {
    $grossMonthly = max(0.0, (float)$grossMonthly);
    if ($grossMonthly <= 0) return 0.0;

    // Прогрессивная шкала НДФЛ c 01.01.2026 (в рамках задачи: 13%..20%)
    $annual = $grossMonthly * 12;
    $brackets = [
        ['limit' => 2400000.0, 'rate' => 0.13],
        ['limit' => 5000000.0, 'rate' => 0.15],
        ['limit' => 20000000.0, 'rate' => 0.18],
        ['limit' => INF, 'rate' => 0.20],
    ];

    $tax = 0.0;
    $prevLimit = 0.0;
    foreach ($brackets as $b) {
        if ($annual <= $prevLimit) break;
        $sliceUpper = min($annual, (float)$b['limit']);
        $slice = max(0.0, $sliceUpper - $prevLimit);
        $tax += $slice * (float)$b['rate'];
        $prevLimit = (float)$b['limit'];
    }

    return ($annual - $tax) / 12;
}
function calcMonthlyIncomeFields($bonusTypeName, $salaryGross, $bonusPercent, $isnGross) {
    $bonusTypeName = mb_strtolower(trim((string)$bonusTypeName));
    $salaryGross = max(0.0, (float)$salaryGross);
    $bonusPercent = max(0.0, (float)$bonusPercent);
    $isnGross = max(0.0, (float)$isnGross);

    $kpiGross = null;
    $netGross = null;

    if (mb_strpos($bonusTypeName, 'ежемесяч') !== false) {
        $kpiGross = $salaryGross + $isnGross + ($salaryGross * $bonusPercent / 100);
    } elseif (mb_strpos($bonusTypeName, 'ежекварт') !== false) {
        $kpiGross = $salaryGross + $isnGross + (($salaryGross * $bonusPercent / 100) / 3);
    } elseif (mb_strpos($bonusTypeName, 'без прем') !== false) {
        $netGross = $salaryGross + $isnGross;
    }

    return [
        'kpi' => ($kpiGross === null) ? '' : (string)round(calcMonthlyNetByProgressiveNdfl($kpiGross)),
        'net' => ($netGross === null) ? '' : (string)round(calcMonthlyNetByProgressiveNdfl($netGross)),
    ];
}
function splitResponsibilitiesToItems($text) {
    $text = (string)$text;
    if (trim($text) === '') return [];
    $parts = preg_split('/[\r\n;]+/u', $text) ?: [];
    $items = [];
    foreach ($parts as $p) {
        $item = trim((string)$p);
        $item = trim($item, " \t\n\r\0\x0B-•.");
        if ($item === '') continue;
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $item));
        $items[$key] = $item;
    }
    return $items;
}
function buildResponsibilitiesDiffText($managerText, $from1cText) {
    $manager = splitResponsibilitiesToItems($managerText);
    $from1c = splitResponsibilitiesToItems($from1cText);

    if ($manager === $from1c) return '';

    $added = [];
    foreach ($manager as $k => $v) {
        if (!array_key_exists($k, $from1c)) $added[] = $v;
    }

    $removed = [];
    foreach ($from1c as $k => $v) {
        if (!array_key_exists($k, $manager)) $removed[] = $v;
    }

    if (empty($added) && empty($removed)) return '';

    $out = [];
    $out[] = 'Добавлено:';
    if ($added) {
        foreach ($added as $item) $out[] = '- ' . rtrim($item, ';') . ';';
    } else {
        $out[] = '- нет;';
    }
    $out[] = 'Удалено:';
    if ($removed) {
        foreach ($removed as $item) $out[] = '- ' . rtrim($item, ';') . ';';
    } else {
        $out[] = '- нет;';
    }

    return implode("\n", $out);
}

/* =========================================================
 * 3) INPUT + LOAD
 * =======================================================*/
$request = Context::getCurrent()->getRequest();
$elementId = (int)($request->getQuery('ID') ?: $request->getQuery('id'));
if ($elementId <= 0) $elementId = (int)($request->get('ID') ?: $request->get('id'));

if ($elementId <= 0) {
    ShowError('Не указан ID заявки');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}
if (!$USER->IsAuthorized()) {
    ShowError('Требуется авторизация');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}


// Проверка прав на чтение элемента
$canRead = CIBlockElement::GetList(
    [],
    [
        'IBLOCK_ID' => IBLOCK_RECRUIT,
        'ID' => $elementId,
        'CHECK_PERMISSIONS' => 'Y',
        'MIN_PERMISSION' => 'R',
    ],
    false,
    false,
    ['ID']
)->Fetch();

if (!$canRead) {
    ShowError('У вас нет прав на просмотр этой заявки');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}




$el = CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => IBLOCK_RECRUIT, 'ID' => $elementId],
    false,
    false,
    ['ID','IBLOCK_ID','NAME','DATE_CREATE','CREATED_BY']
)->GetNextElement();

if (!$el) {
    ShowError('Заявка не найдена (ИБ '.IBLOCK_RECRUIT.', ID '.$elementId.')');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}

$elementFields = $el->GetFields();
$metaMap = getPropMetaMap(IBLOCK_RECRUIT);

$codes = [];
foreach ($FIELDS as $f) $codes[] = (string)$f['CODE'];
$codes = array_values(array_unique($codes));

$props = [];
CIBlockElement::GetPropertyValuesArray($props, IBLOCK_RECRUIT, ['ID' => $elementId], ['CODE' => $codes]);
$curProps = $props[$elementId] ?? [];

/* =========================================================
 * 3A) ACCESS CHECK (C&B / recruiter / recruiting heads / admins)
 * =======================================================*/
$currentUserId = (int)$USER->GetID();
$currentUserTag = 'user_' . $currentUserId;
$currentUserTagLower = mb_strtolower($currentUserTag);

$isAdmin = $USER->IsAdmin();
$cbUsers = getGlobalVarUserList(CB_GLOBAL_VAR_ID);
$recruitHeads = getGlobalVarUserList(RECRUIT_HEAD_GLOBAL_VAR_ID);

$isCbManager = in_array($currentUserTagLower, $cbUsers, true);
$isRecruitHead = in_array($currentUserTagLower, $recruitHeads, true);

$recruiterRaw = normPropValue($curProps['REKRUTER'] ?? '');
$recruiterRaw = trim((string)$recruiterRaw);
$isRecruiter = false;
if ($recruiterRaw !== '') {
    $recruiterVariants = [
        mb_strtolower($recruiterRaw),
        mb_strtolower('user_' . (int)$recruiterRaw),
    ];
    $isRecruiter = in_array($currentUserTagLower, $recruiterVariants, true) || ((int)$recruiterRaw > 0 && (int)$recruiterRaw === $currentUserId);
}

$actorType = null;
$roleLabel = '';
if ($isAdmin || $isCbManager) {
    $actorType = 'cb';
    $roleLabel = ROLE_LABEL_CB;
} elseif ($isRecruiter || $isRecruitHead) {
    $actorType = 'recruiter';
    $roleLabel = ROLE_LABEL_RECRUITER;
}

if ($actorType === null) {
    ShowError('У вас нет прав на запуск этого скрипта');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    die();
}

foreach ($FIELDS as &$fieldItem) {
    if ($actorType === 'cb') {
        continue;
    }
    $fieldItem['EDITABLE'] = in_array((string)$fieldItem['CODE'], $RECRUITER_ALLOWED_CODES, true);
}
unset($fieldItem);

/* =========================================================
 * 4) VIEW MODE
 * =======================================================*/
foreach ($FIELDS as &$fieldItem) {
    $fieldItem['EDITABLE'] = false;
}
unset($fieldItem);

/* =========================================================
 * 5) RENDER
 * =======================================================*/
function renderSectionStart($title, $bg, $border) {
    $titleEsc = htmlspecialcharsbx((string)$title);
    $bg = htmlspecialcharsbx((string)$bg);
    $border = htmlspecialcharsbx((string)$border);

    return '
      <div class="req-group" style="background: '.$bg.'; border: 1px solid '.$border.';">
        <div class="req-group__head">
          <div class="req-group__title">'.$titleEsc.'</div>
        </div>
        <div class="req-group__body">
    ';
}
function renderSectionEnd() {
    return '</div></div>';
}

function renderSelectByIblock($code, $label, $selectedId, $iblockId, $editable) {
    $codeEsc = htmlspecialcharsbx($code);
    $labelEsc = htmlspecialcharsbx($label);
    $readonlyAttr = $editable ? '' : 'disabled';
    $requiredAttr = '';
    $requiredMark = '';

    $options = getIblockOptionsCached((int)$iblockId);
    $selectedId = (int)$selectedId;

    $optionsHtml = '<option value="0">— выберите —</option>';
    foreach ($options as $o) {
        $id = (int)$o['ID'];
        $sel = ($selectedId === $id) ? 'selected' : '';
        $name = (string)$o['NAME'];
        $optionsHtml .= '<option value="'.$id.'" '.$sel.' data-option-name="'.htmlspecialcharsbx(mb_strtolower($name)).'">'.htmlspecialcharsbx($name).'</option>';
    }

    return '
    <div class="ui-form-row">
      <div class="ui-form-label"><div class="ui-ctl-label-text">'.$labelEsc.$requiredMark.'</div></div>
      <div class="ui-form-content">
        <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
          <div class="ui-ctl-after ui-ctl-icon-angle"></div>
          <select class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" '.$readonlyAttr.' '.$requiredAttr.'>
            '.$optionsHtml.'
          </select>
        </div>
      </div>
    </div>';
}

function renderInput($code, $name, $editable, $meta, $value, $referenceMap) {
    $codeEsc = htmlspecialcharsbx($code);
    $nameEsc = htmlspecialcharsbx($name);
    $readonlyAttr = $editable ? '' : 'disabled';
    $rowIdAttr = ' id="row_'.$codeEsc.'"';
    $labelNoteHtml = '';
    $labelAfterTitleHtml = '';
    $requiredAttr = '';
    $requiredMark = '';

    if ($code === 'DOKHOD_V_MESYATS_V_SREDNEM_PRI_VYPOLNENII_KPI_RUB_') {
        $labelNoteHtml = '<div class="req-ndfl-note" id="ndfl_rate_kpi"></div>';
    }
    if ($code === 'DOKHOD_V_MESYATS_V_SREDNEM_RUB_POSLE_VYCHETA_NDFL') {
        $labelNoteHtml = '<div class="req-ndfl-note" id="ndfl_rate_net"></div>';
    }
    if ($code === 'OBYAZANNOSTI') {
        global $curProps;
        $managerText = (string)normPropValue($curProps['OBYAZANNOSTI'] ?? '');
        $from1cText = (string)normPropValue($curProps['DOLZHNOSTNYE_OBYAZANNOSTI_1C'] ?? '');
        $hasDiff = (buildResponsibilitiesDiffText($managerText, $from1cText) !== '');
        $labelAfterTitleHtml = '<span class="req-manager-edited-note" id="manager_edited_note"'.($hasDiff ? '' : ' style="display:none;"').'> (обязанности отредактированы руководителем)</span>';
    }

    if ($code === 'NEPOSREDSTVENNYY_RUKOVODITEL') {
        ob_start();
        echo '<div class="ui-form-row"'.$rowIdAttr.'>';
        echo '<div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.$labelNoteHtml.'</div></div>';
        echo '<div class="ui-form-content">';
        if ($editable) {
            global $APPLICATION;
            $val = (int)normPropValue($value);
            $APPLICATION->IncludeComponent(
                'bitrix:intranet.user.selector',
                '',
                [
                    'INPUT_NAME'          => 'employee_id',
                    'INPUT_NAME_STRING'   => 'employee_name',
                    'INPUT_VALUE'         => $val > 0 ? [$val] : [],
                    'MULTIPLE'            => 'N',
                    'NAME_TEMPLATE'       => '#LAST_NAME# #NAME# #SECOND_NAME#',
                    'SHOW_EXTRANET_USERS' => 'NONE',
                    'EXTERNAL'            => 'A',
                    'POPUP'               => 'Y',
                ]
            );
        } else {
            echo '<div class="ui-ctl ui-ctl-textbox ui-ctl-w100"><input type="text" class="ui-ctl-element" value="'.htmlspecialcharsbx((string)normPropValue($value)).'" disabled></div>';
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    if ($code === 'RUKOVODYASHCHAYA_DOLZHNOST') {
        $val = mb_strtoupper(trim((string)normPropValue($value)));
        $selectedY = ($val === 'Y') ? 'selected' : '';
        $selectedN = ($val === 'N') ? 'selected' : '';
        return '
        <div class="ui-form-row"'.$rowIdAttr.'>
          <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.$requiredMark.$labelAfterTitleHtml.$labelNoteHtml.'</div></div>
          <div class="ui-form-content">
            <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
              <div class="ui-ctl-after ui-ctl-icon-angle"></div>
              <select class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" '.$readonlyAttr.' '.$requiredAttr.'>
                <option value="">— выберите —</option>
                <option value="Y" '.$selectedY.'>Да</option>
                <option value="N" '.$selectedN.'>Нет</option>
              </select>
            </div>
          </div>
        </div>';
    }

    if ($code === 'STAVKA') {
        $val = (string)normPropValue($value);
        $numericVal = (float)str_replace(',', '.', $val);
        if ($numericVal <= 0) $numericVal = 1.0;
        $options = '';
        for ($i = 1; $i <= 10; $i++) {
            $optionVal = number_format($i / 10, 1, '.', '');
            $selected = ((float)$optionVal === (float)$numericVal) ? 'selected' : '';
            $options .= '<option value="'.$optionVal.'" '.$selected.'>'.$optionVal.'</option>';
        }

        return '
        <div class="ui-form-row"'.$rowIdAttr.'>
          <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.$requiredMark.'</div></div>
          <div class="ui-form-content">
            <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
              <div class="ui-ctl-after ui-ctl-icon-angle"></div>
              <select class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" '.$readonlyAttr.' '.$requiredAttr.'>
                '.$options.'
              </select>
            </div>
          </div>
        </div>';
    }

    if ($code === 'RAZNITSA_TEKSTOV') {
        global $curProps;
        $managerText = (string)normPropValue($curProps['OBYAZANNOSTI'] ?? '');
        $from1cText = (string)normPropValue($curProps['DOLZHNOSTNYE_OBYAZANNOSTI_1C'] ?? '');
        $diffText = buildResponsibilitiesDiffText($managerText, $from1cText);
        $displayStyle = ($diffText === '') ? ' style="display:none;"' : '';

        return '
        <div class="ui-form-row"'.$rowIdAttr.$displayStyle.'>
          <div class="ui-form-label"></div>
          <div class="ui-form-content">
            <details class="req-diff-details" id="diff_details" open>
              <summary>Разница текстов</summary>
              <div class="ui-ctl ui-ctl-textarea ui-ctl-w100" style="margin-top:8px;">
                <textarea class="ui-ctl-element" id="field_'.$codeEsc.'" rows="6" readonly>'.htmlspecialcharsbx($diffText).'</textarea>
              </div>
            </details>
          </div>
        </div>';
    }

    if (isset($referenceMap[$code])) {
        $label = labelWithoutPrivyazka($name);
        $selectedId = (int)normPropValue($value);
        return renderSelectByIblock($code, $label, $selectedId, (int)$referenceMap[$code], $editable);
    }

    if ($code === 'PRIZNAK_PO_DOLZHNOSTI_TEKST') {
        $val = trim((string)normPropValue($value));
        $isMass = ($val === 'Массовая должность') ? 'selected' : '';
        $isNonMass = ($val === 'Немассовая должность') ? 'selected' : '';

        return '
        <div class="ui-form-row"'.$rowIdAttr.'>
          <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.'</div></div>
          <div class="ui-form-content">
            <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
              <div class="ui-ctl-after ui-ctl-icon-angle"></div>
              <select class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" '.$readonlyAttr.'>
                <option value="Массовая должность" '.$isMass.'>Массовая должность</option>
                <option value="Немассовая должность" '.$isNonMass.'>Немассовая должность</option>
              </select>
            </div>
          </div>
        </div>';
    }

    $valStr = is_array($value) ? (string)($value['VALUE'] ?? '') : (string)normPropValue($value);
    $valEsc = htmlspecialcharsbx($valStr);

    // textarea?
    $isTextarea = in_array($code, ['OBYAZANNOSTI','DOLZHNOSTNYE_OBYAZANNOSTI_1C','OBORUDOVANIE_DLYA_RABOTY_TEKST','KOMMENTARII_C_B','DELOVYE_KACHESTVA','DOPOLNITELNYE_TREBOVANIYA','PRICHINA_ZAYAVKI_NA_PODBOR','KOMMENTARII'], true);

    if ($isTextarea) {
        $isResponsibilitiesTextarea = in_array($code, ['OBYAZANNOSTI', 'DOLZHNOSTNYE_OBYAZANNOSTI_1C'], true);
        $rows = $isResponsibilitiesTextarea ? 15 : 4;
        $textareaWrapStyleAttr = $isResponsibilitiesTextarea ? ' style="height: 320px;"' : '';
        $textareaStyleAttr = $isResponsibilitiesTextarea ? ' style="height: 320px !important; min-height: 320px;"' : '';
        return '
        <div class="ui-form-row"'.$rowIdAttr.'>
          <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.$requiredMark.$labelAfterTitleHtml.$labelNoteHtml.'</div></div>
          <div class="ui-form-content">
            <div class="ui-ctl ui-ctl-textarea ui-ctl-w100"'.$textareaWrapStyleAttr.'>
              <textarea class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" rows="'.$rows.'"'.$textareaStyleAttr.' '.$readonlyAttr.'>'.$valEsc.'</textarea>
            </div>
          </div>
        </div>';
    }

    $isCalculatedIncomeField = in_array($code, [
        'DOKHOD_V_MESYATS_V_SREDNEM_PRI_VYPOLNENII_KPI_RUB_',
        'DOKHOD_V_MESYATS_V_SREDNEM_RUB_POSLE_VYCHETA_NDFL',
    ], true);
    if ($isCalculatedIncomeField) {
        $readonlyAttr = 'readonly';
    }

    return '
    <div class="ui-form-row"'.$rowIdAttr.'>
      <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.$requiredMark.$labelAfterTitleHtml.$labelNoteHtml.'</div></div>
      <div class="ui-form-content">
        <div class="ui-ctl ui-ctl-textbox ui-ctl-w100">
          <input class="ui-ctl-element" id="field_'.$codeEsc.'" type="text" name="'.$codeEsc.'" value="'.$valEsc.'" '.$readonlyAttr.' '.$requiredAttr.'>
        </div>
      </div>
    </div>';
}

function hasDisplayValue($code, $value, $referenceMap, $curProps) {
    if ($code === 'RAZNITSA_TEKSTOV') {
        $managerText = (string)normPropValue($curProps['OBYAZANNOSTI'] ?? '');
        $from1cText = (string)normPropValue($curProps['DOLZHNOSTNYE_OBYAZANNOSTI_1C'] ?? '');
        return trim(buildResponsibilitiesDiffText($managerText, $from1cText)) !== '';
    }

    if (isset($referenceMap[$code])) {
        return ((int)normPropValue($value) > 0);
    }

    if ($code === 'NEPOSREDSTVENNYY_RUKOVODITEL') {
        $v = trim((string)normPropValue($value));
        return ($v !== '' && $v !== '0');
    }

    $v = trim((string)normPropValue($value));
    return $v !== '';
}

?>
<style>
  .req-group{
    border-radius: 14px;
    padding: 14px 14px 6px;
    margin: 16px 0;
    box-shadow: 0 1px 0 rgba(0,0,0,0.03);
  }
  .req-group__head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom: 8px;
  }
  .req-group__title{
    font-size: 16px;
    font-weight: 600;
  }
  .req-group__body .ui-form-row{
    margin-top: 8px;
  }
  .ui-ctl-element[disabled],
  .ui-ctl-element[readonly]{
    color:#000 !important;
    background:#fff !important;
    -webkit-text-fill-color:#000 !important;
    opacity:1 !important;
  }
  .req-ndfl-note{
    margin-top: 2px;
    font-size: 11px;
    line-height: 1.25;
    font-weight: 400;
    color:#6b7280;
  }
  .req-manager-edited-note{
    color:#8b5e00;
    font-size: 12px;
    font-weight: 500;
  }
  .req-diff-details summary{
    cursor: pointer;
    color:#2067b0;
    font-weight: 600;
  }
  .req-modal{
    display:none;
    position:fixed;
    inset:0;
    z-index:2000;
  }
  .req-modal--open{ display:block; }
  .req-modal__backdrop{
    position:absolute;
    inset:0;
    background:rgba(17,24,39,.45);
  }
  .req-modal__dialog{
    position:relative;
    max-width:800px;
    margin:6vh auto;
    background:#fff;
    border-radius:10px;
    box-shadow:0 20px 45px rgba(0,0,0,.2);
    overflow:hidden;
  }
  .req-modal__head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:14px 16px;
    border-bottom:1px solid #edf0f4;
    font-weight:600;
  }
  .req-modal__close{
    border:0;
    background:transparent;
    font-size:24px;
    line-height:1;
    color:#6b7280;
    cursor:pointer;
  }
  .req-modal__body{
    padding:16px;
    max-height:70vh;
    overflow:auto;
  }
</style>

<div class="ui-alert ui-alert-primary">
  <span class="ui-alert-message">
    Просмотр заявки #<?= (int)$elementId ?> — <?= htmlspecialcharsbx($elementFields['NAME']) ?>
  </span>
</div>

<div class="ui-form" style="max-width: 1100px;">
  <?php
  $byGroup = [];
  foreach ($FIELDS as $f) {
      $code = (string)$f['CODE'];
      $g = getGroupByCode($code, $GROUP_MAP);
      if (!isset($byGroup[$g])) $byGroup[$g] = [];
      $byGroup[$g][] = $f;
  }

  foreach ($GROUP_ORDER as $groupTitle) {
      if (empty($byGroup[$groupTitle])) continue;

      $style = $GROUP_STYLES[$groupTitle] ?? ['BG' => '#FFFFFF', 'BORDER' => '#E5E5E5'];
      echo renderSectionStart($groupTitle, $style['BG'], $style['BORDER']);

      foreach ($byGroup[$groupTitle] as $f) {
          $code = (string)$f['CODE'];
          $meta = $metaMap[$code] ?? null;
          $val  = $curProps[$code] ?? '';
          if (!hasDisplayValue($code, $val, $REFERENCE_IBLOCK_BY_CODE, $curProps)) {
              continue;
          }
          echo renderInput($code, (string)$f['NAME'], false, $meta, $val, $REFERENCE_IBLOCK_BY_CODE);
      }

      echo renderSectionEnd();
  }
  ?>

  <div class="ui-form-row" style="margin-top:18px;">
    <div class="ui-form-label"></div>
    <div class="ui-form-content">
      <a href="/forms/staff_recruitment/list.php" class="ui-btn ui-btn-light-border">Вернуться к заявкам</a>
    </div>
  </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>
