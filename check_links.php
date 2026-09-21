<?php
/**
 * Восстановление связей в исторических данных подбора.
 *
 * Страница всегда сначала строит прогноз. Запись выполняется только для явно
 * отмеченных строк, не заменяет уже заполненные одиночные связи и защищена
 * bitrix_sessid().
 */

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/lib/recruitment_linker.php';

global $APPLICATION;
$APPLICATION->SetTitle('Восстановление связей подбора');

const RL_IBLOCK_REQUEST = 201;
const RL_IBLOCK_CANDIDATE = 207;
const RL_IBLOCK_OFFER = 218;
const RL_IBLOCK_EMPLOYEE = 196;

const RL_REQUEST_CANDIDATES = 3127;
const RL_REQUEST_OFFERS = 3128;
const RL_REQUEST_EMPLOYEES = 3129;

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$types = [
    'candidate' => [
        'title' => 'Анкета', 'iblock' => RL_IBLOCK_CANDIDATE,
        'request' => 1596, 'recruiter' => 1323, 'manager' => 1988, 'position' => 1617,
        'candidate' => null, 'offer' => 1616, 'request_back' => RL_REQUEST_CANDIDATES,
    ],
    'offer' => [
        'title' => 'Оффер', 'iblock' => RL_IBLOCK_OFFER,
        'request' => 1601, 'recruiter' => 1190, 'manager' => 1164, 'position' => 1161,
        'candidate' => 1603, 'offer' => null, 'request_back' => RL_REQUEST_OFFERS,
    ],
    'employee' => [
        'title' => 'Карточка', 'iblock' => RL_IBLOCK_EMPLOYEE,
        'request' => 1619, 'recruiter' => 961, 'manager' => 959, 'position' => 958,
        'candidate' => 1621, 'offer' => 2085, 'request_back' => RL_REQUEST_EMPLOYEES,
    ],
];

function rl_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rl_property_values(int $iblockId, int $elementId, int $propertyId): array
{
    $result = [];
    $iterator = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ID' => $propertyId]);
    while ($property = $iterator->Fetch()) {
        $id = rl_extract_id($property['VALUE'] ?? null);
        if ($id > 0) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}

function rl_load(int $iblockId, array $propertyIds): array
{
    $rows = [];
    $propertyIds = array_values(array_unique(array_map('intval', array_filter($propertyIds))));
    $iterator = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId],
        false,
        false,
        ['ID', 'NAME', 'DATE_CREATE']
    );
    while ($element = $iterator->Fetch()) {
        $id = (int)$element['ID'];
        $rows[$id] = [
            'id' => $id,
            'name' => (string)$element['NAME'],
            'date' => strtotime((string)$element['DATE_CREATE']) ?: 0,
            'props' => [],
        ];
    }

    // GetProperty для каждого поля каждого элемента создавал десятки тысяч
    // запросов. Загружаем свойства пакетами: один запрос на 500 элементов.
    foreach (array_chunk(array_keys($rows), 500) as $elementIds) {
        $loaded = [];
        CIBlockElement::GetPropertyValuesArray(
            $loaded,
            $iblockId,
            ['ID' => $elementIds],
            ['ID' => $propertyIds]
        );
        foreach ($loaded as $elementId => $properties) {
            foreach ($properties as $property) {
                $propertyId = (int)($property['ID'] ?? 0);
                if ($propertyId > 0) {
                    $rows[(int)$elementId]['props'][$propertyId] = $property['VALUE'] ?? '';
                }
            }
        }
    }
    return $rows;
}

$requests = rl_load(RL_IBLOCK_REQUEST, [1035, 1034, 1011, RL_REQUEST_CANDIDATES, RL_REQUEST_OFFERS, RL_REQUEST_EMPLOYEES]);
foreach ($requests as &$request) {
    $request['recruiter'] = rl_extract_id($request['props'][1035] ?? null);
    $request['manager'] = rl_extract_id($request['props'][1034] ?? null);
    $request['position'] = (string)($request['props'][1011] ?? '');
    $request['backlinks'] = ['candidate' => [], 'offer' => [], 'employee' => []];
}
unset($request);

