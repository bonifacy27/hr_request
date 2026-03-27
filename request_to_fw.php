<?php
/**
 * /forms/staff_recruitment/request_to_fw.php
 *
 * Интеграция заявки на подбор (ИБ 201) -> FriendWork.
 *
 * Логика:
 * 1) GET id=ID_заявки: показываем данные, которые будут отправлены в FriendWork.
 * 2) Если в заявке уже заполнен ID_FW_VAKANSII, повторное создание запрещено.
 * 3) POST submit_to_fw: создаём вакансию в FriendWork и записываем:
 *    - ID_FW_VAKANSII
 *    - SSYLKA_NA_VAKANSIYU_FW
 */

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

global $APPLICATION;
$APPLICATION->SetTitle('Отправка заявки в FriendWork');

const IBLOCK_RECRUITMENT = 201;
const FW_CLIENT_ID = 5322;
const FW_STATUS_DRAFT = 5;
const FW_POSITION_COUNT = 1;
const FW_LOGIN_ENDPOINT = 'https://app.friend.work/api/Accounts/LogIn';
const FW_JOBS_ENDPOINT = 'https://app.friend.work/api/Jobs';
const FW_JOB_EDIT_URL = 'https://app.friend.work/Job/Edit/';

/**
 * Достаём учётные данные FW из env. Для прода лучше хранить в настройках Битрикс/секретах.
 */
function fwGetCredentials(): array
{
    $username = trim((string)getenv('FRIENDWORK_USERNAME'));
    $password = trim((string)getenv('FRIENDWORK_PASSWORD'));

    return [
        'username' => $username,
        'password' => $password,
    ];
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeText(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return $value;
}

function buildDescription(array $fields): string
{
    $header = "<b>Триколор</b> — мультиплатформенный оператор, предлагающий единое информационное пространство развлечений и сервисов для всей семьи.<br>\n"
        . "Наряду с ТВ, мы предлагаем передовые digital-сервисы и услуги, включая онлайн-кинотеатр, умный дом, видеонаблюдение и спутниковый интернет.<br><br>";

    $subheader = "<b>Приглашаем Вас присоединиться к команде крупнейшего мультиплатформенного оператора РФ, входящего в список лицензированных операторов связи и включенного в соответствующий перечень Минцифры России!</b><br><br>";

    $blocks = [];

    if ($fields['functions'] !== '') {
        $blocks[] = "<br><b>Обязанности:</b><br>" . nl2br(h($fields['functions'])) . "<br>";
    }
    if ($fields['speciality'] !== '') {
        $blocks[] = "<br><b>Специальность:</b><br>" . nl2br(h($fields['speciality'])) . "<br>";
    }
    if ($fields['experience'] !== '') {
        $blocks[] = "<br><b>Опыт:</b><br>" . nl2br(h($fields['experience'])) . "<br>";
    }
    if ($fields['softskills'] !== '') {
        $blocks[] = "<br><b>Деловые качества:</b><br>" . nl2br(h($fields['softskills'])) . "<br>";
    }
    if ($fields['software_skills'] !== '') {
        $blocks[] = "<br><b>Знание специальных программ:</b><br>" . nl2br(h($fields['software_skills'])) . "<br>";
    }
    if ($fields['extra_requests'] !== '') {
        $blocks[] = "<br><b>Дополнительно:</b><br>" . nl2br(h($fields['extra_requests'])) . "<br>";
    }

    return $header . $subheader . implode('', $blocks);
}

/**
 * Авторизация в FW. Возвращает путь к cookie-файлу.
 */
function fwLoginAndGetCookieFile(string $username, string $password): array
{
    if ($username === '' || $password === '') {
        return [false, 'Не заданы FRIENDWORK_USERNAME / FRIENDWORK_PASSWORD в окружении.', ''];
    }

    $cookieFile = sys_get_temp_dir() . '/fw_cookie_' . md5($username . microtime(true)) . '.txt';
    $loginUrl = FW_LOGIN_ENDPOINT . '?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $loginUrl);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        @unlink($cookieFile);
        return [false, 'Ошибка авторизации FriendWork. HTTP: ' . $httpCode . '; CURL: ' . $curlErr, ''];
    }

    return [true, '', $cookieFile];
}

