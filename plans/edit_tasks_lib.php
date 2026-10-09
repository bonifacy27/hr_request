<?php
/** Правила и сохранение редактора KPI. Основные задачи ПВД не изменяются. */
function kpiEditH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kpiEditDate($value)
{
    foreach (['!d.m.Y', '!Y-m-d', '!d.m.Y H:i:s', '!Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, trim((string)$value), new DateTimeZone('Europe/Moscow'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) return $date->format('Y-m-d');
    }
    return '';
}

function kpiEditTomorrow()
{
    return (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
}

function kpiEditAllowed(array $task, $tomorrow)
{
    return $task['status'] === 3396791 && $task['due_date'] !== '' && $task['due_date'] >= $tomorrow;
}

function kpiEditLoad($planId)
{
    $ids = [];
    $properties = CIBlockElement::GetProperty(359, $planId, ['id' => 'asc'], ['CODE' => 'ZADACHI_KPI']);
    while ($property = $properties->Fetch()) {
        if ((int)$property['VALUE'] > 0) $ids[(int)$property['VALUE']] = (int)$property['VALUE'];
    }
    sort($ids, SORT_NUMERIC);
    $rows = [];
    foreach ($ids as $id) {
        $element = CIBlockElement::GetList([], ['IBLOCK_ID' => 363, 'ID' => $id], false, false, ['ID', 'NAME', 'PREVIEW_TEXT'])->Fetch();
        if (!$element) throw new RuntimeException('Связанная KPI-задача #' . $id . ' не найдена.');
        $props = [];
        $result = CIBlockElement::GetProperty(363, $id, ['id' => 'asc'], []);
        while ($property = $result->Fetch()) $props[$property['CODE']] = $property['VALUE'];
        $rows[$id] = [
            'id' => $id, 'name' => (string)$element['NAME'],
            'type' => (int)($props['TIP_ZADACHI_KPI'] ?? 0),
            'planned_result' => (string)($props['PLANIRUEMY_REZULTAT'] ?? $element['PREVIEW_TEXT']),
            'weight' => (int)($props['VES'] ?? 0),
            'due_date' => kpiEditDate($props['SROK'] ?? ''),
            'status' => (int)($props['STATUS'] ?? 0),
            // Также учитываем ответственного и остальные свойства при проверке устаревшей формы.
            'properties' => $props,
        ];
    }
    return $rows;
}

function kpiEditValues(array $row)
{
    return [
        'type' => (int)($row['type'] ?? 0),
        'planned_result' => trim((string)($row['planned_result'] ?? '')),
        'weight' => (int)($row['weight'] ?? 0),
        'due_date' => kpiEditDate($row['due_date'] ?? ''),
    ];
}

function kpiEditChanges(array $existing, array $submitted, array $types, $tomorrow)
{
    $seen = [];
    $changes = [];
    $count = 0;
    foreach ($submitted as $input) {
        if (!is_array($input)) throw new RuntimeException('Некорректная строка KPI.');
        $id = (int)($input['id'] ?? 0);
        if ($id < 0 || ($id > 0 && (!isset($existing[$id]) || isset($seen[$id])))) {
            throw new RuntimeException('Задача не принадлежит ПВД или указана повторно.');
        }
        if ($id > 0) {
            $seen[$id] = true;
            if (!kpiEditAllowed($existing[$id], $tomorrow)) {
                // Неизменяемые строки отправляют только ID. Подмена их полей запрещена.
                if (count(array_intersect(['type', 'planned_result', 'weight', 'due_date'], array_keys($input))) > 0
                    && kpiEditValues($input) !== kpiEditValues($existing[$id])) {
                    throw new RuntimeException('Задача #' . $id . ' недоступна для редактирования.');
                }
                $count++;
                continue;
            }
        }
        $values = kpiEditValues($input);
        if ($id === 0 && $values['type'] === 0 && $values['planned_result'] === '' && $values['weight'] === 0
            && trim((string)($input['due_date'] ?? '')) === '') continue;
        if (!isset($types[$values['type']]) || $values['planned_result'] === ''
            || !preg_match('/^[1-9][0-9]*$/', (string)($input['weight'] ?? ''))
            || $values['due_date'] === '' || $values['due_date'] < $tomorrow) {
            throw new RuntimeException('Заполните тип, результат, положительный целый вес и срок не ранее завтрашнего дня.');
        }
        $count++;
        if ($id === 0) $changes[] = ['action' => 'add', 'id' => 0, 'after' => $values];
        elseif ($values !== kpiEditValues($existing[$id])) {
            $changes[] = ['action' => 'update', 'id' => $id, 'before' => kpiEditValues($existing[$id]), 'after' => $values];
        }
    }
    foreach ($existing as $id => $row) {
        if (isset($seen[$id])) continue;
        if (!kpiEditAllowed($row, $tomorrow)) throw new RuntimeException('Задачу #' . $id . ' нельзя удалить: проверьте статус и текущий срок.');
        $changes[] = ['action' => 'delete', 'id' => $id, 'before' => kpiEditValues($row)];
    }
    if ($count === 0) throw new RuntimeException('В ПВД должна остаться хотя бы одна KPI-задача.');
    return $changes;
}

function kpiEditDescribe(array $changes, array $types, $saved = false)
{
    $labels = ['type' => 'Тип задачи', 'planned_result' => 'Планируемый результат', 'weight' => 'Вес (%)', 'due_date' => 'Планируемый срок'];
    $lines = [];
    foreach ($changes as $change) {
        $verbs = $saved
            ? ['add' => 'Добавлена новая KPI-задача', 'delete' => 'Удалена KPI-задача #', 'update' => 'Изменена KPI-задача #']
            : ['add' => 'Добавить новую KPI-задачу', 'delete' => 'Удалить KPI-задачу #', 'update' => 'Изменить KPI-задачу #'];
        $lines[] = $verbs[$change['action']] . ($change['action'] === 'add' ? '' : $change['id']);
        foreach ($labels as $key => $label) {
            $before = $change['before'][$key] ?? '';
            $after = $change['after'][$key] ?? '';
            if ($change['action'] === 'update' && $before === $after) continue;
            if ($key === 'type') {
                $before = $types[$before] ?? (string)$before;
                $after = $types[$after] ?? (string)$after;
            }
            if ($key === 'due_date') {
                $before = $before ? date('d.m.Y', strtotime($before)) : '';
                $after = $after ? date('d.m.Y', strtotime($after)) : '';
            }
            $lines[] = $label . ': ' . ($change['action'] === 'update' ? $before . ' → ' . $after : ($change['action'] === 'delete' ? $before : $after));
        }
        $lines[] = '';
    }
    return trim(implode("\n", $lines));
}

function kpiEditStartWorkflow($template, $elementId, array $parameters = [])
{
    $errors = [];
    $workflow = CBPDocument::StartWorkflow($template, ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (int)$elementId], $parameters, $errors);
    if (!$workflow || $errors) throw new RuntimeException('Не удалось запустить бизнес-процесс #' . $template . '.');
}

function kpiEditSave($planId, array $changes, array $existing, array $types, $managerId)
{
    $ids = array_keys($existing);
    $created = [];
    foreach ($changes as $change) {
        $id = $change['id'];
        $element = new CIBlockElement();
        if ($change['action'] === 'delete') {
            if (!CIBlockElement::Delete($id)) throw new RuntimeException('Не удалось удалить KPI-задачу #' . $id . '.');
            $ids = array_values(array_diff($ids, [$id]));
            continue;
        }
        $after = $change['after'];
        $properties = [
            'TIP_ZADACHI_KPI' => $after['type'], 'PLANIRUEMY_REZULTAT' => $after['planned_result'],
            'VES' => $after['weight'], 'SROK' => date('d.m.Y', strtotime($after['due_date'])),
        ];
        $fields = ['NAME' => $types[$after['type']], 'PREVIEW_TEXT' => $after['planned_result']];
        if ($change['action'] === 'add') {
            $fields['IBLOCK_ID'] = 363;
            $fields['PROPERTY_VALUES'] = $properties + ['PLAN_VVODA_V_DOLZHNOST' => $planId, 'STATUS' => 3396791, 'OTVETSTVENNYY' => $managerId];
            $id = (int)$element->Add($fields);
            if (!$id) throw new RuntimeException('Не удалось добавить KPI-задачу: ' . $element->LAST_ERROR);
            $ids[] = $id;
            $created[] = $id;
        } else {
            if (!$element->Update($id, $fields)) throw new RuntimeException('Не удалось обновить KPI-задачу #' . $id . ': ' . $element->LAST_ERROR);
            // Сохраняем остальные свойства, статус и ответственного существующей задачи.
            CIBlockElement::SetPropertyValuesEx($id, 363, $properties);
        }
    }
    CIBlockElement::SetPropertyValuesEx($planId, 359, ['ZADACHI_KPI' => array_values($ids)]);
    $saved = kpiEditLoad($planId);
    $savedIds = array_keys($saved);
    sort($ids, SORT_NUMERIC);
    if ($savedIds !== $ids) throw new RuntimeException('Не удалось сохранить связи KPI-задач с ПВД.');
    $createdIndex = 0;
    foreach ($changes as $change) {
        if ($change['action'] === 'delete') continue;
        $id = $change['action'] === 'add' ? $created[$createdIndex++] : $change['id'];
        if (kpiEditValues($saved[$id]) !== $change['after']) throw new RuntimeException('Не удалось сохранить поля KPI-задачи #' . $id . '.');
    }
    return $created;
}
