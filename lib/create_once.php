<?php

/** Serialize retries across workers; keep the request key on the element itself. */
final class RecruitmentCreateOnce
{
    private $connection;
    private $lockName;
    public $xmlId;

    public static function token(string $scope): string
    {
        $nonce = bin2hex(random_bytes(16));
        return $nonce . '.' . hash_hmac('sha256', $scope . ':' . $nonce, bitrix_sessid());
    }

    public static function valid(string $scope, string $token): bool
    {
        if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $parts)) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $scope . ':' . $parts[1], bitrix_sessid()), $parts[2]);
    }

    public function __construct(string $scope, string $token)
    {
        if (!self::valid($scope, $token)) {
            throw new DomainException('Форма устарела. Обновите страницу перед созданием.');
        }
        $this->xmlId = 'recruitment-create-' . hash('sha256', $scope . ':' . $token);
        $this->lockName = hash('sha256', $this->xmlId);
        $this->connection = \Bitrix\Main\Application::getConnection();
        // MySQL advisory locks are shared by all PHP workers and released on disconnect.
        $row = $this->connection->query("SELECT GET_LOCK('{$this->lockName}', 0) AS ACQUIRED")->fetch();
        if ((int)($row['ACQUIRED'] ?? 0) !== 1) {
            throw new DomainException('Создание уже выполняется. Дождитесь завершения и откройте список.');
        }
        register_shutdown_function([$this, 'release']);
    }

    public function existingId(int $iblockId): int
    {
        $row = CIBlockElement::GetList([], [
            'IBLOCK_ID' => $iblockId,
            '=XML_ID' => $this->xmlId,
        ], false, ['nTopCount' => 1], ['ID'])->Fetch();
        return (int)($row['ID'] ?? 0);
    }

    public function release(): void
    {
        if ($this->lockName !== null) {
            $this->connection->query("SELECT RELEASE_LOCK('{$this->lockName}')");
            $this->lockName = null;
        }
    }
}
