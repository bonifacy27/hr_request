<?php
/**
 * Просмотр анкеты сотрудника без возможности редактирования.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Просмотр анкеты сотрудника');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const VIEW_ADAPTATION_IBLOCK_ID = 196;
const VIEW_ADAPTATION_LIST_URL = '/forms/staff_recruitment/adaptation/list.php';

function viewH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function viewDecodeName($value): string
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function viewUserName($value): string
{
    $userId = (int)preg_replace('/\D+/', '', (string)$value);
    if ($userId <= 0) {
        return '';
    }

    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return 'Пользователь #' . $userId;
    }

    $name = trim(implode(' ', array_filter([
        (string)($user['LAST_NAME'] ?? ''),
        (string)($user['NAME'] ?? ''),
        (string)($user['SECOND_NAME'] ?? ''),
    ], static function ($part) {
        return trim($part) !== '';
    })));

    return $name !== '' ? $name : (string)($user['LOGIN'] ?? ('Пользователь #' . $userId));
}

function viewLinkedElementName($value): string
{
    $elementId = (int)$value;
    if ($elementId <= 0) {
        return '';
    }

    $element = CIBlockElement::GetList(
        [],
        ['ID' => $elementId, 'CHECK_PERMISSIONS' => 'Y', 'MIN_PERMISSION' => 'R'],
        false,
        ['nTopCount' => 1],
        ['ID', 'NAME']
    )->GetNext();

    return $element ? viewDecodeName($element['NAME']) : ('Элемент #' . $elementId);
}

$fields = [
    ['code' => 'FAMILIYA', 'label' => 'Фамилия', 'type' => 'S'],
    ['code' => 'IMYA', 'label' => 'Имя', 'type' => 'S'],
    ['code' => 'OTCHESTVO', 'label' => 'Отчество', 'type' => 'S'],
    ['code' => 'POL', 'label' => 'Пол', 'type' => 'L'],
    ['code' => 'ORGANIZATSIYA', 'label' => 'Организация', 'type' => 'E'],
    ['code' => 'DOLZHNOST', 'label' => 'Должность', 'type' => 'S'],
    ['code' => 'OTDEL', 'label' => 'Отдел', 'type' => 'S'],
    ['code' => 'DIREKTSIYA', 'label' => 'Дирекция', 'type' => 'S'],
    ['code' => 'RUKOVODITEL', 'label' => 'Руководитель', 'type' => 'USER'],
    ['code' => 'FIO_RUKOVODITELYA', 'label' => 'ФИО руководителя', 'type' => 'S'],
    ['code' => 'OTVETSTVENNYY_MENEDZHER_OPIA', 'label' => 'Рекрутер', 'type' => 'USER'],
    ['code' => 'DATA_PRIEMA', 'label' => 'Дата приема', 'type' => 'DATE'],
    ['code' => 'DATA_OKONCHANIYA_IS', 'label' => 'Дата окончания ИС', 'type' => 'DATE'],
    ['code' => 'FORMAT_RABOTY_', 'label' => 'Формат работы', 'type' => 'E'],
    ['code' => 'ADRES_OFISA_LST', 'label' => 'Офис', 'type' => 'E'],
    ['code' => 'NACHALO_RABOCHEGO_DNYA', 'label' => 'Начало рабочего дня', 'type' => 'E'],
    ['code' => 'KABINET_SPISOK', 'label' => 'Местоположение сотрудника', 'type' => 'E'],
    ['code' => 'NOMER_KABINETA', 'label' => 'Номер кабинета', 'type' => 'S'],
    ['code' => 'EST_LI_OBYAZATELSTVO_LST', 'label' => 'Есть обязательства', 'type' => 'L'],
    ['code' => 'SODERZHANIE_OBYAZATELSTV', 'label' => 'Содержимое обязательств', 'type' => 'TEXT'],
    ['code' => 'KONTAKTNYY_NOMER_TELEFONA', 'label' => 'Контактный номер телефона', 'type' => 'S'],
    ['code' => 'LICHNAYA_POCHTA_KANDIDATA', 'label' => 'Личная почта кандидата', 'type' => 'S'],
    ['code' => 'FOTO_SOTRUDNIKA', 'label' => 'Фото сотрудника', 'type' => 'FILE'],
    ['code' => 'EST_REKOMENDATSIYA', 'label' => 'Принят по рекомендации?', 'type' => 'VIRTUAL'],
    ['code' => 'REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET', 'label' => 'По чьей рекомендации принят сотрудник?', 'type' => 'TEXT'],
    ['code' => 'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI', 'label' => 'Основные обязанности (для новости)', 'type' => 'TEXT'],
    ['code' => 'FIO_V_DATELNOM_PADEZHE', 'label' => 'ФИО в дательном падеже', 'type' => 'S'],
    ['code' => 'FIO_V_RODITELNOM_PADEZHE', 'label' => 'ФИО в винительном падеже', 'type' => 'S'],
    ['code' => 'OBORUDOVANIE_DLYA_RABOTY', 'label' => 'Оборудование для работы', 'type' => 'E'],
    ['code' => 'RABOCHEE_MESTO', 'label' => 'Рабочее место', 'type' => 'L'],
    ['code' => 'DOSTUPY', 'label' => 'Доступы', 'type' => 'L'],
    ['code' => 'VDI_VERSIYA_OS_NA_LICHNOM_PK_NOUTBUKE', 'label' => 'VDI: версия ОС на личном ПК/ноутбуке', 'type' => 'L'],
    ['code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI', 'label' => 'Комментарии к заявке на создание учетной записи', 'type' => 'TEXT'],
    ['code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA', 'label' => 'Комментарии к заявке на создание АРМ сотрудника', 'type' => 'TEXT'],
    ['code' => 'PROPUSK_NUZHEN', 'label' => 'Пропуск нужен', 'type' => 'YESNO'],
    ['code' => 'OPISANIE_K_ZAYAVKE_NA_PROPUSK', 'label' => 'Комментарии к заявке на пропуск', 'type' => 'TEXT'],
    ['code' => 'NEOBKHODIMAYA_MEBEL_TEKST', 'label' => 'Необходима ли мебель?', 'type' => 'TEXT'],
    ['code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH', 'label' => 'Комментарии к заявке на создание рабочего места (АХС)', 'type' => 'TEXT'],
];

$sections = [
    ['title' => '1. Основные данные', 'fields' => ['FAMILIYA', 'IMYA', 'OTCHESTVO', 'POL', 'ORGANIZATSIYA', 'DOLZHNOST', 'OTDEL', 'DIREKTSIYA', 'RUKOVODITEL', 'FIO_RUKOVODITELYA', 'OTVETSTVENNYY_MENEDZHER_OPIA']],
    ['title' => '2. Условия выхода', 'fields' => ['DATA_PRIEMA', 'DATA_OKONCHANIYA_IS', 'FORMAT_RABOTY_', 'ADRES_OFISA_LST', 'NACHALO_RABOCHEGO_DNYA', 'KABINET_SPISOK', 'NOMER_KABINETA']],
    ['title' => '3. Обязательства', 'fields' => ['EST_LI_OBYAZATELSTVO_LST', 'SODERZHANIE_OBYAZATELSTV']],
    ['title' => '4. Контакты', 'fields' => ['KONTAKTNYY_NOMER_TELEFONA', 'LICHNAYA_POCHTA_KANDIDATA', 'FOTO_SOTRUDNIKA']],
    ['title' => '5. Новость и ФИО', 'fields' => ['EST_REKOMENDATSIYA', 'REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET', 'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI', 'FIO_V_DATELNOM_PADEZHE', 'FIO_V_RODITELNOM_PADEZHE']],
    ['title' => '6. Рабочее место и доступы', 'fields' => ['OBORUDOVANIE_DLYA_RABOTY', 'RABOCHEE_MESTO', 'DOSTUPY', 'VDI_VERSIYA_OS_NA_LICHNOM_PK_NOUTBUKE', 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI', 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA', 'PROPUSK_NUZHEN', 'OPISANIE_K_ZAYAVKE_NA_PROPUSK', 'NEOBKHODIMAYA_MEBEL_TEKST', 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH']],
];

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    ShowError('Требуется авторизация.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$anketaId = (int)($_GET['ID'] ?? $_GET['id'] ?? 0);
if ($anketaId <= 0) {
    ShowError('Не указан ID анкеты.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$anketa = CIBlockElement::GetList([], [
    'IBLOCK_ID' => VIEW_ADAPTATION_IBLOCK_ID,
    'ID' => $anketaId,
    'CHECK_PERMISSIONS' => 'Y',
    'MIN_PERMISSION' => 'R',
], false, ['nTopCount' => 1], ['ID', 'NAME'])->GetNext();
if (!$anketa) {
    ShowError('Анкета не найдена или нет прав на просмотр.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$propertyCodes = array_values(array_filter(array_column($fields, 'code'), static function ($code) {
    return $code !== 'EST_REKOMENDATSIYA';
}));
$propertiesByElement = [];
CIBlockElement::GetPropertyValuesArray(
    $propertiesByElement,
    VIEW_ADAPTATION_IBLOCK_ID,
    ['ID' => $anketaId],
    ['CODE' => $propertyCodes]
);
$properties = $propertiesByElement[$anketaId] ?? [];

$fieldMap = [];
$displayValues = [];
foreach ($fields as $field) {
    $code = $field['code'];
    $fieldMap[$code] = $field;
    if ($field['type'] === 'VIRTUAL') {
        continue;
    }

    $property = $properties[$code] ?? [];
    $value = $field['type'] === 'L'
        ? ($property['VALUE_ENUM'] ?? $property['VALUE'] ?? '')
        : ($property['VALUE'] ?? '');
    $values = is_array($value) ? $value : [$value];
    $formatted = [];
    foreach ($values as $item) {
        if ((string)$item === '') {
            continue;
        }
        if ($field['type'] === 'E') {
            $item = viewLinkedElementName($item);
        } elseif ($field['type'] === 'USER') {
            $item = viewUserName($item);
        } elseif ($field['type'] === 'YESNO') {
            $item = (string)$item === 'Y' ? 'Да' : ((string)$item === 'N' ? 'Нет' : $item);
        }
        if ((string)$item !== '') {
            $formatted[] = viewDecodeName($item);
        }
    }
    $displayValues[$code] = implode(', ', $formatted);
}

$recommendation = trim((string)($displayValues['REKOMENDATSIYA_NAPISHITE_NET_ESLI_EE_NET'] ?? ''));
$displayValues['EST_REKOMENDATSIYA'] = $recommendation !== '' && $recommendation !== 'Принят без рекомендации' ? 'Да' : 'Нет';
$photoValue = $properties['FOTO_SOTRUDNIKA']['VALUE'] ?? 0;
if (is_array($photoValue)) {
    $photoValue = reset($photoValue);
}
$photoId = (int)$photoValue;
$photoPath = $photoId > 0 ? (string)CFile::GetPath($photoId) : '';
?>
<style>
.anketa-view{max-width:960px;margin:24px auto;padding:0 12px}.anketa-view-title{font-size:24px;font-weight:600;margin:0}.anketa-view-subtitle{margin:5px 0 18px;color:#7a869a}
.anketa-view-section{margin-top:16px;padding:18px;border:1px solid #e2e8ef;border-radius:10px;background:#f7f9fb;box-shadow:0 1px 2px rgba(31,45,61,.04)}
.anketa-view-section:nth-of-type(even){background:#f4f8fc}.anketa-view-section-title{margin:0 0 14px;font-size:17px;font-weight:600;color:#263238}
.anketa-view-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px 22px}.anketa-view-field{min-width:0;padding-bottom:10px;border-bottom:1px solid rgba(198,205,211,.55)}
.anketa-view-label{margin-bottom:4px;color:#6b778c;font-size:12px}.anketa-view-value{color:#172b4d;font-size:15px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}
.anketa-view-empty{color:#9aa5b1}.anketa-view-full{grid-column:1/-1}.anketa-view-photo{display:block;max-width:260px;max-height:320px;border-radius:8px;border:1px solid #d8dee6;background:#fff;object-fit:contain}
.anketa-view-actions{margin-top:20px}@media(max-width:700px){.anketa-view-grid{grid-template-columns:1fr}.anketa-view-full{grid-column:auto}}
</style>
<div class="anketa-view">
    <h1 class="anketa-view-title">Просмотр анкеты сотрудника</h1>
    <div class="anketa-view-subtitle">Анкета #<?= $anketaId ?><?= trim((string)$anketa['NAME']) !== '' ? ' — ' . viewH($anketa['NAME']) : '' ?></div>

    <?php foreach ($sections as $section): ?>
        <section class="anketa-view-section">
            <h2 class="anketa-view-section-title"><?= viewH($section['title']) ?></h2>
            <div class="anketa-view-grid">
                <?php foreach ($section['fields'] as $code):
                    $field = $fieldMap[$code];
                    $isFull = in_array($field['type'], ['TEXT', 'FILE'], true);
                    $value = trim((string)($displayValues[$code] ?? ''));
                    ?>
                    <div class="anketa-view-field<?= $isFull ? ' anketa-view-full' : '' ?>">
                        <div class="anketa-view-label"><?= viewH($field['label']) ?></div>
                        <div class="anketa-view-value<?= $value === '' && !($code === 'FOTO_SOTRUDNIKA' && $photoPath !== '') ? ' anketa-view-empty' : '' ?>">
                            <?php if ($code === 'FOTO_SOTRUDNIKA' && $photoPath !== ''): ?>
                                <a href="<?= viewH($photoPath) ?>" target="_blank" rel="noopener">
                                    <img class="anketa-view-photo" src="<?= viewH($photoPath) ?>" alt="Фото сотрудника">
                                </a>
                            <?php else: ?>
                                <?= $value !== '' ? nl2br(viewH($value)) : 'Не указано' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="anketa-view-actions">
        <a class="ui-btn ui-btn-light-border" href="<?= viewH(VIEW_ADAPTATION_LIST_URL) ?>">Вернуться в список</a>
    </div>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
