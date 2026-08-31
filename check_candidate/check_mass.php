<?php
$checkMassRetryRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['retry_link_element_id']);
require $_SERVER['DOCUMENT_ROOT'] . ($checkMassRetryRequest
    ? '/bitrix/modules/main/include/prolog_before.php'
    : '/bitrix/header.php');
if (!$checkMassRetryRequest) {
    while (ob_get_level()) ob_end_flush();
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', 0);
    header('X-Accel-Buffering: no');
    flush();
}

use Bitrix\Main\Loader;
if (
    !Loader::includeModule("iblock") ||
    !Loader::includeModule("lists") ||
    !Loader::includeModule("bizproc")
) {
    die("Не удалось подключить модули (iblock, lists, bizproc).");
}

if (!$checkMassRetryRequest) {
    echo "<div style='font-size:11px;color:#777'>check_mass.php v4.2 (Workflow retry)</div>";
}

/* ================================================================
    CONFIG
   ================================================================ */
// INTERNAL FRIENDWORK API (OLD — to get candidates)
const FW_USER_INTERNAL = 'user';
const FW_PASS_INTERNAL = 'pass';
const FW_API_INTERNAL  = 'https://app.friend.work/api';

// EXTERNAL FRIENDWORK API (NEW — to get recruiters, update statuses)
const FW_TOKEN_EXTERNAL = 'token';
const FW_API_EXTERNAL   = 'https://api.friend.work';

// ↓↓↓ БЕЛЫЙ СПИСОК ID РЕКРУТЕРОВ ↓↓↓
const ALLOWED_RECRUITER_IDS = [5790,4797,5749,5856,5755,6049,5748,6258,5500,5931,5968,5558,5725,6163]; // ← ← ← ЗАМЕНИТЕ НА РЕАЛЬНЫЕ ID

// FriendWork accepts candidates page by page. A larger page dramatically cuts
// the number of remote calls (and therefore the chance of an nginx 502).
const FW_CANDIDATES_PER_PAGE = 100;
const FW_REQUESTS_PER_MINUTE = 20;
const FW_MAX_RETRIES = 3;
// At most two API pages per PHP request keeps its worst-case duration below
// the reverse proxy timeout. The browser continues with the next batch.
const FW_PAGES_PER_REQUEST = 2;
const FW_CONNECT_TIMEOUT = 10;
const FW_REQUEST_TIMEOUT = 15;
const FW_CACHE_TTL = 7200;

const CHECK_MASS_LOG_NAME = 'check_mass.log';
const CANDIDATE_IBLOCK_ID = 207;
const CANDIDATE_WORKFLOW_TEMPLATE_ID = 466;
const LINK_WORKFLOW_TEMPLATE_ID = 328;

$tmpCookie = __DIR__ . '/fw_cookie.txt';

function checkMassLog($message, array $context = [])
{
    $logDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload/logs';
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        error_log('check_mass: cannot create log directory ' . $logDir);
        return;
    }

    $record = [
        'time' => date('c'),
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false || @file_put_contents($logDir . '/' . CHECK_MASS_LOG_NAME, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log('check_mass: cannot write log file');
    }
}

function checkMassCachePath($token)
{
    $cacheDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload/check_mass_cache';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
        return null;
    }
    return $cacheDir . '/' . preg_replace('/[^a-f0-9]/', '', (string)$token) . '.json';
}

function checkMassReadState($token)
{
    $path = checkMassCachePath($token);
    if (!$path || !is_file($path) || filemtime($path) < time() - FW_CACHE_TTL) {
        return null;
    }
    $state = json_decode((string)file_get_contents($path), true);
    return is_array($state) ? $state : null;
}

function checkMassWriteState($token, array $state)
{
    $path = checkMassCachePath($token);
    if (!$path) return false;
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, $path);
}

function checkMassContinueUrl($token)
{
    $params = $_GET;
    $params['load_token'] = $token;
    return '?' . http_build_query($params);
}

function checkMassShowContinuation($message, $token, $delaySeconds = 1)
{
    $url = checkMassContinueUrl($token);
    $htmlUrl = htmlspecialchars($url, ENT_QUOTES);
    $jsUrl = json_encode($url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $delayMs = max(1, (int)$delaySeconds) * 1000;
    echo '<h3>' . htmlspecialchars($message) . '</h3>';
    echo '<p>Страница обновится автоматически. Не закрывайте вкладку.</p>';
    echo '<script>setTimeout(function(){ window.location.replace(' . $jsUrl . '); }, ' . $delayMs . ');</script>';
    echo '<noscript><a href="' . $htmlUrl . '">Продолжить загрузку</a></noscript>';
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/**
 * Starts a workflow for an element of a universal-list iblock.
 *
 * BizprocDocument is an old/incorrect provider for list elements.  With it a
 * workflow can return an id but is not attached to the list document and does
 * not appear in that document's workflow log.
 */
function checkMassStartListWorkflow($templateId, $elementId, array &$errors)
{
    $errors = [];
    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId];
    $workflowId = CBPDocument::StartWorkflow((int)$templateId, $documentId, [], $errors);

    checkMassLog($workflowId === false ? 'Workflow start failed' : 'Workflow started', [
        'template_id' => (int)$templateId,
        'element_id' => (int)$elementId,
        'document_id' => $documentId,
        'workflow_id' => $workflowId,
        'errors' => $errors,
    ]);

    return $workflowId;
}

function checkMassIsValidCandidateLink($link)
{
    $parts = parse_url(trim((string)$link));
    return is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'trcol.ru'
        && trim((string)($parts['path'] ?? ''), '/') !== '';
}

function checkMassWorkflowErrorsText(array $errors)
{
    $messages = [];
    foreach ($errors as $error) {
        $messages[] = is_array($error)
            ? (string)($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE))
            : (string)$error;
    }
    return implode('; ', array_filter($messages));
}

