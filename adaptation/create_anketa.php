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

function getIblockElementsById(int $iblockId): array
{
    $res = [];
    $rs = CIBlockElement::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME']);
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
    ['id' => 961, 'code' => 'OTVETSTVENNYY_MENEDZHER_OPIA', 'label' => 'Рекрутер', 'type' => 'USER'],
    ['id' => 963, 'code' => 'DATA_PRIEMA', 'label' => 'Дата приема', 'type' => 'DATE'],
    ['id' => 964, 'code' => 'DATA_OKONCHANIYA_IS', 'label' => 'Дата окончания ИС', 'type' => 'DATE'],
    ['id' => 1059, 'code' => 'KONTAKTNYY_NOMER_TELEFONA', 'label' => 'Контактный номер телефона', 'type' => 'S'],
    ['id' => 1421, 'code' => 'FORMAT_RABOTY_', 'label' => 'Формат работы', 'type' => 'E', 'link_iblock' => IBL_WORK_FORMAT],
    ['id' => 1420, 'code' => 'ADRES_OFISA_LST', 'label' => 'Офис', 'type' => 'E', 'link_iblock' => IBL_OFFICE],
    ['id' => 1198, 'code' => 'KABINET_SPISOK', 'label' => 'Местоположение сотрудника', 'type' => 'E', 'link_iblock' => IBL_LOCATION],
    ['id' => 967, 'code' => 'NOMER_KABINETA', 'label' => 'Номер кабинета', 'type' => 'S'],
    ['id' => 1623, 'code' => 'NACHALO_RABOCHEGO_DNYA', 'label' => 'Начало рабочего дня', 'type' => 'E', 'link_iblock' => IBL_WORK_START],
    ['id' => 989, 'code' => 'EST_LI_OBYAZATELSTVO_LST', 'label' => 'Есть ли обязательство?', 'type' => 'L'],
    ['id' => 1076, 'code' => 'SODERZHANIE_OBYAZATELSTV', 'label' => 'Содержание обязательств', 'type' => 'S'],
    ['id' => 3108, 'code' => 'PRINYAT_PO_REKOMENDATSII', 'label' => 'Принят по рекомендации', 'type' => 'S'],
    ['id' => 970, 'code' => 'FIO_V_DATELNOM_PADEZHE', 'label' => 'ФИО в дательном падеже', 'type' => 'S'],
    ['id' => 971, 'code' => 'FIO_V_RODITELNOM_PADEZHE', 'label' => 'ФИО в винительном падеже', 'type' => 'S'],
    ['id' => 2864, 'code' => 'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI', 'label' => 'Основные обязанности (для новости)', 'type' => 'S'],
    ['id' => 2865, 'code' => 'DOLZHNOST_DLYA_NOVOSTI', 'label' => 'Должность (для новости)', 'type' => 'S'],
    ['id' => 976, 'code' => 'RABOCHEE_MESTO', 'label' => 'Рабочее место', 'type' => 'L'],
    ['id' => 988, 'code' => 'PROPUSK_NUZHEN', 'label' => 'Пропуск нужен?', 'type' => 'CHK'],
    ['id' => 990, 'code' => 'NEOBKHODIMAYA_MEBEL', 'label' => 'Необходимая мебель', 'type' => 'L'],
    ['id' => 991, 'code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI', 'label' => 'Комментарии к заявке на создание учетной записи', 'type' => 'S'],
    ['id' => 992, 'code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA', 'label' => 'Комментарии к заявке на создание АРМ сотрудника', 'type' => 'S'],
    ['id' => 994, 'code' => 'OPISANIE_K_ZAYAVKE_NA_PROPUSK', 'label' => 'Комментарии к заявке на пропуск', 'type' => 'S'],
    ['id' => 993, 'code' => 'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH', 'label' => 'Комментарии к заявке на создание рабочего места (АХС)', 'type' => 'S'],
    ['id' => 1901, 'code' => 'DOSTUPY', 'label' => 'Доступы', 'type' => 'L'],
    ['id' => 1109, 'code' => 'VDI_VERSIYA_OS_NA_LICHNOM_PK_NOUTBUKE', 'label' => 'VDI: версия ОС на личном ПК/ноутбуке', 'type' => 'L'],
    ['id' => 1108, 'code' => 'LICHNAYA_POCHTA_KANDIDATA', 'label' => 'Личная почта кандидата', 'type' => 'S'],
];

