<?php
/**
 * Возвращает полное ФИО выбранного в анкете руководителя.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

header('Content-Type: application/json; charset=UTF-8');

$result = ['success' => false, 'fio' => ''];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$USER->IsAuthorized() || !check_bitrix_sessid()) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    return;
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    return;
}

$user = CUser::GetByID($userId)->Fetch();
if (!$user || ($user['ACTIVE'] ?? 'N') !== 'Y') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    return;
}

$fio = trim((string)CUser::FormatName('#LAST_NAME# #NAME# #SECOND_NAME#', $user, true, false));
echo json_encode(['success' => true, 'fio' => $fio], JSON_UNESCAPED_UNICODE);
