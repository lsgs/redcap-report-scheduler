<?php
/**
 * REDCap External Module: Report Scheduler
 * Set up a schedule on which to run a report and email it to a list of recipients.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ReportScheduler;

use ExternalModules\AbstractExternalModule;

require_once 'ScheduledReport.php';

class ReportScheduler extends AbstractExternalModule
{
        protected $project;
        protected $project_reports;
        protected $logging=false;
            
        public function cronEntry() {
                global $Proj, $user_rights;
                $user_rights['reports'] = 1;
                $projects = $this->getProjectsWithModuleEnabled();

                if (count($projects) > 0) {
                        foreach($projects as $project_id) {
                                try {   
                                        $Proj = $this->project = new \Project($project_id);
                                        
                                        //**************************************
                                        //Can't just run() here because PROJECT_ID constant is used in 
                                        //DataExport::getReportNames() which means we can't run for more 
                                        //than one project at a time. 
                                        //Running for one project at a time by sending a separate 
                                        //request for each project using http_get().
                                        //**************************************
                                        //$this->run();
                                        //**************************************
                                        $projectUrl = $this->getUrl('run_project_reports.php', true, false); // using api endpoint results in DataExport::doReport() returning string content of file rather than doc id - may hamper extending this to enable stats package export option (i.e. attaching csv and syntax files to emails)
                                        if (!strpos($projectUrl, 'pid=')) { $projectUrl .= '&pid='.$project_id; }
                                        $result = \http_get($projectUrl);
                                        //echo $result;
                                        //**************************************
                                        
                                        $msg = 'Scheduled Report processing complete for project id='.intval($this->project->project_id).PHP_EOL.$projectUrl.PHP_EOL.print_r($result,true);
                                        $this->logmsg($msg, false);
                                } catch (\Exception $e) {
                                        \REDCap::logEvent($this->PREFIX . " exception: " . $e->getMessage(), '', '', null, null, $project_id);
                                }
                        }
                }
        }
        
        public function run() {
                global $Proj;
                if (!defined('USERID')) define('USERID', $this->PREFIX); // used in \DataExport, required to prevent exceptions for PHP 8
                $result = array('not_due'=>0,'sent'=>0,'failed'=>0,'empty_suppressed'=>0);
                if (is_null($Proj)) {
                        $Proj = new \Project($_GET['pid']);
                }
                $this->project = $Proj;
                $this->setProjectReports();
                
                foreach ($this->project_reports as $rpt) {

                        if ($rpt->isDue()) { 
                            
                                $msg = 'Project id='.intval($this->project->project_id).': Scheduled Report index '.intval($rpt->getSettingsPageIndex()).' is due';
                                $this->logmsg($msg);
                                
                                list($data_doc_id, $syntax_doc_id) = $this->exportReport($rpt);

                                if (is_null($data_doc_id)) {
                                        $msg = 'Failed to export report id '.intval($rpt->getReportId());
                                        $result['failed']++;
                                        $this->logmsg($msg);
                                        continue;
                                } else {
                                        $q = $this->query("select doc_id from redcap_docs_to_edocs where docs_id = ?", [$data_doc_id]);
                                        $qread = db_fetch_assoc($q);
                                        $data_edoc_id = $qread["doc_id"];

                                        list ($mimeType, $docName, $fileContent) = \Files::getEdocContentsAttributes($data_edoc_id);
                                        
                                        $q = $this->query("select gzipped, stored_name, doc_size from redcap_edocs_metadata where doc_id = ?", [$data_edoc_id]);
                                        $qread = db_fetch_assoc($q);
                                        $gzipped = (bool)$qread["gzipped"];
                                        $storedName = htmlspecialchars($qread["stored_name"], ENT_QUOTES);
                                        $docSize = intval($qread["doc_size"]);

                                        // write uncompressed file contents to temp dir so can attach frome there
                                        // when file name begins with 14-digit timestamp the cron job will delete it after 30min
                                        if ($gzipped) {
                                                $tempDirStoredName = APP_PATH_TEMP.substr_replace($storedName, '', -3);
                                                $fileContent = gzip_decode_file($fileContent);
                                        } else {
                                                $tempDirStoredName = APP_PATH_TEMP.$storedName;
                                        }

                                        $tempDirStoredName = $this->getSafePath($tempDirStoredName, APP_PATH_TEMP);
                                        file_put_contents($tempDirStoredName, $fileContent);

                                        $lastSetTimes = $this->getProjectSetting('schedule-last');
                                        $lastSetTimes[$rpt->getSRId()] = date('Y-m-d H:i:s');

                                        if (strlen($fileContent[0])<2 && $rpt->getSuppressEmpty()) { // $fileContent[0] = '"' when empty
                                                $this->setProjectSetting('schedule-last', $lastSetTimes);
                                                $msg = "Scheduled Report index {$rpt->getSettingsPageIndex()} due but contains no records. Message not sent.";
                                                $result['empty_suppressed']++;
                                        } else {
                                                $rpt->messagePiping($data_doc_id, $docName);

                                                if ($rpt->getAttachExport()) {
                                                    $rpt->getMessage()->setAttachment($tempDirStoredName, $docName);
                                                }

                                                if ($rpt->getMessage()->send()) {
                                                        $this->setProjectSetting('schedule-last', $lastSetTimes);
                                                        $msg = "Scheduled Report index ".intval($rpt->getSettingsPageIndex())." sent";
                                                        $result['sent']++;
                                                } else {
                                                        $msg = "Scheduled Report index ".intval($rpt->getSettingsPageIndex())." send failed <br>".print_r($this->escape($rpt), true);
                                                        $result['failed']++;
                                                }

                                        }
                                        $this->logmsg($msg, false);
                                }

                        } else {

                                $msg = 'Project id='.intval($this->project->project_id).': Scheduled Report index '.intval($rpt->getSettingsPageIndex()).' is NOT due';
                                $result['not_due']++;
                                $this->logmsg($msg);
                        }
                }
                return $result;
        }
        
        protected function setProjectReports() {
                $this->project_reports = array();
                $project_settings = $this->getProjectSettings($this->project->project_id);
                
                //$msg = 'Cron extmod_report_scheduler: project='.$this->project->project_id.' settings='.print_r($project_settings, true);
                //$this->logmsg($msg);

                foreach ($project_settings['scheduled-report'] as $key => $value) {
                        if (!$value) { continue; }

                        if (!$project_settings['schedule-enabled'][$key]) { continue; }
                        $report = new ScheduledReport();

                        $report->setSRId($key);
                        $report->setPid($this->project->project_id);
                        $report->setReportId($project_settings['report-id'][$key]);
                        $report->setReportTitle($project_settings['report-title'][$key]);
                        $report->setPermissionLevel($project_settings['report-rights'][$key]);
                        $report->setReportFormat($project_settings['report-format'][$key]);
                        $report->setFrequency($project_settings['schedule-freq'][$key]);
                        $report->setFrequencyUnit($project_settings['schedule-freq-unit'][$key]);
                        $report->setSuppressEmpty($project_settings['suppress-empty'][$key]);
                        $report->setActiveFrom($project_settings['schedule-start'][$key]);
                        $report->setActiveTo($project_settings['schedule-end'][$key]);
                        $report->setLastSent($project_settings['schedule-last'][$key]);
                        
                        $fromUser = $project_settings['message-from-user'][$key];
                        $fromUser123 = $project_settings['message-from-user-123'][$key];
                        $from = $this->getUserEmail($fromUser, $fromUser123);
                        
                        $attach = $project_settings['message-attach-report'][$key];
                        $attach = ($attach=='') ? '1' : $attach; // scheduled reports created before this setting added default to "attach export file"
                        $report->setAttachExport($attach);
                        
                        $report->setMessage(new \Message());
                        $report->getMessage()->setTo(implode(';',$project_settings['recipient-email'][$key]));
                        $report->getMessage()->setFrom($from);
                        $report->getMessage()->setSubject($project_settings['message-subject'][$key]);
                        $report->getMessage()->setBody($project_settings['message-body'][$key], true);

                        $this->project_reports[] = $report;
                }
                return;
        }

        protected function getUserEmail($fromUser, $fromUser123) {
                $fieldname = ($fromUser123==1) ? 'user_email' : 'user_email'.$fromUser123;
                $sql = "select ".$this->escape($fieldname)." from redcap_user_information where username = ? limit 1";
                $q = $this->query($sql, [$fromUser]);
                $r = db_fetch_assoc($q);
                return htmlspecialchars($r[$fieldname], ENT_QUOTES);
        }
        
        protected function getReportTitle($project_id, $report_id) {
                $sql = "select title from redcap_reports where project_id=? and report_id=? limit 1";
                $q = $this->query($sql, [$project_id,$report_id]);
                $r = db_fetch_assoc($q);
                $title = $r['title'];
                if (empty($title)) { $title = "ERROR: Report id $report_id not found in current project."; }
                return htmlspecialchars($title, ENT_QUOTES);
        }
        
        /**
         * exportReport
         * Get CSV file to attach to message. 
         * @param \MCRI\ScheduledReport $report
         * @return type
         */
        protected function exportReport(ScheduledReport $report) {
                global $user_rights;
                $user_rights['data_export_tool'] = $report->getPermissionLevel();

                //**************************************************************
                // This section as per REDCap::getReport()
		        // Does user have De-ID rights?
		        $deidRights = ($user_rights['data_export_tool'] == '2');
		        // De-Identification settings
		        $hashRecordID = ($deidRights);
		        $removeIdentifierFields = ($user_rights['data_export_tool'] == '3' || $deidRights);
		        $removeUnvalidatedTextFields = ($deidRights);
		        $removeNotesFields = ($deidRights);
		        $removeDateFields = ($deidRights);
                //**************************************************************

                $csvDelimiter = ','; // TODO add to config
                $decimalCharacter = '.'; // TODO add to config

                list($data_edoc_id, $syntax_edoc_id) = \DataExport::doReport(
                        $report->getReportId(), // $report_id='0', 
                        'export', // $outputType='report', 
                        $report->getReportFormat(), // $outputFormat='html',
                        false, // $apiExportLabels=false, 
                        false, // $apiExportHeadersAsLabels=false,
                        false, // $outputDags=false, 
                        false, // $outputSurveyFields=false, 
                        $removeIdentifierFields, // $removeIdentifierFields=false, 
                        $hashRecordID, // $hashRecordID=false,
                        $removeUnvalidatedTextFields, // $removeUnvalidatedTextFields=false, 
                        $removeNotesFields, // $removeNotesFields=false,
                        $removeDateFields, // $removeDateFields=false, 
                        false, // $dateShiftDates=false, 
                        false, // $dateShiftSurveyTimestamps=false,
                        array(), // $selectedInstruments=array(), 
                        array(), // $selectedEvents=array(), 
                        false, // $returnIncludeRecordEventArray=false,
                        false, // $outputCheckboxLabel=false, 
                        false, // $includeOdmMetadata=false, 
                        true, // $storeInFileRepository=true,
                        true, // $replaceFileUploadDocId=true, 
                        '', // $liveFilterLogic="", 
                        '', // $liveFilterGroupId="", 
                        '', // $liveFilterEventId="",
                        false, // $isDeveloper=false, 
                        $csvDelimiter, // $csvDelimiter=",", 
                        $decimalCharacter  // $decimalCharacter=''
                );
                return array($data_edoc_id, $syntax_edoc_id);
        }
        
        public function logmsg($msg, $always=false) {
                global $Proj;
                if (!defined('PROJECT_ID') && isset($Proj)) { // e.g. in cronEntry()
                        $this->logging = (bool)$this->getProjectSetting('logging', $Proj->project_id);
                }
                if ($this->logging || $always) {
                        $this->log($this->escape($msg));
                }
        }
        
        /**
         * redcap_module_save_configuration
         * Look up report ids and populate report-title settings
         * Look up user/profile and populate message-from-address settings
         * @param string $project_id
         */
        public function redcap_module_save_configuration($project_id) {
                if (is_null($project_id) || !is_numeric($project_id)) { return; } // only continue for project-level config changes

                $project_settings = $this->getProjectSettings($project_id);
                
                if (!$project_settings['enabled']) { return; }
                $update = false;
                foreach ($project_settings['scheduled-report'] as $key => $value) {
                        if (!$value) { continue; }

                        $reportId = $project_settings['report-id'][$key];
                        $title = $this->getReportTitle($project_id, $reportId);
                        if ($title !== $project_settings['report-title'][$key]) {
                                $project_settings['report-title'][$key] = $title;
                                $update = true;
                        }
                        
                        $fromUser = $project_settings['message-from-user'][$key];
                        $fromUser123 = $project_settings['message-from-user-123'][$key];
                        $useremail = $this->getUserEmail($fromUser, $fromUser123);

                        if ($useremail !== $project_settings['message-from-address'][$key]) {
                                $project_settings['message-from-address'][$key] = $useremail;
                                $update = true;
                        }

                }
                if ($update) { $this->setProjectSettings($project_settings, $project_id); }
                return;
        }
	
        /**
         * redcap_module_configuration_settings
         * Triggered when the system or project configuration dialog is displayed for a given module.
         * Allows dynamically modify and return the settings that will be displayed.
         * @param string $project_id, $project_settings
         */
        public function redcap_module_configuration_settings($project_id, $project_settings) {
                foreach ($project_settings as $si => $sarray) {
                        if ((intval($project_id)>0 && $sarray['key']=='email-error-project') ||
                            (is_null($project_id)  && $sarray['key']=='email-error-system')) {
                                break;
                        }
                }
                $project_settings[$si]['hidden'] = $this->canSendEmail();
                return $project_settings;
        }

        public function canSendEmail() {
                // Check if emails can be sent 
                global $test_email_address;
                $email = new \Message();
                $email->setTo($test_email_address);//'redcapemailtest@gmail.com');
                $email->setFrom($GLOBALS['project_contact_email']);
                $email->setFromName('redcapemailtest');
                $email->setSubject('redcapemailtest');
                $email->setBody('external module report scheduler email test',true);
                return (bool)($email->send());
        }
}