$formData = [];
foreach ($fields as $f) {
    $formData[$f['code']] = ($f['type'] === 'CHK') ? 'N' : '';
}

$errors = [];
$saveMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $propertyValues = [];
    foreach ($fields as $f) {
        $code = $f['code'];
        $value = $_POST[$code] ?? '';

        if ($f['type'] === 'CHK') {
            $value = parseCheckbox($value);
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
        if ($value !== '') {
            $propertyValues[$code] = $value;
        }
    }

    $lastName = trim((string)($formData['FAMILIYA'] ?? ''));
    $firstName = trim((string)($formData['IMYA'] ?? ''));
    $middleName = trim((string)($formData['OTCHESTVO'] ?? ''));
    $name = trim($lastName . ' ' . $firstName . ' ' . $middleName);

    if ($name === '') {
        $errors[] = 'Заполните минимум Фамилию и Имя.';
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
                $formData[$f['code']] = ($f['type'] === 'CHK') ? 'N' : '';
            }
        }
    }
}

?><style>
.anketa-wrap{max-width:960px;margin:24px auto;padding:0 12px}.anketa-title{font-size:24px;font-weight:600;margin:0 0 18px}
.anketa-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}.anketa-field{display:flex;flex-direction:column;gap:6px}
.anketa-field label{font-size:13px;color:#525c69}.anketa-field input,.anketa-field select{height:38px;padding:0 10px;border:1px solid #c6cdd3;border-radius:6px}
.anketa-full{grid-column:1/-1}.anketa-actions{margin-top:18px}.anketa-msg{padding:10px 12px;border-radius:6px;margin-bottom:14px}
.anketa-msg-ok{background:#e8f7e8;color:#1f7a1f}.anketa-msg-err{background:#ffe9e9;color:#9f2f2f}
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
        <div class="anketa-grid">
            <?php foreach ($fields as $f): $code = $f['code']; ?>
                <div class="anketa-field <?= in_array($code, ['OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_UCHETNOY_ZAPISI','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA','OPISANIE_K_ZAYAVKE_NA_PROPUSK','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH'], true) ? 'anketa-full' : '' ?>">
                    <label for="<?= h($code) ?>"><?= h($f['label']) ?></label>
                    <?php if ($f['type'] === 'L'): ?>
                        <?php $options = getPropertyEnums(IBL_ADAPTATION, $code); ?>
                        <select name="<?= h($code) ?>" id="<?= h($code) ?>">
                            <option value="">— не выбрано —</option>
                            <?php foreach ($options as $opt): ?>
                                <option value="<?= h($opt['ID']) ?>" <?= ((string)$formData[$code] === (string)$opt['ID']) ? 'selected' : '' ?>><?= h($opt['VALUE']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($f['type'] === 'E'): ?>
                        <?php $options = getIblockElementsById((int)$f['link_iblock']); ?>
                        <select name="<?= h($code) ?>" id="<?= h($code) ?>">
                            <option value="">— не выбрано —</option>
                            <?php foreach ($options as $opt): ?>
                                <option value="<?= h($opt['ID']) ?>" <?= ((string)$formData[$code] === (string)$opt['ID']) ? 'selected' : '' ?>><?= h($opt['NAME']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($f['type'] === 'DATE'): ?>
                        <input type="date" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                    <?php elseif ($f['type'] === 'USER'): ?>
                        <input type="hidden" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                        <div id="<?= h($code) ?>_selector"></div>
                    <?php elseif ($f['type'] === 'CHK'): ?>
                        <input type="checkbox" name="<?= h($code) ?>" id="<?= h($code) ?>" value="Y" <?= ($formData[$code] === 'Y') ? 'checked' : '' ?>>
                    <?php else: ?>
                        <input type="text" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="anketa-actions">
            <button class="ui-btn ui-btn-success" type="submit">Сохранить</button>
        </div>
    </form>
</div>
<script>
BX.ready(function () {
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
            dialogOptions: {
                context: code + '_context',
                entities: [{id: 'user'}],
                multiple: false,
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
    }

    initUserSelector('RUKOVODITEL');
    initUserSelector('OTVETSTVENNYY_MENEDZHER_OPIA');
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
