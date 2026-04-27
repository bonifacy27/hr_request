<?php
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Просмотр анкеты кандидата');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    ShowError('Требуется авторизация.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const CANDIDATE_IBLOCK_ID = 207;

$fields = [
    ['ID' => 1083, 'TYPE' => 'S', 'NAME' => 'Фамилия', 'CODE' => 'FAMILIYA'],
    ['ID' => 1084, 'TYPE' => 'S', 'NAME' => 'Имя', 'CODE' => 'IMYA'],
    ['ID' => 1085, 'TYPE' => 'S', 'NAME' => 'Отчество', 'CODE' => 'OTCHESTVO'],
    ['ID' => 1091, 'TYPE' => 'F', 'NAME' => 'Анкета кандидата', 'CODE' => 'ANKETA_KANDIDATA'],
    ['ID' => 1088, 'TYPE' => 'S', 'NAME' => 'Моб. телефон (+7)', 'CODE' => 'MOB_TELEFON_7'],
    ['ID' => 1089, 'TYPE' => 'S', 'NAME' => 'E-mail', 'CODE' => 'E_MAIL'],
    ['ID' => 1092, 'TYPE' => 'L', 'NAME' => 'Статус анкеты', 'CODE' => 'STATUS_ANKETY'],
    ['ID' => 1093, 'TYPE' => 'L', 'NAME' => 'Тип анкеты', 'CODE' => 'TIP_ANKETY'],
    ['ID' => 1086, 'TYPE' => 'F', 'NAME' => 'Паспорт', 'CODE' => 'PASPORT'],
    ['ID' => 1224, 'TYPE' => 'F', 'NAME' => 'СНИЛС', 'CODE' => 'SNILS'],
    ['ID' => 1225, 'TYPE' => 'F', 'NAME' => 'ИНН', 'CODE' => 'INN'],
    ['ID' => 1226, 'TYPE' => 'F', 'NAME' => 'Диплом', 'CODE' => 'DIPLOM'],
    ['ID' => 1227, 'TYPE' => 'F', 'NAME' => 'Трудовая книжка', 'CODE' => 'TRUDOVAYA_KNIZHKA'],
    ['ID' => 3071, 'TYPE' => 'F', 'NAME' => 'СТД-Р', 'CODE' => 'STD_R'],
    ['ID' => 3072, 'TYPE' => 'S', 'NAME' => 'Причина отсутствия трудовой', 'CODE' => 'PRICHINA_OTSUTSTVIYA_TRUDOVOY'],
    ['ID' => 1228, 'TYPE' => 'F', 'NAME' => 'Военный билет', 'CODE' => 'VOENNYY_BILET'],
    ['ID' => 1689, 'TYPE' => 'F', 'NAME' => 'Резюме', 'CODE' => 'RESUME'],
    ['ID' => 1731, 'TYPE' => 'F', 'NAME' => 'Характеристики ПК', 'CODE' => 'COMP_SPEC'],
    ['ID' => 1732, 'TYPE' => 'F', 'NAME' => 'Скорость интернета', 'CODE' => 'INTERNET_SPEEDTEST'],
    ['ID' => 1733, 'TYPE' => 'F', 'NAME' => 'Скорость печати', 'CODE' => 'TYPING_SPEED'],
    ['ID' => 1726, 'TYPE' => 'F', 'NAME' => 'Согласование кандидата руководителем', 'CODE' => 'SOGLASOVANIE_KANDIDATA_RUKOVODITELEM'],
    ['ID' => 1276, 'TYPE' => 'S', 'NAME' => 'История', 'CODE' => 'ISTORIYA'],
    ['ID' => 1323, 'TYPE' => 'S', 'NAME' => 'Рекрутер', 'CODE' => 'REKRUTER'],
    ['ID' => 1338, 'TYPE' => 'S', 'NAME' => 'Комментарий СБ', 'CODE' => 'KOMMENTARIY_SB'],
    ['ID' => 1988, 'TYPE' => 'S', 'NAME' => 'Руководитель', 'CODE' => 'RUKOVODITEL'],
    ['ID' => 2086, 'TYPE' => 'S', 'NAME' => 'Комментарий СБ по ограничениям', 'CODE' => 'KOMMENTARIY_SB_PO_OGRANICHENIYAM'],
    ['ID' => 2854, 'TYPE' => 'S', 'NAME' => 'Путь создания анкеты', 'CODE' => 'ROUTE'],
];

