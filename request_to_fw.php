<?php
/**
 * /forms/staff_recruitment/staffing/request_to_fw.php
 *
 * Интеграция заявки на подбор (ИБ 201) -> FriendWork.
 * Версия: v1.0.0 (2026-03-27)
 *
 * Логика:
 * 1) GET id=ID_заявки: показываем данные, которые будут отправлены в FriendWork.
 * 2) Если в заявке уже заполнен ID_FW_VAKANSII, повторное создание запрещено.
 * 3) POST submit_to_fw: создаём вакансию в FriendWork и записываем:
 *    - ID_FW_VAKANSII
 *    - SSYLKA_NA_VAKANSIYU_FW
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Application;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

global $APPLICATION;
$APPLICATION->SetTitle('Отправка заявки в FriendWork');

const IBLOCK_RECRUITMENT = 201;
const FW_CLIENT_ID = 5322;
const FW_STATUS_DRAFT = 5;
const FW_POSITION_COUNT = 1;
const FW_LOGIN_ENDPOINT = 'https://app.friend.work/api/Accounts/LogIn';
const FW_JOBS_ENDPOINT = 'https://app.friend.work/api/Jobs';
const FW_ACCOUNTS_ENDPOINT = 'https://app.friend.work/api/Accounts';
const FW_JOB_EDIT_URL = 'https://app.friend.work/Job/Edit/';

/**
 * Достаём учётные данные FW из глобальных констант БП (b_bp_global_const).
 * Логин  - Constant1698403240866
 * Пароль - Constant1698403290839
 */
