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

$fieldsByCode = [
    'FAMILIYA' => ['TYPE' => 'S', 'NAME' => 'Фамилия'],
    'IMYA' => ['TYPE' => 'S', 'NAME' => 'Имя'],
    'OTCHESTVO' => ['TYPE' => 'S', 'NAME' => 'Отчество'],
    'MOB_TELEFON_7' => ['TYPE' => 'S', 'NAME' => 'Моб. телефон (+7)'],
    'E_MAIL' => ['TYPE' => 'S', 'NAME' => 'E-mail'],
    'TIP_ANKETY' => ['TYPE' => 'L', 'NAME' => 'Тип анкеты'],
    'STATUS_ANKETY' => ['TYPE' => 'L', 'NAME' => 'Статус анкеты'],

    'ANKETA_KANDIDATA' => ['TYPE' => 'F', 'NAME' => 'Анкета кандидата'],
    'PASPORT' => ['TYPE' => 'F', 'NAME' => 'Паспорт'],
    'SNILS' => ['TYPE' => 'F', 'NAME' => 'СНИЛС'],
    'INN' => ['TYPE' => 'F', 'NAME' => 'ИНН'],
    'DIPLOM' => ['TYPE' => 'F', 'NAME' => 'Диплом'],
    'TRUDOVAYA_KNIZHKA' => ['TYPE' => 'F', 'NAME' => 'Трудовая книжка'],
    'STD_R' => ['TYPE' => 'F', 'NAME' => 'СТД-Р'],
    'PRICHINA_OTSUTSTVIYA_TRUDOVOY' => ['TYPE' => 'S', 'NAME' => 'Причина отсутствия трудовой'],
    'VOENNYY_BILET' => ['TYPE' => 'F', 'NAME' => 'Военный билет'],
    'RESUME' => ['TYPE' => 'F', 'NAME' => 'Резюме'],
    'COMP_SPEC' => ['TYPE' => 'F', 'NAME' => 'Характеристики ПК'],
    'INTERNET_SPEEDTEST' => ['TYPE' => 'F', 'NAME' => 'Скорость интернета'],
    'TYPING_SPEED' => ['TYPE' => 'F', 'NAME' => 'Скорость печати'],

    'REKRUTER' => ['TYPE' => 'U', 'NAME' => 'Рекрутер'],
    'RUKOVODITEL' => ['TYPE' => 'U', 'NAME' => 'Руководитель'],
    'SOGLASOVANIE_KANDIDATA_RUKOVODITELEM' => ['TYPE' => 'F', 'NAME' => 'Согласование кандидата руководителем'],

    'STATUS_ANKETY_BLOCK4' => ['TYPE' => 'L', 'NAME' => 'Статус анкеты', 'SOURCE_CODE' => 'STATUS_ANKETY'],
    'KOMMENTARIY_SB' => ['TYPE' => 'S', 'NAME' => 'Комментарий СБ'],
    'KOMMENTARIY_SB_PO_OGRANICHENIYAM' => ['TYPE' => 'S', 'NAME' => 'Комментарий СБ по ограничениям'],
    'ROUTE' => ['TYPE' => 'S', 'NAME' => 'Путь создания анкеты'],
];

$blocks = [
    'Блок 1' => ['FAMILIYA', 'IMYA', 'OTCHESTVO', 'MOB_TELEFON_7', 'E_MAIL', 'TIP_ANKETY', 'STATUS_ANKETY'],
    'Блок 2' => ['ANKETA_KANDIDATA', 'PASPORT', 'SNILS', 'INN', 'DIPLOM', 'TRUDOVAYA_KNIZHKA', 'STD_R', 'PRICHINA_OTSUTSTVIYA_TRUDOVOY', 'VOENNYY_BILET', 'RESUME', 'COMP_SPEC', 'INTERNET_SPEEDTEST', 'TYPING_SPEED'],
    'Блок 3' => ['REKRUTER', 'RUKOVODITEL', 'SOGLASOVANIE_KANDIDATA_RUKOVODITELEM'],
    'Блок 4' => ['STATUS_ANKETY_BLOCK4', 'KOMMENTARIY_SB', 'KOMMENTARIY_SB_PO_OGRANICHENIYAM', 'ROUTE'],
];

function h($value)
{
    return htmlspecialcharsbx((string)$value);
}