function checkMassReadGeneratedLink($elementId)
{
    $result = ['link' => '', 'password' => ''];
    $props = CIBlockElement::GetProperty(CANDIDATE_IBLOCK_ID, (int)$elementId);
    while ($property = $props->Fetch()) {
        if ($property['CODE'] === 'SSYLKA_NA_ANKETU') $result['link'] = (string)$property['VALUE'];
        if ($property['CODE'] === 'PAROL_ANKETY') $result['password'] = (string)$property['VALUE'];
    }
    return $result;
}

if ($checkMassRetryRequest) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    global $USER;
    $elementId = (int)$_POST['retry_link_element_id'];
    $element = $elementId > 0
        ? CIBlockElement::GetList([], [
            'IBLOCK_ID' => CANDIDATE_IBLOCK_ID,
            'ID' => $elementId,
        ], false, ['nTopCount' => 1], ['ID'])->Fetch()
        : false;

    if (!$USER || !$USER->IsAuthorized() || !check_bitrix_sessid() || !$element) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав или анкета не найдена.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $errors = [];
    $workflowId = checkMassStartListWorkflow(LINK_WORKFLOW_TEMPLATE_ID, $elementId, $errors);
    if ($workflowId === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось запустить процесс ID 328: ' . checkMassWorkflowErrorsText($errors),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $generated = ['link' => '', 'password' => ''];
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $generated = checkMassReadGeneratedLink($elementId);
        if (checkMassIsValidCandidateLink($generated['link'])) break;
        usleep(500000);
    }
    $valid = checkMassIsValidCandidateLink($generated['link']);
    if (!$valid) {
        checkMassLog('Candidate link retry returned invalid link', [
            'element_id' => $elementId,
            'workflow_id' => $workflowId,
            'link' => $generated['link'],
        ]);
    }
    echo json_encode([
        'success' => $valid,
        'workflow_id' => $workflowId,
        'link' => $generated['link'],
        'password' => $generated['password'],
        'message' => $valid
            ? 'Ссылка сгенерирована, список обновлён.'
            : 'Процесс ID 328 завершился без корректной ссылки. Попробуйте запустить его ещё раз.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* =====================================================================
    UNIVERSAL HTTP FUNCTION FOR EXTERNAL API
   ===================================================================== */
function fwExternal($method, $url, $payload = null)
{
    $ch = curl_init();
    $headers = [
        "Authorization: Bearer " . FW_TOKEN_EXTERNAL,
        "Content-Type: application/json",
        "Accept: application/json"
    ];
    $opts = [
        CURLOPT_URL            => FW_API_EXTERNAL . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => FW_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => FW_REQUEST_TIMEOUT,
    ];
    if ($method === "POST") {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    if ($method === "PUT") {
        $opts[CURLOPT_CUSTOMREQUEST] = "PUT";
        $opts[CURLOPT_POSTFIELDS]   = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'http' => $code,
        'data' => json_decode($resp, true),
        'raw'  => $resp,
        'err'  => $error
    ];
}

/* =====================================================================
    INTERNAL API AUTHENTICATION
   ===================================================================== */
function fwInternalAuth()
{
    global $tmpCookie;
    $loginUrl =
        FW_API_INTERNAL . "/Accounts/LogIn?username=" .
        urlencode(FW_USER_INTERNAL) .
        "&password=" . urlencode(FW_PASS_INTERNAL);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $loginUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $tmpCookie,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => FW_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => FW_REQUEST_TIMEOUT
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/* =====================================================================
    INTERNAL API REQUEST
   ===================================================================== */
function fwInternal($method, $url, $payload = null)
{
    global $tmpCookie;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => FW_API_INTERNAL . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $tmpCookie,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => FW_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => FW_REQUEST_TIMEOUT
    ];
    if ($method === "POST") {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_HTTPHEADER] = ["Content-Type: application/json"];
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http' => $code,
        'data' => json_decode($resp, true),
        'raw'  => $resp,
        'err'  => $err
    ];
}

/* =====================================================================
    INPUT PARAMETERS
   ===================================================================== */
$jobId      = isset($_REQUEST['job_id']) ? (int)$_REQUEST['job_id'] : 0;
$doProcess  = (!empty($_REQUEST['process']) && $_REQUEST['process'] === 'Y');
$assignMode = $_REQUEST['assign_mode'] ?? 'auto';

/* =====================================================================
    PARSE MANUAL RECRUITER
   ===================================================================== */
function extractUserId($value)
{
    if (is_array($value)) {
        if (isset($value[0])) {
            $value = $value[0];
        } else {
            $keys = array_keys($value);
            if (!empty($keys[0])) {
                $value = $keys[0];
            }
        }
    }
    return (int)preg_replace('/\D+/', '', (string)$value);
}

if (!empty($_REQUEST['manual_recruiter']) && is_array($_REQUEST['manual_recruiter'])) {
    $manualRecruiterRaw = $_REQUEST['manual_recruiter'][0];
} elseif (!empty($_REQUEST['manual_recruiter'])) {
    $manualRecruiterRaw = $_REQUEST['manual_recruiter'];
} else {
    $manualRecruiterRaw = null;
    foreach ($_REQUEST as $k => $v) {
        if (strpos($k, "visual_ius_") === 0) {
            $manualRecruiterRaw = $v;
            break;
        }
    }
}
$manualRecruiter = extractUserId($manualRecruiterRaw);

/* =====================================================================
    FIRST SCREEN: REQUEST JOB ID
   ===================================================================== */
if (!$jobId && !$doProcess) {
    echo "<h2>Загрузка кандидатов FriendWork</h2>";
    echo "ID вакансии высвечивается в url вакансии.<br><br>";
    echo "Например, для вакансии https://app.friend.work/Job/Edit/1134042 id равен 1134042<br><br>";
    echo '
<form method="get" id="fwForm">
    <label>ID вакансии FriendWork:
        <input type="number" name="job_id" required>
    </label><br><br>
    <button type="submit"
            id="fwSubmitBtn"
            style="font-size:16px; padding:6px 14px;">
        Получить список кандидатов
    </button>
    <span id="fwLoading"
          style="display:none; margin-left:10px; font-size:14px; color:#555;">
        ⏳ Загрузка кандидатов, пожалуйста подождите. При количестве кандидатов более 400 загрузка может идти несколько минут
    </span>
</form>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById("fwForm");
    const btn  = document.getElementById("fwSubmitBtn");
    const load = document.getElementById("fwLoading");
    form.addEventListener("submit", function() {
        btn.disabled = true;
        btn.style.opacity = "0.5";
        btn.style.cursor = "not-allowed";
        load.style.display = "inline";
    });
});
</script>
';
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/* =====================================================================
    1) AUTH INTERNAL API
   ===================================================================== */
$loadToken = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['load_token'] ?? ''));
$loadState = $loadToken ? checkMassReadState($loadToken) : null;
if (!$loadState || (int)($loadState['job_id'] ?? 0) !== $jobId) {
    $loadToken = bin2hex(random_bytes(16));
    $loadState = [
        'job_id' => $jobId,
        'page' => 1,
        'candidates' => [],
        'requests' => 0,
        'window_started_at' => time(),
        'window_requests' => 0,
        'started_at' => microtime(true),
        'page_failures' => 0,
        'completed' => false,
    ];
    checkMassWriteState($loadToken, $loadState);
    checkMassLog('Candidate loading started', [
        'job_id' => $jobId, 'per_page' => FW_CANDIDATES_PER_PAGE, 'token' => $loadToken,
    ]);
}

