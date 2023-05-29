<?php
header("Content-Type: application/json");

try {
        //$msg = 'Project id='.$Proj->project_id.' running scheduled reports';
        //$module->logmsg($msg);
        if (!$module->canSendEmail()) throw new \Exception('Server cannot send email!');
        $result = $module->run();
} catch (\Exception $ex) {
        http_response_code(500);
        $msg = $module->escape($ex->getMessage().PHP_EOL.$ex->getTraceAsString());
        $module->logmsg($msg, true);
        $result = $msg;
}
$r=json_encode($result);
echo $r;