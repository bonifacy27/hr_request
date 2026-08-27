<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Отчет по плану ввода в должность");

CModule::IncludeModule('lists');
CModule::IncludeModule('bizproc');
CModule::IncludeModule('iblock');

// Стили для таблиц
echo '
<style>
.report-table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    font-size: 14px;
    max-width: 100%;
}
.report-table th,
.report-table td {
    border: 1px solid #ccc;
    padding: 10px;
    text-align: left;
    vertical-align: top;
}
.report-table th {
    background-color: #f0f0f0;
    font-weight: bold;
    white-space: nowrap;
}
.report-table tr:nth-child(even) {
    background-color: #fafafa;
}
.report-table tr:hover {
    background-color: #f0f8ff;
}
</style>
';

/* ============================================================================
   Функция: получение исполнителей через USER_ID
   ============================================================================ */
function getCurrentExecutor($elementId, $iblockId)
{
    $userIds = [];

    $candidates = [
        ["lists", "Bitrix\\Lists\\BizprocDocumentLists", (string)$elementId],
        ["lists", "BizprocDocument", "lists_{$iblockId}_{$elementId}"],
        ["lists", "lists_{$iblockId}_group_206", (int)$elementId],
        ["lists", "lists_{$iblockId}", (int)$elementId],
        ["iblock", "CIBlockDocument", "iblock_{$iblockId}_{$elementId}"],
    ];

    foreach ($candidates as $docIdCandidate) {
        $rs = CBPTaskService::GetList(
            ["ID" => "DESC"],
            [
                "DOCUMENT_ID" => $docIdCandidate,
                "STATUS"      => 0,
            ],
            false,
            false,
            ["USER_ID"]
        );

        while ($task = $rs->GetNext()) {
            $uid = (int)$task["USER_ID"];
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }
    }

    if (empty($userIds)) {
        return "—";
    }

    $names = [];
    foreach (array_keys($userIds) as $uid) {
        $rsUser = CUser::GetByID($uid);
        if ($arUser = $rsUser->Fetch()) {
            $name = trim($arUser["LAST_NAME"] . " " . $arUser["NAME"] . " " . $arUser["SECOND_NAME"]);
            if (!empty($name)) {
                $names[] = $name;
            }
        }
    }

    return !empty($names) ? implode(", ", $names) : "—";
}

/* ============================================================================
   Форма выбора плана с поиском
   ============================================================================ */
if (!isset($_GET["PLAN_ID"])) {
    $plans = CIBlockElement::GetList(
        ["NAME" => "ASC"],
        ["IBLOCK_ID" => 359, "ACTIVE" => "Y"],
        false, false,
        ["ID", "NAME"]
    );

    $planList = [];
    while ($p = $plans->GetNext()) {
        $planList[] = $p;
    }

    echo '<form method="GET" style="max-width:600px; margin:20px 0;">';
    echo '<label><b>Выберите план ввода в должность:</b></label><br>';
    echo '<input type="text" id="planInput" name="PLAN_NAME" list="plansList" placeholder="Начните вводить название..." style="width:100%; padding:6px; margin:5px 0;">';
    echo '<input type="hidden" id="planIdHidden" name="PLAN_ID">';
    echo '<datalist id="plansList">';
    foreach ($planList as $p) {
        echo '<option value="' . htmlspecialchars($p["NAME"]) . '" data-id="' . $p["ID"] . '">' . htmlspecialchars($p["NAME"]) . '</option>';
    }
    echo '</datalist>';
    echo '<br><button type="submit" class="ui-btn ui-btn-success" id="submitBtn" disabled>Показать отчет</button>';
    echo '</form>';

    echo '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const input = document.getElementById("planInput");
        const hidden = document.getElementById("planIdHidden");
        const button = document.getElementById("submitBtn");
        const options = document.querySelectorAll("#plansList option");

        input.addEventListener("input", function() {
            const selectedText = this.value;
            const option = Array.from(options).find(opt => opt.value === selectedText);
            if (option) {
                hidden.value = option.getAttribute("data-id");
                button.disabled = false;
            } else {
                hidden.value = "";
                button.disabled = true;
            }
        });
    });
    </script>
    ';

    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

/* ============================================================================
   Получение плана
   ============================================================================ */