function fwCreateJob(array $payload, string $cookieFile): array
{
    $ch = curl_init(FW_JOBS_ENDPOINT);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $responseRaw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseRaw === false) {
        return [false, 'Ошибка CURL при создании вакансии: ' . $curlErr, [], $httpCode, ''];
    }

    $response = json_decode($responseRaw, true);
    if (!is_array($response)) {
        $response = [];
    }

    if ($httpCode >= 400) {
        return [false, 'FriendWork вернул HTTP ' . $httpCode . ': ' . $responseRaw, $response, $httpCode, $responseRaw];
    }

    if (empty($response['jobId'])) {
        return [false, 'FriendWork не вернул jobId. Ответ: ' . $responseRaw, $response, $httpCode, $responseRaw];
    }

    return [true, '', $response, $httpCode, $responseRaw];
}

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$requestId = (int)($_REQUEST['id'] ?? 0);
if ($requestId <= 0) {
    ShowError('Не передан корректный id заявки (параметр id).');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$select = [
    'ID',
    'IBLOCK_ID',
    'NAME',
    'PROPERTY_DOLZHNOST',
    'PROPERTY_OBYAZANNOSTI',
    'PROPERTY_ZHELAEMAYA_SPETSIALNOST',
    'PROPERTY_OPYT_RABOTY',
    'PROPERTY_DELOVYE_KACHESTVA',
    'PROPERTY_ZNANIE_SPETSIALNYKH_PROGRAMM',
    'PROPERTY_DOPOLNITELNYE_TREBOVANIYA',
    'PROPERTY_REKRUTER',
    'PROPERTY_ID_FW_VAKANSII',
    'PROPERTY_SSYLKA_NA_VAKANSIYU_FW',
];

$filter = [
    'ID' => $requestId,
    'IBLOCK_ID' => IBLOCK_RECRUITMENT,
];

$rsElement = CIBlockElement::GetList([], $filter, false, false, $select);
$element = $rsElement->GetNext();

if (!$element) {
    ShowError('Заявка не найдена в ИБ 201. ID: ' . h($requestId));
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$recruiterId = (int)$element['PROPERTY_REKRUTER_VALUE'];
$recruiterEmail = '';
$recruiterName = '';
if ($recruiterId > 0) {
    $rsUser = CUser::GetByID($recruiterId);
    if ($user = $rsUser->Fetch()) {
        $recruiterEmail = trim((string)($user['EMAIL'] ?? ''));
        $recruiterName = trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
    }
}

$fields = [
    'name' => normalizeText($element['PROPERTY_DOLZHNOST_VALUE'] ?? ''),
    'functions' => normalizeText($element['PROPERTY_OBYAZANNOSTI_VALUE'] ?? ''),
    'speciality' => normalizeText($element['PROPERTY_ZHELAEMAYA_SPETSIALNOST_VALUE'] ?? ''),
    'experience' => normalizeText($element['PROPERTY_OPYT_RABOTY_VALUE'] ?? ''),
    'softskills' => normalizeText($element['PROPERTY_DELOVYE_KACHESTVA_VALUE'] ?? ''),
    'software_skills' => normalizeText($element['PROPERTY_ZNANIE_SPETSIALNYKH_PROGRAMM_VALUE'] ?? ''),
    'extra_requests' => normalizeText($element['PROPERTY_DOPOLNITELNYE_TREBOVANIYA_VALUE'] ?? ''),
];

$description = buildDescription($fields);
$comment = 'Создано из заявки на подбор ' . $requestId;

$payload = [
    'ClientId' => FW_CLIENT_ID,
    'Status' => FW_STATUS_DRAFT,
    'IsDraft' => 1,
    'PositionCount' => FW_POSITION_COUNT,
    'Name' => $fields['name'],
    'Description' => $description,
    'Comment' => $comment,
    'ResponsibleId' => $recruiterEmail,
];

$existingFwId = trim((string)($element['PROPERTY_ID_FW_VAKANSII_VALUE'] ?? ''));
$existingFwUrl = trim((string)($element['PROPERTY_SSYLKA_NA_VAKANSIYU_FW_VALUE'] ?? ''));
$alreadyCreated = ($existingFwId !== '');

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && ($_POST['action'] ?? '') === 'submit_to_fw') {
    if ($alreadyCreated) {
        $errors[] = 'По этой заявке уже создана вакансия в FriendWork (ID: ' . h($existingFwId) . '). Повторное создание недоступно.';
    }

    if ($payload['Name'] === '') {
        $errors[] = 'Не заполнено поле DOLZHNOST (название должности) — невозможно сформировать Name.';
    }
    if ($payload['ResponsibleId'] === '') {
        $errors[] = 'Не удалось определить e-mail рекрутера (поле REKRUTER).';
    }

    if (!$errors) {
        [$loginOk, $loginError, $cookieFile] = fwLoginAndGetCookieFile(fwGetCredentials()['username'], fwGetCredentials()['password']);
        if (!$loginOk) {
            $errors[] = $loginError;
        } else {
            [$createOk, $createError, $fwResponse] = fwCreateJob($payload, $cookieFile);
            @unlink($cookieFile);

            if (!$createOk) {
                $errors[] = $createError;
            } else {
                $jobId = (int)$fwResponse['jobId'];
                $jobUrl = FW_JOB_EDIT_URL . $jobId;

                CIBlockElement::SetPropertyValuesEx(
                    $requestId,
                    IBLOCK_RECRUITMENT,
                    [
                        'ID_FW_VAKANSII' => $jobId,
                        'SSYLKA_NA_VAKANSIYU_FW' => $jobUrl,
                    ]
                );

                $existingFwId = (string)$jobId;
                $existingFwUrl = $jobUrl;
                $alreadyCreated = true;
                $success = 'Вакансия успешно создана в FriendWork. ID: ' . h($jobId);
            }
        }
    }
}
?>

<style>
    .fw-card { max-width: 1100px; margin: 18px auto; padding: 18px; border: 1px solid #dfe3eb; border-radius: 10px; background: #fff; }
    .fw-row { margin-bottom: 12px; }
    .fw-label { font-weight: 600; color: #1f3b61; margin-bottom: 4px; }
    .fw-value { background: #f7f9fc; border: 1px solid #e6ebf3; border-radius: 8px; padding: 10px 12px; white-space: pre-wrap; word-break: break-word; }
    .fw-error { background: #fff1f0; color: #a8071a; border: 1px solid #ffa39e; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
    .fw-success { background: #f6ffed; color: #135200; border: 1px solid #b7eb8f; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
    .fw-muted { color: #5c6b80; font-size: 13px; }
    .fw-html-box { background: #fcfdff; border: 1px solid #e6ebf3; border-radius: 8px; padding: 12px; }
    .fw-actions { margin-top: 16px; display: flex; gap: 12px; align-items: center; }
    .fw-btn { cursor: pointer; border: 0; border-radius: 8px; background: #2f7fd8; color: #fff; padding: 10px 16px; font-weight: 600; }
    .fw-btn[disabled] { background: #9db7d7; cursor: not-allowed; }
</style>

<div class="fw-card">
    <h2 style="margin-top:0;">Интеграция заявки #<?= h($requestId) ?> в FriendWork</h2>

    <?php foreach ($errors as $error): ?>
        <div class="fw-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($success !== ''): ?>
        <div class="fw-success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($alreadyCreated): ?>
        <div class="fw-success">
            По данной заявке уже создана вакансия в FriendWork.<br>
            ID_FW_VAKANSII: <b><?= h($existingFwId) ?></b><br>
            <?php if ($existingFwUrl !== ''): ?>
                SSYLKA_NA_VAKANSIYU_FW: <a href="<?= h($existingFwUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($existingFwUrl) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="fw-row">
        <div class="fw-label">Название вакансии (Name)</div>
        <div class="fw-value"><?= h($payload['Name']) ?></div>
    </div>

    <div class="fw-row">
        <div class="fw-label">Ответственный рекрутер (ResponsibleId / e-mail)</div>
        <div class="fw-value"><?= h($payload['ResponsibleId']) ?></div>
        <div class="fw-muted">Пользователь: <?= h($recruiterName !== '' ? $recruiterName : ('ID ' . $recruiterId)) ?></div>
    </div>

    <div class="fw-row">
        <div class="fw-label">Комментарий (Comment)</div>
        <div class="fw-value"><?= h($payload['Comment']) ?></div>
    </div>

    <div class="fw-row">
        <div class="fw-label">Описание вакансии (Description) — HTML preview</div>
        <div class="fw-html-box"><?= $payload['Description'] ?></div>
    </div>

    <div class="fw-row">
        <div class="fw-label">JSON, который будет отправлен в FriendWork</div>
        <div class="fw-value"><?= h(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></div>
    </div>

    <form method="post" class="fw-actions">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="submit_to_fw">
        <button class="fw-btn" type="submit" <?= $alreadyCreated ? 'disabled' : '' ?>>Отправить в FriendWork</button>
        <span class="fw-muted">Редактирование полей на этой странице не предусмотрено.</span>
    </form>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
