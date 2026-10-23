<?php

/** Start once per employee form, including requests from different web workers. */
function startAdaptationOnce($elementId)
{
    $connection = \Bitrix\Main\Application::getConnection();
    $key = 'adaptation_299_196_' . (int)$elementId;
    if (!$connection->lock($key, 0)) {
        return ['danger', 'Запуск уже выполняется. Обновите страницу.'];
    }

    try {
        // Read again inside the lock: the page and the earlier POST checks may be stale.
        if ((int)getElementPropertyValue($elementId, ['ID' => PROP_ADAPTATION_STATUS]) > 0) {
            return ['danger', 'Создание заявок доступно только для анкеты без статуса адаптации.'];
        }
        if (COption::GetOptionString('bizproc', $key, '') !== '') {
            return ['danger', 'Запуск для этой анкеты уже зарегистрирован. Повторный запуск заблокирован; при ошибке обратитесь к администратору.'];
        }
        foreach (docIdCandidates($elementId) as $documentId) {
            $states = CBPStateService::GetList(
                ['ID' => 'ASC'],
                ['DOCUMENT_ID' => $documentId, 'WORKFLOW_TEMPLATE_ID' => START_ADAPTATION_WORKFLOW_ID],
                false,
                false,
                ['ID']
            );
            if ($states->Fetch()) {
                return ['danger', 'Процесс создания заявок Jira для этой анкеты уже запускался.'];
            }
        }

        // Persist BEFORE calling the engine. A timeout or exception can happen after
        // Jira side effects; automatically retrying an uncertain outcome is unsafe.
        $marker = 'starting:' . time();
        COption::SetOptionString('bizproc', $key, $marker);
        if (COption::GetOptionString('bizproc', $key, '') !== $marker) {
            return ['danger', 'Не удалось зарегистрировать запуск. Бизнес-процесс не запущен.'];
        }
        $errors = [];
        $workflowId = CBPDocument::StartWorkflow(
            START_ADAPTATION_WORKFLOW_ID,
            ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
            [],
            $errors
        );
        if ($workflowId) {
            COption::SetOptionString('bizproc', $key, 'workflow:' . $workflowId);
        }
        if (!$workflowId || $errors) {
            return ['danger', 'Не удалось подтвердить успешный запуск. Повторный запуск заблокирован; обратитесь к администратору для проверки процесса и заявок Jira.'];
        }
        return ['success', 'Процесс создания заявок Jira успешно запущен.'];
    } catch (\Throwable $e) {
        AddMessage2Log('Employee ' . (int)$elementId . ': ' . $e->getMessage(), 'adaptation_start');
        return ['danger', 'Ошибка проверки или запуска процесса. Обратитесь к администратору перед повторным запуском.'];
    } finally {
        $connection->unlock($key);
    }
}