function h($value)
{
    return htmlspecialcharsbx((string)$value);
}

function findPropertyByCode(array $properties, $code)
{
    foreach ($properties as $property) {
        if (!is_array($property)) {
            continue;
        }
        if ((string)($property['CODE'] ?? '') === (string)$code) {
            return $property;
        }
    }

    return null;
}

function normalizeValues($value)
{
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $item) {
            if ($item === '' || $item === null) {
                continue;
            }
            $clean[] = $item;
        }
        return $clean;
    }

    if ($value === '' || $value === null) {
        return [];
    }

    return [$value];
}

function renderPropertyValue(array $property, $type)
{
    if ($type === 'F') {
        $fileIds = normalizeValues($property['VALUE'] ?? null);
        if (!$fileIds) {
            return '<span class="text-muted">—</span>';
        }

        $links = [];
        $index = 1;
        foreach ($fileIds as $fileId) {
            $fileId = (int)$fileId;
            if ($fileId <= 0) {
                continue;
            }

            $filePath = CFile::GetPath($fileId);
            if ($filePath === '') {
                continue;
            }

            $file = CFile::GetFileArray($fileId);
            $fileName = (string)($file['ORIGINAL_NAME'] ?? $file['FILE_NAME'] ?? ('Файл ' . $index));
            $links[] = '<a href="' . h($filePath) . '" target="_blank">Открыть ' . h($fileName) . '</a>';
            $index++;
        }

        return $links ? implode('<br>', $links) : '<span class="text-muted">—</span>';
    }

    if ($type === 'L') {
        $value = (string)($property['VALUE_ENUM'] ?? $property['VALUE'] ?? '');
        return $value !== '' ? h($value) : '<span class="text-muted">—</span>';
    }

    $value = (string)($property['VALUE'] ?? '');
    if ($value === '') {
        return '<span class="text-muted">—</span>';
    }

    return nl2br(h($value));
}

$candidateId = (int)($_GET['id'] ?? 0);
if ($candidateId <= 0) {
    ShowError('Некорректный ID анкеты кандидата.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$rs = CIBlockElement::GetList(
    [],
    [
        'IBLOCK_ID' => CANDIDATE_IBLOCK_ID,
        'ID' => $candidateId,
        'ACTIVE' => 'Y',
        'CHECK_PERMISSIONS' => 'Y',
        'MIN_PERMISSION' => 'R',
    ],
    false,
    false,
    ['ID', 'IBLOCK_ID', 'NAME']
);

$element = $rs->GetNextElement();
if (!$element) {
    ShowError('Анкета кандидата не найдена или недоступна.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$elementFields = $element->GetFields();
$properties = $element->GetProperties();
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.page-wrap { padding: 16px 24px; }
.card-view { max-width: 1180px; }
.table td, .table th { vertical-align: middle; }
.field-name { width: 320px; white-space: nowrap; }
</style>

<div class="container-fluid page-wrap">
    <div class="card card-view">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Анкета кандидата #<?= (int)$elementFields['ID'] ?></strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-striped table-bordered mb-0">
                <thead class="thead-light">
                <tr>
                    <th class="field-name">Поле</th>
                    <th>Значение</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($fields as $field):
                    $property = findPropertyByCode($properties, $field['CODE']);
                    $content = $property ? renderPropertyValue($property, $field['TYPE']) : '<span class="text-muted">—</span>';
                    ?>
                    <tr>
                        <td class="field-name"><?= h($field['NAME']) ?></td>
                        <td><?= $content ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <a href="list.php" class="btn btn-secondary">Вернуться к списку</a>
        </div>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
