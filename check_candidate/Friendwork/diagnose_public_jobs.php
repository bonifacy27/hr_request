<?php
/**
 * Read-only diagnostics for FriendWork Public API jobs.
 * Checks whether GET /jobs/{jobId} exposes candidates in its response.
 */

use Bitrix\Main\Application;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

global $APPLICATION, $USER;
$APPLICATION->SetTitle('Диагностика FriendWork Public API');

if (!$USER || !$USER->IsAuthorized()) {
    die('Требуется авторизация.');
}

const FW_DIAG_API_URL = 'https://api.friend.work';
const FW_DIAG_TOKEN_CONST_ID = 'Constant1789370789700';
const FW_DIAG_DEFAULT_JOB_ID = 294031;
const FW_DIAG_CONNECT_TIMEOUT = 10;
const FW_DIAG_REQUEST_TIMEOUT = 30;

function fwDiagH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fwDiagDecodeConstant($raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    $decoded = @unserialize($raw, ['allowed_classes' => false]);
    if (is_array($decoded)) return trim((string)($decoded['value'] ?? $decoded[0] ?? ''));
    if (is_string($decoded)) return trim($decoded);

    $unescaped = stripcslashes($raw);
    $decoded = @unserialize($unescaped, ['allowed_classes' => false]);
    if (is_string($decoded)) return trim($decoded);
    if (preg_match('/^s:\d+:"(.*)";$/s', $unescaped, $matches)) return trim($matches[1]);

    return $raw;
}

function fwDiagGetToken(): array
{
    try {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $id = $helper->forSql(FW_DIAG_TOKEN_CONST_ID);
        $row = $connection->query("SELECT PROPERTY_VALUE FROM b_bp_global_const WHERE ID = '" . $id . "'")->fetch();
        $token = fwDiagDecodeConstant($row['PROPERTY_VALUE'] ?? '');
        return ['token' => $token, 'error' => $token === '' ? 'Константа токена пуста или не найдена.' : ''];
    } catch (\Throwable $e) {
        return ['token' => '', 'error' => $e->getMessage()];
    }
}

function fwDiagRequest(string $token, string $path): array
{
    $responseHeaders = [];
    $ch = curl_init(FW_DIAG_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => FW_DIAG_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => FW_DIAG_REQUEST_TIMEOUT,
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $length;
        },
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'url' => FW_DIAG_API_URL . $path,
        'http' => $http,
        'error' => $error,
        'trace_id' => $responseHeaders['x-trace-id'] ?? '',
        'content_type' => $responseHeaders['content-type'] ?? '',
        'raw' => (string)$raw,
        'data' => json_decode((string)$raw, true),
    ];
}

function fwDiagCandidateKeys($value, string $path = ''): array
{
    if (!is_array($value)) return [];
    $found = [];
    foreach ($value as $key => $child) {
        $childPath = $path === '' ? (string)$key : $path . '.' . $key;
        if (stripos((string)$key, 'candidate') !== false) $found[] = $childPath;
        $found = array_merge($found, fwDiagCandidateKeys($child, $childPath));
    }
    return array_values(array_unique($found));
}

$jobId = max(1, (int)($_GET['job_id'] ?? FW_DIAG_DEFAULT_JOB_ID));
$credentials = fwDiagGetToken();
$result = null;
$candidateKeys = [];

if ($credentials['error'] === '') {
    $result = fwDiagRequest($credentials['token'], '/jobs/' . $jobId);
    $candidateKeys = fwDiagCandidateKeys($result['data']);
}
?>

<style>
.fw-diag {max-width:1100px;font-family:Arial,sans-serif}.fw-diag pre{white-space:pre-wrap;word-break:break-word;background:#f5f5f5;padding:12px;border:1px solid #ddd}.fw-ok{color:#19733b}.fw-warn{color:#9a6200}.fw-error{color:#b42318}
</style>
<div class="fw-diag">
    <form method="get">
        <label>ID вакансии: <input type="number" min="1" name="job_id" value="<?=fwDiagH($jobId)?>" required></label>
        <button type="submit">Проверить GET /jobs/{jobId}</button>
    </form>

    <p>Токен: <b><?= $credentials['error'] === '' ? 'найден в ' . fwDiagH(FW_DIAG_TOKEN_CONST_ID) : 'недоступен' ?></b></p>
    <?php if ($credentials['error'] !== ''): ?>
        <p class="fw-error"><?=fwDiagH($credentials['error'])?></p>
    <?php elseif ($result !== null): ?>
        <h3>Результат read-only запроса</h3>
        <ul>
            <li>URL: <code><?=fwDiagH($result['url'])?></code></li>
            <li>HTTP: <b><?=fwDiagH($result['http'])?></b></li>
            <li>Content-Type: <?=fwDiagH($result['content_type'] ?: '-')?></li>
            <li>X-Trace-Id: <?=fwDiagH($result['trace_id'] ?: '-')?></li>
            <li>cURL: <?=fwDiagH($result['error'] ?: 'ошибок нет')?></li>
        </ul>

        <?php if ($result['http'] === 200 && empty($candidateKeys)): ?>
            <p class="fw-warn"><b>Кандидаты не найдены в ответе.</b> Endpoint возвращает карточку вакансии, но не список кандидатов.</p>
        <?php elseif (!empty($candidateKeys)): ?>
            <p class="fw-ok"><b>В ответе найдены поля, содержащие candidate:</b> <?=fwDiagH(implode(', ', $candidateKeys))?></p>
        <?php else: ?>
            <p class="fw-error"><b>Запрос не подтверждён.</b> Проверьте HTTP-код, тело ответа и X-Trace-Id.</p>
        <?php endif; ?>

        <h3>Ответ FriendWork</h3>
        <pre><?=fwDiagH(is_array($result['data'])
            ? json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : mb_substr($result['raw'], 0, 20000))?></pre>
    <?php endif; ?>

    <p><b>Важно:</b> скрипт выполняет только документированный GET и ничего не создаёт и не изменяет.</p>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
