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
        
        public function run($forceRunSchedReportNum = null) {
                global $Proj;
                if (!defined('USERID')) define('USERID', $this->PREFIX); // used in \DataExport, required to prevent exceptions for PHP 8
                $result = array('not_due'=>0,'sent'=>0,'failed'=>0,'empty_suppressed'=>0);
                if (is_null($Proj)) {
                        $Proj = new \Project($_GET['pid']);
                }
                $this->project = $Proj;
                $includeDisabled = (!is_null($forceRunSchedReportNum));
                $this->setProjectReports( $includeDisabled, $forceRunSchedReportNum );
                
                foreach ($this->project_reports as $rpt) {

                        if ($rpt->isDue() || !is_null($forceRunSchedReportNum)) { 
                            
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
                                        if (is_null($forceRunSchedReportNum)) {
                                                // if running on schedule (not manually triggered) then update schedule-last
                                                $lastSetTimes[$rpt->getSRId()] = date('Y-m-d H:i:s');
                                        }

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
        
        protected function setProjectReports($includeDisabled=false, $schedReportNumber=null) {
                global $Proj;
                $this->project_reports = array();
                $this->project = $Proj;
                $project_settings = $this->getProjectSettings($this->project->project_id);
                
                //$msg = 'Cron extmod_report_scheduler: project='.$this->project->project_id.' settings='.print_r($project_settings, true);
                //$this->logmsg($msg);

                foreach ($project_settings['scheduled-report'] as $key => $value) {
                        if (!$value) { continue; }
                        if (!is_null($schedReportNumber) && $key!=$schedReportNumber) continue;
                        if (!$includeDisabled && !$project_settings['schedule-enabled'][$key]) { continue; }
                        $report = new ScheduledReport();

                        $report->setSRId($key);
                        $report->setPid($this->project->project_id);
                        $report->setEnabled($project_settings['schedule-enabled'][$key]);
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
        
        public function getProjectReports($includeDisabled=false) {
            if (empty($this->project_reports)) {
                $this->setProjectReports($includeDisabled);
            }
            return $this->project_reports;
        }

        protected function getUserEmail($fromUser, $fromUser123) {
                $fieldname = ($fromUser123==1) ? 'user_email' : 'user_email'.$fromUser123;
                $sql = "select ".$this->escape($fieldname)." from redcap_user_information where username = ? limit 1";
                $q = $this->query($sql, [$fromUser]);
                $r = db_fetch_assoc($q);
                return htmlspecialchars($r[$fieldname], ENT_QUOTES);
        }

        protected function getUserEmailAddresses() {
            $userEmails = array();
            $sql = "select ur.username, user_email, user_email2, user_email3 from redcap_user_rights ur inner join redcap_user_information ui on ur.username=ui.username where project_id=? order by ur.username";
            $q = $this->query($sql, [$this->project->project_id]);
            while ($row = db_fetch_assoc($q)) {
                $un = $row['username'];
                $e1 = $this->escape($row['user_email']);
                $e2 = $this->escape($row['user_email2']);
                $e3 = $this->escape($row['user_email3']);
                $userEmails[$row['username'].'-1'] = $e1;
                if (!empty($e2)) $userEmails[$row['username'].'-2'] = $e2;
                if (!empty($e3)) $userEmails[$row['username'].'-3'] = $e3;
            }
            return $userEmails;
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
         * Look up report ids and update report-title settings
         * Look up user/profile and populate message-from settings
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
                        
                        $from = $project_settings['message-from'][$key];
                        $fromUser = $project_settings['message-from-user'][$key];
                        $fromUser123 = $project_settings['message-from-user-123'][$key];
                        if (empty($from)) {  // migrate from pre v1.4.0 config version 
                            $from = $project_settings['message-from'][$key] = $fromUser.'-'.$fromUser123;
                            $update = true;
                        } else {
                            list($fromUser, $fromUser123) = explode('-', $from, 2);

                            if ($fromUser !== $project_settings['message-from-user'][$key]) {
                                $project_settings['message-from-user'][$key] = $fromUser;
                                $update = true;
                            }
                            if ($fromUser123 !== $project_settings['message-from-user-123'][$key]) {
                                $project_settings['message-from-user-123'][$key] = $fromUser123;
                                $update = true;
                            }
                        }
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
         * Look up report ids and update report-title settings
         * Look up user/profile and populate message-from settings
         * @param mixed $project_id, $configSettings
         */
        public function redcap_module_configuration_settings($project_id, $configSettings) {
            foreach ($configSettings as $si => $sarray) {
                if ($sarray['key']=='summary-page') {
                    $url = $this->getUrl('summary.php',false,false);
                    $configSettings[$si]['name'] = str_replace('href="#"', 'href="'.$url.'"', $configSettings[$si]['name']);

                } else if ($sarray['key']=='scheduled-report') {
                    $srConfigIndex = $si;
                    $subSettings = $sarray['sub_settings'];
                    foreach ($subSettings as $ssi => $ss) {
                        if ($ss['key']=='report-id') {

                            // include report id in label with report title
                            foreach ($ss['choices'] as $choiceIdx => $choice) {
                                $subSettings[$ssi]['choices'][$choiceIdx]['name'] = $choice['value'].': '.$choice['name'];
                            }

                            
                        } else if ($ss['key']=='message-from') {

                            // make dropdown for user/email selection
                            $sql = "select username, email123, user_email
                                    from (
                                    select ur.username, 1 as email123, user_email from redcap_user_rights ur inner join redcap_user_information ui on ur.username=ui.username where project_id=?
                                    union all 
                                    select ur.username, 2 as email123, user_email2 as user_email from redcap_user_rights ur inner join redcap_user_information ui on ur.username=ui.username where project_id=?
                                    union all 
                                    select ur.username, 3 as email123, user_email3 as user_email from redcap_user_rights ur inner join redcap_user_information ui on ur.username=ui.username where project_id=?
                                    ) useremails
                                    where coalesce(user_email,'')<>''
                                    order by username, email123";
                            $q = $this->query($sql, [$project_id, $project_id, $project_id]);
                            $userEmailChoices = array();
                            while ($row = $q->fetch_assoc()) {
                                $userEmailChoices[] = array(
                                    'value' => $row['username'].'-'.$row['email123'],
                                    'name' => $row['username'].' '.$row['email123'].': '.$row['user_email']
                                );
                            }
                            $subSettings[$ssi]['type'] = 'dropdown';
                            $subSettings[$ssi]['choices'] = $userEmailChoices;
                        }

                        $configSettings[$si]['sub_settings'] = $subSettings;
                    }

                } else if ( (intval($project_id)>0 && $sarray['key']=='email-error-project') ||
                            (is_null($project_id)  && $sarray['key']=='email-error-system') ) {
                        $configSettings[$si]['hidden'] = $this->canSendEmail($project_id);
                }
            }

            // v1.4 update new 'message-from' settings from 'message-from-user' and 'message-from-user-123' where needed
            $project_settings = $this->getProjectSettings($project_id);
                
            $update = false;
            foreach ($project_settings['scheduled-report'] as $key => $value) {
                    if (!$value) { continue; }
                    $from = $project_settings['message-from'][$key];
                    $fromUser = $project_settings['message-from-user'][$key];
                    $fromUser123 = $project_settings['message-from-user-123'][$key];
                    if (empty($from)) {  // migrate from pre v1.4.0 config version 
                        $project_settings['message-from'][$key] = $fromUser.'-'.$fromUser123;
                        $update = true;
                    }
            }
            if ($update) {
                $this->setProjectSettings($project_settings, $project_id); 
                $configSettings[$srConfigIndex] = array(
                    'name' => "<div class='green text-center' style='position:relative;left:-8px;width:733px;'><i class='fas fa-info-circle mr-2'></i>Some background settings relating to report senders have been successfully updated.<br>Reopen this dialog to view settings.</div>",
                    'key' => "update-message",
                    'type' => "descriptive"
                );
            }

            return $configSettings;
        }

        public function canSendEmail($project_id=null) {
                // Check if emails can be sent 
                global $test_email_address;
                $email = new \Message();
                $email->setTo($test_email_address);//'redcapemailtest@gmail.com');
                $email->setFrom($GLOBALS['project_contact_email']);
                $email->setFromName('redcapemailtest');
                $email->setSubject('redcapemailtest');
                $email->setBody('external module report scheduler email test '.($project_id ?? ''),true);
                return (bool)($email->send());
        }

        public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) 
        {
            if ($action!=='run-report') return;
            $report = null;
            $schedules = $this->getProjectReports(true); // include disabled
            foreach ($schedules as $rpt) {
                if ($rpt->getSRID() == $payload) {
                    $report = $rpt;
                    break;
                }
            }
            if (is_null($report)) {
                $result = 'no report';
            } else {
                $result = $this->run($report->getSRID());
            }
            return $result;
        }    
}