$planId = intval($_GET["PLAN_ID"]);
if ($planId <= 0) {
    echo "<div class='ui-alert ui-alert-danger'>Некорректный ID плана</div>";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

$plan = CIBlockElement::GetList(
    [],
    ["IBLOCK_ID" => 359, "ID" => $planId],
    false, false,
    ["ID", "NAME", "PROPERTY_2775", "PROPERTY_2776", "PROPERTY_2802", "PROPERTY_2809"]
)->Fetch();

if (!$plan) {
    echo "<div class='ui-alert ui-alert-danger'>План не найден</div>";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

/* ============================================================================
   Получение задач
   ============================================================================ */
$zadachiPVD = [];
$resProp = CIBlockElement::GetProperty(359, $planId, ["sort" => "asc"], ["CODE" => "ZADACHI_PO_PLANU_VVODA_V_DOLZHNOST"]);
while ($prop = $resProp->Fetch()) {
    if ($prop["VALUE"]) {
        $zadachiPVD[] = $prop["VALUE"];
    }
}

$zadachiKPI = [];
$resProp = CIBlockElement::GetProperty(359, $planId, ["sort" => "asc"], ["CODE" => "ZADACHI_KPI"]);
while ($prop = $resProp->Fetch()) {
    if ($prop["VALUE"]) {
        $zadachiKPI[] = $prop["VALUE"];
    }
}

/* ============================================================================
   Вывод основной информации
   ============================================================================ */
echo "<h2>План ввода в должность: <b>" . htmlspecialchars($plan['NAME']) . "</b></h2>";
$ruk = "";
if ($plan["PROPERTY_2775_VALUE"]) {
    $u = CUser::GetByID($plan["PROPERTY_2775_VALUE"])->Fetch();
    $ruk = trim($u["LAST_NAME"] . " " . $u["NAME"] . " " . $u["SECOND_NAME"]);
}
echo "<table class='report-table' style='max-width:600px;'>";
echo "<tr><td><b>ФИО сотрудника</b></td><td>" . htmlspecialchars($plan['NAME']) . "</td></tr>";
echo "<tr><td><b>Руководитель</b></td><td>" . htmlspecialchars($ruk) . "</td></tr>";
echo "<tr><td><b>Дата трудоустройства</b></td><td>" . htmlspecialchars($plan['PROPERTY_2776_VALUE']) . "</td></tr>";
echo "<tr><td><b>Дата окончания ИС</b></td><td>" . htmlspecialchars($plan['PROPERTY_2802_VALUE']) . "</td></tr>";

$pdfUrl = "https://ourtricolortv.nsc.ru/pub/apps/plans/plan.php?id_plan=" . $planId;
echo "<tr><td><b>План ввода (PDF)</b></td><td><a href='" . htmlspecialchars($pdfUrl) . "' target='_blank'>Сгенерировать PDF</a></td></tr>";
echo "</table><br><hr>";

// === Проверка: если нет ни одной задачи ===
if (empty($zadachiPVD) && empty($zadachiKPI)) {
    echo "<div class='ui-alert ui-alert-warning' style='margin: 20px 0; padding: 15px; font-size: 16px;'>";
    echo "<b>План ещё не заполнен руководителем.</b>";
    echo "</div>";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

/* ============================================================================
   Задачи ПВД (IBLOCK_ID = 360)
   ============================================================================ */
echo "<h3>Задачи по ПВД</h3>";
if (!empty($zadachiPVD)) {
    echo "<table class='report-table'>";
    echo "<thead><tr>
        <th>ID</th>
        <th>Название</th>
        <th>Статус</th>
        <th>Треб. контроль</th>
        <th>План. срок</th>
        <th>Факт. срок</th>
        <th>Текущий исполнитель</th>
    </tr></thead><tbody>";
    foreach ($zadachiPVD as $taskId) {
        $task = CIBlockElement::GetList(
            [],
            ["IBLOCK_ID" => 360, "ID" => $taskId],
            false, false,
            ["ID","NAME","PROPERTY_2767","PROPERTY_2836","PROPERTY_2807","PROPERTY_2806"]
        )->Fetch();
        if (!$task) continue;

        $status = "";
        if ($task["PROPERTY_2767_VALUE"]) {
            $st = CIBlockElement::GetByID($task["PROPERTY_2767_VALUE"])->Fetch();
            if ($st) $status = $st["NAME"];
        }

        $executor = getCurrentExecutor($task["ID"], 360);

        echo "<tr>
            <td><a href=\"https://ourtricolortv.nsc.ru/workgroups/group/206/lists/360/element/0/" . (int)$task['ID'] . "/\" target=\"_blank\">" . htmlspecialchars($task['ID']) . "</a></td>
            <td>" . htmlspecialchars($task['NAME']) . "</td>
            <td>" . htmlspecialchars($status) . "</td>
            <td>" . htmlspecialchars($task['PROPERTY_2836_VALUE']) . "</td>
            <td>" . htmlspecialchars($task['PROPERTY_2807_VALUE']) . "</td>
            <td>" . htmlspecialchars($task['PROPERTY_2806_VALUE']) . "</td>
            <td>" . htmlspecialchars($executor) . "</td>
        </tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<div class='ui-alert'>Нет задач ПВД</div>";
}
echo "<br><hr>";

/* ============================================================================
   KPI задачи (IBLOCK_ID = 363)
   ============================================================================ */
echo "<h3>KPI задачи</h3>";
if (!empty($zadachiKPI)) {
    echo "<table class='report-table'>";
    echo "<thead><tr>
        <th>ID</th>
        <th>Название</th>
        <th>Статус</th>
        <th>Срок</th>
        <th>Факт. срок</th>
        <th>Текущий исполнитель</th>
    </tr></thead><tbody>";
    foreach ($zadachiKPI as $taskId) {
        $task = CIBlockElement::GetList(
            [],
            ["IBLOCK_ID" => 363, "ID" => $taskId],
            false, false,
            ["ID","NAME","PROPERTY_2805","PROPERTY_2784","PROPERTY_2789"]
        )->Fetch();
        if (!$task) continue;

        $status = "";
        if ($task["PROPERTY_2805_VALUE"]) {
            $st = CIBlockElement::GetByID($task["PROPERTY_2805_VALUE"])->Fetch();
            if ($st) $status = $st["NAME"];
        }

        $executor = getCurrentExecutor($task["ID"], 363);

        echo "<tr>
            <td><a href=\"https://ourtricolortv.nsc.ru/workgroups/group/206/lists/363/element/0/" . (int)$task['ID'] . "/\" target=\"_blank\">" . htmlspecialchars($task['ID']) . "</a></td>
            <td>" . htmlspecialchars($task['NAME']) . "</td>
            <td>" . htmlspecialchars($status) . "</td>
            <td>" . htmlspecialchars($task['PROPERTY_2784_VALUE']) . "</td>
            <td>" . htmlspecialchars($task['PROPERTY_2789_VALUE']) . "</td>
            <td>" . htmlspecialchars($executor) . "</td>
        </tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<div class='ui-alert'>Нет KPI задач</div>";
}

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
?>