function fwGetCredentials()
{
    $result = [
        'username' => '',
        'password' => '',
        'error' => '',
    ];

    try {
        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();

        $ids = [
            'Constant1698403240866',
            'Constant1698403290839',
        ];

        $escapedIds = array_map([$sqlHelper, 'forSql'], $ids);
        $in = "'" . implode("','", $escapedIds) . "'";

        $rows = [];
        $rs = $connection->query("
            SELECT ID, PROPERTY_VALUE
            FROM b_bp_global_const
            WHERE ID IN (" . $in . ")
        ");

        while ($row = $rs->fetch()) {
            $rows[$row['ID']] = (string)valueOr($row, 'PROPERTY_VALUE', '');
        }

        $usernameRaw = valueOr($rows, 'Constant1698403240866', '');
        $passwordRaw = valueOr($rows, 'Constant1698403290839', '');

        // Значение констант в БП часто хранится сериализованным массивом вида ['value' => '...'].
        $decodeValue = static function (string $raw): string {
            $raw = trim($raw);
            if ($raw === '') {
                return '';
            }

            // 1) Пробуем как есть (массив/строка после serialize()).
            $unserialized = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                if (isset($unserialized['value'])) {
                    return trim((string)$unserialized['value']);
                }
                if (isset($unserialized[0])) {
                    return trim((string)$unserialized[0]);
                }
            }
            if (is_string($unserialized)) {
                return trim($unserialized);
            }

            // 2) Частый кейс в таблице: экранированная сериализованная строка,
            // например: s:27:"test@tricolor.tv";
            $unescaped = stripcslashes($raw);
            if ($unescaped !== $raw) {
                $unserialized = @unserialize($unescaped, ['allowed_classes' => false]);
                if (is_string($unserialized)) {
                    return trim($unserialized);
                }
            }

            // 3) Fallback для "s:<len>:"value";" без успешного unserialize.
            if (preg_match('/^s:\d+:"(.*)";$/s', $unescaped, $m)) {
                return trim((string)$m[1]);
            }
            if (preg_match('/^s:\d+:"(.*)";$/s', $raw, $m)) {
                return trim((string)$m[1]);
            }

            return trim($raw);
        };

        $result['username'] = $decodeValue($usernameRaw);
        $result['password'] = $decodeValue($passwordRaw);

        if ($result['username'] === '' || $result['password'] === '') {
            $result['error'] = 'Не удалось получить логин/пароль FriendWork из b_bp_global_const (Constant1698403240866 / Constant1698403290839).';
        }
    } catch (\Throwable $e) {
        $result['error'] = 'Ошибка получения констант FriendWork из b_bp_global_const: ' . $e->getMessage();
    }

    return $result;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valueOr($array, $key, $default = '')
{
    return (is_array($array) && isset($array[$key])) ? $array[$key] : $default;
}




function fwLog($message, $data = null)
{
    if (is_array($data) || is_object($data)) {
        $data = print_r($data, true);
    }
    if ($data !== null) {
        $message .= ' | ' . (string)$data;
    }
    error_log('[request_to_fw] ' . $message);
}

function normalizeText($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return $value;
}





function normalizeEmail($email)
{
    $email = mb_strtolower(trim((string)$email));
    $email = preg_replace('/\s+/', '', $email);
    return $email;
}

function emailLocalPart($email)
{
    $email = normalizeEmail($email);
    $pos = strpos($email, '@');
    if ($pos === false) {
        return $email;
    }
    return (string)substr($email, 0, $pos);
}

function buildDescription($fields)
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
 * Авторизация в FW. Возвращает путь к cookie-файлу и отладочные данные.
 */
function fwLoginAndGetCookieFile($username, $password)
{
    if ($username === '' || $password === '') {
        return [false, 'Не заданы логин/пароль FriendWork.', '', ['username' => $username, 'password' => $password]];
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
        fwLog('Ошибка авторизации FriendWork', ['httpCode' => $httpCode, 'curlError' => $curlErr, 'response' => $response]);
        return [false, 'Ошибка авторизации FriendWork. HTTP: ' . $httpCode . '; CURL: ' . $curlErr, '', ['username' => $username, 'password' => $password, 'loginUrl' => $loginUrl, 'httpCode' => $httpCode, 'curlError' => $curlErr, 'response' => $response]];
    }

    return [true, '', $cookieFile, ['username' => $username, 'password' => $password, 'loginUrl' => $loginUrl, 'httpCode' => $httpCode, 'curlError' => $curlErr, 'response' => $response]];
}

function fwCreateJob($payload, $cookieFile)
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
        fwLog('Ошибка CURL при создании вакансии', ['httpCode' => $httpCode, 'curlError' => $curlErr]);
        return [false, 'Ошибка CURL при создании вакансии: ' . $curlErr, [], $httpCode, ''];
    }

    $response = json_decode($responseRaw, true);
    if (!is_array($response)) {
        $response = [];
    }

    if ($httpCode >= 400) {
        fwLog('FriendWork Jobs HTTP error', ['httpCode' => $httpCode, 'response' => $responseRaw]);
        return [false, 'FriendWork вернул HTTP ' . $httpCode . ': ' . $responseRaw, $response, $httpCode, $responseRaw];
    }

    if (empty($response['jobId'])) {
        fwLog('FriendWork Jobs missing jobId', ['httpCode' => $httpCode, 'response' => $responseRaw]);
        return [false, 'FriendWork не вернул jobId. Ответ: ' . $responseRaw, $response, $httpCode, $responseRaw];
    }

    return [true, '', $response, $httpCode, $responseRaw];
}

function fwGetAccounts($cookieFile)
{
    $ch = curl_init(FW_ACCOUNTS_ENDPOINT);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $responseRaw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseRaw === false) {
        fwLog('Ошибка CURL при получении аккаунтов FriendWork', ['httpCode' => $httpCode, 'curlError' => $curlErr]);
        return [false, 'Ошибка CURL при получении аккаунтов FriendWork: ' . $curlErr, [], $httpCode, ''];
    }

    $response = json_decode($responseRaw, true);
    if (!is_array($response)) {
        $response = [];
    }

    if ($httpCode >= 400) {
        fwLog('FriendWork Accounts HTTP error', ['httpCode' => $httpCode, 'response' => $responseRaw]);
        return [false, 'FriendWork /api/Accounts вернул HTTP ' . $httpCode . ': ' . $responseRaw, $response, $httpCode, $responseRaw];
    }

    return [true, '', $response, $httpCode, $responseRaw];
}