/* =====================================================================
    2) GET CANDIDATES IN SHORT WEB REQUESTS
   ===================================================================== */
if (empty($loadState['completed'])) {
    echo "<h3>Загрузка кандидатов из FriendWork…</h3>";
    $windowElapsed = time() - (int)$loadState['window_started_at'];
    if ($windowElapsed >= 60) {
        $loadState['window_started_at'] = time();
        $loadState['window_requests'] = 0;
        $windowElapsed = 0;
    }
    if ($loadState['window_requests'] >= FW_REQUESTS_PER_MINUTE) {
        checkMassWriteState($loadToken, $loadState);
        checkMassShowContinuation(
            'Загружено кандидатов: ' . count($loadState['candidates']) . '. Ожидаем лимит FriendWork…',
            $loadToken,
            max(1, 60 - $windowElapsed)
        );
    }

    fwInternalAuth();
    $pagesThisRequest = min(
        FW_PAGES_PER_REQUEST,
        FW_REQUESTS_PER_MINUTE - (int)$loadState['window_requests']
    );
    for ($i = 0; $i < $pagesThisRequest; $i++) {
        $page = (int)$loadState['page'];
        $fwPage = fwInternal("POST", "/Candidates", [
            "page" => $page,
            "perPageCount" => FW_CANDIDATES_PER_PAGE,
            "statuses" => [212069],
            "jobId" => $jobId,
        ]);
        $loadState['requests']++;
        $loadState['window_requests']++;

        if ($fwPage['http'] != 200) {
            $loadState['page_failures']++;
            checkMassWriteState($loadToken, $loadState);
            checkMassLog('Candidate page request failed', [
                'job_id' => $jobId, 'page' => $page, 'http' => $fwPage['http'],
                'failure' => $loadState['page_failures'], 'curl_error' => $fwPage['err'],
                'response' => mb_substr((string)$fwPage['raw'], 0, 1000),
            ]);
            if ($loadState['page_failures'] >= FW_MAX_RETRIES) {
                echo "<h2>FriendWork не ответил после нескольких попыток (страница $page)</h2>";
                echo "<p>Обновите страницу позже — уже загруженные страницы сохранены.</p>";
                require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
                exit;
            }
            checkMassShowContinuation(
                "FriendWork временно не ответил на странице $page. Повторяем попытку…",
                $loadToken,
                5 * $loadState['page_failures']
            );
        }

        $loadState['page_failures'] = 0;
        $chunk = $fwPage['data']['candidates'] ?? [];
        echo "Страница $page: получено " . count($chunk) . " кандидатов<br>";
        if (!$chunk) {
            $loadState['completed'] = true;
            break;
        }
        foreach ($chunk as $candidate) $loadState['candidates'][] = $candidate;
        $loadState['page']++;
    }
    checkMassWriteState($loadToken, $loadState);

    if (empty($loadState['completed'])) {
        checkMassShowContinuation(
            'Загружено кандидатов: ' . count($loadState['candidates']) . '. Продолжаем следующую пачку…',
            $loadToken
        );
    }
    checkMassLog('Candidate loading completed', [
        'job_id' => $jobId,
        'candidates' => count($loadState['candidates']),
        'pages' => $loadState['page'],
        'requests' => $loadState['requests'],
        'duration_seconds' => round(microtime(true) - $loadState['started_at'], 3),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'token' => $loadToken,
    ]);
}

