<?php
/** Передача плана ввода в должность новому руководителю. */
define('BX_COMPOSITE_DO_NOT_CACHE', true);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Делегировать ПВД');
\Bitrix\Main\UI\Extension::load(['main.core', 'ui.entity-selector']);
foreach (['iblock', 'lists', 'bizproc'] as $module) {
    if (!\Bitrix\Main\Loader::includeModule($module)) {
        ShowError('Не удалось подключить модуль ' . $module);
        require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
        return;
    }
}
global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    ShowError('Требуется авторизация.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
function delegateH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function delegateUserId($value)
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return preg_match('/(\d+)/', (string)$value, $matches) ? (int)$matches[1] : 0;
}

function delegateUserName($userId)
{
    $user = CUser::GetByID((int)$userId)->Fetch();
    if (!$user) {
        return '—';
    }
    $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
    return $name !== '' ? $name : (string)$user['LOGIN'];
}

function delegateDocumentIds($elementId, $iblockId)
{
    return [
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
        ['lists', 'BizprocDocument', 'lists_' . (int)$iblockId . '_' . (int)$elementId],
        ['lists', 'lists_' . (int)$iblockId . '_group_206', (int)$elementId],
        ['lists', 'lists_' . (int)$iblockId, (int)$elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . (int)$iblockId . '_' . (int)$elementId],
    ];
}

function delegateLinkedIds($planId, $propertyId)
{
    $result = [];
    $properties = CIBlockElement::GetProperty(
        359,
        (int)$planId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['ID' => (int)$propertyId]
    );
    while ($property = $properties->Fetch()) {
        $id = (int)($property['VALUE'] ?? 0);
        if ($id > 0) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}


function delegateAssignments($id, $iblock)
{
    $assignments = [];
    foreach (delegateDocumentIds($id, $iblock) as $document) {
        $result = CBPTaskService::GetList(['ID' => 'DESC'], [
            'DOCUMENT_ID' => $document, 'STATUS' => CBPTaskStatus::Running,
            'USER_STATUS' => CBPTaskUserStatus::Waiting,
        ], false, false, ['ID', 'USER_ID']);
        while ($task = $result->Fetch()) {
            if ((int)$task['USER_ID'] > 0) {
                $assignments[(int)$task['ID'] . ':' . (int)$task['USER_ID']] = [
                    'ID' => (int)$task['ID'], 'USER_ID' => (int)$task['USER_ID'],
                ];
            }
        }
    }
    ksort($assignments);
    return array_values($assignments);
}

function delegateRows($planId)
{
    $rows = [];
    foreach ([360 => [2761, 2767, 2827, [2836 => 'Треб. контроль', 2807 => 'План. срок', 2806 => 'Факт. срок']],
              363 => [2769, 2805, 2828, [2784 => 'Срок', 2789 => 'Факт. срок']]] as $iblock => $config) {
        foreach (delegateLinkedIds($planId, $config[0]) as $id) {
            $select = ['ID', 'NAME', 'PROPERTY_' . $config[1], 'PROPERTY_' . $config[2]];
            foreach ($config[3] as $property => $label) $select[] = 'PROPERTY_' . $property;
            $task = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblock, 'ID' => $id], false, false, $select)->Fetch();
            // Не допускаем незаметной передачи неполного набора связанных задач.
            if (!$task) throw new RuntimeException('Связанная задача ' . $id . ' не найдена.');
            $status = (int)$task['PROPERTY_' . $config[1] . '_VALUE'];
            $statusElement = CIBlockElement::GetList([], ['IBLOCK_ID' => 361, 'ID' => $status], false, false, ['NAME'])->Fetch();
            $rows[] = ['ID' => $id, 'IBLOCK' => $iblock, 'NAME' => $task['NAME'],
                'STATUS_ID' => $status, 'STATUS' => $statusElement ? $statusElement['NAME'] : '—',
                'RESPONSIBLE' => delegateUserId($task['PROPERTY_' . $config[2] . '_VALUE']),
                'PROPERTY' => $config[2], 'FIELDS' => $config[3], 'ELEMENT' => $task,
                'ASSIGNMENTS' => delegateAssignments($id, $iblock)];
        }
    }
    return $rows;
}

function delegateCanTransfer(array $row, $manager)
{
    return $manager > 0 && $row['RESPONSIBLE'] === $manager
        && in_array($row['STATUS_ID'], [3396791, 3507933], true);
}

