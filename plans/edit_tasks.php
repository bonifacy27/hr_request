<?php
/** Редактирование только KPI-задач плана ввода в должность. */
define('BX_COMPOSITE_DO_NOT_CACHE', true);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once __DIR__ . '/edit_tasks_lib.php';
require_once __DIR__ . '/delegate_permissions.php';
foreach (['iblock', 'lists', 'bizproc'] as $module) {
    if (!\Bitrix\Main\Loader::includeModule($module)) die('Не удалось подключить модуль ' . $module);
}
global $USER, $APPLICATION;
if (!$USER || !$USER->IsAuthorized()) die('Требуется авторизация.');
$planId = (int)($_GET['PLAN_ID'] ?? $_GET['id'] ?? $_GET['ID'] ?? 0);
$plan = $planId > 0 ? CIBlockElement::GetList([], ['IBLOCK_ID' => 359, 'ID' => $planId, 'CHECK_PERMISSIONS' => 'Y'], false, false,
    ['ID', 'NAME', 'PROPERTY_2775', 'PROPERTY_2796', 'PROPERTY_2776', 'PROPERTY_2802', 'PROPERTY_' . KPI_EDIT_ONCE_PROPERTY_ID])->Fetch() : false;
if (!$plan) die('План не найден или недоступен. Укажите PLAN_ID или id.');
function kpiEditUserId($value) {
    if (is_array($value)) $value = reset($value);
    return preg_match('/^(?:user_)?([0-9]+)$/i', trim((string)$value), $matches) ? (int)$matches[1] : 0;
}
$managerId = kpiEditUserId($plan['PROPERTY_2775_VALUE'] ?? '');
$recruiterId = kpiEditUserId($plan['PROPERTY_2796_VALUE'] ?? '');
$userId = (int)$USER->GetID();
if (!$USER->IsAdmin() && $userId !== $managerId && !plansCanReplaceManager($userId, $recruiterId)) die('Недостаточно прав для редактирования KPI этого ПВД.');
$types = [];
$result = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => 363, 'CODE' => 'TIP_ZADACHI_KPI']);
while ($type = $result->Fetch()) $types[(int)$type['ID']] = (string)$type['VALUE'];
$tomorrow = kpiEditTomorrow();
$today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
$window = null;
$editUnavailable = '';
try {
    $definition = CIBlockProperty::GetList([], ['IBLOCK_ID' => 359, 'ID' => KPI_EDIT_ONCE_PROPERTY_ID])->Fetch();
    if (!$definition || $definition['PROPERTY_TYPE'] !== 'S' || ($definition['USER_TYPE'] ?? '') !== 'DateTime' || $definition['MULTIPLE'] !== 'N') {
        throw new RuntimeException('Для редактирования необходимо одиночное поле типа «Дата/время» с ID ' . KPI_EDIT_ONCE_PROPERTY_ID . ' в инфоблоке ПВД №359.');
    }
    $window = kpiEditWindow($plan['PROPERTY_2776_VALUE'] ?? '', $plan['PROPERTY_2802_VALUE'] ?? '');
    kpiEditCheckWindow($window, $plan['PROPERTY_' . KPI_EDIT_ONCE_PROPERTY_ID . '_VALUE'] ?? '', $today);
} catch (Throwable $exception) {
    $editUnavailable = $exception->getMessage();
}
$rows = [];
$error = '';
$success = '';
$preview = null;
$draft = null;
$connection = \Bitrix\Main\Application::getConnection();
$transaction = false;
try {
    $rows = kpiEditLoad($planId);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!check_bitrix_sessid()) throw new RuntimeException('Сессия истекла. Обновите страницу.');
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'retry' && $editUnavailable !== '') throw new RuntimeException($editUnavailable);
        if ($action === 'preview') {
            if (!empty($_SESSION['KPI_EDIT_JOBS'][$planId])) throw new RuntimeException('Сначала завершите отправку уведомления о предыдущем сохранении.');
            if (!hash_equals(hash('sha256', serialize([kpiEditPlanState($plan), $rows])), (string)($_POST['revision'] ?? ''))) {
                throw new RuntimeException('Задачи изменились после открытия формы. Обновите страницу.');
            }
            $submitted = $_POST['rows'] ?? [];
            if (!is_array($submitted)) throw new RuntimeException('Некорректные данные KPI.');
            $draft = $submitted;
            $changes = kpiEditChanges($rows, $submitted, $types, $tomorrow, $window['last']);
            if (!$changes) throw new RuntimeException('Изменений нет.');
            if ($managerId <= 0 && array_filter($changes, function ($change) { return $change['action'] === 'add'; })) {
                throw new RuntimeException('В ПВД не указан руководитель для новых задач.');
            }
            $preview = [
                'submitted' => $submitted, 'revision' => hash('sha256', serialize([kpiEditPlanState($plan), $rows])),
                'description' => kpiEditDescribe($changes, $types), 'token' => bin2hex(random_bytes(24)), 'user' => $userId,
            ];
            $_SESSION['KPI_EDIT_PENDING'][$planId] = $preview;
        } elseif ($action === 'apply') {
            $pending = $_SESSION['KPI_EDIT_PENDING'][$planId] ?? [];
            if (($pending['user'] ?? 0) !== $userId || !hash_equals($pending['token'] ?? '', (string)($_POST['token'] ?? ''))) {
                throw new RuntimeException('Подтверждение устарело. Повторите проверку изменений.');
            }
            $connection->startTransaction();
            $transaction = true;
            // Сериализуем сохранения этого ПВД и повторно читаем статусы и сроки.
            $connection->queryExecute('SELECT ID FROM b_iblock_element WHERE ID = ' . $planId . ' FOR UPDATE');
            $currentPlan = CIBlockElement::GetList([], ['IBLOCK_ID' => 359, 'ID' => $planId], false, false, ['ID', 'PROPERTY_2775', 'PROPERTY_2796', 'PROPERTY_2776', 'PROPERTY_2802', 'PROPERTY_' . KPI_EDIT_ONCE_PROPERTY_ID])->Fetch();
            $currentManager = kpiEditUserId($currentPlan['PROPERTY_2775_VALUE'] ?? '');
            $currentRecruiter = kpiEditUserId($currentPlan['PROPERTY_2796_VALUE'] ?? '');
            if (!$currentPlan || (!$USER->IsAdmin() && $userId !== $currentManager && !plansCanReplaceManager($userId, $currentRecruiter))) {
                throw new RuntimeException('Права на редактирование ПВД изменились.');
            }
            $rows = kpiEditLoad($planId);
            if (!hash_equals($pending['revision'], hash('sha256', serialize([kpiEditPlanState($currentPlan), $rows])))) {
                throw new RuntimeException('Задачи или руководитель изменились. Повторите проверку изменений.');
            }
            $currentWindow = kpiEditWindow($currentPlan['PROPERTY_2776_VALUE'] ?? '', $currentPlan['PROPERTY_2802_VALUE'] ?? '');
            kpiEditCheckWindow($currentWindow, $currentPlan['PROPERTY_' . KPI_EDIT_ONCE_PROPERTY_ID . '_VALUE'] ?? '', (new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow')))->format('Y-m-d'));
            $changes = kpiEditChanges($rows, $pending['submitted'], $types, kpiEditTomorrow(), $currentWindow['last']);
            $description = kpiEditDescribe($changes, $types);
            if ($description !== $pending['description']) throw new RuntimeException('Список изменений устарел. Повторите проверку.');
            $author = CUser::GetByID($userId)->Fetch();
            $authorName = trim(($author['LAST_NAME'] ?? '') . ' ' . ($author['NAME'] ?? '') . ' ' . ($author['SECOND_NAME'] ?? ''));
            $stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i:s');
            $jobToken = bin2hex(random_bytes(24));
            $created = kpiEditSave($planId, $changes, $rows, $types, $currentManager);
            CIBlockElement::SetPropertyValuesEx($planId, 359, [KPI_EDIT_ONCE_PROPERTY_ID => $stamp]);
            $savedMarker = CIBlockElement::GetProperty(359, $planId, [], ['ID' => KPI_EDIT_ONCE_PROPERTY_ID])->Fetch();
            if (!$savedMarker || strtotime((string)$savedMarker['VALUE']) !== strtotime($stamp)) throw new RuntimeException('Не удалось сохранить признак однократного редактирования.');
            $text = 'В ПВД #' . $planId . ' ' . $plan['NAME'] . " внесены изменения задач:\n"
                . 'Автор: ' . ($authorName ?: 'Пользователь #' . $userId) . "\n"
                . 'Дата изменений: ' . $stamp . ".\n\n" . kpiEditDescribe($changes, $types, true, $created);
            $connection->commitTransaction();
            $transaction = false;
            unset($_SESSION['KPI_EDIT_PENDING'][$planId]);
            // Уведомление можно повторить без повторного сохранения или удаления задач.
            $_SESSION['KPI_EDIT_JOBS'][$planId] = ['created' => $created, 'text' => $text, 'user' => $userId, 'token' => $jobToken];
        } elseif ($action === 'retry') {
            $job = $_SESSION['KPI_EDIT_JOBS'][$planId] ?? [];
            if (($job['user'] ?? 0) !== $userId || !hash_equals($job['token'] ?? '', (string)($_POST['token'] ?? ''))) {
                throw new RuntimeException('Повторная отправка уведомления недоступна.');
            }
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
        if (in_array($action, ['apply', 'retry'], true)) {
            $job =& $_SESSION['KPI_EDIT_JOBS'][$planId];
            while ($job['created']) {
                kpiEditStartWorkflow(1364, $job['created'][0]);
                array_shift($job['created']);
            }
            kpiEditStartWorkflow(1372, $planId, ['par_Changes' => $job['text']]);
            unset($_SESSION['KPI_EDIT_JOBS'][$planId]);
            $success = 'KPI-задачи сохранены. Бизнес-процесс уведомления запущен.';
            $editUnavailable = 'KPI-задачи этого ПВД уже редактировались. Повторное редактирование невозможно.';
            $rows = kpiEditLoad($planId);
        }
    }
} catch (Throwable $exception) {
    if ($transaction) $connection->rollbackTransaction();
    $error = $exception->getMessage();
    if (!empty($_SESSION['KPI_EDIT_JOBS'][$planId])) $error = 'Задачи уже сохранены, но запуск бизнес-процессов не завершен. ' . $error;
}
$revision = hash('sha256', serialize([kpiEditPlanState($plan), $rows]));
$retryJob = $_SESSION['KPI_EDIT_JOBS'][$planId] ?? null;
$APPLICATION->SetTitle('Редактирование KPI-задач ПВД');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_after.php');
?>
<style>
.kpi-editor {max-width:1200px;margin:24px auto;padding:20px;font:14px/1.5 Arial,sans-serif}
.kpi-editor table {width:100%;border-collapse:collapse;margin:16px 0}
.kpi-editor th,.kpi-editor td {border:1px solid #ccc;padding:10px;vertical-align:top}
.kpi-editor th {background:#f0f0f0}.kpi-editor textarea {width:100%;min-height:80px;box-sizing:border-box}
.kpi-editor select,.kpi-editor input {max-width:100%;box-sizing:border-box;padding:6px}
.kpi-editor .locked {background:#f7eeee}.kpi-editor small {display:block;color:#983434}
.kpi-editor .actions {display:flex;gap:12px;margin-top:16px;flex-wrap:wrap}
.kpi-editor .notice {padding:12px;background:#fff3cd;margin:12px 0}
.kpi-editor .success {padding:12px;background:#dcf3df}.kpi-editor .changes {white-space:pre-wrap;background:#f5f5f5;padding:16px}
.kpi-editor .table-scroll {overflow:auto}.kpi-editor button {padding:8px 14px;cursor:pointer}
</style>
<div class="kpi-editor">
<h2>KPI задачи (обязательно минимум 1 строка)</h2>
<p>ПВД: <?= kpiEditH($plan['NAME']) ?> (#<?= $planId ?>)</p>
<?php if ($window): ?><p>Редактирование доступно один раз, с <?= date('d.m.Y', strtotime($window['first'])) ?> по <?= date('d.m.Y', strtotime($window['last'])) ?> включительно. Планируемый срок измененных и новых задач — не позднее <?= date('d.m.Y', strtotime($window['last'])) ?>.</p><?php endif; ?>
<p>Можно изменить или удалить задачи в статусе «Инициализация» с текущим сроком не ранее <?= date('d.m.Y', strtotime($tomorrow)) ?>. Срок новых и измененных задач — также не ранее этой даты.</p>
<?php if ($editUnavailable && !$error): ?><div class="notice"><?= kpiEditH($editUnavailable) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice" role="alert"><?= kpiEditH($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="success" role="status"><?= kpiEditH($success) ?></div><?php endif; ?>
<?php if ($retryJob): ?>
<pre class="changes"><?= kpiEditH($retryJob['text']) ?></pre>
<?php if ($retryJob['user'] === $userId): ?>
<form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="token" value="<?= kpiEditH($retryJob['token']) ?>"><button type="submit">Повторить запуск уведомления</button></form>
<?php endif; ?>
<?php elseif ($preview): ?>
<h3>Будет изменено</h3>
<pre class="changes"><?= kpiEditH($preview['description']) ?></pre>
<form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="<?= kpiEditH($preview['token']) ?>">
<div class="actions"><button type="submit">Подтвердить и сохранить</button><button type="button" id="back-to-edit">Продолжить редактирование</button></div></form>
<?php endif; ?>
<form method="post" id="kpi-edit-form" <?= $preview || $retryJob || $editUnavailable ? 'hidden' : '' ?>>
<?= bitrix_sessid_post() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="revision" value="<?= kpiEditH($revision) ?>">
<div class="table-scroll"><table id="kpi-table"><thead><tr><th>Тип задачи</th><th>Планируемый результат</th><th>Вес (%)</th><th>Планируемый срок</th><th>Действие</th></tr></thead><tbody id="kpi-rows"></tbody></table></div>
<div class="actions"><button type="button" id="add-kpi">Добавить еще задачу</button><button type="submit">Проверить изменения</button></div>
</form>
<p><a href="/forms/staff_recruitment/plans/list.php">Вернуться к списку ПВД</a></p>
</div>
<script>
(function () {
    var types = <?= json_encode($types, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var existing = <?= json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var submitted = <?= json_encode($preview ? $preview['submitted'] : $draft, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var latest = <?= json_encode($window['last'] ?? '') ?>;
    var tomorrow = <?= json_encode($tomorrow) ?>;
    var body = document.getElementById('kpi-rows');
    var index = 0;
    function escape(value) {var node = document.createElement('span'); node.textContent = String(value == null ? '' : value); return node.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');}
    function addRow(row) {
        row = row || {};
        var name = 'rows[' + index++ + ']';
        var old = existing.find(function (task) {return task.id === Number(row.id);});
        var locked = old && (old.status !== 3396791 || !old.due_date || old.due_date < tomorrow);
        var tr = document.createElement('tr');
        if (locked) {
            tr.className = 'locked';
            tr.innerHTML = '<td><input type="hidden" name="' + name + '[id]" value="' + old.id + '">' + escape(types[old.type] || old.name) + '</td><td>' + escape(old.planned_result) + '</td><td>' + old.weight + '</td><td>' + escape(old.due_date) + '</td><td><small>' + (old.status !== 3396791 ? 'Статус отличается от «Инициализация».' : 'Текущий срок раньше завтрашнего дня.') + '</small></td>';
        } else {
            var options = '<option value="">Выберите тип</option>';
            Object.keys(types).forEach(function (id) {options += '<option value="' + id + '"' + (Number(id) === Number(row.type) ? ' selected' : '') + '>' + escape(types[id]) + '</option>';});
            tr.innerHTML = '<td><input type="hidden" name="' + name + '[id]" value="' + (Number(row.id) || 0) + '"><select name="' + name + '[type]" required>' + options + '</select></td>'
                + '<td><textarea name="' + name + '[planned_result]" required>' + escape(row.planned_result) + '</textarea></td>'
                + '<td><input type="number" name="' + name + '[weight]" min="1" step="1" required value="' + (Number(row.weight) || '') + '"></td>'
                + '<td><input type="date" name="' + name + '[due_date]" min="' + tomorrow + '" max="' + latest + '" required value="' + escape(row.due_date) + '"></td>'
                + '<td><button type="button" class="delete-kpi">Удалить</button></td>';
            tr.querySelector('.delete-kpi').addEventListener('click', function () {tr.remove();});
            // Старые неизмененные сроки сохраняются. Верхний предел применяется при любом изменении строки.
            var dateInput = tr.querySelector('input[type="date"]');
            function updateDateLimit() {
                var unchanged = old
                    && Number(tr.querySelector('select').value) === old.type
                    && tr.querySelector('textarea').value.trim() === old.planned_result.trim()
                    && Number(tr.querySelector('input[type="number"]').value) === old.weight
                    && dateInput.value === old.due_date;
                dateInput.max = unchanged ? '' : latest;
            }
            tr.addEventListener('input', updateDateLimit);
            tr.addEventListener('change', updateDateLimit);
            updateDateLimit();
        }
        body.appendChild(tr);
    }
    (submitted || existing).forEach(addRow);
    if (!(submitted || existing).length) addRow();
    document.getElementById('add-kpi').addEventListener('click', function () {addRow();});
    var back = document.getElementById('back-to-edit');
    if (back) back.addEventListener('click', function () {document.getElementById('kpi-edit-form').hidden = false; back.closest('form').hidden = true;});
}());
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog.php'); ?>