$candidates = $loadState['candidates'];
echo "<br><b>Всего кандидатов загружено: " . count($candidates) . "</b><br><hr>";

if (!$candidates) {
    echo "<h2>Нет кандидатов для вакансии $jobId</h2>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/* =====================================================================
    3) GET EXTERNAL ACCOUNTS (с email рекрутёров)
   ===================================================================== */
$fwAccounts = fwExternal("GET", "/accounts");
if ($fwAccounts['http'] != 200) {
    echo "<h2>Ошибка получения аккаунтов (внешний API)</h2>";
    echo "<pre>".htmlspecialchars($fwAccounts['raw'])."</pre>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}
$externalAcc = [];
foreach ($fwAccounts['data'] as $acc) {
    $id = $acc['accountId'];
    $email = strtolower($acc['userName'] ?? '');
    $fio   = trim(($acc['firstName'] ?? '') . " " . ($acc['lastName'] ?? ''));
    $externalAcc[$id] = [
        'email' => $email,
        'fio'   => $fio
    ];
}

/* =====================================================================
    EXPORT TO EXCEL
   ===================================================================== */
if (($_REQUEST['export'] ?? '') === 'excel' && isset($_REQUEST['results'])) {
    $results = json_decode($_REQUEST['results'], true);
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=ankety_{$jobId}.xls");
    echo "<table border='1'>
            <tr>
                <th>ID анкеты</th>
                <th>ФИО</th>
                <th>Email</th>
                <th>Телефон</th>
                <th>Ответственный</th>
                <th>Ссылка</th>
                <th>Пароль</th>
            </tr>";
    foreach ($results as $eid => $d) {
        $r = CUser::GetByID($d['RECRUITER'])->Fetch();
        $rn = $r ? $r['LAST_NAME']." ".$r['NAME'] : $d['RECRUITER'];
        echo "<tr>
                <td>{$eid}</td>
                <td>{$d['FIO']}</td>
                <td>{$d['EMAIL']}</td>
                <td>{$d['PHONE']}</td>
                <td>{$rn}</td>
                <td>{$d['LINK']}</td>
                <td>{$d['PASSWORD']}</td>
             </tr>";
    }
    echo "</table>";
    exit;
}

/* =====================================================================
    PROCESSING SELECTED CANDIDATES
   ===================================================================== */
if ($doProcess) {
    global $USER;
    $selected = $_REQUEST['candidates'] ?? [];
    if (!$selected) {
        echo "<h2 style='color:red'>Не выбрано ни одного кандидата</h2>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    if ($assignMode === 'manual' && $manualRecruiter <= 0) {
        echo "<h2 style='color:red'>Вы выбрали ручной режим, укажите рекрутера</h2>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    if ($assignMode === 'manual' && !in_array($manualRecruiter, ALLOWED_RECRUITER_IDS)) {
        echo "<h2 style='color:red'>Выбранный рекрутер не входит в список разрешённых</h2>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    $selectedLookup = array_fill_keys(array_map('strval', $selected), true);
    $currentUserId = (int)$USER->GetID();
    $diagnostic = [];
    $createdElements = [];
    $elementData = [];

    $processStartedAt = microtime(true);
    checkMassLog('Candidate processing started', [
        'job_id' => $jobId, 'selected' => count($selectedLookup), 'assign_mode' => $assignMode,
    ]);

    echo "<h2>Обработка выбранных кандидатов</h2>";

    foreach ($candidates as $c) {
        $candidateId = $c['candidateId'];
        if (!isset($selectedLookup[(string)$candidateId])) continue;

        $ln = $c['lastName'] ?? '';
        $fn = $c['firstName'] ?? '';
        $mn = $c['middleName'] ?? '';
        $fio = trim("$ln $fn $mn");
        $email = $c['communicationChannels']['email'][0] ?? '';
        $phone = $c['communicationChannels']['phone'][0] ?? '';

        $fwRespId = 0;
        $statusDate = '-';
        if (!empty($c['activeCandidateStatuses'][0])) {
            $active = $c['activeCandidateStatuses'][0];
            $historyId = $active['candidateHistoryId'];
            if (!empty($c['histories'])) {
                foreach ($c['histories'] as $h) {
                    if ($h['candidateHistoryId'] == $historyId) {
                        $fwRespId = $h['responsibleId'] ?? 0;
                        if (!empty($h['dateCreated'])) {
                            $ts = strtotime($h['dateCreated']);
                            if ($ts) {
                                $statusDate = date('d.m.Y H:i', $ts);
                            }
                        }
                        break;
                    }
                }
            }
        }

        $assignedRecruiter = null;
        $method = '';
        $debug = [];

        if ($assignMode === 'manual') {
            $assignedRecruiter = $manualRecruiter;
            $method = "Назначен вручную";
            $debug[] = "Manual mode";
        } else {
            $debug[] = "Auto mode";
            $debug[] = "FW responsibleId = $fwRespId";
            $foundEmail = '';
            $foundFIO = '';
            if ($fwRespId && isset($externalAcc[$fwRespId])) {
                $foundEmail = strtolower($externalAcc[$fwRespId]['email']);
                $foundFIO   = $externalAcc[$fwRespId]['fio'];
            }
            $debug[] = "Responsible email = $foundEmail";
            $debug[] = "Responsible FIO = $foundFIO";

            if ($foundEmail) {
                $rs = CUser::GetList($b, $o, ["=EMAIL" => $foundEmail]);
                if ($u = $rs->Fetch()) {
                    $assignedRecruiter = $u["ID"];
                    $method = "Найден по email";
                }
            }
            if (!$assignedRecruiter && $foundEmail && str_ends_with($foundEmail, "@tricolor.tv")) {
                $alt = str_replace("@tricolor.tv", "@tricolor.ru", $foundEmail);
                $rs = CUser::GetList($b, $o, ["=EMAIL" => $alt]);
                if ($u = $rs->Fetch()) {
                    $assignedRecruiter = $u["ID"];
                    $method = "Найден по email (.tv→.ru)";
                }
            }
            if (!$assignedRecruiter && $foundEmail) {
                $login = strstr($foundEmail, '@', true);
                $rs = CUser::GetList($b, $o, ["LOGIN" => $login]);
                if ($u = $rs->Fetch()) {
                    $assignedRecruiter = $u["ID"];
                    $method = "Найден по LOGIN ($login)";
                }
            }
            if (!$assignedRecruiter && $foundEmail) {
                $local = strstr($foundEmail, "@", true);
                $rs = CUser::GetList($b, $o, ["%EMAIL" => $local]);
                if ($u = $rs->Fetch()) {
                    $assignedRecruiter = $u["ID"];
                    $method = "Найден по части EMAIL ($local)";
                }
            }
            if (!$assignedRecruiter && $foundFIO) {
                $parts = explode(" ", $foundFIO);
                $rs = CUser::GetList($b, $o, [
                    "NAME"      => $parts[1] ?? '',
                    "LAST_NAME" => $parts[0] ?? ''
                ]);
                if ($u = $rs->Fetch()) {
                    $assignedRecruiter = $u["ID"];
                    $method = "Найден по ФИО ($foundFIO)";
                }
            }
            if (!$assignedRecruiter) {
                $assignedRecruiter = $currentUserId;
                $method = "Ответственный не найден — назначен текущий";
            }
        }

        $diagnostic[$candidateId] = [
            'fio'       => $fio,
            'method'    => $method,
            'recruiter' => $assignedRecruiter,
            'debug'     => $debug
        ];

        $properties = [
            1083 => $ln,
            1084 => $fn,
            1085 => $mn,
            1088 => $phone,
            1089 => $email,
            1093 => 813,
            1323 => $assignedRecruiter
        ];

        $el = new CIBlockElement;
        $arElement = [
            "IBLOCK_ID"       => 207,
            "NAME"            => $fio,
            "ACTIVE"          => "Y",
            "PROPERTY_VALUES" => $properties
        ];
        $elementId = $el->Add($arElement);

        echo "<hr><b>$fio</b><br>";
        if ($elementId) {
            echo "<span style='color:green'>Создан элемент: $elementId</span><br>";
            $createdElements[] = $elementId;
            $elementData[$elementId] = [
                'ID'        => $candidateId,
                'FIO'       => $fio,
                'EMAIL'     => $email,
                'PHONE'     => $phone,
                'RECRUITER' => $assignedRecruiter,
                'LINK'      => '',
                'PASSWORD'  => ''
            ];

            $errors = [];
            $wfId1 = checkMassStartListWorkflow(CANDIDATE_WORKFLOW_TEMPLATE_ID, $elementId, $errors);
            if ($wfId1 !== false) {
                echo "<span style='color:blue'>БП #1 запущен: " . htmlspecialchars((string)$wfId1) . "</span><br>";
            } else {
                echo "<span style='color:red'>Не удалось запустить БП #1: "
                    . htmlspecialchars(checkMassWorkflowErrorsText($errors)) . "</span><br>";
            }

            $dateNow = date("Y-m-d\TH:i:s");
            $payloadStatus = [
                "AccountId"   => $fwRespId ?: 0,
                "JobId"       => $jobId,
                "StatusId"    => 113626,
                "Rating"      => 3,
                "IsAllDay"    => 1,
                "IsClosed"    => 0,
                "FromDate"    => $dateNow,
                "DateCreated" => $dateNow,
                "Description" => "Status updated by Bitrix24"
            ];
            $fwUpd = fwExternal("POST",
                "/Candidate/{$candidateId}/CandidateHistories/set",
                $payloadStatus
            );
            if ($fwUpd['http'] == 200 || $fwUpd['http'] == 201) {
                echo "<span style='color:green'>Статус кандидата обновлён</span><br>";
            } else {
                echo "<span style='color:red'>Ошибка обновления статуса (FW external)</span><br>";
            }
        } else {
            checkMassLog('Candidate element creation failed', [
                'job_id' => $jobId, 'candidate_id' => $candidateId, 'error' => $el->LAST_ERROR,
            ]);
            echo "<span style='color:red'>Ошибка создания элемента: {$el->LAST_ERROR}</span><br>";
        }
    }

    echo "<h2>Запуск БП #2</h2>";
    foreach ($createdElements as $elementId) {
        $errors = [];
        $wfId2 = checkMassStartListWorkflow(LINK_WORKFLOW_TEMPLATE_ID, $elementId, $errors);
        echo "<hr>Анкета $elementId — ";
        if ($wfId2 !== false) {
            echo "БП #2 запущен: " . htmlspecialchars((string)$wfId2) . "<br>";
        } else {
            echo "<span style='color:red'>не удалось запустить БП #2: "
                . htmlspecialchars(checkMassWorkflowErrorsText($errors)) . "</span><br>";
        }
        $link = '';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $generated = checkMassReadGeneratedLink($elementId);
            $link = $generated['link'];
            $password = $generated['password'];
            if ($link || $password) break;
            usleep(500000);
        }
        $elementData[$elementId]['LINK'] = $link;
        $elementData[$elementId]['PASSWORD'] = $password;
        echo "Ссылка: <b data-link-value='" . (int)$elementId . "'>" . htmlspecialchars((string)$link) . "</b><br>";
        if (!checkMassIsValidCandidateLink($link)) {
            $elementData[$elementId]['LINK_INVALID'] = true;
            echo "<div data-link-result='" . (int)$elementId . "' style='margin:6px 0;padding:8px;border:1px solid #d99;background:#fff4e5;color:#8a4b00'>"
                . "⚠️ Создана некорректная ссылка. Нужно сгенерировать ссылку заново, запустив процесс ID 328."
                . " <button type='button' data-link-retry='" . (int)$elementId . "' onclick='checkMassRetryLink(" . (int)$elementId . ", this)'>Запустить ID 328 и обновить список</button>"
                . "</div>";
            checkMassLog('Invalid candidate link generated', [
                'element_id' => (int)$elementId,
                'workflow_id' => $wfId2,
                'link' => (string)$link,
            ]);
        }
        echo "Пароль: <b>" . htmlspecialchars((string)$password) . "</b><br>";
    }

    $json = htmlspecialchars(json_encode($elementData), ENT_QUOTES);
    echo "<br><hr>";
    echo "<form method='post' style='margin-bottom:10px'>
            <input type='hidden' name='job_id' value='$jobId'>
            <input type='hidden' name='load_token' value='" . htmlspecialchars($loadToken, ENT_QUOTES) . "'>
            <input type='hidden' name='export' value='excel'>
            <input type='hidden' name='results' value='$json'>
            <button type='submit' style='font-size:14px'>📄 Экспорт в Excel</button>
          </form>";

    echo "<script>
    function checkMassRetryLink(elementId, button) {
        const buttons = document.querySelectorAll('[data-link-retry=\"' + elementId + '\"]');
        buttons.forEach(function(item) { item.disabled = true; });
        button.textContent = 'Запускаем процесс…';
        const data = new FormData();
        data.append('retry_link_element_id', elementId);
        data.append('sessid', " . json_encode(bitrix_sessid()) . ");
        fetch(window.location.pathname, {method: 'POST', body: data, credentials: 'same-origin'})
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success) throw new Error(result.message || 'Не удалось получить корректную ссылку.');
                const safeLink = document.createElement('a');
                safeLink.href = result.link;
                safeLink.target = '_blank';
                safeLink.rel = 'noopener noreferrer';
                safeLink.textContent = result.link;
                document.querySelectorAll('[data-link-cell=\"' + elementId + '\"]').forEach(function(cell) {
                    cell.replaceChildren(safeLink.cloneNode(true));
                });
                document.querySelectorAll('[data-link-value=\"' + elementId + '\"]').forEach(function(value) {
                    value.textContent = result.link;
                });
                document.querySelectorAll('[data-link-result=\"' + elementId + '\"]').forEach(function(block) {
                    block.textContent = result.message;
                    block.style.color = 'green';
                    block.style.background = '#efffed';
                });
            })
            .catch(function(error) {
                alert(error.message);
                buttons.forEach(function(item) { item.disabled = false; });
                button.textContent = 'Запустить ID 328 и обновить список';
            });
    }
    </script>";

    echo "<h2>Итоговые анкеты</h2>";
    echo "<table border='1' cellpadding='4' cellspacing='0'>
            <tr style='background:#eee'>
                <th>ID анкеты</th>
                <th>ФИО</th>
                <th>Email</th>
                <th>Телефон</th>
                <th>Ответственный</th>
                <th>Ссылка</th>
                <th>Пароль</th>
            </tr>";
    foreach ($elementData as $eid => $d) {
        $r = CUser::GetByID($d['RECRUITER'])->Fetch();
        $rn = $r ? $r['LAST_NAME']." ".$r['NAME'] : $d['RECRUITER'];
        echo "<tr>
                <td>{$eid}</td>
                <td>{$d['FIO']}</td>
                <td>{$d['EMAIL']}</td>
                <td>{$d['PHONE']}</td>
                <td>{$rn}</td>
                <td data-link-cell='" . (int)$eid . "'>";
        if (!empty($d['LINK']) && empty($d['LINK_INVALID'])) {
            $safeLink = htmlspecialchars((string)$d['LINK'], ENT_QUOTES);
            echo "<a href='{$safeLink}' target='_blank' rel='noopener noreferrer'>{$safeLink}</a>";
        } elseif (!empty($d['LINK_INVALID'])) {
            echo "<span style='color:#b35c00;font-weight:bold'>⚠️ Некорректная ссылка.</span> "
                . "<button type='button' data-link-retry='" . (int)$eid . "' onclick='checkMassRetryLink(" . (int)$eid . ", this)'>Запустить ID 328 и обновить список</button>";
        } else {
            echo "-";
        }
        echo    "</td>
                <td>{$d['PASSWORD']}</td>
              </tr>";
    }
    echo "</table><hr>";

    echo "<h2>Диагностика присвоения рекрутёров</h2>";
    foreach ($diagnostic as $cid => $d) {
        echo "<div style='margin-bottom:12px'>";
        echo "<b>{$d['fio']} (CandidateID: $cid)</b><br>";
        echo "Метод: <b>{$d['method']}</b><br>";
        $r = CUser::GetByID($d['recruiter'])->Fetch();
        if ($r) {
            $n = $r['LAST_NAME']." ".$r['NAME'];
            echo "Назначенный рекрутер: <b>$n</b> (ID {$d['recruiter']})<br>";
        }
        echo "<details><summary>Подробнее</summary><pre>";
        print_r($d['debug']);
        echo "</pre></details><hr>";
        echo "</div>";
    }

    checkMassLog('Candidate processing completed', [
        'job_id' => $jobId,
        'selected' => count($selectedLookup),
        'created' => count($createdElements),
        'duration_seconds' => round(microtime(true) - $processStartedAt, 3),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
    ]);

    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/* =====================================================================
    PREPARE CANDIDATE ROWS FOR SORTING
   ===================================================================== */