function delegateWarning(array $row, $manager)
{
    if ($row['STATUS_ID'] === 3347534) return '';
    if (!in_array($row['STATUS_ID'], [3396791, 3507933], true)) {
        return 'Смена ответственного на этом этапе невозможна. Сначала сотрудник должен выполнить свое задание по задачам ПВД и KPI, и только затем можно будет сменить ответственного.';
    }
    if ($manager <= 0 || $row['RESPONSIBLE'] !== $manager) {
        return 'Ответственный не является текущим руководителем ПВД и смене не подлежит.';
    }
    return '';
}

function delegateSnapshot($manager, array $rows)
{
    $state = [$manager];
    foreach ($rows as $row) $state[] = [$row['IBLOCK'], $row['ID'], $row['STATUS_ID'], $row['RESPONSIBLE'], $row['ASSIGNMENTS']];
    return hash('sha256', serialize($state));
}

function delegateSetUser($id, $iblock, $property, $userId)
{
    CIBlockElement::SetPropertyValuesEx($id, $iblock, [$property => $userId]);
    $value = CIBlockElement::GetProperty($iblock, $id, [], ['ID' => $property])->Fetch();
    if (!$value || delegateUserId($value['VALUE']) !== $userId) {
        throw new RuntimeException('Не удалось сохранить изменение для элемента ' . $id . '.');
    }
}

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$planId = (int)($request->get('PLAN_ID') ?: $request->get('id_plan'));
$plan = $planId > 0 ? CIBlockElement::GetList([], [
    'IBLOCK_ID' => 359, 'ID' => $planId, 'CHECK_PERMISSIONS' => 'Y',
], false, false, ['ID', 'NAME', 'PROPERTY_2775', 'PROPERTY_2796', 'PROPERTY_2776', 'PROPERTY_2802'])->Fetch() : false;
if (!$plan) {
    ShowError('План не найден или недоступен. Укажите PLAN_ID.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
$manager = delegateUserId($plan['PROPERTY_2775_VALUE']);
$recruiter = delegateUserId($plan['PROPERTY_2796_VALUE']);
$canChange = $USER->IsAdmin() || in_array((int)$USER->GetID(), array_filter([$manager, $recruiter]), true);
$rows = [];
$error = '';
$success = false;
$preview = false;
$newManager = (int)$request->getPost('new_manager');
try {
    $rows = delegateRows($planId);
    if ($request->isPost()) {
        if (!check_bitrix_sessid()) throw new RuntimeException('Сессия истекла. Обновите страницу.');
        if (!$canChange) throw new RuntimeException('Недостаточно прав для смены руководителя.');
        $newUser = CUser::GetByID($newManager)->Fetch();
        if (!$newUser || $newUser['ACTIVE'] !== 'Y' || $newManager === $manager) {
            throw new RuntimeException('Выберите нового активного руководителя.');
        }
        $action = (string)$request->getPost('action');
        if ($action === 'preview') {
            $preview = true;
            $_SESSION['PVD_DELEGATE'][$planId] = ['manager' => $newManager, 'snapshot' => delegateSnapshot($manager, $rows), 'token' => bin2hex(random_bytes(24))];
        } elseif ($action === 'apply') {
            $pending = $_SESSION['PVD_DELEGATE'][$planId] ?? [];
            if (($pending['manager'] ?? 0) !== $newManager
                || !hash_equals($pending['token'] ?? '', (string)$request->getPost('token'))
                || ($pending['snapshot'] ?? '') !== delegateSnapshot($manager, $rows)) {
                throw new RuntimeException('Данные изменились или подтверждение устарело. Повторите проверку.');
            }
            unset($_SESSION['PVD_DELEGATE'][$planId]);
            foreach ($rows as $row) {
                if (delegateCanTransfer($row, $manager) && $row['STATUS_ID'] === 3507933 && !$row['ASSIGNMENTS']) {
                    throw new RuntimeException('Для задачи ' . $row['ID'] . ' на согласовании не найдено текущее задание. Изменения не выполнены.');
                }
            }
            foreach ($rows as $row) {
                if (!delegateCanTransfer($row, $manager)) continue;
                if ($row['STATUS_ID'] === 3507933) {
                    foreach ($row['ASSIGNMENTS'] as $assignment) {
                        if ($assignment['USER_ID'] === $newManager) continue;
                        if (CBPTaskService::DelegateTask($assignment['ID'], $assignment['USER_ID'], $newManager) === false) {
                            throw new RuntimeException('Не удалось делегировать задание ' . $assignment['ID'] . '.');
                        }
                    }
                }
                delegateSetUser($row['ID'], $row['IBLOCK'], $row['PROPERTY'], $newManager);
            }
            // Руководитель меняется после успешной передачи всех доступных задач.
            delegateSetUser($planId, 359, 2775, $newManager);
            $manager = $newManager;
            $success = true;
            $rows = delegateRows($planId);
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    if ($request->getPost('action') === 'apply') $error .= ' Проверьте текущих ответственных и исполнителей: часть изменений могла сохраниться.';
}
?>
<style>
.pvd-delegate table {border-collapse:collapse;width:100%;margin:16px 0;font-size:14px}
.pvd-delegate th,.pvd-delegate td {border:1px solid #ccc;padding:10px;text-align:left;vertical-align:top}
.pvd-delegate th {background:#f0f0f0}.pvd-delegate .summary {max-width:900px}
.pvd-delegate .manager-info {display:flex;align-items:center;flex-wrap:wrap;gap:16px}
.pvd-delegate .task-blocked td {background:#ffe5e5}
.pvd-delegate .responsible-warning {display:block;font-size:12px;line-height:1.4;color:#a32020;margin-top:4px;max-width:320px}
.pvd-delegate .scroll {overflow:auto}
</style>
<div class="pvd-delegate">
<h2>План ввода в должность: <?= delegateH($plan['NAME']) ?></h2>
<?php if ($error): ?><div class="ui-alert ui-alert-danger"><?= delegateH($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="ui-alert ui-alert-success">Руководитель ПВД изменен. Доступные задачи переданы новому руководителю.</div><?php endif; ?>
<table class="summary">
<tr><th>ФИО сотрудника</th><td><?= delegateH($plan['NAME']) ?></td></tr>
<tr><th>Руководитель</th><td><div class="manager-info"><span><?= delegateH(delegateUserName($manager)) ?></span>
<?php if ($canChange): ?><button type="button" class="ui-btn ui-btn-primary" onclick="document.getElementById('manager-selection').hidden=false">Сменить руководителя ПВД</button><?php endif; ?></div></td></tr>
<tr><th>Дата трудоустройства</th><td><?= delegateH($plan['PROPERTY_2776_VALUE']) ?></td></tr>
<tr><th>Дата окончания ИС</th><td><?= delegateH($plan['PROPERTY_2802_VALUE']) ?></td></tr>
<tr><th>Рекрутер</th><td><?= delegateH(delegateUserName($recruiter)) ?></td></tr>
</table>
<?php if ($canChange): ?>
<form method="post" id="manager-selection" <?= $preview || $error ? '' : 'hidden' ?>>
<?= bitrix_sessid_post() ?>
<input type="hidden" name="PLAN_ID" value="<?= $planId ?>">
<input type="hidden" name="action" value="preview">
<span>Новый руководитель ПВД:</span>
<input type="hidden" name="new_manager" id="new-manager" value="<?= $newManager ?>">
<span id="selected-manager"><?= $newManager > 0 ? delegateH(delegateUserName($newManager)) : 'Сотрудник не выбран' ?></span>
<button class="ui-btn ui-btn-light-border" type="button" id="pick-manager">Выбрать сотрудника</button>
<button class="ui-btn ui-btn-primary" type="submit" id="check-manager" <?= $newManager > 0 && $newManager !== $manager ? '' : 'disabled' ?>>Проверить изменения</button>
</form>
<?php endif; ?>
<?php if ($preview): ?>
<h3>Предлагаемые изменения</h3>
<p>Сменить руководителя ПВД с <?= delegateH(delegateUserName($manager)) ?> на <?= delegateH(delegateUserName($newManager)) ?>.</p>
<ul>
<?php foreach ($rows as $row): ?>
<li><?= $row['IBLOCK'] === 360 ? 'ПВД' : 'KPI' ?> #<?= $row['ID'] ?>: <?= delegateH($row['NAME']) ?> —
<?php if (delegateWarning($row, $manager) !== ''): ?><?= delegateH(delegateWarning($row, $manager)) ?>
<?php elseif ($row['STATUS_ID'] === 3396791): ?>изменить ответственного на <?= delegateH(delegateUserName($newManager)) ?>.
<?php elseif ($row['STATUS_ID'] === 3507933): ?>изменить ответственного и делегировать текущие задания на <?= delegateH(delegateUserName($newManager)) ?>.<?php if (!$row['ASSIGNMENTS']): ?> <b>Текущее задание не найдено; передача заблокирована.</b><?php endif; ?>
<?php elseif ($row['STATUS_ID'] === 3347534): ?>задача выполнена, ответственный останется прежним.
<?php else: ?>Смена ответственного на этом этапе невозможна. Сначала сотрудник должен выполнить свое задание по задачам ПВД и KPI, и только затем можно будет сменить ответственного.
<?php endif; ?></li>
<?php endforeach; ?></ul>
<form method="post"><?= bitrix_sessid_post() ?>
<input type="hidden" name="PLAN_ID" value="<?= $planId ?>"><input type="hidden" name="action" value="apply">
<input type="hidden" name="new_manager" value="<?= $newManager ?>">
<input type="hidden" name="token" value="<?= delegateH($_SESSION['PVD_DELEGATE'][$planId]['token']) ?>">
<button class="ui-btn ui-btn-success" type="submit">Подтвердить изменения</button>
<a class="ui-btn ui-btn-light-border" href="?PLAN_ID=<?= $planId ?>">Отмена</a>
</form>
<?php endif; ?>
<?php foreach ([360 => 'Задачи ПВД', 363 => 'Задачи KPI'] as $iblock => $title):
$group = array_filter($rows, function ($row) use ($iblock) { return $row['IBLOCK'] === $iblock; }); ?>
<h3><?= $title ?></h3>
<?php if (!$group): ?><p>Нет задач.</p><?php else:
$first = reset($group); ?>
<div class="scroll"><table><thead><tr><th>ID</th><th>Название</th><th>Статус</th>
<?php foreach ($first['FIELDS'] as $label): ?><th><?= delegateH($label) ?></th><?php endforeach; ?>
<th>Текущий исполнитель</th><th>Ответственный</th></tr></thead><tbody>
<?php foreach ($group as $row): $warning = delegateWarning($row, $manager); ?>
<tr<?= $warning !== '' ? ' class="task-blocked"' : '' ?>><td><a href="/workgroups/group/206/lists/<?= $iblock ?>/element/0/<?= $row['ID'] ?>/" target="_blank" rel="noopener"><?= $row['ID'] ?></a></td>
<td><?= delegateH($row['NAME']) ?></td><td><?= delegateH($row['STATUS']) ?></td>
<?php foreach ($row['FIELDS'] as $property => $label): ?><td><?= delegateH($row['ELEMENT']['PROPERTY_' . $property . '_VALUE'] ?? '') ?></td><?php endforeach; ?>
<td><?= delegateH(implode(', ', array_unique(array_map(function ($assignment) { return delegateUserName($assignment['USER_ID']); }, $row['ASSIGNMENTS']))) ?: '—') ?></td>
<td><?= delegateH(delegateUserName($row['RESPONSIBLE'])) ?>
<?php if ($warning !== ''): ?><small class="responsible-warning"><?= delegateH($warning) ?></small><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; endforeach; ?>
</div>
<script>
(function () {
function initManagerSelector() {
    var button = document.getElementById('pick-manager');
    if (!button) return;
    var input = document.getElementById('new-manager');
    var label = document.getElementById('selected-manager');
    var submit = document.getElementById('check-manager');
    var currentManagerId = <?= (int)$manager ?>;
    var selector = null;
    function createSelector() {
        return new BX.UI.EntitySelector.Dialog({
            targetNode: button,
            context: 'delegate-onboarding-plan-manager',
            multiple: false,
            dropdownMode: true,
            enableSearch: true,
            entities: [{ id: 'user', options: { inviteEmployeeLink: false } }],
            preselectedItems: input.value > 0 ? [['user', parseInt(input.value, 10)]] : [],
            events: {
                'Item:onSelect': function (event) {
                    var item = event.getData().item;
                    var userId = parseInt(item.getId(), 10) || 0;
                    input.value = userId > 0 ? String(userId) : '';
                    label.textContent = item.getTitle() || 'Сотрудник не выбран';
                    submit.disabled = userId <= 0 || userId === currentManagerId;
                    selector.hide();
                },
                'Item:onDeselect': function () {
                    input.value = '';
                    label.textContent = 'Сотрудник не выбран';
                    submit.disabled = true;
                }
            }
        });
    }
    button.addEventListener('click', function () {
        // Подключаем обработчик до создания диалога: ошибка загрузки не оставляет кнопку без действия.
        if (selector) {
            selector.show();
            return;
        }
        button.disabled = true;
        BX.Runtime.loadExtension('ui.entity-selector').then(function () {
            selector = createSelector();
            selector.show();
        }).catch(function (error) {
            console.error('Не удалось открыть выбор руководителя ПВД', error);
            label.textContent = 'Не удалось загрузить список сотрудников. Обновите страницу и повторите выбор.';
        }).then(function () {
            button.disabled = false;
        });
    });
    document.getElementById('manager-selection').addEventListener('submit', function (event) {
        var userId = parseInt(input.value, 10) || 0;
        if (userId <= 0 || userId === currentManagerId) event.preventDefault();
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initManagerSelector);
} else {
    initManagerSelector();
}
}());
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
