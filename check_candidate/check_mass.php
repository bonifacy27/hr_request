<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
while (ob_get_level()) ob_end_flush();
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

echo "<div style='font-size:11px;color:#777'>check_mass.php v4.0 (Hybrid FW API + Sorting + Whitelist)</div>";

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

$tmpCookie = __DIR__ . '/fw_cookie.txt';

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
        CURLOPT_HTTPHEADER     => $headers
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
        CURLOPT_SSL_VERIFYHOST => false
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
fwInternalAuth();

/* =====================================================================
    2) GET ALL CANDIDATES VIA PAGINATION + RATE LIMIT (20 req/min)
   ===================================================================== */
echo "<h3>Загрузка кандидатов из FriendWork…</h3>";
$allCandidates = [];
$page = 1;
$perPage = 20;
$requests = 0;
$minuteStart = time();

while (true) {
    $now = time();
    $elapsed = $now - $minuteStart;
    if ($elapsed >= 60) {
        $minuteStart = $now;
        $requests = 0;
    }
    if ($requests >= 20) {
        $wait = 60 - $elapsed;
        if ($wait < 1) $wait = 1;
        echo "<b>Лимит 20 запросов/мин достигнут → ждем $wait сек…</b><br>";
        sleep($wait);
        $minuteStart = $now;
        $requests = 0;
    }

    $payload = [
        "page"          => $page,
        "perPageCount"  => $perPage,
        "statuses"      => [212069],
        "jobId"         => $jobId
    ];
    $fwPage = fwInternal("POST", "/Candidates", $payload);
    $requests++;

    if ($fwPage['http'] != 200) {
        $raw = $fwPage['raw'];
        if (strpos($raw, 'too many calls') !== false) {
            echo "<b>FriendWork: too many calls → ждём 10 сек…</b><br>";
            flush();
            sleep(10);
            continue;
        }
        echo "<h2>Ошибка получения кандидатов (страница $page)</h2>";
        echo "<pre>".htmlspecialchars($raw)."</pre>";
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
        exit;
    }

    $chunk = $fwPage['data']['candidates'] ?? [];
    $count = count($chunk);
    echo "Страница $page: получено $count кандидатов<br>";
    flush(); ob_flush();

    if ($count === 0) {
        echo "<b>FriendWork перестал отдавать данные — останов.</b><br>";
        break;
    }
    $allCandidates = array_merge($allCandidates, $chunk);
    if ($count < $perPage) {
        break;
    }
    $page++;
}

$candidates = $allCandidates;
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
if ($_REQUEST['export'] === 'excel' && isset($_REQUEST['results'])) {
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

    $currentUserId = (int)$USER->GetID();
    $diagnostic = [];
    $createdElements = [];
    $elementData = [];

    echo "<h2>Обработка выбранных кандидатов</h2>";

    foreach ($candidates as $c) {
        $candidateId = $c['candidateId'];
        if (!in_array($candidateId, $selected)) continue;

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
            $wfId1 = CBPDocument::StartWorkflow(
                466,
                ['lists', 'BizprocDocument', $elementId],
                [],
                $errors
            );
            echo "<span style='color:blue'>БП #1 запущен: $wfId1</span><br>";

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
            echo "<span style='color:red'>Ошибка создания элемента: {$el->LAST_ERROR}</span><br>";
        }
    }

    echo "<h2>Запуск БП #2</h2>";
    foreach ($createdElements as $elementId) {
        $errors = [];
        $wfId2 = CBPDocument::StartWorkflow(
            328,
            ['lists', 'BizprocDocument', $elementId],
            [],
            $errors
        );
        echo "<hr>Анкета $elementId — БП #2 запущен: $wfId2<br>";
        $link = '';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $props = CIBlockElement::GetProperty(207, $elementId);
            while ($p = $props->Fetch()) {
                if ($p['CODE'] == "SSYLKA_NA_ANKETU") $link = $p['VALUE'];
                if ($p['CODE'] == "PAROL_ANKETY")    $password = $p['VALUE'];
            }
            if ($link || $password) break;
            usleep(500000);
        }
        $elementData[$elementId]['LINK'] = $link;
        $elementData[$elementId]['PASSWORD'] = $password;
        echo "Ссылка: <b>$link</b><br>";
        echo "Пароль: <b>$password</b><br>";
    }

    $json = htmlspecialchars(json_encode($elementData));
    echo "<br><hr>";
    echo "<a href='?job_id=$jobId&export=excel&results=$json'
           style='font-size:14px; display:inline-block; margin-bottom:10px;'>
           📄 Экспорт в Excel
          </a>";

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
                <td>";
        if ($d['LINK']) {
            echo "<a href='{$d['LINK']}' target='_blank'>{$d['LINK']}</a>";
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
<form method="get">
    <input type="hidden" name="job_id" value="<?=htmlspecialchars($jobId)?>">
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