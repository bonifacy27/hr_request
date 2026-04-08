<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

while (ob_get_level()) { ob_end_flush(); }
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 0);
header('X-Accel-Buffering: no');
flush();

use Bitrix\Main\Loader;

if (
    !Loader::includeModule("iblock") ||
    !Loader::includeModule("lists") ||
    !Loader::includeModule("bizproc")
) {
    die("Не удалось подключить модули (iblock, lists, bizproc).");
}

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    die("Требуется авторизация.");
}

echo "<div style='font-size:11px;color:#777'>check_prof.php v1.5</div>";

/* ================================================================
   CONFIG
   ================================================================ */

// INTERNAL FRIENDWORK API (OLD — получение кандидатов)
const FW_USER_INTERNAL = 'login';
const FW_PASS_INTERNAL = 'pass';
const FW_API_INTERNAL  = 'https://app.friend.work/api';

// EXTERNAL FRIENDWORK API (NEW — справочник аккаунтов/ответственных)
const FW_TOKEN_EXTERNAL = 'rntF2WupwPYYvJ93VFrJ3PTzQAmi7YQ1QV_Zj9nFaW5P--4hmUyhF2psZTxY3ERVypfbRCPUsiW01n14Trg99RQtzA9Lm0ElfHXblj-15gvvunO6T7raLGoR5-puwTkEh5bNHR2pcZ-emqD5pBcg-UoHdLBM0oinPq5_bmS6v0LC7s0aPIA_UKXtddBlOdnCiPsWqXrU7WTNbsm_eLVsL7UWIUzgaL99F951bNQbv5fKmfXGzpIqIxtNzyHuoTLZ0oYGMYKFsFVOHVccFFUgILThL-sn2eCgCIowytSG-0Mlw7s3_KZGsv0IVFQQb9Uh7VhLkHHAXah7Etb-kyUJvGkCv3kOnEjlencGrUYOs3c28NES';
const FW_API_EXTERNAL   = 'https://api.friend.work';

// Bitrix
const IBLOCK_REQUESTS = 201; // список/ИБ "Заявки на подбор"
const PROP_FW_VACANCY_ID_NUM = 1593;            // число (ID свойства)
const PROP_FW_VACANCY_SELECT = 'PROPERTY_1593'; // строка для SELECT

// Заявка -> Должность
const PROP_REQ_DOLZHNOST_SELECT = 'PROPERTY_1011'; // DOLZHNOST в заявке (для SELECT)
const PROP_REQ_DOLZHNOST_ID_NUM = 1011;            // ID свойства (для GetProperty фоллбек)

const IBLOCK_CANDIDATES = 207; // список "Кандидаты" (анкеты)
const BP_TEMPLATE_1 = 466;
const BP_TEMPLATE_2 = 328;

// Friendwork
const FW_STATUS_APPROVED_INTERVIEW_DONE = 127730;

// Свойства анкеты (ИБ 207) — известные ID из вашего окружения
const PROP_LASTNAME_ID   = 1083;
const PROP_FIRSTNAME_ID  = 1084;
const PROP_MIDDLENAME_ID = 1085;
const PROP_PHONE_ID      = 1088;
const PROP_EMAIL_ID      = 1089;

const PROP_TIP_ANKETY_ID = 1093; // список
const TIP_ANKETY_PROF_VALUE = 814;

const PROP_RECRUITER_ID  = 1323; // пользователь

const PROP_RESUME_ID     = 1689; // файл
const PROP_SOGLAS_ID     = 1726; // файл

// Новые обязательные поля (ИБ 207)
const PROP_ID_KANDIDATA_FW_ID = 1594; // ID_KANDIDATA_FRIENDWORK
const PROP_ID_VAKANSII_FW_ID  = 1595; // ID_VAKANSII_FRIENDWORK
const PROP_ID_ZAYAVKI_ID      = 1596; // ID_ZAYAVKI_NA_PODBOR
const PROP_ROUTE_ID           = 2854; // ROUTE (строка)
const ROUTE_VALUE             = "Из заявки на подбор";

// Анкета -> Должность
const PROP_ANK_DOLZHNOST_ID   = 1617; // DOLZHNOST в анкете (ИБ 207)

// cookie для internal FW
$tmpCookie = __DIR__ . '/fw_cookie_prof.txt';

