<?php
/**
 * Связка планов ввода в должность (ИБ 359) с анкетами сотрудников (ИБ 196).
 *
 * Источником связи является поле KARTOCHKA_SOTRUDNIKA плана. В связанную
 * анкету записывается ID плана в числовое поле PLAN_VVODA_V_DOLZHNOST_ID.
 */

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

global $APPLICATION;
$APPLICATION->SetTitle('Связка планов ввода в должность с анкетами сотрудников');

const LP_IBLOCK_PLAN = 359;
const LP_IBLOCK_EMPLOYEE = 196;
const LP_PROP_PLAN_EMPLOYEE = 2801; // 359.KARTOCHKA_SOTRUDNIKA
const LP_PROP_EMPLOYEE_PLAN = 3164; // 196.PLAN_VVODA_V_DOLZHNOST_ID

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

function lp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lp_property_value(int $iblockId, int $elementId, int $propertyId): string
{
    $result = CIBlockElement::GetProperty(
        $iblockId,
        $elementId,
        ['sort' => 'asc'],
        ['ID' => $propertyId]
    );

    while ($property = $result->Fetch()) {
        $value = trim((string)($property['VALUE'] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function lp_id($value): int
{
    $value = trim((string)$value);
    return ctype_digit($value) ? (int)$value : 0;
}

function lp_employee(int $employeeId): ?array
{
    if ($employeeId <= 0) {
        return null;
    }

    $row = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => LP_IBLOCK_EMPLOYEE, 'ID' => $employeeId],
        false,
        ['nTopCount' => 1],
        ['ID', 'NAME']
    )->Fetch();

    return $row ?: null;
}

function lp_save_link(int $employeeId, int $planId): bool
{
    CIBlockElement::SetPropertyValuesEx(
        $employeeId,
        LP_IBLOCK_EMPLOYEE,
        [LP_PROP_EMPLOYEE_PLAN => $planId]
    );

    return lp_id(lp_property_value(
        LP_IBLOCK_EMPLOYEE,
        $employeeId,
        LP_PROP_EMPLOYEE_PLAN
    )) === $planId;
}

$plans = [];
$planResult = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => LP_IBLOCK_PLAN],
    false,
    false,
    ['ID', 'NAME', 'ACTIVE']
);

while ($plan = $planResult->GetNext()) {
    $planId = (int)$plan['ID'];
    $employeeId = lp_id(lp_property_value(
        LP_IBLOCK_PLAN,
        $planId,
        LP_PROP_PLAN_EMPLOYEE
    ));
    $employee = lp_employee($employeeId);
    $currentPlanId = $employee
        ? lp_id(lp_property_value(LP_IBLOCK_EMPLOYEE, $employeeId, LP_PROP_EMPLOYEE_PLAN))
        : 0;

    $plans[$planId] = [
        'id' => $planId,
        'name' => (string)$plan['NAME'],
        'active' => (string)$plan['ACTIVE'],
        'employee_id' => $employeeId,
        'employee_name' => $employee ? (string)$employee['NAME'] : '',
        'current_plan_id' => $currentPlanId,
        'can_update' => $employee !== null,
        'matches' => $employee !== null && $currentPlanId === $planId,
    ];
}

$isApply = (
    (string)($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && check_bitrix_sessid()
    && (string)($_POST['apply'] ?? '') === 'Y'
);
$selected = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['selected_plan'] ?? [])),
    static function ($id) {
        return $id > 0;
    }
)));

$updated = 0;
$failed = [];
if ($isApply) {
    foreach ($selected as $planId) {
        $row = $plans[$planId] ?? null;
        if (!$row || !$row['can_update']) {
            $failed[] = '#' . $planId . ': анкета сотрудника не найдена';
            continue;
        }

        if ($row['matches']) {
            continue;
        }

        if (!lp_save_link((int)$row['employee_id'], $planId)) {
            $failed[] = '#' . $planId . ': значение не сохранилось';
            continue;
        }

        $plans[$planId]['current_plan_id'] = $planId;
        $plans[$planId]['matches'] = true;
        $updated++;
    }
}

