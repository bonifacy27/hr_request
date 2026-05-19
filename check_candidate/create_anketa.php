<?php
/**
 * Форма создания анкеты кандидата для дальнейшей проверки.
 * URL: /check_candidate/create_anketa.php
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты кандидата');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc')) {
    ShowError('Не удалось подключить модули iblock/bizproc.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

Extension::load(['main.core', 'ui.entity-selector']);

const IBL_CANDIDATES = 207;
const IBL_REQUESTS = 201;
const BP_TEMPLATE_1 = 466;
const BP_TEMPLATE_2 = 328;
const TIP_ANKETY_PROF_VALUE = 814;
const FW_API_INTERNAL  = 'https://app.friend.work/api';
const FW_LOGIN_CONST_ID = 'Constant1698403240866';
const FW_PASS_CONST_ID  = 'Constant1698403290839';
const FW_STATUS_APPROVED_INTERVIEW_DONE = 127730;

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $value;
}

function decodeName(string $name): string
{
    return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function getIblockElementsById(int $iblockId): array
{
    $res = [];
    $rs = CIBlockElement::GetList(['ID' => 'DESC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME']);
    while ($row = $rs->GetNext()) {
        $res[] = ['ID' => (string)$row['ID'], 'NAME' => decodeName((string)$row['NAME'])];
    }
    return $res;
}

function getElementById(int $iblockId, int $id, array $select): ?array
{
    $row = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $id, 'ACTIVE' => 'Y'], false, ['nTopCount' => 1], $select)->GetNext();
    return $row ?: null;
}

function startListWorkflow(int $templateId, int $elementId, array &$errors): bool
{
    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $elementId];
    return CBPDocument::StartWorkflow($templateId, $documentId, [], $errors) !== false;
}

function decodeGlobalConstValue(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $unserialized = @unserialize($raw, ['allowed_classes' => false]);
    if (is_array($unserialized)) {
        if (isset($unserialized['value'])) return trim((string)$unserialized['value']);
        if (isset($unserialized[0])) return trim((string)$unserialized[0]);
    }
    if (is_string($unserialized)) return trim($unserialized);
    return trim($raw);
}

function fwGetCredentials(): array
{
    global $DB;
    $ids = [FW_LOGIN_CONST_ID, FW_PASS_CONST_ID];
    $map = [];
    $rs = $DB->Query("SELECT ID, PROPERTY_VALUE FROM b_bp_global_const WHERE ID IN ('" . implode("','", $ids) . "')");
    while ($row = $rs->Fetch()) {
        $map[$row['ID']] = (string)$row['PROPERTY_VALUE'];
    }
    return [
        'username' => decodeGlobalConstValue((string)($map[FW_LOGIN_CONST_ID] ?? '')),
        'password' => decodeGlobalConstValue((string)($map[FW_PASS_CONST_ID] ?? '')),
    ];
}

function fwInternalAuth(string $cookieFile): string
{
    $cfg = fwGetCredentials();
    if ($cfg['username'] === '' || $cfg['password'] === '') {
        return 'Не удалось получить логин/пароль Friendwork.';
    }
    $loginUrl = FW_API_INTERNAL . "/Accounts/LogIn?username=" . urlencode($cfg['username']) . "&password=" . urlencode($cfg['password']);
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $loginUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 200) {
        return 'Ошибка авторизации Friendwork: HTTP ' . $httpCode . ($err ? ' (' . $err . ')' : '');
    }
    return '';
}

function fwInternalCandidatesByVacancy(int $vacancyId, string $cookieFile): array
{
    $all = [];
    $page = 1;
    while (true) {
        $payload = ["page" => $page, "perPageCount" => 20, "statuses" => [FW_STATUS_APPROVED_INTERVIEW_DONE], "jobId" => $vacancyId];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => FW_API_INTERNAL . '/Candidates',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return ['error' => 'Ошибка запроса кандидатов Friendwork (HTTP '.$code.').', 'data' => []];
        $data = json_decode((string)$raw, true);
        $chunk = $data['candidates'] ?? [];
        if (!is_array($chunk) || count($chunk) === 0) break;
        $all = array_merge($all, $chunk);
        if (count($chunk) < 20) break;
        $page++;
    }
    return ['error' => '', 'data' => $all];
}

$mode = 'manual';
$selectedRequestId = (int)($_GET['id_request'] ?? 0);
$selectedCandidateId = (int)($_GET['candidate_id'] ?? 0);
if ($selectedRequestId > 0) {
    $mode = 'request';
}
if (isset($_POST['MODE'])) {
    $mode = in_array($_POST['MODE'], ['request', 'manual', 'mass'], true) ? $_POST['MODE'] : 'manual';
    $selectedRequestId = (int)($_POST['SOURCE_REQUEST_ID'] ?? $selectedRequestId);
}
if ($mode === 'mass') {
    LocalRedirect('/pub/apps/adaptation/check_mass.php');
}

$fields = [
    ['id' => 1083, 'code' => 'FAMILIYA', 'label' => 'Фамилия', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1084, 'code' => 'IMYA', 'label' => 'Имя', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1085, 'code' => 'OTCHESTVO', 'label' => 'Отчество', 'type' => 'S', 'required' => false, 'hidden' => false],
    ['id' => 1088, 'code' => 'KONTAKTNYY_TELEFON', 'label' => 'Контактный телефон', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1089, 'code' => 'EMAIL', 'label' => 'E-mail', 'type' => 'S', 'required' => false, 'hidden' => false],
    ['id' => 1323, 'code' => 'REKRUTER', 'label' => 'Рекрутер', 'type' => 'USER', 'required' => true, 'hidden' => false],
    ['id' => 1617, 'code' => 'DOLZHNOST', 'label' => 'Должность', 'type' => 'S', 'required' => true, 'hidden' => false],
    ['id' => 1988, 'code' => 'RUKOVODITEL', 'label' => 'Руководитель', 'type' => 'USER', 'required' => true, 'hidden' => false],
    ['id' => 1098, 'code' => 'DATA_VYKHODA', 'label' => 'Плановая дата выхода', 'type' => 'DATE', 'required' => false, 'hidden' => true],
    ['id' => 1596, 'code' => 'ID_ZAYAVKI_NA_PODBOR', 'label' => 'ID заявки на подбор', 'type' => 'N', 'required' => false, 'hidden' => true],
    ['id' => 2854, 'code' => 'ROUTE', 'label' => 'Маршрут', 'type' => 'S', 'required' => false, 'hidden' => true],
    ['id' => 1093, 'code' => 'TIP_ANKETY', 'label' => 'Тип анкеты', 'type' => 'L', 'required' => false, 'hidden' => true],
    ['id' => 1689, 'code' => 'REZYUME', 'label' => 'Резюме', 'type' => 'FILE', 'required' => false, 'hidden' => false],
    ['id' => 1726, 'code' => 'SOGLASOVANIE_KANDIDATA', 'label' => 'Согласование кандидата руководителем', 'type' => 'FILE', 'required' => false, 'hidden' => false],
];

$formData = [];
foreach ($fields as $f) {
    $formData[$f['code']] = '';
}

$requestList = getIblockElementsById(IBL_REQUESTS);
$errors = [];
$saveMessage = null;
$warnings = [];
$fwCandidates = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'request' && $selectedRequestId > 0) {
    $rq = getElementById(IBL_REQUESTS, $selectedRequestId, ['ID', 'PROPERTY_DOLZHNOST', 'PROPERTY_1035', 'PROPERTY_NEPOSREDSTVENNYY_RUKOVODITEL', 'PROPERTY_1593']);
    if ($rq) {
        $formData['DOLZHNOST'] = (string)($rq['PROPERTY_DOLZHNOST_VALUE'] ?? '');
        $formData['REKRUTER'] = (string)($rq['PROPERTY_1035_VALUE'] ?? '');
        $formData['RUKOVODITEL'] = (string)($rq['PROPERTY_NEPOSREDSTVENNYY_RUKOVODITEL_VALUE'] ?? '');
        $fwVacancyId = (int)($rq['PROPERTY_1593_VALUE'] ?? 0);
        if ($fwVacancyId <= 0) {
            $warnings[] = 'В заявке не указана вакансия Friendwork (PROPERTY_1593).';
        } else {
            $cookieFile = __DIR__ . '/fw_cookie_create_anketa.txt';
            $authErr = fwInternalAuth($cookieFile);
            if ($authErr !== '') {
                $warnings[] = $authErr;
            } else {
                $fwRes = fwInternalCandidatesByVacancy($fwVacancyId, $cookieFile);
                if ($fwRes['error'] !== '') {
                    $warnings[] = $fwRes['error'];
                } else {
                    $fwCandidates = $fwRes['data'];
                    if (count($fwCandidates) === 0) {
                        $warnings[] = 'По привязанной к заявке на подбор вакансии не найден кандидат.';
                    } else {
                        $selected = $fwCandidates[0];
                        if ($selectedCandidateId > 0) {
                            foreach ($fwCandidates as $cand) {
                                if ((int)($cand['candidateId'] ?? 0) === $selectedCandidateId) {
                                    $selected = $cand;
                                    break;
                                }
                            }
                        }
                        $fio = trim((string)($selected['lastName'] ?? '') . ' ' . (string)($selected['firstName'] ?? '') . ' ' . (string)($selected['middleName'] ?? ''));
                        $parts = preg_split('/\s+/', trim($fio));
                        $formData['FAMILIYA'] = (string)($parts[0] ?? '');
                        $formData['IMYA'] = (string)($parts[1] ?? '');
                        $formData['OTCHESTVO'] = (string)($parts[2] ?? '');
                        $formData['KONTAKTNYY_TELEFON'] = (string)($selected['communicationChannels']['phone'][0] ?? '');
                        $formData['EMAIL'] = (string)($selected['communicationChannels']['email'][0] ?? '');
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $propertyValues = [];

    foreach ($fields as $f) {
        $code = $f['code'];
        $value = $_POST[$code] ?? '';

        if ($f['type'] === 'FILE') {
            $value = $_FILES[$code] ?? null;
        } elseif ($f['type'] === 'DATE') {
            $value = normalizeDate((string)$value);
        } elseif ($f['type'] === 'USER') {
            $value = (string)(int)preg_replace('/\D+/', '', trim((string)$value));
            if ($value === '0') {
                $value = '';
            }
        } else {
            $value = trim((string)$value);
        }

        if (!is_array($value)) {
            $formData[$code] = (string)$value;
        }

        if ($f['type'] === 'FILE') {
            if (is_array($value) && !empty($value['name'])) {
                $propertyValues[(int)$f['id']] = $value;
            }
        } elseif ($value !== '') {
            $propertyValues[(int)$f['id']] = $value;
        }

        if (!empty($f['required']) && trim((string)($formData[$code] ?? '')) === '') {
            $errors[] = 'Не заполнено обязательное поле: ' . $f['label'];
        }
    }

    // Системные значения по требованиям.
    $propertyValues[1093] = TIP_ANKETY_PROF_VALUE;
    if ($mode === 'request') {
        $propertyValues[2854] = 'Из заявки на подбор';
        $propertyValues[1596] = $selectedRequestId > 0 ? $selectedRequestId : '';
    } else {
        $propertyValues[2854] = 'Без заявки на подбор';
        unset($propertyValues[1596]);
    }
    unset($propertyValues[1098]); // поле скрыто и не участвует в форме.

    $name = trim(($formData['FAMILIYA'] ?? '') . ' ' . ($formData['IMYA'] ?? '') . ' ' . ($formData['OTCHESTVO'] ?? ''));
    if ($name === '') {
        $errors[] = 'Заполните минимум Фамилию и Имя.';
    }
    if ($mode === 'request' && $selectedRequestId <= 0) {
        $errors[] = 'Для режима "Из заявки на подбор" выберите заявку.';
    }

    if (!$errors) {
        $el = new CIBlockElement();
        $newId = $el->Add([
            'IBLOCK_ID' => IBL_CANDIDATES,
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'PROPERTY_VALUES' => $propertyValues,
        ]);

        if (!$newId) {
            $errors[] = (string)$el->LAST_ERROR;
        } else {
            $bpErrors1 = [];
            $bpErrors2 = [];
            startListWorkflow(BP_TEMPLATE_1, (int)$newId, $bpErrors1);
            startListWorkflow(BP_TEMPLATE_2, (int)$newId, $bpErrors2);
            if (!empty($bpErrors1) || !empty($bpErrors2)) {
                $errors[] = 'Анкета создана, но запуск БП завершился с ошибками.';
            }
            $saveMessage = 'Анкета кандидата создана. ID: ' . (int)$newId;
        }
    }
}
?>
<style>
.anketa-wrap{max-width:960px;margin:24px auto;padding:0 12px}.anketa-title{font-size:24px;font-weight:600;margin:0 0 18px}
.anketa-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}.anketa-field{display:flex;flex-direction:column;gap:6px}
.anketa-field label{font-size:13px;color:#525c69}.anketa-field input,.anketa-field select{height:38px;padding:0 10px;border:1px solid #c6cdd3;border-radius:6px}
.anketa-full{grid-column:1/-1}.anketa-actions{margin-top:18px}.anketa-msg{padding:10px 12px;border-radius:6px;margin-bottom:14px;white-space:pre-line}
.anketa-msg-ok{background:#e8f7e8;color:#1f7a1f}.anketa-msg-err{background:#ffe9e9;color:#9f2f2f}
.anketa-mode-box{border:1px solid #dfe5eb;border-radius:8px;padding:12px 14px;background:#fafcff}
.anketa-source-select{max-width:560px;width:100%}.anketa-mode-row{display:flex;gap:14px;align-items:end;flex-wrap:wrap}.req{color:#d95757}
</style>
<div class="anketa-wrap">
    <h1 class="anketa-title">Создание анкеты кандидата</h1>

    <?php if ($saveMessage): ?>
        <div class="anketa-msg anketa-msg-ok"><?= h($saveMessage) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="anketa-msg anketa-msg-err"><?= h(implode("\n", $errors)) ?></div>
    <?php endif; ?>
    <?php if ($warnings): ?>
        <div class="anketa-msg anketa-msg-err"><?= h(implode("\n", $warnings)) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= bitrix_sessid_post() ?>

        <div class="anketa-field anketa-full anketa-mode-box">
            <label>Режим создания</label>
            <div>
                <label><input type="radio" name="MODE" value="request" <?= $mode === 'request' ? 'checked' : '' ?>> Из заявки на подбор</label>
                <label style="margin-left:12px"><input type="radio" name="MODE" value="manual" <?= $mode === 'manual' ? 'checked' : '' ?>> Без заявки на подбор</label>
                <label style="margin-left:12px"><input type="radio" name="MODE" value="mass" <?= $mode === 'mass' ? 'checked' : '' ?>> Массовый подбор</label>
            </div>
            <div class="anketa-mode-row">
                <div id="request_block" style="display:<?= $mode === 'request' ? 'block' : 'none' ?>">
                    <label for="SOURCE_REQUEST_ID">Заявка на подбор</label>
                    <select class="anketa-source-select" name="SOURCE_REQUEST_ID" id="SOURCE_REQUEST_ID">
                        <option value="">— выбрать заявку —</option>
                        <?php foreach ($requestList as $it): ?>
                            <option value="<?= h($it['ID']) ?>" <?= ((string)$selectedRequestId === (string)$it['ID']) ? 'selected' : '' ?>><?= h($it['ID'] . ' — ' . $it['NAME']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($mode === 'request' && count($fwCandidates) > 1): ?>
                    <div>
                        <label for="SOURCE_CANDIDATE_ID">Кандидат из Friendwork</label>
                        <select class="anketa-source-select" id="SOURCE_CANDIDATE_ID">
                            <?php foreach ($fwCandidates as $cand): $cid = (int)($cand['candidateId'] ?? 0); ?>
                                <?php $fio = trim((string)($cand['lastName'] ?? '') . ' ' . (string)($cand['firstName'] ?? '') . ' ' . (string)($cand['middleName'] ?? '')); ?>
                                <option value="<?= h($cid) ?>" <?= $selectedCandidateId === $cid ? 'selected' : '' ?>><?= h(($cid ?: '-') . ' — ' . ($fio ?: 'Без ФИО')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="anketa-grid">
            <?php foreach ($fields as $f): if (!empty($f['hidden'])) { continue; } $code = $f['code']; ?>
                <div class="anketa-field <?= in_array($code, ['REZYUME','SOGLASOVANIE_KANDIDATA'], true) ? 'anketa-full' : '' ?>">
                    <label for="<?= h($code) ?>"><?= h($f['label']) ?><?= !empty($f['required']) ? '<span class="req">*</span>' : '' ?></label>
                    <?php if ($f['type'] === 'USER'): ?>
                        <input type="hidden" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                        <div id="<?= h($code) ?>_selector"></div>
                    <?php elseif ($f['type'] === 'FILE'): ?>
                        <input type="file" name="<?= h($code) ?>" id="<?= h($code) ?>">
                    <?php elseif ($f['type'] === 'DATE'): ?>
                        <input type="date" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                    <?php else: ?>
                        <input type="text" name="<?= h($code) ?>" id="<?= h($code) ?>" value="<?= h($formData[$code]) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="anketa-actions">
            <button class="ui-btn ui-btn-success" type="submit">Создать анкету</button>
        </div>
    </form>
</div>
<script>
BX.ready(function () {
    function initUserSelector(code) {
        const hidden = BX(code);
        const container = BX(code + '_selector');
        if (!hidden || !container || !BX.UI || !BX.UI.EntitySelector) {
            return;
        }
        const preselected = [];
        if (hidden.value) {
            const userId = parseInt(String(hidden.value).replace('user_', ''), 10);
            if (userId > 0) {
                preselected.push(['user', userId]);
            }
        }

        const tagSelector = new BX.UI.EntitySelector.TagSelector({
            dialogOptions: {
                context: code + '_context',
                entities: [{id: 'user'}],
                multiple: false,
                preselectedItems: preselected
            },
            events: {
                onAfterTagAdd: function () {
                    const tags = tagSelector.getTags();
                    hidden.value = (tags.length > 0) ? String(tags[0].getId()) : '';
                },
                onAfterTagRemove: function () {
                    hidden.value = '';
                }
            }
        });
        tagSelector.renderTo(container);
    }

    initUserSelector('REKRUTER');
    initUserSelector('RUKOVODITEL');

    document.querySelectorAll('input[name="MODE"]').forEach(function (el) {
        el.addEventListener('change', function () {
            if (this.value === 'mass') {
                window.location.href = '/pub/apps/adaptation/check_mass.php';
                return;
            }
            BX('request_block').style.display = (this.value === 'request') ? 'block' : 'none';
        });
    });

    const requestInput = BX('SOURCE_REQUEST_ID');
    if (requestInput) {
        requestInput.addEventListener('change', function () {
            const id = parseInt(this.value, 10) || 0;
            const url = new URL(window.location.href);
            url.searchParams.delete('id_request');
            if (id > 0) {
                url.searchParams.set('id_request', String(id));
            }
            window.location.href = url.toString();
        });
    }
    const candidateInput = BX('SOURCE_CANDIDATE_ID');
    if (candidateInput) {
        candidateInput.addEventListener('change', function () {
            const id = parseInt(this.value, 10) || 0;
            const url = new URL(window.location.href);
            url.searchParams.delete('candidate_id');
            if (id > 0) {
                url.searchParams.set('candidate_id', String(id));
            }
            window.location.href = url.toString();
        });
    }
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