/* ================================================================
   HELPERS
   ================================================================ */

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Определяем куда вернуть пользователя после создания анкеты.
 * Поддерживаем несколько вариантов параметров, чтобы ссылку можно было формировать как угодно.
 */
function getReturnUrl(): string
{
    $candidates = [
        (string)($_REQUEST['return_url'] ?? ''),
        (string)($_REQUEST['task_url'] ?? ''),
        (string)($_REQUEST['back_url_full'] ?? ''),
    ];

    // иногда back_url дают как относительный
    if (!empty($_REQUEST['back_url'])) {
        $bu = (string)$_REQUEST['back_url'];
        if (preg_match('~^https?://~i', $bu)) {
            $candidates[] = $bu;
        } else {
            $candidates[] = $bu; // может быть /company/... — тоже ок
        }
    }

    foreach ($candidates as $u) {
        $u = trim($u);
        if ($u === '') continue;
        // базовая безопасность: не даём редирект на javascript:
        if (stripos($u, 'javascript:') === 0) continue;
        return $u;
    }
    return '';
}

function fwInternalAuth()
{
    global $tmpCookie;
    $loginUrl = FW_API_INTERNAL . "/Accounts/LogIn?username=" .
        urlencode(FW_USER_INTERNAL) . "&password=" . urlencode(FW_PASS_INTERNAL);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $loginUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $tmpCookie,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function fwInternal($method, $url, $payload = null)
{
    global $tmpCookie;

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => FW_API_INTERNAL . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $tmpCookie,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
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

function fwExternal($method, $url, $payload = null)
{
    $ch = curl_init();
    $headers = [
        "Authorization: Bearer " . FW_TOKEN_EXTERNAL,
        "Content-Type: application/json"
    ];

    curl_setopt($ch, CURLOPT_URL, FW_API_EXTERNAL . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

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

function setOptionalPropsByCode(int $iblockId, array &$propValues, array $codeToValue): void
{
    $codes = array_keys($codeToValue);
    if (empty($codes)) return;

    $codes = array_values(array_filter(array_map(function($c){
        $c = trim((string)$c);
        if ($c === '') return null;
        return str_replace('|', '\|', $c);
    }, $codes)));

    if (empty($codes)) return;

    $codeMask = implode('|', $codes);

    $res = CIBlockProperty::GetList(
        [],
        [
            "IBLOCK_ID" => $iblockId,
            "ACTIVE"    => "Y",
            "CODE"      => $codeMask
        ]
    );

    while ($p = $res->Fetch()) {
        $code = (string)$p['CODE'];
        $pid  = (int)$p['ID'];
        if ($pid > 0 && array_key_exists($code, $codeToValue)) {
            $propValues[$pid] = $codeToValue[$code];
        }
    }
}

function makeFileArrayFromUpload(string $fieldName): ?array
{
    if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['tmp_name'])) return null;
    if (!is_uploaded_file($_FILES[$fieldName]['tmp_name'])) return null;

    $arr = \CFile::MakeFileArray($_FILES[$fieldName]['tmp_name']);
    if (!$arr) return null;

    $arr['name'] = $_FILES[$fieldName]['name'] ?? $arr['name'];
    return $arr;
}

function getFwVacancyIdFromRequest(int $requestElementId): int
{
    $row = CIBlockElement::GetList(
        [],
        ["IBLOCK_ID" => IBLOCK_REQUESTS, "ID" => $requestElementId],
        false,
        ["nTopCount" => 1],
        ["ID", "NAME", PROP_FW_VACANCY_SELECT]
    )->Fetch();

    if ($row) {
        $key = PROP_FW_VACANCY_SELECT . "_VALUE"; // PROPERTY_1593_VALUE
        if (!empty($row[$key])) return (int)$row[$key];
        if (isset($row[$key]) && is_string($row[$key])) {
            $v = trim($row[$key]);
            if ($v !== '') return (int)$v;
        }
    }

    $propRes = CIBlockElement::GetProperty(
        IBLOCK_REQUESTS,
        $requestElementId,
        ["sort" => "asc"],
        ["ID" => PROP_FW_VACANCY_ID_NUM]
    );
    if ($p = $propRes->Fetch()) {
        $v = $p["VALUE"];
        if (is_array($v)) $v = reset($v);
        $v = trim((string)$v);
        if ($v !== '') return (int)$v;
    }

    return 0;
}

/**
 * Читаем "Должность" из заявки: PROPERTY_1011
 */
function getDolzhnostFromRequest(int $requestElementId): string
{
    $row = CIBlockElement::GetList(
        [],
        ["IBLOCK_ID" => IBLOCK_REQUESTS, "ID" => $requestElementId],
        false,
        ["nTopCount" => 1],
        ["ID", PROP_REQ_DOLZHNOST_SELECT]
    )->Fetch();

    if ($row) {
        $key = PROP_REQ_DOLZHNOST_SELECT . "_VALUE"; // PROPERTY_1011_VALUE
        if (isset($row[$key])) {
            if (is_array($row[$key])) {
                $v = reset($row[$key]);
                return trim((string)$v);
            }
            return trim((string)$row[$key]);
        }
    }

    // фоллбек
    $propRes = CIBlockElement::GetProperty(
        IBLOCK_REQUESTS,
        $requestElementId,
        ["sort" => "asc"],
        ["ID" => PROP_REQ_DOLZHNOST_ID_NUM]
    );
    if ($p = $propRes->Fetch()) {
        $v = $p["VALUE"];
        if (is_array($v)) $v = reset($v);
        return trim((string)$v);
    }

    return '';
}

/**
 * Запуск БП по элементу списка (ваша рабочая схема)
 */
function startListWorkflow(int $templateId, int $elementId, array &$errors = [])
{
    $errors = [];
    $documentId = ["lists", "Bitrix\\Lists\\BizprocDocumentLists", $elementId];
    return CBPDocument::StartWorkflow($templateId, $documentId, [], $errors);
}

/* ================================================================
   INPUT
   ================================================================ */
$jobRequestId = isset($_REQUEST['job_id']) ? (int)$_REQUEST['job_id'] : 0;
if ($jobRequestId <= 0) {
    echo "<div style='color:red'>Не передан job_id</div>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

$currentUserId = (int)$USER->GetID();
$returnUrl = getReturnUrl();

/* ================================================================
   1) Заявка + ID вакансии FW + Должность
   ================================================================ */
$arReq = CIBlockElement::GetList(
    [],
    ["IBLOCK_ID" => IBLOCK_REQUESTS, "ID" => $jobRequestId],
    false,
    ["nTopCount" => 1],
    ["ID", "NAME"]
)->Fetch();

if (!$arReq) {
    echo "<div style='color:red'>Заявка на подбор не найдена (IBLOCK ".IBLOCK_REQUESTS.", ID $jobRequestId)</div>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

$fwVacancyId = getFwVacancyIdFromRequest($jobRequestId);
$reqDolzhnost = getDolzhnostFromRequest($jobRequestId);

echo "<h2>Профессиональный подбор — заявка #".h($jobRequestId)."</h2>";
echo "<div><b>Заявка:</b> ".h($arReq['NAME'])."</div>";
echo "<div><b>Friendwork Vacancy ID (PROPERTY_1593):</b> ".h($fwVacancyId)."</div>";
echo "<div><b>Должность (PROPERTY_1011):</b> ".h($reqDolzhnost)."</div>";
echo "<hr>";

if ($fwVacancyId <= 0) {
    echo "<div style='color:red'>В заявке не заполнен ID_FW_VAKANSII (PROPERTY_1593) или он не читается</div>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/* ================================================================
   2) Accounts FW external (не критично)
   ================================================================ */
$fwAccounts = fwExternal("GET", "/Accounts");
$externalAcc = [];

if ($fwAccounts['http'] == 200 && is_array($fwAccounts['data'])) {
    foreach ($fwAccounts['data'] as $acc) {
        $id = (int)($acc['accountId'] ?? 0);
        if ($id <= 0) continue;
        $fio = trim(($acc['firstName'] ?? '') . " " . ($acc['lastName'] ?? ''));
        $externalAcc[$id] = $fio ?: ("Account #".$id);
    }
} else {
    echo "<div style='color:#a66'>Предупреждение: не удалось получить Accounts из FW external API (будет показываться ID ответственного)</div>";
}

/* ================================================================
   3) Кандидаты FW internal
   ================================================================ */
fwInternalAuth();

echo "<h3>Загрузка кандидатов из Friendwork…</h3>";

$allCandidates = [];
$page = 1;
$perPage = 20;

$requests = 0;
$minuteStart = time();

while (true) {
    $now = time();
    $elapsed = $now - $minuteStart;
    if ($elapsed >= 60) { $minuteStart = $now; $requests = 0; }
    if ($requests >= 20) {
        $wait = 60 - $elapsed;
        if ($wait < 1) $wait = 1;
        echo "<b>Лимит 20 запросов/мин достигнут → ждем $wait сек…</b><br>";
        flush();
        sleep($wait);
        $minuteStart = time();
        $requests = 0;
    }

    $payload = [
        "page"         => $page,
        "perPageCount" => $perPage,
        "statuses"     => [FW_STATUS_APPROVED_INTERVIEW_DONE],
        "jobId"        => $fwVacancyId
    ];

    $fwPage = fwInternal("POST", "/Candidates", $payload);
    $requests++;

    if ($fwPage['http'] != 200) {
        echo "<div style='color:red'><b>Ошибка получения кандидатов (страница $page)</b></div>";
        echo "<pre style='white-space:pre-wrap'>".h($fwPage['raw'])."</pre>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    $chunk = $fwPage['data']['candidates'] ?? [];
    $count = count($chunk);

    echo "Страница $page: получено $count<br>";
    flush();

    if ($count === 0) break;

    $allCandidates = array_merge($allCandidates, $chunk);
    if ($count < $perPage) break;

    $page++;
}

echo "<b>Всего кандидатов в статусе 127730: ".count($allCandidates)."</b><hr>";

/* ================================================================
   4) UI
   ================================================================ */

$action = (string)($_REQUEST['action'] ?? '');
$selectCandidateId = isset($_REQUEST['candidate_id']) ? (int)$_REQUEST['candidate_id'] : 0;

$byId = [];
foreach ($allCandidates as $c) {
    if (!empty($c['candidateId'])) $byId[(int)$c['candidateId']] = $c;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        echo "<div style='color:red'>Ошибка: неверная сессия. Обновите страницу.</div>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    $candidateId = isset($_POST['candidate_id']) ? (int)$_POST['candidate_id'] : 0;
    if ($candidateId <= 0 || empty($byId[$candidateId])) {
        echo "<div style='color:red'>Кандидат не найден или не выбран.</div>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    // на POST тоже протаскиваем return_url (на случай, если он был только в GET)
    if (empty($returnUrl) && !empty($_POST['return_url'])) {
        $_REQUEST['return_url'] = (string)$_POST['return_url'];
        $returnUrl = getReturnUrl();
    }

    $resumeFile = makeFileArrayFromUpload('RESUME');
    $soglasFile = makeFileArrayFromUpload('SOGLAS');

    $errors = [];
    if (!$resumeFile) $errors[] = "Не загружен файл Резюме (обязательное поле).";
    if (!$soglasFile) $errors[] = "Не загружен файл Согласование кандидата руководителем (обязательное поле).";

    if (!empty($errors)) {
        echo "<div style='color:red'><b>Ошибки:</b><ul>";
        foreach ($errors as $e) echo "<li>".h($e)."</li>";
        echo "</ul></div>";
        echo "<a href='?job_id=".h($jobRequestId)."&return_url=".urlencode($returnUrl)."'>← Вернуться к списку кандидатов</a>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    $c = $byId[$candidateId];

    $ln = (string)($c['lastName'] ?? '');
    $fn = (string)($c['firstName'] ?? '');
    $mn = (string)($c['middleName'] ?? '');
    $fio = trim("$ln $fn $mn");

    $email = $c['communicationChannels']['email'][0] ?? '';
    $phone = $c['communicationChannels']['phone'][0] ?? '';

    $fwRespId = 0;
    $statusDate = '';
    if (!empty($c['activeCandidateStatuses'][0])) {
        $active = $c['activeCandidateStatuses'][0];
        $historyId = $active['candidateHistoryId'] ?? 0;
        if (!empty($c['histories']) && $historyId) {
            foreach ($c['histories'] as $h) {
                if (!empty($h['candidateHistoryId']) && (int)$h['candidateHistoryId'] === (int)$historyId) {
                    $fwRespId = (int)($h['responsibleId'] ?? 0);
                    $statusDate = (string)($h['dateCreated'] ?? '');
                    break;
                }
            }
        }
    }

    $propValues = [
        PROP_LASTNAME_ID   => $ln,
        PROP_FIRSTNAME_ID  => $fn,
        PROP_MIDDLENAME_ID => $mn,
        PROP_PHONE_ID      => $phone,
        PROP_EMAIL_ID      => $email,

        PROP_TIP_ANKETY_ID => TIP_ANKETY_PROF_VALUE,
        PROP_RECRUITER_ID  => $currentUserId,

        PROP_RESUME_ID     => $resumeFile,
        PROP_SOGLAS_ID     => $soglasFile,

        PROP_ID_ZAYAVKI_ID      => $jobRequestId,
        PROP_ID_KANDIDATA_FW_ID => $candidateId,
        PROP_ID_VAKANSII_FW_ID  => $fwVacancyId,
        PROP_ROUTE_ID           => ROUTE_VALUE,

        // === НОВОЕ: должность из заявки ===
        PROP_ANK_DOLZHNOST_ID    => $reqDolzhnost,
    ];

    setOptionalPropsByCode(IBLOCK_CANDIDATES, $propValues, [
        'FW_RESPONSIBLE_ID' => $fwRespId,
        'FW_STATUS_DATE'    => $statusDate,
    ]);

    $el = new CIBlockElement;
    $arElement = [
        "IBLOCK_ID"       => IBLOCK_CANDIDATES,
        "NAME"            => $fio ?: ("Candidate #".$candidateId),
        "ACTIVE"          => "Y",
        "PROPERTY_VALUES" => $propValues
    ];

    $elementId = (int)$el->Add($arElement);

    if ($elementId <= 0) {
        echo "<div style='color:red'><b>Ошибка создания элемента:</b> ".h($el->LAST_ERROR)."</div>";
        echo "<a href='?job_id=".h($jobRequestId)."&return_url=".urlencode($returnUrl)."'>← Вернуться</a>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    // Запуск БП
    $bpErrors1 = [];
    startListWorkflow(BP_TEMPLATE_1, $elementId, $bpErrors1);

    $bpErrors2 = [];
    startListWorkflow(BP_TEMPLATE_2, $elementId, $bpErrors2);

    // === НОВОЕ: Возврат пользователя в задание БП ===
    if (!empty($returnUrl)) {
        // если returnUrl относительный — LocalRedirect нормально отработает
        LocalRedirect($returnUrl);
    }

    // если return_url не передали — покажем как раньше
    echo "<h2>Создание анкеты</h2>";
    echo "<div style='color:green'><b>Анкета создана:</b> ID ".h($elementId)."</div>";
    echo "<div>БП #1 (".BP_TEMPLATE_1.") ошибок: ".h(count($bpErrors1))."</div>";
    echo "<div>БП #2 (".BP_TEMPLATE_2.") ошибок: ".h(count($bpErrors2))."</div>";
    echo "<hr><div><a href='?job_id=".h($jobRequestId)."'>← К списку кандидатов</a></div>";

    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/* ================================================================
   Форма загрузки файлов
   ================================================================ */
if ($selectCandidateId > 0 && !empty($byId[$selectCandidateId])) {
    $c = $byId[$selectCandidateId];

    $ln = (string)($c['lastName'] ?? '');
    $fn = (string)($c['firstName'] ?? '');
    $mn = (string)($c['middleName'] ?? '');
    $fio = trim("$ln $fn $mn");

    $email = $c['communicationChannels']['email'][0] ?? '';
    $phone = $c['communicationChannels']['phone'][0] ?? '';

    $fwRespId = 0;
    $statusDateFormatted = '-';
    if (!empty($c['activeCandidateStatuses'][0])) {
        $active = $c['activeCandidateStatuses'][0];
        $historyId = $active['candidateHistoryId'] ?? 0;
        if (!empty($c['histories']) && $historyId) {
            foreach ($c['histories'] as $h) {
                if (!empty($h['candidateHistoryId']) && (int)$h['candidateHistoryId'] === (int)$historyId) {
                    $fwRespId = (int)($h['responsibleId'] ?? 0);
                    $dc = (string)($h['dateCreated'] ?? '');
                    $ts = $dc ? strtotime($dc) : false;
                    if ($ts) $statusDateFormatted = date('d.m.Y H:i', $ts);
                    break;
                }
            }
        }
    }
    $fwRespName = $fwRespId ? ($externalAcc[$fwRespId] ?? ("ID ".$fwRespId)) : "-";

    echo "<h2>Начать проверку кандидата</h2>";
    echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse'>
            <tr><td><b>CandidateID</b></td><td>".h($selectCandidateId)."</td></tr>
            <tr><td><b>ФИО</b></td><td>".h($fio)."</td></tr>
            <tr><td><b>Email</b></td><td>".h($email)."</td></tr>
            <tr><td><b>Телефон</b></td><td>".h($phone)."</td></tr>
            <tr><td><b>Дата статуса</b></td><td>".h($statusDateFormatted)."</td></tr>
            <tr><td><b>Ответственный (FW)</b></td><td>".h($fwRespName)."</td></tr>
          </table>";

    echo "<hr>";
    echo "<form method='POST' enctype='multipart/form-data' action='?job_id=".h($jobRequestId)."'>
            ".bitrix_sessid_post()."
            <input type='hidden' name='action' value='create'>
            <input type='hidden' name='candidate_id' value='".h($selectCandidateId)."'>
            <input type='hidden' name='return_url' value='".h($returnUrl)."'>

            <div style='margin-bottom:12px'>
                <b>Согласование кандидата руководителем</b> (обязательно)<br>
                <input type='file' name='SOGLAS' required>
            </div>

            <div style='margin-bottom:12px'>
                <b>Резюме</b> (обязательно)<br>
                <input type='file' name='RESUME' required>
            </div>

            <button type='submit' style='padding:10px 14px; font-size:14px'>
                Создать анкету и запустить БП
            </button>
          </form>";

    echo "<hr><a href='?job_id=".h($jobRequestId)."&return_url=".urlencode($returnUrl)."'>← Вернуться к списку кандидатов</a>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

/* ================================================================
   Список кандидатов
   ================================================================ */
if (empty($allCandidates)) {
    echo "<div>Кандидатов в статусе 127730 не найдено.</div>";
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

echo "<h2>Кандидаты (статус: Собеседование состоялось / Одобрен)</h2>";

echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse; width:100%'>
        <tr style='background:#eee'>
            <th>CandidateID</th>
            <th>ФИО</th>
            <th>Email</th>
            <th>Телефон</th>
            <th>Дата статуса</th>
            <th>Ответственный (FW)</th>
            <th>Действие</th>
        </tr>";

foreach ($allCandidates as $c) {
    $candidateId = (int)($c['candidateId'] ?? 0);
    if ($candidateId <= 0) continue;

    $ln = (string)($c['lastName'] ?? '');
    $fn = (string)($c['firstName'] ?? '');
    $mn = (string)($c['middleName'] ?? '');
    $fio = trim("$ln $fn $mn");

    $email = $c['communicationChannels']['email'][0] ?? '';
    $phone = $c['communicationChannels']['phone'][0] ?? '';

    $fwRespId = 0;
    $statusDateFormatted = '-';

    if (!empty($c['activeCandidateStatuses'][0])) {
        $active = $c['activeCandidateStatuses'][0];
        $historyId = $active['candidateHistoryId'] ?? 0;

        if (!empty($c['histories']) && $historyId) {
            foreach ($c['histories'] as $h) {
                if (!empty($h['candidateHistoryId']) && (int)$h['candidateHistoryId'] === (int)$historyId) {
                    $fwRespId = (int)($h['responsibleId'] ?? 0);
                    $dc = (string)($h['dateCreated'] ?? '');
                    $ts = $dc ? strtotime($dc) : false;
                    if ($ts) $statusDateFormatted = date('d.m.Y H:i', $ts);
                    break;
                }
            }
        }
    }

    $fwRespName = $fwRespId ? ($externalAcc[$fwRespId] ?? ("ID ".$fwRespId)) : "-";

    $link = "?job_id=".h($jobRequestId)."&candidate_id=".h($candidateId);
    if (!empty($returnUrl)) {
        $link .= "&return_url=" . urlencode($returnUrl);
    }

    echo "<tr>
            <td>".h($candidateId)."</td>
            <td>".h($fio)."</td>
            <td>".h($email)."</td>
            <td>".h($phone)."</td>
            <td>".h($statusDateFormatted)."</td>
            <td>".h($fwRespName)."</td>
            <td><a href='".$link."' style='font-weight:bold'>Начать проверку</a></td>
          </tr>";
}

echo "</table>";

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');