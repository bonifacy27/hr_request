<?php
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Редактирование анкеты кандидата');

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
const EDIT_ADMIN_USER_ID = 3532;
const RECRUIT_HEAD_GLOBAL_VAR_ID = 'Variable1722503621093';
const RECRUITER_PROPERTY_ID = 1323;

$editableFileProperties = [
    1086 => ['CODE' => 'PASPORT', 'NAME' => 'Паспорт'],
    1224 => ['CODE' => 'SNILS', 'NAME' => 'СНИЛС'],
    1225 => ['CODE' => 'INN', 'NAME' => 'ИНН'],
    1226 => ['CODE' => 'DIPLOM', 'NAME' => 'Диплом'],
    1227 => ['CODE' => 'TRUDOVAYA_KNIZHKA', 'NAME' => 'Трудовая книжка'],
    3071 => ['CODE' => 'STD_R', 'NAME' => 'СТД-Р'],
    3153 => ['CODE' => 'PRICHINA_OTSUTSTVIYA_TRUDOVOY', 'NAME' => 'Причина отсутствия трудовой'],
    1228 => ['CODE' => 'VOENNYY_BILET', 'NAME' => 'Военный билет'],
];

$fieldsByCode = [
    'CANDIDATE_FIO' => ['TYPE' => 'FULL_NAME', 'NAME' => 'ФИО кандидата'],
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
    'PRICHINA_OTSUTSTVIYA_TRUDOVOY' => ['TYPE' => 'F', 'NAME' => 'Причина отсутствия трудовой книжки', 'SOURCE_CODE' => 'PROPERTY_3153'],
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
    'Основная информация' => ['CANDIDATE_FIO', 'MOB_TELEFON_7', 'E_MAIL', 'TIP_ANKETY', 'STATUS_ANKETY'],
    'Документы кандидата' => ['ANKETA_KANDIDATA', 'PASPORT', 'SNILS', 'INN', 'DIPLOM', 'TRUDOVAYA_KNIZHKA', 'STD_R', 'PRICHINA_OTSUTSTVIYA_TRUDOVOY', 'VOENNYY_BILET', 'COMP_SPEC', 'INTERNET_SPEEDTEST', 'TYPING_SPEED'],
    'Согласование и ответственные' => ['REKRUTER', 'RUKOVODITEL', 'SOGLASOVANIE_KANDIDATA_RUKOVODITELEM', 'RESUME'],
    'Служба безопасности' => ['STATUS_ANKETY_BLOCK4', 'KOMMENTARIY_SB', 'KOMMENTARIY_SB_PO_OGRANICHENIYAM', 'ROUTE'],
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

function fileIconByExt($fileName)
{
    $ext = mb_strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    $map = [
        'pdf' => '📕',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'doc' => '📘',
        'docx' => '📘',
        'msg' => '✉️',
    ];

    return $map[$ext] ?? '📄';
}

function renderInlineNote($label, $valueHtml)
{
    if ($valueHtml === '') {
        return '';
    }

    return '<div class="mt-2 text-muted"><strong>' . h($label) . ':</strong> ' . $valueHtml . '</div>';
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
            $icon = fileIconByExt($fileName);
            $links[] = '<a href="' . h($filePath) . '" target="_blank">' . h($icon . ' ' . $fileName) . '</a>';
        }

        return $links ? implode('<br>', $links) : '';
    }

    if ($type === 'L') {
        $value = trim((string)($property['VALUE_ENUM'] ?? $property['VALUE'] ?? ''));
        return $value !== '' ? h($value) : '';
    }

    if ($type === 'FULL_NAME') {
        $last = trim((string)($property['LAST_NAME'] ?? ''));
        $first = trim((string)($property['FIRST_NAME'] ?? ''));
        $middle = trim((string)($property['MIDDLE_NAME'] ?? ''));
        $full = trim($last . ' ' . $first . ' ' . $middle);
        return $full !== '' ? h($full) : '';
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

function getGlobalVarUserList(string $varId): array
{
    $users = [];
    try {
        $connection = \Bitrix\Main\Application::getConnection();
        $safeVarId = $connection->getSqlHelper()->forSql($varId);
        $row = $connection->query("SELECT PROPERTY_VALUE FROM b_bp_global_var WHERE ID = '{$safeVarId}' LIMIT 1")->fetch();
        if ($row && !empty($row['PROPERTY_VALUE'])) {
            $values = @unserialize($row['PROPERTY_VALUE'], ['allowed_classes' => false]);
            foreach ((array)$values as $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $users[] = mb_strtolower($value);
                }
            }
        }
    } catch (\Throwable $e) {
        return [];
    }

    return array_values(array_unique($users));
}

