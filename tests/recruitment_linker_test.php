<?php

require_once __DIR__ . '/../lib/recruitment_linker.php';

function expect($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect(rl_extract_id('user_412') === 412, 'ID извлекается из пользовательского значения');
expect(rl_extract_ids(['12', 'user_14', '12']) === [12, 14], 'множественные ID извлекаются без дублей');
expect(rl_normalize_text('Ведущий — Разработчик') === 'ведущий разработчик', 'текст нормализуется');
expect(rl_title_similarity('Senior PHP developer', 'PHP developer senior') > .7, 'порядок слов не мешает сравнению');
expect(rl_date_similarity(strtotime('2024-01-01'), strtotime('2024-01-08')) === 1.0, 'семь дней считаются близкими');

$entity = ['recruiter' => 7, 'manager' => 8, 'position' => 'PHP разработчик', 'date' => strtotime('2024-02-01')];
$requests = [
    10 => ['recruiter' => 7, 'manager' => 8, 'position' => 'Разработчик PHP', 'date' => strtotime('2024-01-25')],
    20 => ['recruiter' => 1, 'manager' => 2, 'position' => 'Бухгалтер', 'date' => strtotime('2020-01-01')],
];
$best = rl_best_request($entity, $requests);
expect($best['request_id'] === 10 && $best['percent'] >= 95, 'выбирается заявка по совокупности признаков');

$ambiguous = rl_best_request($entity, [10 => $requests[10], 11 => $requests[10]]);
expect($ambiguous['percent'] === 69, 'неоднозначное совпадение нельзя применить автоматически');

expect(rl_score($entity, $requests[20], true)['percent'] === 100, 'явная связь имеет максимальную уверенность');

$index = rl_build_request_index($requests);
$candidates = rl_candidate_requests($entity, $requests, $index);
expect(array_keys($candidates) === [10], 'индекс исключает заведомо неподходящие заявки');

$sorted = rl_sort_suggestions([
    'low' => ['type' => 'offer', 'id' => 2, 'score' => ['percent' => 60]],
    'high' => ['type' => 'candidate', 'id' => 1, 'score' => ['percent' => 95]],
    'middle' => ['type' => 'employee', 'id' => 3, 'score' => ['percent' => 75]],
]);
expect(array_keys($sorted) === ['high', 'middle', 'low'], 'предложения сортируются по вероятности по убыванию');
echo "OK\n";