$entities = [];
foreach ($types as $type => $config) {
    $propertyIds = [$config['request'], $config['recruiter'], $config['manager'], $config['position'], $config['candidate'], $config['offer']];
    $entities[$type] = rl_load($config['iblock'], $propertyIds);
    foreach ($entities[$type] as &$entity) {
        $entity['type'] = $type;
        foreach (['request', 'recruiter', 'manager', 'candidate', 'offer'] as $field) {
            $propertyId = $config[$field];
            $entity[$field] = $propertyId ? rl_extract_id($entity['props'][$propertyId] ?? null) : 0;
        }
        $entity['position'] = (string)($entity['props'][$config['position']] ?? '');
    }
    unset($entity);
}

// Обратные связи уже пришли в пакетной загрузке заявки.
foreach ($requests as $requestId => &$request) {
    foreach ($types as $type => $config) {
        $request['backlinks'][$type] = rl_extract_ids($request['props'][$config['request_back']] ?? null);
    }
}
unset($request);

$knownRequest = [];
$backlinkVotes = [];
foreach ($entities as $type => $items) {
    foreach ($items as $id => $entity) {
        if (isset($requests[$entity['request']])) {
            $knownRequest[$type][$id] = $entity['request'];
        }
    }
}
foreach ($requests as $requestId => $request) {
    foreach ($request['backlinks'] as $type => $ids) {
        foreach ($ids as $id) {
            if (isset($entities[$type][$id]) && empty($knownRequest[$type][$id])) {
                $backlinkVotes[$type][$id][$requestId] = $requestId;
            }
        }
    }
}
foreach ($backlinkVotes as $type => $items) {
    foreach ($items as $id => $requestIds) {
        if (count($requestIds) === 1) {
            $knownRequest[$type][$id] = reset($requestIds);
        }
    }
}

// Несколько проходов позволяют протянуть заявку по цепочке
// карточка -> оффер -> анкета -> заявка.
for ($pass = 0; $pass < 3; $pass++) {
    foreach ($entities as $type => $items) {
        foreach ($items as $id => $entity) {
            if (!empty($knownRequest[$type][$id])) {
                continue;
            }
            $votes = [];
            if ($entity['candidate'] && !empty($knownRequest['candidate'][$entity['candidate']])) {
                $votes[] = $knownRequest['candidate'][$entity['candidate']];
            }
            if ($entity['offer'] && !empty($knownRequest['offer'][$entity['offer']])) {
                $votes[] = $knownRequest['offer'][$entity['offer']];
            }
            if (count(array_unique($votes)) === 1) {
                $knownRequest[$type][$id] = $votes[0];
            }
        }
    }
}

$suggestions = [];
$requestIndex = rl_build_request_index($requests);
foreach ($entities as $type => $items) {
    foreach ($items as $id => $entity) {
        if ($entity['request'] > 0) {
            continue; // Никогда не предлагаем заменить существующую связь.
        }
        $linkedRequest = $knownRequest[$type][$id] ?? 0;
        if ($linkedRequest && isset($requests[$linkedRequest])) {
            $score = rl_score($entity, $requests[$linkedRequest], true);
        } else {
            // Сравниваем только с заявками, у которых совпал хотя бы один
            // индексируемый признак, вместо полного декартова произведения.
            $candidateRequests = rl_candidate_requests($entity, $requests, $requestIndex);
            $score = rl_best_request($entity, $candidateRequests);
            $linkedRequest = $score['request_id'];
        }
        if (!$linkedRequest || $score['percent'] < 45) {
            continue;
        }
        $suggestions[$type . ':' . $id] = [
            'key' => $type . ':' . $id,
            'type' => $type,
            'id' => $id,
            'request_id' => $linkedRequest,
            'score' => $score,
            'safe' => $score['percent'] >= 70,
        ];
    }
}