// Get sort params
$defaultSortBy = 'status_date';
$defaultOrder  = 'desc';
$sortBy  = $_REQUEST['sort_by']  ?? $defaultSortBy;
$sortOrder = strtolower($_REQUEST['sort_order'] ?? $defaultOrder);
if (!in_array($sortOrder, ['asc', 'desc'])) {
    $sortOrder = $defaultOrder;
}

$candidateRows = [];
foreach ($candidates as $c) {
    $id  = $c['candidateId'];
    $ln  = $c['lastName'] ?? '';
    $fn  = $c['firstName'] ?? '';
    $mn  = $c['middleName'] ?? '';
    $fio = trim("$ln $fn $mn");
    $email = $c['communicationChannels']['email'][0] ?? '';
    $phone = $c['communicationChannels']['phone'][0] ?? '';

    $fwResp = '-';
    $statusDate = '-';
    $statusDateTs = 0;
    $fwRespId = null;

    if (!empty($c['activeCandidateStatuses'][0])) {
        $active = $c['activeCandidateStatuses'][0];
        $historyId = $active['candidateHistoryId'];
        if (!empty($c['histories'])) {
            foreach ($c['histories'] as $h) {
                if ($h['candidateHistoryId'] == $historyId) {
                    $fwRespId = $h['responsibleId'] ?? null;
                    if ($fwRespId && isset($externalAcc[$fwRespId])) {
                        $fwResp = $externalAcc[$fwRespId]['fio'];
                    } else {
                        $fwResp = $fwRespId ?: '-';
                    }
                    if (!empty($h['dateCreated'])) {
                        $ts = strtotime($h['dateCreated']);
                        if ($ts) {
                            $statusDate = date('d.m.Y H:i', $ts);
                            $statusDateTs = $ts;
                        }
                    }
                    break;
                }
            }
        }
    }

    $candidateRows[] = [
        'id'            => $id,
        'fio'           => $fio,
        'email'         => $email,
        'phone'         => $phone,
        'status_date'   => $statusDate,
        'status_date_ts'=> $statusDateTs,
        'fw_resp'       => $fwResp,
        'raw'           => $c
    ];
}