function userIdFromPropertyValue($value): int
{
    if (is_array($value)) {
        $value = reset($value);
    }
    $value = trim((string)$value);
    if (stripos($value, 'user_') === 0) {
        return (int)substr($value, 5);
    }
    return (int)$value;
}

function uploadedFilesForProperty(int $propertyId): array
{
    $files = [];
    $source = $_FILES['documents'] ?? [];
    $names = $source['name'][$propertyId] ?? [];
    if (!is_array($names)) {
        $names = [$names];
    }

    foreach (array_keys($names) as $index) {
        $error = (int)($source['error'][$propertyId][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => (string)($source['name'][$propertyId][$index] ?? ''),
            'type' => (string)($source['type'][$propertyId][$index] ?? ''),
            'tmp_name' => (string)($source['tmp_name'][$propertyId][$index] ?? ''),
            'error' => $error,
            'size' => (int)($source['size'][$propertyId][$index] ?? 0),
            'MODULE_ID' => 'iblock',
        ];
    }

    return $files;
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
$recruiterProperty = null;
foreach ($properties as $property) {
    if ((int)($property['ID'] ?? 0) === RECRUITER_PROPERTY_ID) {
        $recruiterProperty = $property;
        break;
    }
}

$currentUserId = (int)$USER->GetID();
$currentUserTag = mb_strtolower('user_' . $currentUserId);
$isRecruitHead = in_array($currentUserTag, getGlobalVarUserList(RECRUIT_HEAD_GLOBAL_VAR_ID), true);
$recruiterId = userIdFromPropertyValue($recruiterProperty['VALUE'] ?? '');
$canEdit = $currentUserId === EDIT_ADMIN_USER_ID || $isRecruitHead || ($recruiterId > 0 && $recruiterId === $currentUserId);
if (!$canEdit) {
    ShowError('Недостаточно прав для редактирования анкеты кандидата.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$saveError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $saveError = 'Сессия истекла. Обновите страницу и повторите действие.';
    } else {
        $updates = [];
        foreach ($editableFileProperties as $propertyId => $config) {
            $uploadedFiles = uploadedFilesForProperty((int)$propertyId);
            if (!$uploadedFiles) {
                continue;
            }
            foreach ($uploadedFiles as $uploadedFile) {
                if ($uploadedFile['error'] !== UPLOAD_ERR_OK || $uploadedFile['tmp_name'] === '' || !is_uploaded_file($uploadedFile['tmp_name'])) {
                    $saveError = 'Не удалось загрузить файл для поля «' . $config['NAME'] . '».';
                    break 2;
                }
            }

            $property = null;
            foreach ($properties as $candidateProperty) {
                if ((int)($candidateProperty['ID'] ?? 0) === (int)$propertyId) {
                    $property = $candidateProperty;
                    break;
                }
            }
            if (!$property) {
                $saveError = 'Поле «' . $config['NAME'] . '» не найдено в анкете.';
                break;
            }

            $mode = (string)($_POST['file_mode'][$propertyId] ?? 'append');
            $values = [];
            if ($mode !== 'replace') {
                foreach (normalizeValues($property['VALUE'] ?? []) as $fileId) {
                    if ((int)$fileId > 0) {
                        $values[] = ['VALUE' => (int)$fileId];
                    }
                }
            }
            foreach ($uploadedFiles as $uploadedFile) {
                $values[] = ['VALUE' => $uploadedFile];
            }
            if (($property['MULTIPLE'] ?? 'N') !== 'Y') {
                $values = end($values);
            }
            $updates[$propertyId] = $values;
        }

        if ($saveError === '' && !$updates) {
            $saveError = 'Выберите хотя бы один документ для загрузки.';
        }
        if ($saveError === '') {
            CIBlockElement::SetPropertyValuesEx($candidateId, CANDIDATE_IBLOCK_ID, $updates);
            LocalRedirect('edit_anketa.php?id=' . $candidateId . '&saved=Y');
        }
    }
}

$propertiesByCode = [];
foreach ($properties as $property) {
    if (!is_array($property)) {
        continue;
    }
    $propertyId = (int)($property['ID'] ?? 0);
    if ($propertyId > 0) {
        $propertiesByCode['PROPERTY_' . $propertyId] = $property;
    }

    $code = (string)($property['CODE'] ?? '');
    if ($code === '') {
        continue;
    }
    $propertiesByCode[$code] = $property;
}


$propertiesByCode['CANDIDATE_FIO'] = [
    'LAST_NAME' => (string)(($propertiesByCode['FAMILIYA']['VALUE'] ?? '')),
    'FIRST_NAME' => (string)(($propertiesByCode['IMYA']['VALUE'] ?? '')),
    'MIDDLE_NAME' => (string)(($propertiesByCode['OTCHESTVO']['VALUE'] ?? '')),
];
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.page-wrap { padding: 16px 24px; }
.blocks-wrap { max-width: 1280px; }
.block-card + .block-card { margin-top: 16px; }
.table td, .table th { vertical-align: middle; }
.field-name { width: 360px; white-space: nowrap; }
.document-edit-row + .document-edit-row { border-top: 1px solid #e5e5e5; }
.document-edit-row { padding: 14px 16px; }
.document-edit-options label { margin-right: 18px; margin-bottom: 8px; }
</style>

<div class="container-fluid page-wrap">
    <div class="blocks-wrap">
        <div class="mb-3">
            <h3 class="mb-0">Редактирование анкеты кандидата #<?= (int)$elementFields['ID'] ?></h3>
        </div>

        <?php if ((string)($_GET['saved'] ?? '') === 'Y'): ?>
            <div class="alert alert-success">Документы анкеты сохранены.</div>
        <?php endif; ?>
        <?php if ($saveError !== ''): ?>
            <div class="alert alert-danger"><?= h($saveError) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="card block-card mb-3">
            <?= bitrix_sessid_post(); ?>
            <div class="card-header"><strong>Добавление и замена документов</strong></div>
            <div class="card-body p-0">
                <?php foreach ($editableFileProperties as $propertyId => $config): ?>
                    <div class="document-edit-row">
                        <label for="document-<?= (int)$propertyId ?>"><strong><?= h($config['NAME']) ?></strong></label>
                        <input
                            type="file"
                            class="form-control-file"
                            id="document-<?= (int)$propertyId ?>"
                            name="documents[<?= (int)$propertyId ?>][]"
                            multiple
                        >
                        <div class="document-edit-options mt-2">
                            <label><input type="radio" name="file_mode[<?= (int)$propertyId ?>]" value="append" checked> Добавить к загруженным документам</label>
                            <label><input type="radio" name="file_mode[<?= (int)$propertyId ?>]" value="replace"> Заменить ранее загруженные документы</label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">Сохранить документы</button>
                <a href="list.php" class="btn btn-secondary">Отмена</a>
            </div>
        </form>

        <?php foreach ($blocks as $blockTitle => $blockCodes): ?>
            <?php
            $rowsHtml = [];
            $absenceReasonCode = 'PRICHINA_OTSUTSTVIYA_TRUDOVOY';
            $absenceReasonConfig = $fieldsByCode[$absenceReasonCode] ?? null;
            $absenceReasonSourceCode = (string)($absenceReasonConfig['SOURCE_CODE'] ?? $absenceReasonCode);
            $absenceReasonProperty = $propertiesByCode[$absenceReasonSourceCode] ?? null;
            $absenceReasonHtml = ($absenceReasonConfig && $absenceReasonProperty)
                ? renderValue($absenceReasonProperty, (string)$absenceReasonConfig['TYPE'])
                : '';
            $absenceReasonWasRendered = false;
            ?>
            <?php foreach ($blockCodes as $code): ?>
                <?php
                $fieldConfig = $fieldsByCode[$code] ?? null;
                if (!$fieldConfig) {
                    continue;
                }

                if ($code === $absenceReasonCode) {
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

                if (!$absenceReasonWasRendered && in_array($code, ['TRUDOVAYA_KNIZHKA', 'STD_R'], true)) {
                    $valueHtml .= renderInlineNote($absenceReasonConfig['NAME'] ?? 'Причина отсутствия трудовой книжки', $absenceReasonHtml);
                    $absenceReasonWasRendered = $absenceReasonHtml !== '';
                }

                $rowsHtml[] = '<tr><td class="field-name">' . h($fieldConfig['NAME']) . '</td><td>' . $valueHtml . '</td></tr>';
                ?>
            <?php endforeach; ?>

            <?php if (!$absenceReasonWasRendered && $absenceReasonHtml !== '' && in_array($absenceReasonCode, $blockCodes, true)): ?>
                <?php $rowsHtml[] = '<tr><td class="field-name">' . h($absenceReasonConfig['NAME'] ?? 'Причина отсутствия трудовой книжки') . '</td><td>' . $absenceReasonHtml . '</td></tr>'; ?>
            <?php endif; ?>

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