if ((string)($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$isApply) {
    ShowError('Запрос отклонён: обновите страницу и повторите операцию.');
} elseif ($isApply && !$failed) {
    ShowMessage(['TYPE' => 'OK', 'MESSAGE' => 'Связи обновлены: ' . $updated . '.']);
} elseif ($isApply) {
    ShowError('Обновлено: ' . $updated . '. Ошибки: ' . implode('; ', $failed));
}

$ready = 0;
$missing = 0;
$conflicts = [];
foreach ($plans as $row) {
    if ($row['can_update'] && !$row['matches']) {
        $ready++;
    }
    if (!$row['can_update']) {
        $missing++;
    }
    if ($row['can_update'] && $row['current_plan_id'] > 0 && !$row['matches']) {
        $conflicts[$row['employee_id']][] = $row['id'];
    }
}
?>

<style>
    .lp-summary { margin: 16px 0; }
    .lp-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    .lp-table th, .lp-table td { border: 1px solid #d6dfe7; padding: 9px; text-align: left; vertical-align: top; }
    .lp-table th { background: #f1f5f7; }
    .lp-ok { color: #2f7d32; font-weight: 600; }
    .lp-warning { color: #b66a00; font-weight: 600; }
    .lp-error { color: #b42318; font-weight: 600; }
</style>

<p>
    Утилита берёт анкету из поля <b>KARTOCHKA_SOTRUDNIKA</b> каждого плана
    и записывает ID плана в поле анкеты <b>PLAN_VVODA_V_DOLZHNOST_ID</b>.
</p>
<div class="lp-summary">
    Всего планов: <b><?= count($plans) ?></b>;
    требуют обновления: <b><?= $ready ?></b>;
    без корректной анкеты: <b><?= $missing ?></b>.
</div>

<?php if ($conflicts): ?>
    <div class="ui-alert ui-alert-warning">
        Поле анкеты является одиночным числом. Для строк, где уже указан другой
        план, выбранное значение будет заменено.
    </div>
<?php endif; ?>

<form method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="apply" value="Y">
    <table class="lp-table">
        <thead>
        <tr>
            <th><input type="checkbox" id="lp-check-all" title="Выбрать все доступные"></th>
            <th>План</th>
            <th>Анкета из KARTOCHKA_SOTRUDNIKA</th>
            <th>Текущее значение в анкете</th>
            <th>Состояние</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($plans as $row): ?>
            <tr>
                <td>
                    <?php if ($row['can_update'] && !$row['matches']): ?>
                        <input class="lp-plan-checkbox" type="checkbox" name="selected_plan[]" value="<?= $row['id'] ?>">
                    <?php endif; ?>
                </td>
                <td>#<?= $row['id'] ?> — <?= lp_h($row['name']) ?><?= $row['active'] !== 'Y' ? ' (неактивен)' : '' ?></td>
                <td>
                    <?php if ($row['employee_id'] > 0): ?>
                        #<?= $row['employee_id'] ?><?= $row['employee_name'] !== '' ? ' — ' . lp_h($row['employee_name']) : '' ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?= $row['current_plan_id'] > 0 ? '#' . $row['current_plan_id'] : '—' ?></td>
                <td>
                    <?php if ($row['matches']): ?>
                        <span class="lp-ok">Связь заполнена</span>
                    <?php elseif (!$row['can_update']): ?>
                        <span class="lp-error"><?= $row['employee_id'] > 0 ? 'Анкета не найдена' : 'Анкета не привязана' ?></span>
                    <?php elseif ($row['current_plan_id'] > 0): ?>
                        <span class="lp-warning">Будет заменено значение #<?= $row['current_plan_id'] ?></span>
                    <?php else: ?>
                        <span class="lp-warning">Требуется заполнить</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button class="ui-btn ui-btn-success" type="submit" onclick="return confirm('Записать ID выбранных планов в анкеты сотрудников?');">
        Обновить выбранные связи
    </button>
</form>

<script>
document.getElementById('lp-check-all').addEventListener('change', function () {
    document.querySelectorAll('.lp-plan-checkbox').forEach(function (checkbox) {
        checkbox.checked = this.checked;
    }, this);
});
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
