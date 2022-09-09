<?php
header("Content-Type: application/json");

try {
        //$msg = 'Project id='.$Proj->project_id.' running scheduled reports';
        //$module->logmsg($msg);
        $result = $module->run();
} catch (\Exception $ex) {
        http_response_code(500);
        $msg = \htmlspecialchars($ex->getMessage().PHP_EOL.$ex->getTraceAsString(),ENT_QUOTES);
        $module->logmsg($msg, true);
        $result = $msg;
}
$r=json_encode($result);
echo $r;