usort($candidateRows, function($a, $b) use ($sortBy, $sortOrder) {
    $order = ($sortOrder === 'asc') ? 1 : -1;
    switch ($sortBy) {
        case 'candidate_id': return ($a['id'] <=> $b['id']) * $order;
        case 'fio':          return strcasecmp($a['fio'], $b['fio']) * $order;
        case 'email':        return strcasecmp($a['email'], $b['email']) * $order;
        case 'phone':        return strcasecmp($a['phone'], $b['phone']) * $order;
        case 'status_date':  return ($a['status_date_ts'] <=> $b['status_date_ts']) * $order;
        case 'fw_resp':      return strcasecmp($a['fw_resp'], $b['fw_resp']) * $order;
        default:             return 0;
    }
});

function buildSortUrl($field, $currentSortBy, $currentOrder) {
    $params = $_REQUEST;
    $params['sort_by'] = $field;
    if ($currentSortBy === $field) {
        $params['sort_order'] = ($currentOrder === 'asc') ? 'desc' : 'asc';
    } else {
        $params['sort_order'] = 'desc';
    }
    unset($params['process']);
    return '?' . http_build_query($params);
}

/* =====================================================================
    SHOW SELECTION TABLE WITH SORTING
   ===================================================================== */
echo "<h2>Выбор кандидатов</h2>";
?>
<form method="post">
    <input type="hidden" name="job_id" value="<?=htmlspecialchars($jobId)?>">
    <input type="hidden" name="load_token" value="<?=htmlspecialchars($loadToken)?>">
    <input type="hidden" name="process" value="Y">

    <?php if ($assignMode === 'manual'): ?>
        <input type="hidden" name="assign_mode" value="manual">
        <input type="hidden" name="manual_recruiter" value="<?=htmlspecialchars($manualRecruiter)?>">
    <?php endif; ?>

    <h3>Назначение ответственного рекрутера:</h3>
    <div style="margin-bottom:12px">
        <b>Присвоить автоматически</b> — система определит рекрутера по данным FriendWork.<br>
        Поиск идёт по e-mail и ФИО. Если рекрутер не найден, назначается текущий пользователь.
    </div>
    <label>
        <input type="radio" name="assign_mode" value="auto" <?=($assignMode === 'auto' ? 'checked' : '')?>>
        Присвоить автоматически
    </label>
    <br><br>
    <div style="margin-bottom:12px">
        <b>Присвоить вручную</b> — выберите одного рекрутера, он будет назначен для всех выбранных анкет.
    </div>
    <label>
        <input type="radio" name="assign_mode" value="manual" <?=($assignMode === 'manual' ? 'checked' : '')?>>
        Присвоить вручную
    </label>

    <div id="manual-block" style="margin:10px 0; padding:10px; background:#fafafa; border:1px solid #ccc; <?=($assignMode === 'manual' ? 'display:block' : 'display:none')?>;">
        <b>Выберите ответственного за все выбранные анкеты:</b><br><br>
        <select name="manual_recruiter" required>
            <option value="">— Выберите рекрутера —</option>
            <?php
            if (!empty(ALLOWED_RECRUITER_IDS)) {
                $rsUsers = CUser::GetList($by = '', $order = '', [
                    'ID' => implode('|', ALLOWED_RECRUITER_IDS),
                    'ACTIVE' => 'Y'
                ]);
                while ($user = $rsUsers->Fetch()) {
                    $displayName = trim($user['LAST_NAME'] . ' ' . $user['NAME']) ?: $user['LOGIN'];
                    $selected = ($manualRecruiter == $user['ID']) ? 'selected' : '';
                    echo '<option value="' . (int)$user['ID'] . '" ' . $selected . '>' . htmlspecialchars($displayName) . '</option>';
                }
            }
            ?>
        </select>
    </div>

    <hr>
    <div style="margin:10px 0;">
        <label style="cursor:pointer;">
            <input type="checkbox" id="toggle_all" checked>
            Отметить / снять всех кандидатов
        </label>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('toggle_all');
        toggle.addEventListener('change', function() {
            document.querySelectorAll('input[name="candidates[]"]').forEach(ch => ch.checked = toggle.checked);
        });
    });
    </script>

    <table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse; width:100%;">
        <thead>
            <tr style="background:#eee">
                <th>Выбрать</th>
                <th><a href="<?=htmlspecialchars(buildSortUrl('candidate_id', $sortBy, $sortOrder))?>">CandidateID</a></th>
                <th><a href="<?=htmlspecialchars(buildSortUrl('fio', $sortBy, $sortOrder))?>">ФИО</a></th>
                <th><a href="<?=htmlspecialchars(buildSortUrl('email', $sortBy, $sortOrder))?>">Email</a></th>
                <th><a href="<?=htmlspecialchars(buildSortUrl('phone', $sortBy, $sortOrder))?>">Телефон</a></th>
                <th>
                    <a href="<?=htmlspecialchars(buildSortUrl('status_date', $sortBy, $sortOrder))?>">
                        Дата статуса
                    </a>
                    <?php if ($sortBy === 'status_date'): ?>
                        <?= $sortOrder === 'desc' ? ' ↓' : ' ↑' ?>
                    <?php endif; ?>
                </th>
                <th><a href="<?=htmlspecialchars(buildSortUrl('fw_resp', $sortBy, $sortOrder))?>">Ответственный (FW)</a></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($candidateRows as $row): ?>
            <tr>
                <td align="center">
                    <input type="checkbox" name="candidates[]" value="<?=$row['id']?>" checked>
                </td>
                <td><?=$row['id']?></td>
                <td><?=$row['fio']?></td>
                <td><?=$row['email']?></td>
                <td><?=$row['phone']?></td>
                <td><?=$row['status_date']?></td>
                <td><?=$row['fw_resp']?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <br>
    <input type="submit" value="Создать анкеты и запустить БП" style="font-size:16px; padding:8px 16px;">
</form>

<script>
document.querySelectorAll('input[name="assign_mode"]').forEach(function(r){
    r.addEventListener('change', function(){
        document.getElementById('manual-block').style.display =
            (this.value === 'manual' ? 'block' : 'none');
    });
});
</script>

<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>
