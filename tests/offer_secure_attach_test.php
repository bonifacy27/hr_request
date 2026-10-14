<?php
require_once __DIR__ . '/../offer/offer_secure_pdf.php';

class OfferAttachResult {
    private $row;
    public function __construct($row) { $this->row = $row; }
    public function Fetch() { return $this->row; }
}
class CIBlockProperty {
    public static $property = ['CODE' => 'OFFER_PDF_DRAFT', 'PROPERTY_TYPE' => 'F', 'MULTIPLE' => 'N'];
    public static function GetByID($id, $iblock) {
        if (!in_array($id, [3196, 3197], true) || $iblock !== 218) { throw new RuntimeException('Wrong property'); }
        return new OfferAttachResult($id === 3197 ? ['CODE' => 'PDF_PASSWORD', 'PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'N'] : self::$property);
    }
}
class CIBlockElement {
    public static $fileId = 0;
    public static $password = '';
    public static $failPassword = false;
    public static $writes = [];
    public static $failWrite = false;
    public static function GetProperty($iblock, $id, $sort, $filter) {
        if ($iblock !== 218 || $id !== 123 || !in_array($filter, [['ID' => 3196], ['ID' => 3197]], true)) {
            throw new RuntimeException('Wrong offer property');
        }
        return new OfferAttachResult(['VALUE' => $filter['ID'] === 3197 ? self::$password : self::$fileId]);
    }
    public static function SetPropertyValuesEx($id, $iblock, $values) {
        self::$writes[] = [$id, $iblock, $values];
        if (self::$failWrite) { return; }
        $file = $values['OFFER_PDF_DRAFT']['VALUE'];
        if ($id !== 123 || $iblock !== 218 || $file['type'] !== 'application/pdf') {
            throw new RuntimeException('Wrong file payload');
        }
        self::$fileId++;
        if (!self::$failPassword) { self::$password = $values['PDF_PASSWORD']; }
        CFile::$files[self::$fileId] = ['ORIGINAL_NAME' => $file['name'], 'FILE_SIZE' => filesize($file['tmp_name'])];
    }
}
class CFile {
    public static $files = [];
    public static function MakeFileArray($path) { return ['tmp_name' => $path]; }
    public static function GetFileArray($id) { return self::$files[$id] ?? false; }
}
function attachCheck($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function attachExpectFailure($operation) {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected attachment failure');
}

for ($i = 0; $i < 100; $i++) {
    $generated = offerSecureGeneratePassword();
    attachCheck((bool)preg_match('/\A[A-Za-z0-9]{8}\z/', $generated) && preg_match('/[A-Za-z]/', $generated) && preg_match('/[0-9]/', $generated), 'Password format');
}

$path = tempnam(sys_get_temp_dir(), 'offer-test-');
try {
    file_put_contents($path, "%PDF-1.7\nfile-property test fixture\n");
    attachCheck(offerSecureAttachPdf(123, $path, 'Ab12Cd34') === 1, 'Initial attachment');
    attachCheck(offerSecureAttachPdf(123, $path, 'Ab12Cd34') === 2, 'Replacement attachment');
    attachCheck(CIBlockElement::$password === 'Ab12Cd34', 'Matching password saved');
    $writes = count(CIBlockElement::$writes);
    CIBlockProperty::$property['PROPERTY_TYPE'] = 'S';
    attachExpectFailure(static function () use ($path) { offerSecureAttachPdf(123, $path, 'Ab12Cd34'); });
    attachCheck(count(CIBlockElement::$writes) === $writes, 'Invalid property must not change offer');
    CIBlockProperty::$property['PROPERTY_TYPE'] = 'F';
    attachExpectFailure(static function () use ($path) { offerSecureAttachPdf(123, $path . '.missing', 'Ab12Cd34'); });
    attachCheck(count(CIBlockElement::$writes) === $writes, 'Missing file must not change offer');
    CIBlockElement::$failWrite = true;
    attachExpectFailure(static function () use ($path) { offerSecureAttachPdf(123, $path, 'Ab12Cd34'); });
    attachCheck(CIBlockElement::$fileId === 2, 'Failed update keeps previous file');
    CIBlockElement::$fileId = 0;
    attachExpectFailure(static function () use ($path) { offerSecureAttachPdf(123, $path, 'Ab12Cd34'); });
    CIBlockElement::$failWrite = false;
    CIBlockElement::$failPassword = true;
    CIBlockElement::$password = '';
    attachExpectFailure(static function () use ($path) { offerSecureAttachPdf(123, $path, 'Ab12Cd34'); });
    echo "OK\n";
} finally {
    unlink($path);
}
