<?php
/**
 * Отправка сотруднику обязательных ЛНА для ознакомления.
 *
 * Скрипт рассчитан на запуск из PHP-действия бизнес-процесса по элементу списка
 * «Анкеты новых сотрудников» (ИБ 196). Также поддерживает прямой запуск с параметром
 * element_id/id для отладки.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

if (!class_exists('Bitrix\\Main\\Loader')) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
}

if (!\Bitrix\Main\Loader::includeModule('iblock')) {
    throw new RuntimeException('Не удалось подключить модуль iblock.');
}

\Bitrix\Main\Loader::includeModule('disk');

if (isset($this) && is_object($this)) {
    $GLOBALS['LNA_BP_ACTIVITY'] = $this;
}

const LNA_EMPLOYEE_IBLOCK_ID = 196;
const LNA_REGULATIONS_IBLOCK_ID = 67;

const LNA_PROP_EMPLOYEE_USER = 972;
const LNA_PROP_REQUIRED_ON_HIRE = 3144;
const LNA_PROP_STATUS = 387;
const LNA_PROP_FILES = 395;

const LNA_REQUIRED_YES_ENUM_ID = 6819;
const LNA_STATUS_ACTIVE_ENUM_ID = 522;

const LNA_MAIL_SUBJECT = 'Документы ЛНА для ознакомления';
const LNA_MAIL_BODY = "Добрый день!\nВам отправлены файлы ЛНА для ознакомления.";

function lnaLog(string $message): void
{
    if (defined('STDOUT')) {
        echo $message . PHP_EOL;
    }
}

function lnaExtractElementIdFromDocumentId($documentId): int
{
    if (is_array($documentId)) {
        $documentId = end($documentId);
    }

    $raw = trim((string)$documentId);
    if ($raw === '') {
        return 0;
    }

    if (ctype_digit($raw)) {
        return (int)$raw;
    }

    if (preg_match('/(?:^|:)(\d+)$/', $raw, $matches)) {
        return (int)$matches[1];
    }

    if (preg_match('/(\d+)$/', $raw, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function lnaDetectCurrentElementId(): int
{
    $candidates = [
        $GLOBALS['LNA_SENDING_ELEMENT_ID'] ?? null,
        $_REQUEST['element_id'] ?? null,
        $_REQUEST['ELEMENT_ID'] ?? null,
        $_REQUEST['id'] ?? null,
        $_REQUEST['ID'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $elementId = lnaExtractElementIdFromDocumentId($candidate);
        if ($elementId > 0) {
            return $elementId;
        }
    }

    if (isset($GLOBALS['bizprocDocumentId'])) {
        $elementId = lnaExtractElementIdFromDocumentId($GLOBALS['bizprocDocumentId']);
        if ($elementId > 0) {
            return $elementId;
        }
    }

    if (isset($GLOBALS['LNA_BP_ACTIVITY']) && is_object($GLOBALS['LNA_BP_ACTIVITY']) && method_exists($GLOBALS['LNA_BP_ACTIVITY'], 'GetDocumentId')) {
        $elementId = lnaExtractElementIdFromDocumentId($GLOBALS['LNA_BP_ACTIVITY']->GetDocumentId());
        if ($elementId > 0) {
            return $elementId;
        }
    }

    return 0;
}

function lnaGetEmployeeUserId(int $elementId): int
{
    $property = CIBlockElement::GetProperty(
        LNA_EMPLOYEE_IBLOCK_ID,
        $elementId,
        [],
        ['ID' => LNA_PROP_EMPLOYEE_USER]
    )->Fetch();

    if (!$property) {
        return 0;
    }

    $value = $property['VALUE'] ?? 0;
    if (is_array($value)) {
        $value = reset($value);
    }

    return (int)$value;
}

function lnaGetUserEmail(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return '';
    }

    return trim((string)($user['EMAIL'] ?? ''));
}

function lnaExtractDiskAttachedObjectId($value): int
{
    if (is_array($value)) {
        foreach (['VALUE', 'ID', 'FILE_ID'] as $key) {
            if (isset($value[$key])) {
                $id = lnaExtractDiskAttachedObjectId($value[$key]);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return 0;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return 0;
    }

    if (preg_match('/^n(\d+)$/i', $raw, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function lnaExtractNumericFileId($value): int
{
    if (is_array($value)) {
        foreach (['FILE_ID', 'ID', 'VALUE'] as $key) {
            if (isset($value[$key])) {
                $id = lnaExtractNumericFileId($value[$key]);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return 0;
    }

    $raw = trim((string)$value);
    return ctype_digit($raw) ? (int)$raw : 0;
}

function lnaFileArrayFromDiskValue($value): ?array
{
    $attachedObjectId = lnaExtractDiskAttachedObjectId($value);
    if ($attachedObjectId > 0 && class_exists('Bitrix\\Disk\\AttachedObject')) {
        $attachedObject = \Bitrix\Disk\AttachedObject::loadById($attachedObjectId);
        if ($attachedObject) {
            $diskFile = $attachedObject->getFile();
            if ($diskFile && method_exists($diskFile, 'getFileId')) {
                $file = CFile::GetFileArray((int)$diskFile->getFileId());
                if ($file) {
                    return $file;
                }
            }
        }
    }

    $fileId = lnaExtractNumericFileId($value);
    if ($fileId > 0) {
        $file = CFile::GetFileArray($fileId);
        if ($file) {
            return $file;
        }
    }

    return null;
}

function lnaAbsoluteFilePath(array $file): string
{
    $path = (string)($file['SRC'] ?? '');
    if ($path === '' && isset($file['SUBDIR'], $file['FILE_NAME'])) {
        $path = '/upload/' . trim((string)$file['SUBDIR'], '/') . '/' . ltrim((string)$file['FILE_NAME'], '/');
    }

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return '';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return $_SERVER['DOCUMENT_ROOT'] . $path;
}

function lnaBuildAttachment(array $file, int $elementId): ?array
{
    $path = lnaAbsoluteFilePath($file);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }

    $name = trim((string)($file['ORIGINAL_NAME'] ?? $file['FILE_NAME'] ?? basename($path)));
    if ($name === '') {
        $name = basename($path);
    }

    return [
        'ELEMENT_ID' => $elementId,
        'FILE_ID' => (int)($file['ID'] ?? 0),
        'PATH' => $path,
        'NAME' => $name,
        'CONTENT_TYPE' => (string)($file['CONTENT_TYPE'] ?? 'application/octet-stream'),
    ];
}

function lnaGetRegulationAttachments(): array
{
    $attachments = [];
    $seen = [];

    $rsElements = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        [
            'IBLOCK_ID' => LNA_REGULATIONS_IBLOCK_ID,
            'ACTIVE' => 'Y',
            'PROPERTY_' . LNA_PROP_REQUIRED_ON_HIRE => LNA_REQUIRED_YES_ENUM_ID,
            'PROPERTY_' . LNA_PROP_STATUS => LNA_STATUS_ACTIVE_ENUM_ID,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($element = $rsElements->Fetch()) {
        $elementId = (int)$element['ID'];
        $rsFiles = CIBlockElement::GetProperty(
            LNA_REGULATIONS_IBLOCK_ID,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['ID' => LNA_PROP_FILES]
        );

        while ($property = $rsFiles->Fetch()) {
            if (empty($property['VALUE'])) {
                continue;
            }

            $file = lnaFileArrayFromDiskValue($property['VALUE']);
            if (!$file) {
                continue;
            }

            $attachment = lnaBuildAttachment($file, $elementId);
            if (!$attachment) {
                continue;
            }

            $key = $attachment['FILE_ID'] > 0 ? 'file:' . $attachment['FILE_ID'] : 'path:' . $attachment['PATH'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $attachments[] = $attachment;
        }
    }

    return $attachments;
}

function lnaEncodeMimeHeader(string $value): string
{
    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($value, defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8')
        : $value;
}

function lnaSendMailViaBitrix(string $email, array $attachments): bool
{
    if (!class_exists('Bitrix\\Main\\Mail\\Mail')) {
        return false;
    }

    $mailAttachments = [];
    foreach ($attachments as $attachment) {
        $mailAttachments[] = [
            'PATH' => $attachment['PATH'],
            'NAME' => $attachment['NAME'],
            'CONTENT_TYPE' => $attachment['CONTENT_TYPE'],
        ];
    }

    return (bool)\Bitrix\Main\Mail\Mail::send([
        'TO' => $email,
        'SUBJECT' => LNA_MAIL_SUBJECT,
        'BODY' => LNA_MAIL_BODY,
        'CHARSET' => defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8',
        'CONTENT_TYPE' => 'text/plain',
        'ATTACHMENT' => $mailAttachments,
    ]);
}

function lnaSendMailFallback(string $email, array $attachments): bool
{
    $charset = defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8';
    $boundary = 'lna-' . md5(uniqid('', true));
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $body = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset=' . $charset . "\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode(LNA_MAIL_BODY));

    foreach ($attachments as $attachment) {
        $content = file_get_contents($attachment['PATH']);
        if ($content === false) {
            continue;
        }

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $attachment['CONTENT_TYPE'] . '; name="' . lnaEncodeMimeHeader($attachment['NAME']) . '"' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . lnaEncodeMimeHeader($attachment['NAME']) . '"' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($content));
    }

    $body .= '--' . $boundary . "--\r\n";

    return mail($email, lnaEncodeMimeHeader(LNA_MAIL_SUBJECT), $body, implode("\r\n", $headers));
}

function lnaSendMail(string $email, array $attachments): bool
{
    if (lnaSendMailViaBitrix($email, $attachments)) {
        return true;
    }

    return lnaSendMailFallback($email, $attachments);
}

$elementId = lnaDetectCurrentElementId();
if ($elementId <= 0) {
    throw new RuntimeException('Не удалось определить ID текущего документа бизнес-процесса.');
}

$userId = lnaGetEmployeeUserId($elementId);
if ($userId <= 0) {
    throw new RuntimeException('В текущем документе не заполнено поле UZ_SOTRUDNIKA.');
}

$email = lnaGetUserEmail($userId);
if ($email === '' || !check_email($email)) {
    throw new RuntimeException('Не удалось определить корректный e-mail сотрудника из поля UZ_SOTRUDNIKA.');
}

$attachments = lnaGetRegulationAttachments();
if (!$attachments) {
    throw new RuntimeException('Не найдены файлы ЛНА для отправки.');
}

if (!lnaSendMail($email, $attachments)) {
    throw new RuntimeException('Не удалось отправить письмо сотруднику ' . $email . '.');
}

lnaLog('Письмо с файлами ЛНА отправлено на ' . $email . '. Вложений: ' . count($attachments) . '.');
