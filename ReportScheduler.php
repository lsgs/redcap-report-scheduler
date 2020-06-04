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
        
        public function __construct() {
                parent::__construct();
                if (defined('PROJECT_ID')) {
                       $this->logging = (bool)$this->framework->getProjectSetting('logging');
                }
        }
        
        public function cronEntry() {
                global $Proj, $user_rights;
                $user_rights['reports'] = 1;
                $projects = $this->framework->getProjectsWithModuleEnabled();

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
                                        
                                        $msg = 'Scheduled Report processing complete for project id='.$this->project->project_id.PHP_EOL.$projectUrl.PHP_EOL.print_r($result,true);
                                        $this->log($msg, true);
                                } catch (Exception $e) {
                                        \REDCap::logEvent($this->PREFIX . " exception: " . $e->getMessage(), '', '', null, null, $project_id);
                                }
                        }
                }
        }
        
        public function run() {
                global $Proj;
                $result = array('not_due'=>0,'sent'=>0,'failed'=>0);
                if (is_null($Proj)) {
                        $Proj = new \Project($_GET['pid']);
                }
                $this->project = $Proj;
                $this->setProjectReports();
                
                foreach ($this->project_reports as $rpt) {

                        if ($rpt->isDue()) { 
                            
                                $msg = 'Project id='.$this->project->project_id.': Scheduled Report index '.$rpt->getSettingsPageIndex().' is due';
                                $this->log($msg);
                                
                                list($data_doc_id, $syntax_doc_id) = $this->exportReport($rpt);

                                if (is_null($data_doc_id)) {
                                        $msg = 'Failed to export report id '.$rpt->getReportId();
                                        $result['failed']++;
                                        $this->log($msg);
                                        continue;
                                } else {
                                        $data_edoc_id = db_result(db_query("select doc_id from redcap_docs_to_edocs where docs_id = ". db_escape($data_doc_id)), 0);
                                        list ($mimeType, $docName, $fileContent) = \Files::getEdocContentsAttributes($data_edoc_id);
                                        $gzipped = db_result(db_query("select gzipped from redcap_edocs_metadata where doc_id = ". db_escape($data_edoc_id)), 0);
                                        $storedName = db_result(db_query("select stored_name from redcap_edocs_metadata where doc_id = ". db_escape($data_edoc_id)), 0);
                                        
                                        // write uncompressed file contents to temp dir so can attach frome there
                                        // when file name begins with 14-digit timestamp the cron job will delete it after 30min
                                        if ($gzipped) {
                                                $tempDirStoredName = APP_PATH_TEMP.substr_replace($storedName, '', -3);
                                                $fileContent = gzip_decode_file($fileContent);
                                        } else {
                                                $tempDirStoredName = APP_PATH_TEMP.$storedName;
                                        }

                                        file_put_contents($tempDirStoredName, $fileContent);
                                        
                                        $rpt->getMessage()->setAttachment($tempDirStoredName, $docName);
                                        
                                        if ($rpt->getMessage()->send()) {
                                                $lastSetTimes = $this->getProjectSetting('schedule-last');
                                                $lastSetTimes[$rpt->getSRId()] = date('Y-m-d H:i:s');
                                                $this->setProjectSetting('schedule-last', $lastSetTimes);
                                                $msg = "Scheduled Report index {$rpt->getSettingsPageIndex()} sent";
                                                $result['sent']++;
                                        } else {
                                                $msg = "Scheduled Report index {$rpt->getSettingsPageIndex()} send failed <br>".print_r($this, true);
                                                $result['failed']++;
                                        }
                                        $this->log($msg);
                                }

                        } else {

                                $msg = 'Project id='.$this->project->project_id.': Scheduled Report index '.$rpt->getSettingsPageIndex().' is NOT due';
                                $result['not_due']++;
                                $this->log($msg);
                        }
                }
                return $result;
        }
        
        protected function setProjectReports() {
                $this->project_reports = array();
                $project_settings = $this->getProjectSettings($this->project->project_id);
                
                //$msg = 'Cron extmod_report_scheduler: project='.$this->project->project_id.' settings='.print_r($project_settings, true);
                //$this->log($msg);
                
                if (is_array($project_settings['scheduled-report']['value'])) { 
                    foreach ($project_settings['scheduled-report']['value'] as $key => $value) {
                            if (!$value) { continue; }
                            if (!$project_settings['schedule-enabled']['value'][$key]) { continue; }
                            $report = new ScheduledReport();

                            $report->setSRId($key);
                            $report->setReportId($project_settings['report-id']['value'][$key]);
                            $report->setPermissionLevel($project_settings['report-rights']['value'][$key]);
                            $report->setReportFormat($project_settings['report-format']['value'][$key]);
                            $report->setFrequency($project_settings['schedule-freq']['value'][$key]);
                            $report->setFrequencyUnit($project_settings['schedule-freq-unit']['value'][$key]);
                            $report->setActiveFrom($project_settings['schedule-start']['value'][$key]);
                            $report->setActiveTo($project_settings['schedule-end']['value'][$key]);
                            $report->setLastSent($project_settings['schedule-last']['value'][$key]);
                            
                            $fromUser = $project_settings['message-from-user']['value'][$key];
                            $fromUser123 = $project_settings['message-from-user-123']['value'][$key];
                            $from = $this->getUserEmail($fromUser, $fromUser123);
                            
                            $report->setMessage(new \Message());
                            $report->getMessage()->setTo(implode(';',$project_settings['recipient-email']['value'][$key]));
                            $report->getMessage()->setFrom($from);
                            $report->getMessage()->setSubject($project_settings['message-subject']['value'][$key]);
                            $report->getMessage()->setBody($project_settings['message-body']['value'][$key], true);

                            $this->project_reports[] = $report;
                    }
                }
                return;
        }

        protected function getUserEmail($fromUser, $fromUser123) {
                $fieldname = ($fromUser123==1) ? 'user_email' : 'user_email'.$fromUser123;
                $sql = "select $fieldname from redcap_user_information where username = '". db_escape($fromUser)."'";
                return db_result(db_query($sql), 0);
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
        
        protected function log($msg, $always=false) {
                global $Proj;
                if (!defined('PROJECT_ID') && isset($Proj)) { // e.g. in cronEntry()
                        $this->logging = (bool)$this->framework->getProjectSetting('logging', $Proj->project_id);
                }
                if ($this->logging || $always) {
                        $this->framework->log($msg);
                }
        }
}