function normalizeValues($value)
{
    if (is_array($value)) {
        return array_values(array_filter($value, static function ($item) {
            return $item !== '' && $item !== null;
        }));
    }

    return ($value === '' || $value === null) ? [] : [$value];
}

function formatUserNameById($userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return '';
    }

    $rsUser = CUser::GetByID($userId);
    $user = $rsUser ? $rsUser->Fetch() : false;
    if (!$user) {
        return '';
    }

    $name = trim((string)$user['LAST_NAME'] . ' ' . (string)$user['NAME']);
    if ($name !== '') {
        return $name;
    }

    return (string)($user['LOGIN'] ?? '');
}

function renderValue(array $property, $type)
{
    if ($type === 'F') {
        $fileIds = normalizeValues($property['VALUE'] ?? null);
        if (!$fileIds) {
            return '';
        }

        $links = [];
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
            $fileName = (string)($file['ORIGINAL_NAME'] ?? $file['FILE_NAME'] ?? ('Файл ' . $fileId));
            $links[] = '<a href="' . h($filePath) . '" target="_blank">Открыть ' . h($fileName) . '</a>';
        }

        return $links ? implode('<br>', $links) : '';
    }

    if ($type === 'L') {
        $value = trim((string)($property['VALUE_ENUM'] ?? $property['VALUE'] ?? ''));
        return $value !== '' ? h($value) : '';
    }

    if ($type === 'U') {
        $rawValues = normalizeValues($property['VALUE'] ?? null);
        if (!$rawValues) {
            return '';
        }

        $names = [];
        foreach ($rawValues as $raw) {
            $name = '';
            if (is_numeric($raw)) {
                $name = formatUserNameById((int)$raw);
            }
            if ($name === '') {
                $name = trim((string)$raw);
            }
            if ($name !== '') {
                $names[] = h($name);
            }
        }

        return $names ? implode('<br>', array_unique($names)) : '';
    }

    $value = trim((string)($property['VALUE'] ?? ''));
    return $value !== '' ? nl2br(h($value)) : '';
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
    ['ID', 'IBLOCK_ID']
);

$element = $rs->GetNextElement();
if (!$element) {
    ShowError('Анкета кандидата не найдена или недоступна.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$elementFields = $element->GetFields();
$properties = $element->GetProperties();
$propertiesByCode = [];
foreach ($properties as $property) {
    if (!is_array($property)) {
        continue;
    }
    $code = (string)($property['CODE'] ?? '');
    if ($code === '') {
        continue;
    }
    $propertiesByCode[$code] = $property;
}
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.page-wrap { padding: 16px 24px; }
.blocks-wrap { max-width: 1280px; }
.block-card + .block-card { margin-top: 16px; }
.table td, .table th { vertical-align: middle; }
.field-name { width: 360px; white-space: nowrap; }
</style>

<div class="container-fluid page-wrap">
    <div class="blocks-wrap">
        <div class="mb-3">
            <h3 class="mb-0">Анкета кандидата #<?= (int)$elementFields['ID'] ?></h3>
        </div>

        <?php foreach ($blocks as $blockTitle => $blockCodes): ?>
            <?php $rowsHtml = []; ?>
            <?php foreach ($blockCodes as $code): ?>
                <?php
                $fieldConfig = $fieldsByCode[$code] ?? null;
                if (!$fieldConfig) {
                    continue;
                }

                $sourceCode = (string)($fieldConfig['SOURCE_CODE'] ?? $code);
                $property = $propertiesByCode[$sourceCode] ?? null;
                if (!$property) {
                    continue;
                }

                $valueHtml = renderValue($property, (string)$fieldConfig['TYPE']);
                if ($valueHtml === '') {
                    continue;
                }

                $rowsHtml[] = '<tr><td class="field-name">' . h($fieldConfig['NAME']) . '</td><td>' . $valueHtml . '</td></tr>';
                ?>
            <?php endforeach; ?>

            <?php if (!$rowsHtml) {
                continue;
            } ?>

            <div class="card block-card">
                <div class="card-header"><strong><?= h($blockTitle) ?></strong></div>
                <div class="card-body p-0">
                    <table class="table table-striped table-bordered mb-0">
                        <tbody>
                        <?= implode('', $rowsHtml) ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="mt-3">
            <a href="list.php" class="btn btn-secondary">Вернуться к списку</a>
        </div>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
