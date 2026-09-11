<?php
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Обезличивание персональных данных');

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
const PERSONAL_DATA_GROUP_ID = 82;
const DEPERSONALIZE_ADMIN_USER_ID = 3532;
const PROP_LAST_NAME = 1083;
const PROP_FIRST_NAME = 1084;
const PROP_MIDDLE_NAME = 1085;
const PROP_PHONE = 1088;
const PROP_EMAIL = 1089;
const PROP_HISTORY = 1276;
const PROP_FRIENDWORK_ID = 1594;

const FILE_PROPERTIES_TO_CLEAR = [1086, 1224, 1225, 1226, 1227, 3071, 3153, 1228, 1689, 1731, 1732, 1733];

function h($value): string
{
    return htmlspecialcharsbx((string)$value);
}

function propertyById(array $properties, int $propertyId): ?array
{
    foreach ($properties as $property) {
        if ((int)($property['ID'] ?? 0) === $propertyId) {
            return $property;
        }
    }
    return null;
}

function propertyString(array $properties, int $propertyId): string
{
    $property = propertyById($properties, $propertyId);
    $value = $property['VALUE'] ?? '';
    if (is_array($value)) {
        $value = implode("\n", array_map('strval', $value));
    }
    return trim((string)$value);
}

function formatUserNameById(int $userId): string
{
    $user = $userId > 0 ? CUser::GetByID($userId)->Fetch() : false;
    if (!$user) {
        return 'Пользователь #' . $userId;
    }
    $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
    return $name !== '' ? $name : ('Пользователь #' . $userId);
}

function maskPhone(string $phone): string
{
    $firstDigitWasFound = false;
    return (string)preg_replace_callback('/\d/u', static function ($matches) use (&$firstDigitWasFound) {
        if (!$firstDigitWasFound) {
            $firstDigitWasFound = true;
            return $matches[0];
        }
        return 'X';
    }, $phone);
}

function maskEmail(string $email): string
{
    return (string)preg_replace('/[^@.]/u', 'X', $email);
}

function fileDeletionValue(array $property)
{
    if (($property['MULTIPLE'] ?? 'N') !== 'Y') {
        return ['VALUE' => ['del' => 'Y']];
    }

    $valueIds = is_array($property['PROPERTY_VALUE_ID'] ?? null)
        ? $property['PROPERTY_VALUE_ID']
        : [$property['PROPERTY_VALUE_ID'] ?? 0];
    $deletions = [];
    foreach ($valueIds as $valueId) {
        $valueId = (int)$valueId;
        if ($valueId > 0) {
            $deletions[$valueId] = ['VALUE' => ['del' => 'Y']];
        }
    }
    return $deletions;
}

$currentUserId = (int)$USER->GetID();
$userGroups = array_map('intval', (array)CUser::GetUserGroup($currentUserId));
if ($currentUserId !== DEPERSONALIZE_ADMIN_USER_ID && !in_array(PERSONAL_DATA_GROUP_ID, $userGroups, true)) {
    ShowError('Недостаточно прав для обезличивания персональных данных.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$candidateId = (int)($_REQUEST['id'] ?? 0);
if ($candidateId <= 0) {
    ShowError('Некорректный ID анкеты кандидата.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$element = CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => CANDIDATE_IBLOCK_ID, 'ID' => $candidateId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y', 'MIN_PERMISSION' => 'R'],
    false,
    ['nTopCount' => 1],
    ['ID']
)->GetNextElement();
if (!$element) {
    ShowError('Анкета кандидата не найдена или недоступна.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$properties = $element->GetProperties();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия истекла. Обновите страницу и повторите действие.';
    } else {
        $friendworkId = propertyString($properties, PROP_FRIENDWORK_ID);
        $updates = [
            PROP_LAST_NAME => 'Кандидат ' . ($friendworkId !== '' ? $friendworkId : $candidateId),
            PROP_FIRST_NAME => 'XXX',
            PROP_MIDDLE_NAME => 'XXX',
            PROP_PHONE => maskPhone(propertyString($properties, PROP_PHONE)),
            PROP_EMAIL => maskEmail(propertyString($properties, PROP_EMAIL)),
        ];
        foreach (FILE_PROPERTIES_TO_CLEAR as $propertyId) {
            $property = propertyById($properties, $propertyId);
            if ($property) {
                $updates[$propertyId] = fileDeletionValue($property);
            }
        }

        $history = propertyString($properties, PROP_HISTORY);
        $historyLine = date('d.m.Y H:i') . ': ' . formatUserNameById($currentUserId)
            . ' удалил персональные данные из анкеты.';
        $updates[PROP_HISTORY] = ($history !== '' ? $history . "\n" : '') . $historyLine;

        CIBlockElement::SetPropertyValuesEx($candidateId, CANDIDATE_IBLOCK_ID, $updates);
        LocalRedirect('list.php?msg=success&text=' . rawurlencode('Персональные данные анкеты обезличены.'));
    }
}
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<div class="container-fluid" style="padding:16px 24px; max-width:900px; margin-left:0;">
    <h3>Обезличивание анкеты #<?= $candidateId ?></h3>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <div class="alert alert-warning">
        Персональные данные и документы будут безвозвратно удалены из анкеты. Продолжить?
    </div>
    <form method="post">
        <?= bitrix_sessid_post(); ?>
        <input type="hidden" name="id" value="<?= $candidateId ?>">
        <button type="submit" class="btn btn-danger">Обезличить ПД</button>
        <a href="list.php" class="btn btn-secondary">Отмена</a>
    </form>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
