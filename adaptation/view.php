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
$employeeName = trim(implode(' ', array_filter([
    $displayValues['FAMILIYA'] ?? '',
    $displayValues['IMYA'] ?? '',
    $displayValues['OTCHESTVO'] ?? '',
])));
if ($employeeName === '') {
    $employeeName = trim((string)$anketa['NAME']) ?: ('Анкета #' . $anketaId);
}
$employeeInitials = mb_substr((string)($displayValues['IMYA'] ?? ''), 0, 1)
    . mb_substr((string)($displayValues['FAMILIYA'] ?? ''), 0, 1);
$employeeInitials = mb_strtoupper($employeeInitials ?: 'НС');
$sidebarFields = [
    'KONTAKTNYY_NOMER_TELEFONA',
    'LICHNAYA_POCHTA_KANDIDATA',
    'ORGANIZATSIYA',
    'DOLZHNOST',
    'OTDEL',
    'DIREKTSIYA',
    'RUKOVODITEL',
    'OTVETSTVENNYY_MENEDZHER_OPIA',
    'DATA_PRIEMA',
    'DATA_OKONCHANIYA_IS',
];
?>
<style>
.dossier{--ink:#182230;--muted:#697586;--line:#e3e8ef;--accent:#315b7d;max-width:1120px;margin:26px auto;padding:0 14px;color:var(--ink)}
.dossier-shell{overflow:hidden;border:1px solid #dde3ea;border-radius:18px;background:#fff;box-shadow:0 18px 48px rgba(30,44,59,.12)}
.dossier-hero{min-height:164px;padding:32px 38px 30px 326px;background:linear-gradient(120deg,#17212b,#263c4d 68%,#315b7d);color:#fff;display:flex;flex-direction:column;justify-content:center}
.dossier-kicker{margin-bottom:8px;color:#b9cbd9;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.dossier-name{margin:0;font-size:34px;font-weight:500;line-height:1.15;letter-spacing:.01em}.dossier-role{margin-top:10px;color:#d9e5ec;font-size:16px}.dossier-role span+span:before{content:'•';margin:0 9px;color:#89a6bb}
.dossier-body{display:grid;grid-template-columns:288px minmax(0,1fr)}.dossier-sidebar{position:relative;padding:0 24px 26px;background:#f3f5f7;border-right:1px solid var(--line)}
.dossier-photo-wrap{width:190px;height:220px;margin:-116px auto 24px;padding:6px;border-radius:14px;background:#fff;box-shadow:0 10px 28px rgba(27,39,51,.2)}.dossier-photo{display:block;width:100%;height:100%;border-radius:9px;object-fit:cover;background:#e5ebf0}
.dossier-photo-placeholder{width:100%;height:100%;border-radius:9px;background:linear-gradient(145deg,#dce5eb,#eef3f6);display:flex;align-items:center;justify-content:center;color:#49677d;font-size:54px;font-weight:300;letter-spacing:.04em}
.dossier-side-title{margin:24px 0 12px;padding-bottom:8px;border-bottom:1px solid #ccd5dd;color:#344054;font-size:12px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.dossier-side-item{margin-bottom:14px}.dossier-side-label{margin-bottom:3px;color:#7b8794;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.dossier-side-value{font-size:14px;line-height:1.4;overflow-wrap:anywhere}.dossier-side-empty{color:#9aa5b1}
.dossier-main{padding:28px 34px 36px;min-width:0;background:#fff}.dossier-section{padding:20px 22px;margin-bottom:18px;border:1px solid var(--line);border-radius:13px;background:#f8fafc}.dossier-section:nth-child(even){background:#f5f8fb}.dossier-section-title{display:flex;align-items:center;gap:12px;margin:0 0 18px;color:#263746;font-size:16px;font-weight:700}.dossier-section-title:after{content:'';height:1px;flex:1;background:#cfd8e1}
.dossier-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px 26px}.dossier-field{min-width:0}.dossier-label{margin-bottom:5px;color:var(--muted);font-size:12px}.dossier-value{font-size:14px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}.dossier-empty{color:#9aa5b1}.dossier-full{grid-column:1/-1}.dossier-actions{display:flex;justify-content:flex-end;margin-top:4px}
@media(max-width:850px){.dossier-hero{padding-left:268px}.dossier-body{grid-template-columns:230px}.dossier-photo-wrap{width:166px;height:196px}.dossier-main{padding:24px 22px}}
@media(max-width:680px){.dossier{margin:12px auto;padding:0 8px}.dossier-hero{min-height:auto;padding:26px 22px 92px}.dossier-name{font-size:27px}.dossier-body{display:block}.dossier-sidebar{padding:0 20px 20px;border-right:0;border-bottom:1px solid var(--line)}.dossier-photo-wrap{width:150px;height:176px;margin:-70px 0 20px}.dossier-main{padding:20px 14px}.dossier-grid{grid-template-columns:1fr}.dossier-full{grid-column:auto}.dossier-section{padding:17px}}
</style>
<div class="dossier">
    <article class="dossier-shell">
        <header class="dossier-hero">
            <div class="dossier-kicker">Досье сотрудника · Анкета #<?= $anketaId ?></div>
            <h1 class="dossier-name"><?= viewH($employeeName) ?></h1>
            <div class="dossier-role">
                <?php if (!empty($displayValues['DOLZHNOST'])): ?><span><?= viewH($displayValues['DOLZHNOST']) ?></span><?php endif; ?>
                <?php if (!empty($displayValues['ORGANIZATSIYA'])): ?><span><?= viewH($displayValues['ORGANIZATSIYA']) ?></span><?php endif; ?>
            </div>
        </header>

        <div class="dossier-body">
            <aside class="dossier-sidebar">
                <div class="dossier-photo-wrap">
                    <?php if ($photoPath !== ''): ?>
                        <a href="<?= viewH($photoPath) ?>" target="_blank" rel="noopener" title="Открыть фото">
                            <img class="dossier-photo" src="<?= viewH($photoPath) ?>" alt="Фото <?= viewH($employeeName) ?>">
                        </a>
                    <?php else: ?>
                        <div class="dossier-photo-placeholder" aria-label="Фото отсутствует"><?= viewH($employeeInitials) ?></div>
                    <?php endif; ?>
                </div>

                <div class="dossier-side-title">Ключевая информация</div>
                <?php foreach ($sidebarFields as $code):
                    $value = trim((string)($displayValues[$code] ?? ''));
                    ?>
                    <div class="dossier-side-item">
                        <div class="dossier-side-label"><?= viewH($fieldMap[$code]['label']) ?></div>
                        <div class="dossier-side-value<?= $value === '' ? ' dossier-side-empty' : '' ?>"><?= $value !== '' ? nl2br(viewH($value)) : 'Не указано' ?></div>
                    </div>
                <?php endforeach; ?>
            </aside>

            <main class="dossier-main">
                <?php foreach ($sections as $section):
                    $sectionFields = array_values(array_filter($section['fields'], static function ($code) use ($sidebarFields) {
                        return $code !== 'FOTO_SOTRUDNIKA' && !in_array($code, $sidebarFields, true);
                    }));
                    if (!$sectionFields) { continue; }
                    ?>
                    <section class="dossier-section">
                        <h2 class="dossier-section-title"><?= viewH($section['title']) ?></h2>
                        <div class="dossier-grid">
                            <?php foreach ($sectionFields as $code):
                                $field = $fieldMap[$code];
                                $isFull = $field['type'] === 'TEXT';
                                $value = trim((string)($displayValues[$code] ?? ''));
                                ?>
                                <div class="dossier-field<?= $isFull ? ' dossier-full' : '' ?>">
                                    <div class="dossier-label"><?= viewH($field['label']) ?></div>
                                    <div class="dossier-value<?= $value === '' ? ' dossier-empty' : '' ?>"><?= $value !== '' ? nl2br(viewH($value)) : 'Не указано' ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <div class="dossier-actions">
                    <a class="ui-btn ui-btn-light-border" href="<?= viewH(VIEW_ADAPTATION_LIST_URL) ?>">Вернуться в список</a>
                </div>
            </main>
        </div>
    </article>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
