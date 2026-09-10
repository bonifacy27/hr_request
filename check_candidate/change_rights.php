<?php
/**
 * Служебная страница для пакетного запуска БП изменения прав по всем анкетам.
 *
 * Шаблон: /services/lists/207/bp_edit/844/
 * Список:  /services/lists/207/view/0/?list_section_id=
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;

$isBatchRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'start_batch';

require $_SERVER['DOCUMENT_ROOT'] . ($isBatchRequest
    ? '/bitrix/modules/main/include/prolog_before.php'
    : '/bitrix/header.php');

const CANDIDATE_IBLOCK_ID = 207;
const RIGHTS_WORKFLOW_TEMPLATE_ID = 844;
const WORKFLOW_BATCH_SIZE = 20;

function rightsWorkflowJson(array $response, int $status = 200): void
{
    global $APPLICATION;

    if (is_object($APPLICATION)) {
        $APPLICATION->RestartBuffer();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rightsWorkflowErrorText(array $errors): string
{
    $messages = [];
    foreach ($errors as $error) {
        if (is_array($error)) {
            $messages[] = (string)($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE));
        } else {
            $messages[] = (string)$error;
        }
    }

    return implode('; ', array_filter($messages));
}

if (!Loader::includeModule('iblock') || !Loader::includeModule('lists') || !Loader::includeModule('bizproc')) {
    if ($isBatchRequest) {
        rightsWorkflowJson(['success' => false, 'message' => 'Не удалось подключить модули iblock/lists/bizproc.'], 500);
    }
    ShowError('Не удалось подключить модули iblock/lists/bizproc.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

global $USER, $APPLICATION;
if (!$USER || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    if ($isBatchRequest) {
        rightsWorkflowJson(['success' => false, 'message' => 'Запуск доступен только администратору.'], 403);
    }
    ShowError('Запуск доступен только администратору.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

if ($isBatchRequest) {
    if (!check_bitrix_sessid()) {
        rightsWorkflowJson(['success' => false, 'message' => 'Сессия истекла. Обновите страницу.'], 403);
    }

    $lastId = max(0, (int)($_POST['last_id'] ?? 0));
    $maxId = max(0, (int)($_POST['max_id'] ?? 0));
    if ($maxId <= 0) {
        rightsWorkflowJson(['success' => false, 'message' => 'Не задана граница обрабатываемого списка.'], 400);
    }

    $elements = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => CANDIDATE_IBLOCK_ID,
            '>ID' => $lastId,
            '<=ID' => $maxId,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        ['nTopCount' => WORKFLOW_BATCH_SIZE],
        ['ID', 'NAME']
    );

    $processed = 0;
    $started = 0;
    $failures = [];
    $nextLastId = $lastId;

    while ($element = $elements->Fetch()) {
        $elementId = (int)$element['ID'];
        $nextLastId = $elementId;
        $processed++;
        $errors = [];

        try {
            $workflowId = CBPDocument::StartWorkflow(
                RIGHTS_WORKFLOW_TEMPLATE_ID,
                ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
                [],
                $errors
            );
        } catch (Throwable $exception) {
            $workflowId = false;
            $errors[] = $exception->getMessage();
        }

        if ($workflowId !== false && !$errors) {
            $started++;
            continue;
        }

        $failures[] = [
            'id' => $elementId,
            'name' => (string)$element['NAME'],
            'error' => rightsWorkflowErrorText($errors) ?: 'Бизнес-процесс вернул пустой идентификатор.',
        ];
    }

    rightsWorkflowJson([
        'success' => true,
        'processed' => $processed,
        'started' => $started,
        'failed' => count($failures),
        'failures' => $failures,
        'last_id' => $nextLastId,
        'done' => $processed === 0 || $nextLastId >= $maxId,
    ]);
}

$APPLICATION->SetTitle('Пакетный запуск БП изменения прав');

$total = (int)CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => CANDIDATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'],
    [],
    false,
    ['ID']
);

$maxElement = CIBlockElement::GetList(
    ['ID' => 'DESC'],
    ['IBLOCK_ID' => CANDIDATE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'],
    false,
    ['nTopCount' => 1],
    ['ID']
)->Fetch();
$maxId = (int)($maxElement['ID'] ?? 0);

$sessid = bitrix_sessid();
?>
<style>
.rights-tool{max-width:820px;margin:24px auto;padding:22px;background:#fff;border:1px solid #dfe3e8;border-radius:8px}
.rights-tool h2{margin:0 0 14px}.rights-tool__warning{padding:14px;background:#fff4ce;border-radius:6px;margin:16px 0}
.rights-tool__actions{display:flex;align-items:center;gap:14px}.rights-tool button{padding:9px 18px;border:0;border-radius:4px;background:#2fc6f6;color:#fff;cursor:pointer;font-weight:600}
.rights-tool button:disabled{background:#a8adb4;cursor:default}.rights-tool progress{width:100%;height:22px;margin:18px 0 8px}
.rights-tool__status{font-weight:600}.rights-tool__errors{margin-top:14px;color:#a52a2a;white-space:pre-wrap}
</style>
<div class="rights-tool">
    <h2>Запуск БП изменения прав</h2>
    <p>Найдено анкет кандидатов: <strong><?= $total ?></strong>.</p>
    <div class="rights-tool__warning">
        Бизнес-процесс №<?= RIGHTS_WORKFLOW_TEMPLATE_ID ?> будет запущен по каждой анкете списка №<?= CANDIDATE_IBLOCK_ID ?>.
        Повторный запуск создаст новые экземпляры процесса.
    </div>
    <div class="rights-tool__actions">
        <button type="button" id="rights-start"<?= $total === 0 ? ' disabled' : '' ?>>Запустить по всем анкетам</button>
        <span id="rights-counter">0 / <?= $total ?></span>
    </div>
    <progress id="rights-progress" max="<?= max(1, $total) ?>" value="0"></progress>
    <div class="rights-tool__status" id="rights-status">Ожидание запуска.</div>
    <div class="rights-tool__errors" id="rights-errors"></div>
</div>
<script>
(function () {
    'use strict';

    const button = document.getElementById('rights-start');
    if (!button) return;

    const progress = document.getElementById('rights-progress');
    const counter = document.getElementById('rights-counter');
    const status = document.getElementById('rights-status');
    const errors = document.getElementById('rights-errors');
    const total = <?= $total ?>;
    const maxId = <?= $maxId ?>;
    let processed = 0;
    let started = 0;
    let failed = 0;
    let lastId = 0;

    async function runBatch() {
        const body = new URLSearchParams({
            action: 'start_batch',
            sessid: <?= json_encode($sessid) ?>,
            last_id: String(lastId),
            max_id: String(maxId)
        });
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Ошибка пакетного запуска.');
        }

        processed += Number(result.processed || 0);
        started += Number(result.started || 0);
        failed += Number(result.failed || 0);
        lastId = Number(result.last_id || lastId);
        progress.value = Math.min(processed, total);
        counter.textContent = processed + ' / ' + total;
        status.textContent = 'Запущено: ' + started + '. Ошибок: ' + failed + '.';

        (result.failures || []).forEach(function (failure) {
            errors.textContent += 'Анкета #' + failure.id + ' (' + failure.name + '): ' + failure.error + '\n';
        });

        if (!result.done) {
            await runBatch();
            return;
        }

        button.textContent = 'Выполнено';
        status.textContent = 'Обработка завершена. Запущено: ' + started + '. Ошибок: ' + failed + '.';
    }

    button.addEventListener('click', async function () {
        if (!window.confirm('Запустить БП №<?= RIGHTS_WORKFLOW_TEMPLATE_ID ?> по всем <?= $total ?> анкетам?')) return;
        button.disabled = true;
        button.textContent = 'Выполняется…';
        status.textContent = 'Запускаем бизнес-процессы пакетами по <?= WORKFLOW_BATCH_SIZE ?> анкет…';
        try {
            await runBatch();
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Продолжить запуск';
            status.textContent = 'Запуск прерван. Нажмите «Продолжить запуск», чтобы повторить текущий пакет.';
            errors.textContent += error.message + '\n';
        }
    });
}());
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>
