<?php
namespace Bitrix\Main {
    class Application {
        public static $connection;
        public static function getConnection() { return self::$connection; }
    }
}
namespace {
    require __DIR__ . '/../adaptation/start_workflow.php';
    const PROP_ADAPTATION_STATUS = 2930;
    const START_ADAPTATION_WORKFLOW_ID = 299;
    class TestConnection {
        public $locked = false;
        public function lock($key, $timeout) {
            if ($this->locked) { return false; }
            return $this->locked = true;
        }
        public function unlock($key) { $this->locked = false; }
    }
    class COption {
        public static $values = [];
        public static $fail = false;
        public static function GetOptionString($module, $key, $default) { return self::$values[$key] ?? $default; }
        public static function SetOptionString($module, $key, $value) { if (!self::$fail) { self::$values[$key] = $value; } }
    }
    class TestResult {
        public function Fetch() { return CBPStateService::$existing ? ['ID' => 'old'] : false; }
    }
    class CBPStateService {
        public static $existing = false;
        public static function GetList($order, $filter, ...$rest) {
            check($filter['WORKFLOW_TEMPLATE_ID'] === 299, 'Template filter');
            return new TestResult();
        }
    }
    class CBPDocument {
        public static $calls = 0;
        public static $mode = 'ok';
        public static function StartWorkflow($template, $document, $parameters, &$errors) {
            self::$calls++;
            check(startAdaptationOnce((int)$document[2])[0] === 'danger', 'Concurrent request rejected');
            if (self::$mode === 'throw') { throw new \RuntimeException('Timeout'); }
            if (self::$mode === 'error') { $errors[] = ['message' => 'Failure']; return false; }
            return 'workflow';
        }
    }
    function getElementPropertyValue($id, $filter) { return $GLOBALS['status']; }
    function docIdCandidates($id) { return [['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$id]]; }
    function AddMessage2Log(...$args) {}
    function check($value, $label) { if (!$value) { throw new \RuntimeException($label); } }
    \Bitrix\Main\Application::$connection = new TestConnection();
    $status = 0;
    check(startAdaptationOnce(1)[0] === 'success', 'First start');
    check(startAdaptationOnce(1)[0] === 'danger' && CBPDocument::$calls === 1, 'Retry with unchanged status');
    check(startAdaptationOnce(2)[0] === 'success', 'Different employee');
    CBPStateService::$existing = true;
    check(startAdaptationOnce(3)[0] === 'danger' && CBPDocument::$calls === 2, 'Existing workflow');
    CBPStateService::$existing = false;
    $status = 10;
    check(startAdaptationOnce(4)[0] === 'danger' && CBPDocument::$calls === 2, 'Fresh status check');
    $status = 0;
    COption::$fail = true;
    check(startAdaptationOnce(5)[0] === 'danger' && CBPDocument::$calls === 2, 'Marker write failure');
    COption::$fail = false;
    foreach (['error', 'throw'] as $index => $mode) {
        CBPDocument::$mode = $mode;
        $id = 6 + $index;
        $before = CBPDocument::$calls;
        check(startAdaptationOnce($id)[0] === 'danger', 'Uncertain start');
        check(!\Bitrix\Main\Application::$connection->locked, 'Lock released');
        check(startAdaptationOnce($id)[0] === 'danger' && CBPDocument::$calls === $before + 1, 'Uncertain start cannot repeat');
    }
    echo "PASS: concurrent requests, retries, existing workflow, status, persistence failure and uncertain outcomes\n";
}
