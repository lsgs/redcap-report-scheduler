<?php
header("Content-Type: application/json");

try {
        $result = $module->run();
} catch (Exception $ex) {
        http_response_code(500);
        $result = $ex->getMessage().PHP_EOL.$ex->getTraceAsString();
}
$r=json_encode($result);
echo $r;