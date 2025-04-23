<?php
if (is_null($module) || !($module instanceof MCRI\ReportScheduler\ReportScheduler)) { exit(); }
header("Content-Type: application/json");
try {
        //$msg = 'Project id='.$Proj->project_id.' running scheduled reports';
        //$module->logmsg($msg);
        //if (!$module->canSendEmail()) throw new \Exception('Server cannot send email!');
        $result = $module->run();
} catch (\Exception $ex) {
        http_response_code(500);
        $msg = $module->escape($ex->getMessage().PHP_EOL.$ex->getTraceAsString());
        $module->logmsg($msg, true);
        $result = "Error occurred running scheduled reports for pid=".$module->escape($_GET['pid']).". See module logging for more details.";
}
$r=json_encode($result);
echo $r;