function findUserRunningTaskForDocument($iblockId, $elementId, $userId)
{
    $candidates = [
        ['lists',  'BizprocDocument', "lists_{$iblockId}_{$elementId}"],
        ['iblock', 'CIBlockDocument', "iblock_{$iblockId}_{$elementId}"],
        ['lists',  'Bitrix\\Lists\\BizprocDocumentLists', $elementId],
    ];

    foreach ($candidates as $docId) {
        try {
            $rs = CBPTaskService::GetList(
                ['ID' => 'ASC'],
                ['DOCUMENT_ID' => $docId, 'STATUS' => CBPTaskStatus::Running, 'USER_ID' => $userId],
                false,
                false,
                ['ID', 'NAME', 'WORKFLOW_ID', 'USER_ID', 'ACTIVITY', 'ACTIVITY_NAME']
            );
            if ($task = $rs->GetNext()) {
                return [
                    'ID' => (int)$task['ID'],
                    'NAME' => (string)$task['NAME'],
                    'WORKFLOW_ID' => (string)$task['WORKFLOW_ID'],
                    'ACTIVITY_NAME' => (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''),
                    'DOCUMENT_ID' => $docId,
                ];
            }
        } catch (\Throwable $e) {
        }
    }

    return null;
}

function taskIsRunning($taskId)
{
    $taskId = (int)$taskId;
    if ($taskId <= 0) {
        return false;
    }

    try {
        $rs = CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['ID' => $taskId, 'STATUS' => CBPTaskStatus::Running],
            false,
            false,
            ['ID']
        );
        return (bool)$rs->GetNext();
    } catch (\Throwable $e) {
        return false;
    }
}

