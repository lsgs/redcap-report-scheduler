<?php
/**
 * REDCap External Module: Report Scheduler
 * View report configs in a table
 * Example URL: 
 * /redcap_v13.10.6/ExternalModules/?prefix=report_scheduler&page=summary&pid=45
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\ReportScheduler\ReportScheduler)) { exit(); }

global $Proj;
$schedules = $module->getProjectReports(true); // include disabled

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$modulePermission = $module->getSystemSetting('config-require-user-permission');
if ($modulePermission) {
    $userHasPermission = (is_array($user_rights['external_module_config']) && in_array('report_scheduler', $user_rights['external_module_config']) || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
} else {
    $userHasPermission = ($user_rights['design'] || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
}
if (!$userHasPermission) { 
    echo '<div class="red">'.\RCView::tt('pub_001').'</div>'; 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
    exit;
}

$columns = array(
    array('title'=>'#','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return intval($rpt->getSRId())+1; }),
    array('title'=>'Enabled','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        return '<i class="fas '.(($rpt->getEnabled()) ? 'fa-check text-success' : 'fa-times text-danger').'"></i>';
    }),
    array('title'=>'Report ID','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return $rpt->getReportId(); }),
    array('title'=>'Report Title','tdclass'=>'','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return $rpt->getReportTitle(); }),
    array('title'=>'Permission','tdclass'=>'','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        $val = $rpt->getPermissionLevel();
        switch ($val) {
            case '1': return 'Full Data Set';
            case '2': return 'De-Identified';
            case '3': return 'Remove Tagged';
            default: return $val;
        } }),
    array('title'=>'Format','tdclass'=>'','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        $val = $rpt->getReportFormat();
        switch ($val) {
            case 'csvraw': return 'CSV (Raw)';
            case 'csvlabels': return 'CSV (Labels)';
            default: return $val;
        } }),
    array('title'=>'Freq','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return $rpt->getFrequency().$rpt->getFrequencyUnit(); }),
    array('title'=>'Active From','tdclass'=>'','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return $rpt->formatUserDatetime($rpt->getActiveFrom()); }),
    array('title'=>'Active To','tdclass'=>'','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return $rpt->formatUserDatetime($rpt->getActiveTo()); }),
    array('title'=>'Suppress Empty','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        return '<i class="fas '.(($rpt->getSuppressEmpty()) ? 'fa-check' : 'fa-times').'"></i>';
    }),
    array('title'=>'Recipient(s)','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        return str_replace(';','<br>',\htmlspecialchars(\REDCap::escapeHtml($rpt->getMessage()->getTo()), ENT_QUOTES));
    }),
    array('title'=>'Message Content','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        $id = $rpt->getSRId();
        $from = \htmlspecialchars(\REDCap::escapeHtml($rpt->getMessage()->getFrom()), ENT_QUOTES);
        $subj = \htmlspecialchars(\REDCap::escapeHtml($rpt->getMessage()->getSubject()), ENT_QUOTES);
        $body = $rpt->getMessage()->getBody();
        $body = str_replace(array('<html>','<body style="font-family:arial,helvetica;">','</body>','</html>'),'',$body);
        $body = \htmlspecialchars(\REDCap::escapeHtml(trim($body)), ENT_QUOTES);
        $tdContent = "<span id='sr-message-$id' style='display:none;'>";
        $tdContent .= "<h6>From</h6><div class='sr-message-content py-1 mb-2'>$from</div>";
        $tdContent .= "<h6>Subject</h6><div class='sr-message-content py-1 mb-2'>$subj</div>";
        $tdContent .= "<h6>Body</h6><div class='sr-message-content py-1'>$body</div>";
        $tdContent .= "</span>";
        $tdContent .= \RCView::button(array('id'=>"sr-message-$id",'data-srid'=>"$id",'class'=>'sr-message-btn btn btn-xs btn-outline-secondary','title'=>'Click to view message content'),'<i class="fas fa-envelope mx-1"></i>');
        return $tdContent;
    }),
    array('title'=>'Distribution Option','tdclass'=>'text-center','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        if (($rpt->getAttachExport())) {
            return '<i class="fas fa-file-csv text-danger" title="Attach (not recommended)"></i>';
        } else {
            return '<i class="fas fa-envelope text-success" title="Include links (recommended)"></i>';
        }
    }),
    array('title'=>'Last Scheduled Send','tdclass'=>'','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ return $rpt->formatUserDatetime($rpt->getLastSent()); }),
    array('title'=>'Force Send Now','tdclass'=>'sr-trigger-col','getter'=>function(MCRI\ReportScheduler\ScheduledReport $rpt){ 
        $id = $rpt->getSRId();
        $trig = \RCView::button(array('id'=>"sr-trigger-$id",'data-srid'=>"$id",'class'=>'sr-trigger-btn btn btn-xs btn-outline-success'),'<i class="fas fa-paper-plane mx-1"></i>');
        return "$trig <div id='sr-trigger-result-$id' class='sr-trigger-result visibility-hidden'></div>";
    })
);

echo '<div class="projhdr"><i class="fas fa-file-export mr-1"></i>Scheduled Reports Summary</div>';
echo '<p>The table below shows the configuration settings for scheduled reports in this project. You can force a run from this page that will run the report even if it is not currently due.</p>';
echo '<div id="sr-summary-table-container">';
echo '<table id="sr-summary-table"><thead><tr>';

foreach ($columns as $col) {
    $class = (empty($col['tdclass'])) ? '' : ' class="'.$module->escape($col['tdclass']).'"';
    echo "<th$class>".\REDCap::filterHtml($col['title']).'</th>';
}

echo '</tr></thead><tbody>';

foreach ($schedules as $rpt) {
    echo '<tr>';
    foreach ($columns as $col) {
        $class = (empty($col['tdclass'])) ? '' : ' class="'.$col['tdclass'].'"';
        $contentGetterFunction = $col['getter'];
        $cellContent = call_user_func($contentGetterFunction, $rpt);
        echo "<td $class>".\REDCap::filterHtml($cellContent).'</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';
$module->initializeJavascriptModuleObject();
?>
<style type="text/css">
    #sr-summary-table-container { max-width: 800px; }
    .sr-trigger-col { min-width: 200px; }
    .sr-trigger-btn { vertical-align: top; }
    .sr-trigger-result { display:inline-block; margin-left:0.25em; margin-bottom:0; width:175px; }
    .sr-trigger-result-pre { margin:0; font-size:8pt; }
    .sr-message-content { border-left: solid 3px #ddd; padding-left: 1em; }
    .visibility-hidden { visibility:hidden; }
</style>
<script type="text/javascript">
    let module = <?=$module->getJavascriptModuleObjectName()?>;
    module.showMessage = function() {
        let rptId = $(this).data('srid');
        let content = $('#sr-message-'+rptId).html();
        simpleDialog(content, 'Message Content');
    }
    module.clickRun = function() {
        let thisBtn = $(this);
        let rptId = $(thisBtn).data('srid');
        let thisOut = $('#sr-trigger-result-'+rptId);

        $(thisBtn).prop('disabled', true);
        $(thisOut)
            .html('<div class="spinner-border text-secondary" role="status"><span class="sr-only">Loading...</span></div>')
            .removeClass('visibility-hidden');
        
        module.ajax('run-report', rptId).then(function(data) {
            $(thisOut).html('<pre class="sr-trigger-result-pre">'+JSON.stringify(data, null, 2)+'</pre>');
            $(thisBtn).prop('disabled', false);
        });
    };

    module.init = function() {
        $('button.sr-message-btn').on('click',module.showMessage);
        $('button.sr-trigger-btn').on('click',module.clickRun);
        $('#sr-summary-table').DataTable({
            paging: false
        });
    };
    $(document).ready(function(){
        module.init();
    });
</script>
<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';