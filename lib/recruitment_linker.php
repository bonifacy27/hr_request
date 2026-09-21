<?php

/** Чистые функции расчёта: файл можно проверять без загрузки Bitrix. */

function rl_extract_id($value): int
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return preg_match('/\d+/', trim((string)$value), $match) ? (int)$match[0] : 0;
}

function rl_extract_ids($value): array
{
    $values = is_array($value) ? $value : [$value];
    $result = [];
    foreach ($values as $item) {
        $id = rl_extract_id($item);
        if ($id > 0) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}

function rl_normalize_text($value): string
{
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = str_replace(['ё', '–', '—'], ['е', '-', '-'], $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim((string)preg_replace('/\s+/u', ' ', $value));
}

function rl_title_similarity($left, $right): float
{
    $left = rl_normalize_text($left);
    $right = rl_normalize_text($right);
    if ($left === '' || $right === '') {
        return 0.0;
    }
    if ($left === $right) {
        return 1.0;
    }
    $a = array_values(array_unique(explode(' ', $left)));
    $b = array_values(array_unique(explode(' ', $right)));
    $union = array_unique(array_merge($a, $b));
    $tokenScore = count($union) ? count(array_intersect($a, $b)) / count($union) : 0;
    similar_text($left, $right, $characterScore);
    return min(1.0, 0.65 * $tokenScore + 0.35 * ($characterScore / 100));
}

function rl_date_similarity(int $entityDate, int $requestDate): float
{
    if (!$entityDate || !$requestDate) {
        return 0.0;
    }
    $days = abs($entityDate - $requestDate) / 86400;
    if ($days <= 7) return 1.0;
    if ($days <= 30) return 0.85;
    if ($days <= 90) return 0.60;
    if ($days <= 180) return 0.35;
    if ($days <= 365) return 0.15;
    return 0.0;
}

function rl_score(array $entity, array $request, bool $linked = false): array
{
    if ($linked) {
        return ['percent' => 100, 'reasons' => ['ID заявки найден по обратной или связанной записи']];
    }
    $score = 0.0;
    $reasons = [];
    foreach ([['recruiter', 25, 'Совпадает рекрутер'], ['manager', 25, 'Совпадает руководитель']] as $factor) {
        [$field, $weight, $label] = $factor;
        if (!empty($entity[$field]) && !empty($request[$field]) && (int)$entity[$field] === (int)$request[$field]) {
            $score += $weight;
            $reasons[] = $label . ' (+' . $weight . ')';
        }
    }
    $title = rl_title_similarity($entity['position'] ?? '', $request['position'] ?? '');
    if ($title > 0) {
        $points = 30 * $title;
        $score += $points;
        $reasons[] = 'Сходство должности ' . round($title * 100) . '% (+' . round($points) . ')';
    }
    $date = rl_date_similarity((int)($entity['date'] ?? 0), (int)($request['date'] ?? 0));
    if ($date > 0) {
        $points = 20 * $date;
        $score += $points;
        $reasons[] = 'Близость дат (+' . round($points) . ')';
    }
    if (!$reasons) {
        $reasons[] = 'Совпадающих признаков нет';
    }
    return ['percent' => (int)round(min(100, $score)), 'reasons' => $reasons];
}

function rl_best_request(array $entity, array $requests): array
{
    $best = ['request_id' => 0, 'percent' => 0, 'reasons' => []];
    $runnerUp = 0;
    foreach ($requests as $requestId => $request) {
        $result = rl_score($entity, $request);
        if ($result['percent'] > $best['percent']) {
            $runnerUp = $best['percent'];
            $best = $result + ['request_id' => (int)$requestId];
        } elseif ($result['percent'] > $runnerUp) {
            $runnerUp = $result['percent'];
        }
    }
    // При почти равных вариантах вероятность должна отражать неоднозначность.
    if ($best['percent'] > 0 && $best['percent'] - $runnerUp < 10) {
        $best['reasons'][] = 'Есть близкий альтернативный вариант: ' . $runnerUp . '%';
        $best['percent'] = min($best['percent'], 69);
    }
    return $best;
}

function rl_position_tokens($value): array
{
    $tokens = explode(' ', rl_normalize_text($value));
    return array_values(array_filter(array_unique($tokens), static function ($token) {
        return mb_strlen($token, 'UTF-8') >= 3;
    }));
}

function rl_build_request_index(array $requests): array
{
    $index = ['recruiter' => [], 'manager' => [], 'token' => [], 'month' => []];
    foreach ($requests as $id => $request) {
        foreach (['recruiter', 'manager'] as $field) {
            $value = (int)($request[$field] ?? 0);
            if ($value > 0) {
                $index[$field][$value][$id] = $id;
            }
        }
        foreach (rl_position_tokens($request['position'] ?? '') as $token) {
            $index['token'][$token][$id] = $id;
        }
        if (!empty($request['date'])) {
            $index['month'][date('Y-m', (int)$request['date'])][$id] = $id;
        }
    }
    return $index;
}

function rl_candidate_requests(array $entity, array $requests, array $index): array
{
    $ids = [];
    foreach (['recruiter', 'manager'] as $field) {
        $value = (int)($entity[$field] ?? 0);
        if ($value > 0 && isset($index[$field][$value])) {
            $ids += $index[$field][$value];
        }
    }
    foreach (rl_position_tokens($entity['position'] ?? '') as $token) {
        if (isset($index['token'][$token])) {
            $ids += $index['token'][$token];
        }
    }
    if (!empty($entity['date'])) {
        $base = new DateTimeImmutable('@' . (int)$entity['date']);
        for ($offset = -12; $offset <= 12; $offset++) {
            $month = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' months')->format('Y-m');
            if (isset($index['month'][$month])) {
                $ids += $index['month'][$month];
            }
        }
    }
    return array_intersect_key($requests, $ids);
}
