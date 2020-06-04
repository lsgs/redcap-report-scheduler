<?php
$module->cronEntry();
echo "Logging in redcap_external_modules_log table for projects where enabled<br>";
echo "select eml.* from redcap_external_modules_log eml inner join redcap_external_modules em on eml.external_module_id=em.external_module_id where directory_prefix='report_scheduler' order by log_id desc limit 100<br>";
echo "done at ".date('Y-m-d H:i:s');