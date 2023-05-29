<?php
if ($module->canSendEmail()) {
    $module->cronEntry();
    echo "View logging in redcap_external_modules_log table for projects where enabled by running this SQL query in the Control Center Database Query Tool:<br>";
    echo "select eml.* from redcap_external_modules_log eml inner join redcap_external_modules em on eml.external_module_id=em.external_module_id where directory_prefix='report_scheduler' order by log_id desc limit 100<br>";
    echo "done at ".date('Y-m-d H:i:s');
} else {
    echo "Verify that your server can send emails! The Report Scheduler external module cannot function without the ability to send emails.";
}