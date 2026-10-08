<?php
/** Общие права действия «Заменить руководителя» в списке и на странице передачи. */
function plansRecruitHeadIds()
{
    static $ids;
    if (is_array($ids)) return $ids;
    $ids = [];
    try {
        $connection = \Bitrix\Main\Application::getConnection();
        // Та же глобальная переменная руководителя отдела подбора, что в offer/list.php.
        $variableId = $connection->getSqlHelper()->forSql('Variable1722503621093');
        $row = $connection->query("SELECT PROPERTY_VALUE FROM b_bp_global_var WHERE ID = '{$variableId}' LIMIT 1")->fetch();
        $values = $row ? @unserialize($row['PROPERTY_VALUE'], ['allowed_classes' => false]) : [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (preg_match('/^(?:user_)?([0-9]+)$/i', trim((string)$value), $matches)) {
                $id = (int)$matches[1];
                if ($id > 0) $ids[$id] = $id;
            }
        }
    } catch (\Throwable $exception) {
        // При недоступной глобальной переменной дополнительные права не выдаются.
    }
    return array_values($ids);
}

function plansCanReplaceManager($userId, $recruiterId)
{
    $userId = (int)$userId;
    return $userId > 0 && ($userId === 3532
        || ($recruiterId > 0 && $userId === (int)$recruiterId)
        || in_array($userId, plansRecruitHeadIds(), true));
}