$apply = $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && ($_POST['apply'] ?? '') === 'Y';
$selected = array_fill_keys(array_map('strval', (array)($_POST['selected'] ?? [])), true);
$selectedPairs = array_fill_keys(array_map('strval', (array)($_POST['selected_pair'] ?? [])), true);
$pairSuggestions = [];
foreach ($entities['offer'] as $offerId => $offer) {
    $candidateId = $offer['candidate'];
    if ($candidateId && isset($entities['candidate'][$candidateId]) && !$entities['candidate'][$candidateId]['offer']) {
        $pairSuggestions['candidate:' . $candidateId . ':offer:' . $offerId] = [
            'target_type' => 'candidate', 'target_id' => $candidateId, 'property' => $types['candidate']['offer'],
            'value' => $offerId, 'text' => 'Анкета #' . $candidateId . ' → оффер #' . $offerId,
        ];
    }
}
foreach ($entities['candidate'] as $candidateId => $candidate) {
    $offerId = $candidate['offer'];
    if ($offerId && isset($entities['offer'][$offerId]) && !$entities['offer'][$offerId]['candidate']) {
        $pairSuggestions['offer:' . $offerId . ':candidate:' . $candidateId] = [
            'target_type' => 'offer', 'target_id' => $offerId, 'property' => $types['offer']['candidate'],
            'value' => $candidateId, 'text' => 'Оффер #' . $offerId . ' → анкета #' . $candidateId,
        ];
    }
}
$updated = 0;
$pairsUpdated = 0;
$failed = [];
if ($apply) {
    foreach ($suggestions as $key => $suggestion) {
        if (!isset($selected[$key]) || !$suggestion['safe']) {
            continue;
        }
        $config = $types[$suggestion['type']];
        $entity = $entities[$suggestion['type']][$suggestion['id']];
        // Повторная проверка непосредственно перед записью защищает от гонки.
        $current = rl_property_values($config['iblock'], $suggestion['id'], $config['request']);
        if ($current) {
            continue;
        }
        CIBlockElement::SetPropertyValuesEx($suggestion['id'], $config['iblock'], [
            $config['request'] => $suggestion['request_id'],
        ]);
        $backlinks = rl_property_values(RL_IBLOCK_REQUEST, $suggestion['request_id'], $config['request_back']);
        $backlinks[] = $suggestion['id'];
        $backlinks = array_values(array_unique(array_map('intval', $backlinks)));
        sort($backlinks, SORT_NUMERIC);
        CIBlockElement::SetPropertyValuesEx($suggestion['request_id'], RL_IBLOCK_REQUEST, [
            $config['request_back'] => $backlinks,
        ]);
        $saved = rl_property_values($config['iblock'], $suggestion['id'], $config['request']);
        if ($saved === [$suggestion['request_id']]) {
            $updated++;
        } else {
            $failed[] = $key;
        }
    }
    foreach ($pairSuggestions as $key => $repair) {
        if (!isset($selectedPairs[$key])) {
            continue;
        }
        $config = $types[$repair['target_type']];
        if (rl_property_values($config['iblock'], $repair['target_id'], $repair['property'])) {
            continue;
        }
        CIBlockElement::SetPropertyValuesEx($repair['target_id'], $config['iblock'], [
            $repair['property'] => $repair['value'],
        ]);
        $saved = rl_property_values($config['iblock'], $repair['target_id'], $repair['property']);
        if ($saved === [$repair['value']]) {
            $pairsUpdated++;
        } else {
            $failed[] = $key;
        }
    }
}

