<?php
/**
 * Форма создания тестовой анкеты кандидата для дальнейшей проверки.
 * URL: /check_candidate/create_test_anketa.php
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание тестовой анкеты кандидата');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc')) {
    ShowError('Не удалось подключить модули iblock/bizproc.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

Extension::load(['main.core', 'ui.entity-selector']);

const IBL_CANDIDATES = 207;
const BP_TEMPLATE_1 = 466;
const BP_TEMPLATE_2 = 328;
const BP_TEMPLATE_3 = 844;
const TESTIROVANIE_PROPERTY_ID = 3146;
const REDIRECT_AFTER_CREATE_URL = 'http://ourtricolortv.nsc.ru';

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

function decodeName(string $name): string
{
    return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function getPropertyEnumOptions(int $iblockId, int $propertyId): array
{
    $options = [];
    $rs = CIBlockPropertyEnum::GetList(
        ['SORT' => 'ASC', 'VALUE' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'PROPERTY_ID' => $propertyId]
    );
    while ($row = $rs->GetNext()) {
        $options[] = [
            'ID' => (string)$row['ID'],
            'VALUE' => decodeName((string)$row['VALUE']),
        ];
    }
    return $options;
}

function startListWorkflow(int $templateId, int $elementId, array &$errors): bool
{
    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $elementId];
    return CBPDocument::StartWorkflow($templateId, $documentId, [], $errors) !== false;
}

$fields = [
    ['id' => 1083, 'code' => 'FAMILIYA', 'label' => 'Фамилия', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1084, 'code' => 'IMYA', 'label' => 'Имя', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1085, 'code' => 'OTCHESTVO', 'label' => 'Отчество', 'type' => 'S', 'required' => false, 'hidden' => false],
    ['id' => 1088, 'code' => 'KONTAKTNYY_TELEFON', 'label' => 'Контактный телефон', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1089, 'code' => 'EMAIL', 'label' => 'E-mail', 'type' => 'S', 'required' => false, 'hidden' => false],
    ['id' => 1323, 'code' => 'REKRUTER', 'label' => 'Рекрутер', 'type' => 'USER', 'required' => true, 'hidden' => false],
    ['id' => 1617, 'code' => 'DOLZHNOST', 'label' => 'Должность', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1988, 'code' => 'RUKOVODITEL', 'label' => 'Руководитель', 'type' => 'USER', 'required' => true, 'hidden' => false],
    ['id' => 1098, 'code' => 'DATA_VYKHODA', 'label' => 'Плановая дата выхода', 'type' => 'DATE', 'required' => false, 'hidden' => true],
    ['id' => 1596, 'code' => 'ID_ZAYAVKI_NA_PODBOR', 'label' => 'ID заявки на подбор', 'type' => 'N', 'required' => false, 'hidden' => true],
    ['id' => 2854, 'code' => 'ROUTE', 'label' => 'Маршрут', 'type' => 'S', 'required' => false, 'hidden' => true],
    ['id' => 1093, 'code' => 'TIP_ANKETY', 'label' => 'Тип анкеты', 'type' => 'L', 'required' => true, 'hidden' => false],
];

$formData = [];
foreach ($fields as $f) {
    $formData[$f['code']] = '';
}

$tipAnketyOptions = getPropertyEnumOptions(IBL_CANDIDATES, 1093);
$errors = [];
$saveMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $propertyValues = [];

    foreach ($fields as $f) {
        $code = $f['code'];
        $value = $_POST[$code] ?? '';

        if ($f['type'] === 'FILE') {
            $value = $_FILES[$code] ?? null;
        } elseif ($f['type'] === 'DATE') {
            $value = normalizeDate((string)$value);
        } elseif ($f['type'] === 'USER') {
            $value = (string)(int)preg_replace('/\D+/', '', trim((string)$value));
            if ($value === '0') {
                $value = '';
            }
        } elseif ($f['type'] === 'L') {
            $value = (string)(int)trim((string)$value);
            if ($value === '0') {
                $value = '';
            }
        } else {
            $value = trim((string)$value);
        }

        $formData[$code] = (string)$value;
        if ($value !== '') {
            $propertyValues[(int)$f['id']] = $value;
        }

        if (!empty($f['required']) && trim((string)($formData[$code] ?? '')) === '') {
            $errors[] = 'Не заполнено обязательное поле: ' . $f['label'];
        }
    }

    // Системные значения для тестовых анкет без заявки на подбор и без предзаполнения.
    $propertyValues[TESTIROVANIE_PROPERTY_ID] = 'Y';
    $propertyValues[2854] = 'Без заявки на подбор';
    unset($propertyValues[1098], $propertyValues[1596]);

    $name = trim(($formData['FAMILIYA'] ?? '') . ' ' . ($formData['IMYA'] ?? '') . ' ' . ($formData['OTCHESTVO'] ?? ''));
    if ($name === '') {
        $errors[] = 'Заполните минимум Фамилию и Имя.';
    }

    if (!$errors) {
        $el = new CIBlockElement();
        $newId = $el->Add([
            'IBLOCK_ID' => IBL_CANDIDATES,
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'PROPERTY_VALUES' => $propertyValues,
        ]);

        if (!$newId) {
            $errors[] = (string)$el->LAST_ERROR;
        } else {
            $bpErrors1 = [];
            $bpErrors2 = [];
            $bpErrors3 = [];
            startListWorkflow(BP_TEMPLATE_1, (int)$newId, $bpErrors1);
            startListWorkflow(BP_TEMPLATE_2, (int)$newId, $bpErrors2);
            startListWorkflow(BP_TEMPLATE_3, (int)$newId, $bpErrors3);
            if (!empty($bpErrors1) || !empty($bpErrors2) || !empty($bpErrors3)) {
                $errors[] = 'Анкета создана, но запуск БП завершился с ошибками.';
            }
            LocalRedirect(REDIRECT_AFTER_CREATE_URL);
            return;
        }
    }
}
?>
<style>
.anketa-wrap{max-width:960px;margin:24px auto;padding:0 12px}.anketa-title{font-size:24px;font-weight:600;margin:0 0 18px}
.anketa-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}.anketa-field{display:flex;flex-direction:column;gap:6px}
.anketa-field label{font-size:13px;color:#525c69}.anketa-field input,.anketa-field select{height:38px;padding:0 10px;border:1px solid #c6cdd3;border-radius:6px}
.anketa-full{grid-column:1/-1}.anketa-actions{margin-top:18px}.anketa-msg{padding:10px 12px;border-radius:6px;margin-bottom:14px;white-space:pre-line}
.anketa-msg-ok{background:#e8f7e8;color:#1f7a1f}.anketa-msg-err{background:#ffe9e9;color:#9f2f2f}.req{color:#d95757}
</style>
<div class="anketa-wrap">
    <h1 class="anketa-title">Создание тестовой анкеты кандидата</h1>

    <?php if ($saveMessage): ?>
        <div class="anketa-msg anketa-msg-ok"><?= h($saveMessage) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="anketa-msg anketa-msg-err"><?= h(implode("\n", $errors)) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= bitrix_sessid_post() ?>

        <div class="anketa-grid">
            <?php foreach ($fields as $f): if (!empty($f['hidden'])) { continue; } $code = $f['code']; ?>
                <div class="anketa-field">
                    <label for="<?= h($code) ?>"><?= h($f['label']) ?><?= !empty($f['required']) ? '<span class="req">*</span>' : '' ?></label>
                    <?php if ($f['type'] === 'USER'): ?>
                        <input type="hidden" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                        <div id="<?= h($code) ?>_selector"></div>
                    <?php elseif ($f['type'] === 'FILE'): ?>
                        <input type="file" name="<?= h($code) ?>" id="<?= h($code) ?>">
                    <?php elseif ($f['type'] === 'DATE'): ?>
                        <input type="date" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                    <?php elseif ($f['type'] === 'L'): ?>
                        <select name="<?= h($code) ?>" id="<?= h($code) ?>">
                            <option value="">— выбрать —</option>
                            <?php foreach ($tipAnketyOptions as $option): ?>
                                <option value="<?= h($option['ID']) ?>" <?= (string)$formData[$code] === (string)$option['ID'] ? 'selected' : '' ?>><?= h($option['VALUE']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="anketa-actions">
            <button class="ui-btn ui-btn-success" type="submit">Создать тестовую анкету</button>
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

    initUserSelector('REKRUTER');
    initUserSelector('RUKOVODITEL');
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