function getRunningTaskByIdForUser($taskId, $userId)
{
    $taskId = (int)$taskId;
    $userId = (int)$userId;
    if ($taskId <= 0 || $userId <= 0) {
        return null;
    }

    try {
        $rs = CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['ID' => $taskId, 'STATUS' => CBPTaskStatus::Running, 'USER_ID' => $userId],
            false,
            false,
            ['ID', 'NAME', 'WORKFLOW_ID', 'USER_ID', 'ACTIVITY', 'ACTIVITY_NAME']
        );
        $task = $rs->GetNext();
        if (!$task) {
            return null;
        }

        return [
            'ID' => (int)$task['ID'],
            'NAME' => (string)$task['NAME'],
            'WORKFLOW_ID' => (string)$task['WORKFLOW_ID'],
            'ACTIVITY_NAME' => (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''),
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

function flattenBizprocErrors(array $errors)
{
    $parts = [];
    foreach ($errors as $e) {
        if (is_array($e)) {
            $msg = trim((string)($e['message'] ?? $e['MESSAGE'] ?? ''));
            $code = trim((string)($e['code'] ?? $e['CODE'] ?? ''));
            if ($msg !== '' && $code !== '') {
                $parts[] = $code . ': ' . $msg;
            } elseif ($msg !== '') {
                $parts[] = $msg;
            } elseif ($code !== '') {
                $parts[] = $code;
            }
        } else {
            $s = trim((string)$e);
            if ($s !== '') {
                $parts[] = $s;
            }
        }
    }

    return implode('; ', array_values(array_unique($parts)));
}

function completeBizprocTask(array $task, $userId, $action = 'approve', $comment = '')
{
    $taskId = (int)($task['ID'] ?? 0);
    $userId = (int)$userId;
    if ($taskId <= 0) {
        return ['OK' => false, 'ERROR' => 'Некорректный ID задачи БП.'];
    }

    $errors = [];
    $aliases = [
        'yes' => 'approve',
        'ok' => 'approve',
        'no' => 'nonapprove',
        'cancel' => 'nonapprove',
        'reject' => 'nonapprove',
        'decline' => 'nonapprove',
    ];
    $code = strtolower(trim((string)$action));
    if ($code === '') {
        $code = 'approve';
    }
    if (isset($aliases[$code])) {
        $code = $aliases[$code];
    }

    try {
        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $fields1 = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
                'ACTION' => $code,
                $code => 'Y',
            ];
            $tmpErr = [];
            CBPDocument::PostTaskForm($taskId, $userId, $fields1, $tmpErr);
            if (!empty($tmpErr)) {
                $errors = array_merge($errors, $tmpErr);
            }
            if (!taskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    try {
        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $fields2 = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
                $code => 'Y',
            ];
            $tmpErr2 = [];
            CBPDocument::PostTaskForm($taskId, $userId, $fields2, $tmpErr2);
            if (!empty($tmpErr2)) {
                $errors = array_merge($errors, $tmpErr2);
            }
            if (!taskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    try {
        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        $activity = (string)($task['ACTIVITY_NAME'] ?? $task['NAME'] ?? '');
        if ($workflowId !== '' && $activity !== '' && method_exists('CBPDocument', 'SendExternalEvent')) {
            $isYes = in_array($code, ['approve', 'accepted', 'accept', 'ok', 'yes', 'y', 'agree'], true);
            $isNo = in_array($code, ['cancel', 'rejected', 'reject', 'no', 'n', 'disagree', 'decline', 'deny', 'refuse', 'nonapprove'], true);
            $payloads = [];

            if ($isYes || $isNo) {
                $appr = $isYes ? 'Y' : 'N';
                $payloads[] = ['APPROVE' => $appr, 'COMMENT' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId];
                $payloads[] = ['RESULT' => $appr, 'COMMENT' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId];
            } else {
                $payloads[] = ['COMMENT' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId];
            }

            foreach ($payloads as $payload) {
                $extErr = [];
                CBPDocument::SendExternalEvent($workflowId, $activity, $payload, $extErr);
                if (!empty($extErr)) {
                    $errors = array_merge($errors, $extErr);
                }
                if (!taskIsRunning($taskId)) {
                    return ['OK' => true, 'ERROR' => ''];
                }
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    try {
        if (method_exists('CBPTaskService', 'DoTask')) {
            CBPTaskService::DoTask($taskId, $userId, [
                'ACTION' => $code,
                $code => 'Y',
                'COMMENT' => $comment,
            ]);
            if (!taskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    $flat = flattenBizprocErrors($errors);
    if ($flat === '') {
        $flat = 'Задание осталось активным после всех попыток завершения.';
    }

    return ['OK' => false, 'ERROR' => $flat];
}


if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
if (!Loader::includeModule('bizproc')) {
    ShowError('Не удалось подключить модуль bizproc.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
if (!Loader::includeModule('lists')) {
    ShowError('Не удалось подключить модуль lists.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$requestId = (int)valueOr($_REQUEST, 'id', 0);
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
        $recruiterEmail = trim((string)valueOr($user, 'EMAIL', ''));
        $recruiterName = trim((string)valueOr($user, 'NAME', '') . ' ' . (string)valueOr($user, 'LAST_NAME', ''));
    }
}


$fields = [
    'name' => normalizeText(valueOr($element, 'PROPERTY_DOLZHNOST_VALUE', '')),
    'functions' => normalizeText(valueOr($element, 'PROPERTY_OBYAZANNOSTI_VALUE', '')),
    'speciality' => normalizeText(valueOr($element, 'PROPERTY_ZHELAEMAYA_SPETSIALNOST_VALUE', '')),
    'experience' => normalizeText(valueOr($element, 'PROPERTY_OPYT_RABOTY_VALUE', '')),
    'softskills' => normalizeText(valueOr($element, 'PROPERTY_DELOVYE_KACHESTVA_VALUE', '')),
    'software_skills' => normalizeText(valueOr($element, 'PROPERTY_ZNANIE_SPETSIALNYKH_PROGRAMM_VALUE', '')),
    'extra_requests' => normalizeText(valueOr($element, 'PROPERTY_DOPOLNITELNYE_TREBOVANIYA_VALUE', '')),
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
    'ResponsibleId' => 0,
];

$existingFwId = trim((string)valueOr($element, 'PROPERTY_ID_FW_VAKANSII_VALUE', ''));
$existingFwUrl = trim((string)valueOr($element, 'PROPERTY_SSYLKA_NA_VAKANSIYU_FW_VALUE', ''));
$alreadyCreated = ($existingFwId !== '');

$errors = [];
$warnings = [];
$success = '';
$debugInfo = [];
$bizprocCompletion = null;
$currentUserId = (int)$GLOBALS['USER']->GetID();
$task = null;
if ($currentUserId > 0) {
    $task = findUserRunningTaskForDocument(IBLOCK_RECRUITMENT, $requestId, $currentUserId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && valueOr($_POST, 'action', '') === 'submit_to_fw') {
    if ($alreadyCreated) {
        $errors[] = 'По этой заявке уже создана вакансия в FriendWork (ID: ' . h($existingFwId) . '). Повторное создание недоступно.';
    }

    if ($payload['Name'] === '') {
        $errors[] = 'Не заполнено поле DOLZHNOST (название должности) — невозможно сформировать Name.';
    }

    if ($recruiterEmail === '') {
        $errors[] = 'Не удалось определить e-mail рекрутера (поле REKRUTER).';
    }

    if (!$errors) {
        $fwCredentials = fwGetCredentials();
        if ($fwCredentials['error'] !== '') {
            $errors[] = $fwCredentials['error'];
            $debugInfo['credentials'] = $fwCredentials;
        } else {
            list($loginOk, $loginError, $cookieFile, $loginDebug) = fwLoginAndGetCookieFile(
                $fwCredentials['username'],
                $fwCredentials['password']
            );
            $debugInfo['login'] = $loginDebug;

            if (!$loginOk) {
                $errors[] = $loginError;
            } else {
                list($accountsOk, $accountsError, $accountsResponse, $accountsHttpCode, $accountsRaw) = fwGetAccounts($cookieFile);
                $debugInfo['accounts'] = [
                    'httpCode' => $accountsHttpCode,
                    'rawResponse' => $accountsRaw,
                    'parsedResponse' => $accountsResponse,
                ];

                if (!$accountsOk) {
                    @unlink($cookieFile);
                    $errors[] = $accountsError;
                } else {
                    $emailLower = normalizeEmail($recruiterEmail);
                    $emailLocalPart = emailLocalPart($recruiterEmail);

                    $resolvedResponsibleId = 0;

                    $accountsList = $accountsResponse;
                    $debugInfo['responsibleLookup'] = array('recruiterEmailRaw' => $recruiterEmail);
                    if (isset($accountsResponse['items']) && is_array($accountsResponse['items'])) {
                        $accountsList = $accountsResponse['items'];
                    } elseif (isset($accountsResponse['data']) && is_array($accountsResponse['data'])) {
                        $accountsList = $accountsResponse['data'];
                    } elseif (isset($accountsResponse['accounts']) && is_array($accountsResponse['accounts'])) {
                        $accountsList = $accountsResponse['accounts'];
                    }

                    $debugInfo['responsibleLookup']['accountsCount'] = is_array($accountsList) ? count($accountsList) : 0;
                    foreach ($accountsList as $account) {
                        if (!is_array($account)) {
                            continue;
                        }

                        $accountEmailRaw = (string)(
                            isset($account['userName']) ? $account['userName'] : (
                            isset($account['username']) ? $account['username'] : (
                            isset($account['UserName']) ? $account['UserName'] : (
                            isset($account['USERNAME']) ? $account['USERNAME'] : (
                            isset($account['email']) ? $account['email'] : (
                            isset($account['EMAIL']) ? $account['EMAIL'] : (
                            isset($account['mail']) ? $account['mail'] : ''))))))
                        );
                        $accountEmail = normalizeEmail($accountEmailRaw);
                        $accountId = (int)(
                            isset($account['accountId']) ? $account['accountId'] : (
                            isset($account['accountID']) ? $account['accountID'] : (
                            isset($account['AccountId']) ? $account['AccountId'] : (
                            isset($account['id']) ? $account['id'] : (
                            isset($account['ID']) ? $account['ID'] : 0))))
                        );

                        $accountLocalPart = emailLocalPart($accountEmail);

                        $isDirectEmailMatch = ($accountEmail !== '' && $accountEmail === $emailLower);
                        $isLocalPartMatch = ($accountLocalPart !== '' && $accountLocalPart === $emailLocalPart);

                        if (($isDirectEmailMatch || $isLocalPartMatch) && $accountId > 0 && $resolvedResponsibleId <= 0) {
                            $resolvedResponsibleId = $accountId;
                        }
                    }

                    $debugInfo['responsibleLookup']['recruiterEmailNormalized'] = $emailLower;
                    $debugInfo['responsibleLookup']['recruiterLocalPart'] = $emailLocalPart;
                    $debugInfo['responsibleLookup']['resolvedResponsibleId'] = $resolvedResponsibleId;

                    if ($resolvedResponsibleId <= 0) {
                        @unlink($cookieFile);
                        $errors[] = 'Не найден аккаунт FriendWork для e-mail рекрутера: ' . h($recruiterEmail);
                    } else {
                        $payload['ResponsibleId'] = $resolvedResponsibleId;

                        list($createOk, $createError, $fwResponse, $createHttpCode, $createRaw) = fwCreateJob($payload, $cookieFile);
                        @unlink($cookieFile);
                        $debugInfo['create'] = [
                            'httpCode' => $createHttpCode,
                            'rawResponse' => $createRaw,
                            'parsedResponse' => $fwResponse,
                        ];

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
                            $success = 'Вакансия успешно создана в FriendWork. ID: ' . h($jobId) . '.';

                            $taskId = (int)($task['ID'] ?? 0);
                            if ($taskId <= 0) {
                                $warnings[] = 'Вакансия создана, но не удалось определить текущее задание БП для автозавершения.';
                            } else {
                                $actualTask = getRunningTaskByIdForUser($taskId, $currentUserId);
                                if (!$actualTask) {
                                    $warnings[] = 'Вакансия создана, но текущее задание БП не найдено среди активных задач пользователя.';
                                } else {
                                    $bizprocCompletion = completeBizprocTask(
                                        $actualTask,
                                        $currentUserId,
                                        'approve',
                                        'Заявка успешно отправлена в FriendWork. Вакансия #' . $jobId
                                    );
                                    if (!empty($bizprocCompletion['OK'])) {
                                        $success = 'Вакансия успешно создана в FriendWork (ID: ' . h($jobId) . '), задание БП успешно завершено.';
                                    } else {
                                        $warnings[] = 'Вакансия создана (ID: ' . h($jobId) . '), но завершить задание БП не удалось: ' . (string)($bizprocCompletion['ERROR'] ?? 'неизвестная ошибка');
                                    }
                                }
                            }
                        }
                    }
                }
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
    <?php foreach ($warnings as $warning): ?>
        <div class="fw-muted" style="margin-bottom: 10px; color:#ad6800;"><?= h($warning) ?></div>
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
        <div class="fw-label">Ответственный рекрутер</div>
        <div class="fw-value">FW ResponsibleId: <?= h($payload['ResponsibleId']) ?></div>
        <div class="fw-muted">E-mail рекрутера (из REKRUTER): <?= h($recruiterEmail) ?></div>
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


    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="fw-row">
            <div class="fw-label">Диагностика FriendWork (логин/пароль и ответы API)</div>
            <div class="fw-value"><?php
                $diagnostic = [
                    'credentials' => [
                        'username' => valueOr($fwCredentials, 'username', ''),
                        'password' => valueOr($fwCredentials, 'password', ''),
                    ],
                    'login' => valueOr($debugInfo, 'login', null),
                    'accounts' => valueOr($debugInfo, 'accounts', null),
                    'create' => valueOr($debugInfo, 'create', null),
                    'responsible' => ['email' => $recruiterEmail, 'fwResponsibleId' => $payload['ResponsibleId']],
                    'responsibleLookup' => valueOr($debugInfo, 'responsibleLookup', null),
                ];
                echo h(json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            ?></div>
        </div>
    <?php endif; ?>

    <form method="post" class="fw-actions">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="submit_to_fw">
        <button class="fw-btn" type="submit" <?= $alreadyCreated ? 'disabled' : '' ?>>Отправить в FriendWork</button>
        <span class="fw-muted">Редактирование полей на этой странице не предусмотрено.</span>
    </form>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