?>
<style>
    .rl-card{background:#fff;border:1px solid #dfe3e8;border-radius:9px;padding:14px;margin:12px 0}.rl-table{width:100%;border-collapse:collapse;font-size:13px}.rl-table th,.rl-table td{padding:8px;border:1px solid #dfe3e8;text-align:left;vertical-align:top}.rl-table th{background:#f6f8fa}.rl-score{font-weight:700}.rl-high{color:#16813d}.rl-low{color:#a15c00}.rl-muted{color:#6b7280;font-size:12px}.rl-reasons{margin:4px 0 0;padding-left:18px}.rl-actions{position:sticky;top:0;background:#fff;padding:10px 0;z-index:2}
</style>
<div class="rl-card">
    <b>Предварительный расчёт</b>
    <p>Найдено <?=count($suggestions)?> незаполненных связей. Автоматически применяются только варианты с вероятностью не ниже 70%. Уже заполненные ID не заменяются.</p>
    <div class="rl-muted">Вес факторов: рекрутер — 25%, руководитель — 25%, должность — 30%, близость дат — 20%. Явная обратная или транзитивная связь даёт 100%.</div>
    <?php if ($apply): ?><p class="<?=empty($failed) ? 'rl-high' : 'rl-low'?>">Связей с заявками обновлено: <?=$updated?>; пар анкета–оффер: <?=$pairsUpdated?>. Ошибок проверки: <?=count($failed)?>.</p><?php endif; ?>
</div>
<form method="post">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="apply" value="Y">
    <div class="rl-actions">
        <button type="button" class="ui-btn ui-btn-light" onclick="document.querySelectorAll('.rl-safe').forEach(x=>x.checked=true)">Выбрать надёжные</button>
        <button type="button" class="ui-btn ui-btn-light-border" onclick="document.querySelectorAll('[name=\'selected[]\']').forEach(x=>x.checked=false)">Снять выбор</button>
        <button type="submit" class="ui-btn ui-btn-success" onclick="return confirm('Записать выбранные связи?')">Связать выбранные</button>
    </div>
    <table class="rl-table">
        <thead><tr><th></th><th>Сущность</th><th>Предлагаемая заявка</th><th>Вероятность</th><th>Основания</th></tr></thead>
        <tbody>
        <?php foreach ($suggestions as $suggestion): $entity = $entities[$suggestion['type']][$suggestion['id']]; $request = $requests[$suggestion['request_id']]; ?>
            <tr>
                <td><input type="checkbox" name="selected[]" value="<?=rl_h($suggestion['key'])?>" class="<?=$suggestion['safe'] ? 'rl-safe' : ''?>" <?=$suggestion['safe'] ? 'checked' : 'disabled'?>></td>
                <td><b><?=rl_h($types[$suggestion['type']]['title'])?> #<?=$suggestion['id']?></b><br><?=rl_h($entity['name'])?><div class="rl-muted"><?=rl_h($entity['position'])?></div></td>
                <td><b>#<?=$suggestion['request_id']?></b><br><?=rl_h($request['name'])?><div class="rl-muted"><?=rl_h($request['position'])?></div></td>
                <td class="rl-score <?=$suggestion['safe'] ? 'rl-high' : 'rl-low'?>"><?=$suggestion['score']['percent']?>%</td>
                <td><ul class="rl-reasons"><?php foreach ($suggestion['score']['reasons'] as $reason): ?><li><?=rl_h($reason)?></li><?php endforeach; ?></ul></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$suggestions): ?><tr><td colspan="5">Подходящих незаполненных связей не найдено.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="rl-card">
        <b>Односторонние связи анкета ↔ оффер</b>
        <p class="rl-muted">Эти пары найдены по уже заполнённому ID на одной стороне. Скрипт может безопасно дописать обратную ссылку.</p>
        <?php foreach ($pairSuggestions as $key => $repair): ?>
            <label style="display:block;margin:5px 0"><input type="checkbox" name="selected_pair[]" value="<?=rl_h($key)?>" checked> <?=rl_h($repair['text'])?> <span class="rl-high">(100%)</span></label>
        <?php endforeach; ?>
        <?php if (!$pairSuggestions): ?><span class="rl-muted">Несимметричных связей нет.</span><?php endif; ?>
    </div>
</form>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
