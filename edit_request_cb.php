<?php
/**
 * /forms/staff_recruitment/edit_request_cb.php
 *
 * Редактирование заявки на подбор (ИБ 201) — роль: Менеджер C&B
 * Версия: v1.0.4 (2026-02-04)
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
const BP_TEMPLATE_ID_EDIT_NOTIFY = 1294;
const ROLE_LABEL = 'Менеджер C&B';

// Справочники (как в create_request.php)
const IBLOCK_LEGAL      = 308;
const IBLOCK_DEP0       = 358;
const IBLOCK_SCHEDULE   = 236;
const IBLOCK_STARTTIME  = 237;
const IBLOCK_FORMAT     = 234;
const IBLOCK_OFFICE     = 233;
const IBLOCK_CONTRACT   = 325;
const IBLOCK_EQUIPMENT  = 326;
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


/* =========================================================
 * 3A) ACCESS CHECK (C&B Managers or Admins only)
 * =======================================================*/
$isAdmin = $USER->IsAdmin();
$isCbManager = false;

try {
    $conn = \Bitrix\Main\Application::getConnection();
    
    $row = $conn->query("
        SELECT PROPERTY_VALUE
        FROM b_bp_global_var
        WHERE ID = 'Variable1722502594854'
        LIMIT 1
    ")->fetch();

    if ($row && !empty($row['PROPERTY_VALUE'])) {
        // Десериализуем данные
        $cbUsers = @unserialize($row['PROPERTY_VALUE'], ['allowed_classes' => false]);
        
        if (is_array($cbUsers)) {
            $currentUserId = 'user_' . $USER->GetID();
            
            // Отладка
            echo "<pre>";
            echo "Current user ID: $currentUserId\n";
            echo "CB Users list:\n";
            print_r($cbUsers);
            echo "In array result: " . (in_array($currentUserId, $cbUsers, true) ? 'YES' : 'NO') . "\n";
            echo "</pre>";
            
            $isCbManager = in_array($currentUserId, $cbUsers, true);
        }
    }
} catch (\Throwable $e) {
    $isCbManager = false;
}

if (!$isAdmin && !$isCbManager) {
    ShowError('У вас нет прав на запуск этого скрипта');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    die();
}




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

    // Условия работы
    'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA' => 'Условия работы',
    'OFIS_PRIVYAZKA' => 'Условия работы',
    'GRAFIK_RABOTY_PRIVYAZKA' => 'Условия работы',
    'FORMAT_RABOTY_PRIVYAZKA' => 'Условия работы',
    'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA' => 'Условия работы',
    'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA' => 'Условия работы',
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
    'KONFIDENTSIALNYY_POISK_LST' => 'Подбор',
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
    ["CODE" => "OBYAZANNOSTI", "NAME" => "Обязанности", "EDITABLE" => true],
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
    ["CODE" => "UROVEN_DOKHODA_MEDIANA", "NAME" => "Уровень дохода (медиана)", "EDITABLE" => true],
    ["CODE" => "VAKANSIYA_PODTVERZHDENA_C_B", "NAME" => "Вакансия подтверждена C&B", "EDITABLE" => true],

    ["CODE" => "TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA", "NAME" => "Тип договора с сотрудником (привязка)", "EDITABLE" => true],
    ["CODE" => "OFIS_PRIVYAZKA", "NAME" => "Офис (привязка)", "EDITABLE" => true],
    ["CODE" => "GRAFIK_RABOTY_PRIVYAZKA", "NAME" => "График работы (привязка)", "EDITABLE" => true],
    ["CODE" => "FORMAT_RABOTY_PRIVYAZKA", "NAME" => "Формат работы (привязка)", "EDITABLE" => true],
    ["CODE" => "NACHALO_RABOCHEGO_DNYA_PRIVYAZKA", "NAME" => "Начало рабочего дня (привязка)", "EDITABLE" => false],
    ["CODE" => "OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA", "NAME" => "Оборудование для работы (привязка)", "EDITABLE" => false],
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
    ["CODE" => "KONFIDENTSIALNYY_POISK_LST", "NAME" => "Конфиденциальный поиск", "EDITABLE" => false],
    ["CODE" => "REKRUTER", "NAME" => "Рекрутер", "EDITABLE" => false],
    ["CODE" => "STATUS_ZAYAVKI", "NAME" => "Статус заявки", "EDITABLE" => false],
    ["CODE" => "KOMMENTARII_K_ZAYAVKE", "NAME" => "Комментарий к заявке", "EDITABLE" => false],
    ["CODE" => "KOMMENTARII", "NAME" => "История", "EDITABLE" => false],
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

    $kpiGross = 0.0;
    $netGross = 0.0;

    if (mb_strpos($bonusTypeName, 'ежемесяч') !== false) {
        $kpiGross = $salaryGross + $isnGross + ($salaryGross * $bonusPercent / 100);
    } elseif (mb_strpos($bonusTypeName, 'ежекварт') !== false) {
        $kpiGross = $salaryGross + $isnGross + (($salaryGross * $bonusPercent / 100) / 3);
    } elseif (mb_strpos($bonusTypeName, 'без прем') !== false) {
        $netGross = $salaryGross + $isnGross;
    }

    return [
        'kpi' => (string)round(calcMonthlyNetByProgressiveNdfl($kpiGross)),
        'net' => (string)round(calcMonthlyNetByProgressiveNdfl($netGross)),
    ];
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
$codes[] = 'JSON';
$codes = array_values(array_unique($codes));

$props = [];
CIBlockElement::GetPropertyValuesArray($props, IBLOCK_RECRUIT, ['ID' => $elementId], ['CODE' => $codes]);
$curProps = $props[$elementId] ?? [];

/* =========================================================
 * 4) SAVE (оставляем как в v1.0.3, без изменений по визуалу)
 * =======================================================*/
$errors = [];
$success = false;

if ($request->isPost() && check_bitrix_sessid()) {
    $post = $request->getPostList()->toArray();

    $updates = [];
    $historyChanged = [];
    $jsonChanged = [];

    if (isset($post['employee_id']) && is_array($post['employee_id'])) {
        $post['employee_id'] = array_values(array_filter(array_map('intval', $post['employee_id'])));
    }

    $bonusTypeId = (int)($post['PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA'] ?? normPropValue($curProps['PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA'] ?? 0));
    $bonusTypeName = getElementNameById(IBLOCK_BONUSTYPE, $bonusTypeId);
    $salaryGross = parseMoneyInput($post['OKLAD'] ?? normPropValue($curProps['OKLAD'] ?? ''));
    $bonusPercent = parseMoneyInput($post['PROTSENT_PREMII_'] ?? normPropValue($curProps['PROTSENT_PREMII_'] ?? ''));
    $isnGross = parseMoneyInput($post['ISN_RUB_GROSS'] ?? normPropValue($curProps['ISN_RUB_GROSS'] ?? ''));

    $calculatedIncome = calcMonthlyIncomeFields($bonusTypeName, $salaryGross, $bonusPercent, $isnGross);
    $post['DOKHOD_V_MESYATS_V_SREDNEM_PRI_VYPOLNENII_KPI_RUB_'] = $calculatedIncome['kpi'];
    $post['DOKHOD_V_MESYATS_V_SREDNEM_RUB_POSLE_VYCHETA_NDFL'] = $calculatedIncome['net'];

    foreach ($FIELDS as $f) {
        if (empty($f['EDITABLE'])) continue;

        $code = (string)$f['CODE'];
        $meta = $metaMap[$code] ?? null;

        // Руководитель
        if ($code === 'NEPOSREDSTVENNYY_RUKOVODITEL') {
            $newVal = 0;
            if (!empty($post['employee_id'][0])) $newVal = (int)$post['employee_id'][0];
            $oldVal = (int)normPropValue($curProps[$code] ?? 0);

            if ($newVal > 0 && $newVal !== $oldVal) {
                $updates[$code] = $newVal;
                $historyChanged[] = $f['NAME'] . ': ' . $oldVal . ' → ' . $newVal;
                $jsonChanged[$code] = $newVal;
            }
            continue;
        }

        if ($code === 'RUKOVODYASHCHAYA_DOLZHNOST') {
            $newVal = mb_strtoupper(trim((string)($post[$code] ?? '')));
            if (!in_array($newVal, ['Y', 'N'], true)) $newVal = '';
            $oldVal = mb_strtoupper(trim((string)normPropValue($curProps[$code] ?? '')));

            if ($newVal !== $oldVal) {
                $updates[$code] = $newVal;
                $historyChanged[] = $f['NAME'] . ': ' . $oldVal . ' → ' . $newVal;
                $jsonChanged[$code] = $newVal;
            }
            continue;
        }

        // Справочник по карте
        if (isset($REFERENCE_IBLOCK_BY_CODE[$code])) {
            $ib = (int)$REFERENCE_IBLOCK_BY_CODE[$code];
            $newId = (int)($post[$code] ?? 0);
            $oldId = (int)normPropValue($curProps[$code] ?? 0);

            if ($newId !== $oldId) {
                $updates[$code] = $newId;
                $jsonChanged[$code] = $newId;

                $oldName = $ib ? getElementNameById($ib, $oldId) : (string)$oldId;
                $newName = $ib ? getElementNameById($ib, $newId) : (string)$newId;

                $historyChanged[] = labelWithoutPrivyazka($f['NAME']) . ': ' . $oldName . ' → ' . $newName;
            }
            continue;
        }

        // L
        if ($meta && $meta['PROPERTY_TYPE'] === 'L') {
            $newEnumId = (int)($post[$code] ?? 0);
            $oldEnumId = (int)($curProps[$code]['VALUE_ENUM_ID'] ?? 0);
            if ($newEnumId !== $oldEnumId) {
                $updates[$code] = $newEnumId;
                $jsonChanged[$code] = $newEnumId;
                $historyChanged[] = $f['NAME'] . ': ' . (string)($curProps[$code]['VALUE'] ?? '') . ' → ' . $newEnumId;
            }
            continue;
        }

        // N
        if ($meta && $meta['PROPERTY_TYPE'] === 'N') {
            $newVal = trim((string)($post[$code] ?? ''));
            $oldVal = (string)normPropValue($curProps[$code] ?? '');
            if ($newVal !== $oldVal) {
                $updates[$code] = $newVal;
                $historyChanged[] = $f['NAME'] . ': ' . $oldVal . ' → ' . $newVal;
                $jsonChanged[$code] = $newVal;
            }
            continue;
        }

        // S / прочее
        $newVal = trim((string)($post[$code] ?? ''));
        $oldVal = (string)normPropValue($curProps[$code] ?? '');
        if ($newVal !== $oldVal) {
            $updates[$code] = $newVal;
            $historyChanged[] = $f['NAME'] . ': ' . $oldVal . ' → ' . $newVal;
            $jsonChanged[$code] = $newVal;
        }
    }

    if (empty($updates)) {
        $errors[] = 'Нет изменений для сохранения.';
    } else {
        // История append
        $historyCode = 'KOMMENTARII';
        $historyOld = (string)normPropValue($curProps[$historyCode] ?? '');

        $who = $USER->GetFullName();
        if ($who === '') $who = 'ID ' . (int)$USER->GetID();

        $stamp = date('d.m.Y H:i');
        $historyBlock = "[{$stamp}] Изменения (" . ROLE_LABEL . ", {$who}):\n- " . implode("\n- ", $historyChanged);

        $updates[$historyCode] = appendHistory($historyOld, $historyBlock);
        $jsonChanged[$historyCode] = $updates[$historyCode];

        // JSON
        $jsonCode = 'JSON';
        $oldJson = (string)normPropValue($curProps[$jsonCode] ?? '');
        $jsonArr = [];
        if ($oldJson !== '') {
            $decoded = json_decode($oldJson, true);
            if (is_array($decoded)) $jsonArr = $decoded;
        }
        foreach ($jsonChanged as $k => $v) $jsonArr[$k] = $v;
        $updates[$jsonCode] = json_encode($jsonArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        CIBlockElement::SetPropertyValuesEx($elementId, IBLOCK_RECRUIT, $updates);

        // Запуск БП
        $documentId = ['iblock', 'CIBlockDocument', $elementId];
        $arErrorsTmp = [];
$bpParams = [
            'par_Changes' => (string)$historyBlock,
        ];
        CBPDocument::StartWorkflow(BP_TEMPLATE_ID_EDIT_NOTIFY, $documentId, $bpParams, $arErrorsTmp);
        if (!empty($arErrorsTmp)) {
            $errors[] = 'Заявка сохранена, но БП не запустился: ' . print_r($arErrorsTmp, true);
        }

        $success = true;

        // перечитать свойства
        $props = [];
        CIBlockElement::GetPropertyValuesArray($props, IBLOCK_RECRUIT, ['ID' => $elementId], ['CODE' => $codes]);
        $curProps = $props[$elementId] ?? [];
    }
}

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
      <div class="ui-form-label"><div class="ui-ctl-label-text">'.$labelEsc.'</div></div>
      <div class="ui-form-content">
        <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
          <div class="ui-ctl-after ui-ctl-icon-angle"></div>
          <select class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" '.$readonlyAttr.'>
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

    if ($code === 'NEPOSREDSTVENNYY_RUKOVODITEL') {
        ob_start();
        echo '<div class="ui-form-row">';
        echo '<div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.'</div></div>';
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
        <div class="ui-form-row">
          <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.'</div></div>
          <div class="ui-form-content">
            <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
              <div class="ui-ctl-after ui-ctl-icon-angle"></div>
              <select class="ui-ctl-element" id="field_'.$codeEsc.'" name="'.$codeEsc.'" '.$readonlyAttr.'>
                <option value="">— выберите —</option>
                <option value="Y" '.$selectedY.'>Да</option>
                <option value="N" '.$selectedN.'>Нет</option>
              </select>
            </div>
          </div>
        </div>';
    }

    if (isset($referenceMap[$code])) {
        $label = labelWithoutPrivyazka($name);
        $selectedId = (int)normPropValue($value);
        return renderSelectByIblock($code, $label, $selectedId, (int)$referenceMap[$code], $editable);
    }

    $valStr = is_array($value) ? (string)($value['VALUE'] ?? '') : (string)normPropValue($value);
    $valEsc = htmlspecialcharsbx($valStr);

    // textarea?
    $isTextarea = in_array($code, ['OBYAZANNOSTI','DELOVYE_KACHESTVA','DOPOLNITELNYE_TREBOVANIYA','PRICHINA_ZAYAVKI_NA_PODBOR','KOMMENTARII'], true);

    if ($isTextarea) {
        return '
        <div class="ui-form-row">
          <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.'</div></div>
          <div class="ui-form-content">
            <div class="ui-ctl ui-ctl-textarea ui-ctl-w100">
              <textarea class="ui-ctl-element" name="'.$codeEsc.'" rows="4" '.$readonlyAttr.'>'.$valEsc.'</textarea>
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
    <div class="ui-form-row">
      <div class="ui-form-label"><div class="ui-ctl-label-text">'.$nameEsc.'</div></div>
      <div class="ui-form-content">
        <div class="ui-ctl ui-ctl-textbox ui-ctl-w100">
          <input class="ui-ctl-element" id="field_'.$codeEsc.'" type="text" name="'.$codeEsc.'" value="'.$valEsc.'" '.$readonlyAttr.'>
        </div>
      </div>
    </div>';
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
</style>

<div class="ui-alert ui-alert-primary">
  <span class="ui-alert-message">
    Редактирование заявки #<?= (int)$elementId ?> — <?= htmlspecialcharsbx($elementFields['NAME']) ?>
  </span>
</div>

<?php if ($success): ?>
  <div class="ui-alert ui-alert-success"><span class="ui-alert-message">Изменения сохранены.</span></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="ui-alert ui-alert-danger">
    <span class="ui-alert-message"><?= nl2br(htmlspecialcharsbx(implode("\n", $errors))) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="ui-form" style="max-width: 1100px;">
  <?= bitrix_sessid_post(); ?>

  <?php
  // раскладываем по группам
  $byGroup = [];
  foreach ($FIELDS as $f) {
      $code = (string)$f['CODE'];
      $g = getGroupByCode($code, $GROUP_MAP);
      if (!isset($byGroup[$g])) $byGroup[$g] = [];
      $byGroup[$g][] = $f;
  }

  // выводим в нужном порядке и с фоном
  foreach ($GROUP_ORDER as $groupTitle) {
      if (empty($byGroup[$groupTitle])) continue;

      $style = $GROUP_STYLES[$groupTitle] ?? ['BG' => '#FFFFFF', 'BORDER' => '#E5E5E5'];
      echo renderSectionStart($groupTitle, $style['BG'], $style['BORDER']);

      foreach ($byGroup[$groupTitle] as $f) {
          $code = (string)$f['CODE'];
          $meta = $metaMap[$code] ?? null;
          $val  = $curProps[$code] ?? '';
          echo renderInput($code, (string)$f['NAME'], (bool)$f['EDITABLE'], $meta, $val, $REFERENCE_IBLOCK_BY_CODE);
      }

      echo renderSectionEnd();
  }
  ?>

  <div class="ui-form-row" style="margin-top:18px;">
    <div class="ui-form-label"></div>
    <div class="ui-form-content">
      <button type="submit" class="ui-btn ui-btn-success">Сохранить</button>
    </div>
  </div>
</form>

<script>
(function () {
  const bonusType = document.getElementById('field_PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA');
  const salary = document.getElementById('field_OKLAD');
  const bonusPercent = document.getElementById('field_PROTSENT_PREMII_');
  const isn = document.getElementById('field_ISN_RUB_GROSS');
  const kpiIncome = document.getElementById('field_DOKHOD_V_MESYATS_V_SREDNEM_PRI_VYPOLNENII_KPI_RUB_');
  const netIncome = document.getElementById('field_DOKHOD_V_MESYATS_V_SREDNEM_RUB_POSLE_VYCHETA_NDFL');

  if (!bonusType || !salary || !bonusPercent || !isn || !kpiIncome || !netIncome) return;

  const parseNum = (v) => {
    if (!v) return 0;
    const norm = String(v).replace(/\s/g, '').replace(',', '.');
    const n = Number(norm);
    return Number.isFinite(n) ? n : 0;
  };

  const monthlyNetByProgressiveNdfl = (grossMonthly) => {
    const gross = Math.max(0, Number(grossMonthly) || 0);
    if (!gross) return 0;
    const annual = gross * 12;
    const brackets = [
      { limit: 2400000, rate: 0.13 },
      { limit: 5000000, rate: 0.15 },
      { limit: 20000000, rate: 0.18 },
      { limit: Infinity, rate: 0.20 }
    ];

    let tax = 0;
    let prev = 0;
    for (const b of brackets) {
      if (annual <= prev) break;
      const upper = Math.min(annual, b.limit);
      const slice = Math.max(0, upper - prev);
      tax += slice * b.rate;
      prev = b.limit;
    }
    return (annual - tax) / 12;
  };

  const compute = () => {
    const typeName = (bonusType.options[bonusType.selectedIndex]?.dataset.optionName || '').toLowerCase();
    const s = parseNum(salary.value);
    const p = parseNum(bonusPercent.value);
    const i = parseNum(isn.value);

    let kpiGross = 0;
    let netGross = 0;

    if (typeName.includes('ежемесяч')) {
      kpiGross = s + i + (s * p / 100);
    } else if (typeName.includes('ежекварт')) {
      kpiGross = s + i + ((s * p / 100) / 3);
    } else if (typeName.includes('без прем')) {
      netGross = s + i;
    }

    kpiIncome.value = String(Math.round(monthlyNetByProgressiveNdfl(kpiGross)));
    netIncome.value = String(Math.round(monthlyNetByProgressiveNdfl(netGross)));
  };

  [bonusType, salary, bonusPercent, isn].forEach((el) => {
    el.addEventListener('change', compute);
    el.addEventListener('input', compute);
  });

  compute();
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>
