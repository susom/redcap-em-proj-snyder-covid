<?php
namespace Stanford\ProjSnyderCovid;

use Aws\Api\Parser\Exception\ParserException;
use ExternalModules\ExternalModules;
use \REDCap;
use DateTime;
use DateInterval;

require_once "emLoggerTrait.php";

class ProjSnyderCovid extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public $projectId;

    /*******************************************************************************************************************/
    /* HOOK METHODS                                                                                                    */
    /***************************************************************************************************************** */

    function redcap_save_record($project_id, $record, $instrument, $event_id)
    {

        $this->projectId = $project_id;
        //make sure the auto create is turned on
        $config_autocreate = $this->getProjectSetting('autocreate_rsp_participant_page', $this->projectId);

        if ($config_autocreate == true) {
            $this->autocreateRSPForm($project_id, $record, $instrument, $event_id);
        }

        $survey_pref_form = $this->getProjectSetting('participant-info-instrument', $project_id);
        $config_event = $this->getProjectSetting('trigger-event-name', $project_id);
        if (($instrument == $survey_pref_form) && ($event_id == $config_event)) {
            $this->setEmailSmsPreference($project_id, $record, $event_id);
        }

        //set up for unsubscribe
        //if unsubscribe form is selected, make updates according to selection
        //1:  disable both email and text
        //2:  check withdrawn checkbox in the ADMIN form
        //3:  check withdrawn checkbox in the ADMIN form
        $unsubscribe_form = $this->getProjectSetting('unsubscribe-instrument', $project_id);
        if (($instrument == $unsubscribe_form) && ($event_id == $config_event)) {
            $this->setUnsubscribePreference($project_id, $record, $event_id);
        }

    }



    /*******************************************************************************************************************/
    /* MIGRATION METHODS                                                                                              */
    /***************************************************************************************************************** */

    /**
     * migrate all the fields
     *
     */
    function process($origin_pid, $first_ct = 0, $last_ct = null) {

        $re = '/day_+(?<daynum>\d+)_arm_1/m';

        //there are lots of orphaned data with wrong values being return with getData. So limit the fields to the 5 valid forms
        $dd_params = array(
            'project_id' => $origin_pid,
            'returnFormat' =>'array',
            'instruments' => array('consent_form_2', 'participant_information', 'medical_history', 'first_check_in',
                'daily_checkin_email', 'daily_checkin_sms')
        );
        $dd = REDCap::getDataDictionary($origin_pid, 'array', false, false); //, $instruments);
        $field_list = array_keys($dd);
        $field_complete = array('consent_form_2_complete', 'participant_information_complete', 'medical_history_complete',
            'first_check_in_complete', 'daily_checkin_email_complete', 'daily_checkin_sms_complete');
        $params = array(
            'project_id'=>$origin_pid,
            'return_format'    => 'json',
            'fields' => array_merge($field_list, $field_complete)
        );
        $q = REDCap::getData($params);

        $records = json_decode($q, true);

        $default_start_date = $this->getProjectSetting('default-start-date', $this->projectId);

        foreach ($records as $k => $row) {
            $prt_form = null;  //reset

            $rec_id = $row['record_id'];


            //remove empty fields from row
            //array_filter will filter out values of '0' so add function to force it to include the 0 values
            $v = array_filter($row, function($value) {
                return ($value !== null && $value !== false && $value !== '');
            });



            if (($first_ct != null) && ($rec_id < $first_ct)) {
                echo "<br> Skipping row $k: RECORD: $rec_id ";
                continue;
            }

            if (($last_ct != null) && ($rec_id > $last_ct )) {
                echo "<br> Skipping row $k: RECORD: $rec_id greater than max";
                continue;
            }
            echo "<br> Analyzing row $k: RECORD: $rec_id ";

            //reset the event name
            $incoming_event = $v['redcap_event_name'];

            if (substr( $incoming_event, 0, 6 ) === "enroll") {
                $new_event = REDCap::getEventNames(true, false,
                                                   $this->getProjectSetting('main-event', $this->projectId));

                //in enrollment arm, copy  consent_date_v2 to 'rsp_prt_start_date'
                // add 'daily' to 'rsp_prt_config_id'
                $prt_form[REDCap::getRecordIdField()] = $v[REDCap::getRecordIdField()];
                $prt_form['rsp_prt_config_id'] = 'daily';
                $prt_form['rsp_prt_start_date'] = $default_start_date;

                // copy email_address_v2   to 'rsp_prt_portal_email'  if not blank
                $prt_form['rsp_prt_portal_email'] = $v['email_address_v2'];

                // copy phone_num_v2    to 'rsp_prt_portal_phone'      if not blank
                $prt_form['rsp_prt_portal_phone'] = $v['phone_num_v2'];

                //this was not used (added May 5)
                //if the survey_preference is 1 = email, 2 = sms
                if ($v['survey_preference'] == '1') {
                    //email so disable sms
                    $prt_form['rsp_prt_disable_sms___1'] = '1';
                } else if ($v['survey_preference'] == '2') {
                    //sms so disable email
                    $prt_form['rsp_prt_disable_email___1'] = '1';
                } else {
                    //none are set, so disable both
                    $prt_form['rsp_prt_disable_sms___1'] = '1';
                    $prt_form['rsp_prt_disable_email___1'] = '1';
                }

                //$prt_form['redcap_repeat_instrument'] = 'rsp_participant_info';
                $prt_form['redcap_repeat_instance'] = '1';
                $prt_form['redcap_event_name'] = $new_event;

                //hold on to consent_date
                //$consent_dates[$rec_id] = $v['consent_date_v2'];

                //These field need to be renamed
                //agree_to_be_in_study_v2,
                // ADDED confirmed_suspected, symptom_onset_0,
                // CHANGE consent_form_2_complete, gender_affirming_care, more_gender_care, pregnancy_stage
                $v['eligible_age'] = $v['agree_to_be_in_study_v2'];
                //unset($v['agree_to_be_in_study_v2']);

                //coding for why_high_risk changed from note field to radiobutton
                //import into new field why_high_risk_note
                if (($v['why_high_risk'] != '1') or ($v['why_high_risk'] != '2') or ($v['why_high_risk'] != '99')) {
                    $v['why_high_risk_note'] = $v['why_high_risk'];
                    unset($v['why_high_risk']);
                }

                //add in the completion status
                $v['consent_complete'] = $v['consent_form_2_complete'];
                $v['screening_complete'] = $v['consent_form_2_complete'];

                //add checkbox to form to signal that it's been migrated from original project
                $v['migrated_18747___1'] = '1';

            }

            if (substr( $incoming_event, 0, 4 ) === "day_") {
                //there are typos where the are two underscores in a row
                str_replace('__', '_', $incoming_event);

                //grok out the day number from the event name
                preg_match_all($re, $incoming_event, $matches, PREG_SET_ORDER, 0);
                $day_num = $matches[0]['daynum'];
                $new_event = REDCap::getEventNames(true, false,
                                                   $this->getProjectSetting('diary-event', $this->projectId));
                //$v['redcap_repeat_instrument'] = 'daily_checkin_email';
                //TODO: get the next instance number
                $v['redcap_repeat_instance'] = $day_num;


                //in daily arm so add the survey meta data
                $v['rsp_survey_config'] = 'daily';
                $v['rsp_survey_date'] = $v['date'];

                //calculate rsp_instance against the default start date
                $v['rsp_survey_day_number'] = $this->calculateDayNumber($default_start_date, $v['date']);

                //if the _complete status is 0, then don't enter it.
                if ($v['daily_checkin_sms_complete'] == '0') {
                    unset($v['daily_checkin_sms_complete']);
                }
                if ($v['daily_checkin_email_complete'] == '0') {
                    unset($v['daily_checkin_email_complete']);
                }

            }
            //data cleaning
            //these fields have codes that are no longer valid
            if ($v['extreme_hr']=='2') {
                unset($v['extreme_hr']);
            }
            if ($v['weight_loss']=='2') {
                unset($v['weight_loss']);
            }

            unset($v['consent_form_2_complete']);
            $v['redcap_event_name'] = $new_event;

            $migrate_arrays = array($v);
            if ($prt_form != null) {
                $migrate_arrays[] = $prt_form;
            }

            //save data record and event
            $response = REDCap::saveData('json', json_encode($migrate_arrays));
            if (!empty($response['errors'])) {
                $msg = ("Not able to save data for row $k for record $rec_id in event $incoming_event");
                $this->emError($msg, $response['errors']);
                echo $msg;
            }

            //if checkbox is set to trigger save of the rsp_participant_info
            if ($this->getProjectSetting('trigger-rsp-save')) {
                //todo: i'm just hardcoding the rsp_participant_info form here
                $rsp_form = 'rsp_participant_info';
                $repeat_instance = 1;

                //trigger the hash creation and sending of the email by triggering the redcap_save_record hook on  the rsp_participant_info form
                \Hooks::call('redcap_save_record', array($this->getProjectId(), $rec_id, $rsp_form,
                    $this->getProjectSetting('main-event'), null, null, null, $repeat_instance));
            }


            if (substr( $incoming_event, 0, 6 ) === "enroll") {
                //need to copy over the signature field
                //just hardcoding the signature fields.
                $file_fields = array('signature_v2', 'signature_2', 'signature');
                //TODO: Ask andy about signature files
                //$sig_status = $this->copyOverSigFields($origin_pid, $this->getProjectId(), $rec_id, $file_fields, $this->getProjectSetting('orig-sig-event'));
            }


        }


    }

    public function calculateDayNumber($start_str, $end_str) {
        //use today
        $date = new DateTime($end_str);
        $start = new DateTime($start_str);

        $interval = $start->diff($date);

        $diff_date = $interval->format("%r%a");
        $diff_hours = $interval->format("%r%h");

        // need at add one day since start is day 0??
        //Need to check that the diff in hours is greater than 0 as date diff is calculating against midnight today
        //and partial days > 12 hours was being considered as 1 day.
        if ( $diff_hours >= 0) {
            //actually, don't add 1. start date should be 0.
            //return ($interval->days + 1);
            return ($diff_date);
        } else {
            return ($diff_date - 1);
        }
        return null;
    }


    /**
     * Copy over the signature field from the passed in event
     *
     * @param $project_id
     * @param $record
     * @param $file_fields
     * @param $event_id
     * @return bool|\mysqli_result
     */
    public function copyOverSigFields($from_project_id, $to_project_id, $record, $file_fields, $from_event_id) {
        $final_event = $this->getProjectSetting('main-event', $this->projectId);

        $sig_status = true;

        # Get doc_ids data for file fields
        $docs = array();

        $params = array(
            'project_id' => $from_project_id,
            'return_format'=>'array',
            'fields'=>$file_fields,
            'records'=>array($record),
            'events'=>$from_event_id);
        $file_data = REDCap::getData($params);

        $sig_fields = $file_data[$record][$from_event_id];

        $values = array();
        foreach ($sig_fields as $field_name => $doc_id) {

            if (!empty($doc_id)) {

                $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($from_project_id) : "redcap_data";

                //check if already exists;
                $check_sql = sprintf("select count(*) from %s where project_id = '%s' and " .
                                     "event_id = '%s' and record = '%s' and field_name = '%s'",
                                     prep($data_table),
                                     prep($from_project_id),
                                     prep($final_event),
                                     prep($record),
                                     prep($field_name));

                //$this->emDebug("SQL is " . $check_sql);
                $q = db_result(db_query($check_sql),0);
                //$this->emDebug("SQL result is " . $q);

                //INSERT ignore INTO redcap_data (project_id, event_id,record,field_name,value) VALUES (186, 1095,13,'patient_signature', 805);
                if ($q == 0) {
                    //no existing signature, so update signature over to the final event
                    $values[] = sprintf("('%s', '%s','%s', '%s','%s')",
                                        prep($to_project_id),
                                        prep($final_event),
                                        prep($record),
                                        prep($field_name),
                                        prep($doc_id));
                }
            }
        }
        $value_str = implode(',', $values);

        if (!empty($values)) {
            $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($to_project_id) : "redcap_data";
            $insert_sql = "INSERT INTO $data_table (project_id, event_id,record,field_name,value) VALUES  " . $value_str . ';';
            $sig_status = db_query($insert_sql);
        }

        return $sig_status;
    }


    /*******************************************************************************************************************/
    /* AUTOCREATE AND AUTOSET METHODS                                                                                              */
    /***************************************************************************************************************** */

    function setUnsubscribePreference($project_id, $record, $event_id)
    {
        //check the unsubscribe field
        //1:  disable both email and text
        //2:  check withdrawn checkbox in the ADMIN form
        //3:  check withdrawn checkbox in the ADMIN form

        $unsubscribe_field = $this->getProjectSetting('unsubscribe-field', $project_id);
        $withdraw_field = $this->getProjectSetting('withdraw-field', $project_id);

        $participant_form = $this->getProjectSetting('target-instrument', $project_id);

        $unsubscribe_value = $this->getFieldValue($project_id, $record, $event_id, $unsubscribe_field);

        $log_msg = "";

        switch ($unsubscribe_value) {
            case 1:
                $this->checkCheckbox($project_id,$participant_form, $record, $event_id, array('rsp_prt_disable_sms', 'rsp_prt_disable_email'), true);
                $log_msg = "Unsubscribe request received: text and email disabled for participant.";
                //$this->turnOffSurveyInvites($project_id, $record, $event_id);
                break;
            case 2:
            case 3:
                //TODO: there is a bug where saveData does not trigger recalc. In the meantime, just disable both email and texts
                $this->checkCheckbox($project_id,$participant_form, $record, $event_id, array('rsp_prt_disable_sms', 'rsp_prt_disable_email', 'rsp_prt_disable_portal'), true);

                //check the withdrawn checkbox field in the ADMIN form
                $this->checkCheckbox($project_id, $participant_form,$record, $event_id, array($withdraw_field));
                $log_msg = "Unsubscribe request received: withdrawn checked for participant. Email and text disabled.";
                break;
        }

        //log event
        //add entry into redcap logging about saved form
        REDCap::logEvent(
            "Unsubscribe request updated by Snyder Covid EM",  //action
            $log_msg,  //change msg
            NULL, //sql optional
            $record, //record optional
            $event_id, //event optional
            $project_id //project ID optional
        );

    }


    /**
     * Once the participant_information form is filled out, get the survey_preference and update
     * the RSP_participant_information form
     *
     * @param $project_id
     * @param $record
     * @param $event_id
     */
    function setEmailSmsPreference($project_id, $record, $event_id) {
        $survey_pref_field = $this->getProjectSetting('survey-pref-field', $project_id);
        $params = array(
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => $record,
            'fields' => array(
                $survey_pref_field,
                'rsp_prt_portal_email',
                'rsp_prt_portal_phone',
                'rsp_prt_disable_sms',
                'rsp_prt_disable_email'
            ),
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        //$results = json_decode($q, true);
        //$entered_data = current($results);

        //survey_preferences are in the none repeating form
        $survey_preference = $q[$record][$event_id][$survey_pref_field];

        $log_msg  = '';
        //if the survey_preference is 1 = email, 2 = sms
        if ($survey_preference == '1') {
            //email so disable sms
            $rsp_form['rsp_prt_disable_sms___1'] = '1';
            $rsp_form['rsp_prt_disable_email___1'] = '0';
            $log_msg = "Converted survey_preference of $survey_preference to receive the daily survey by email.";
        } else if ($survey_preference == '2') {
            //sms so disable email
            $rsp_form['rsp_prt_disable_sms___1'] = '0';
            $rsp_form['rsp_prt_disable_email___1'] = '1';
            $log_msg = "Converted survey_preference of $survey_preference to receive the daily survey by texts.";
        } else {
            //none are set, so disable both
            //from mtg of May 15: if no preference, set it to send emails
            $rsp_form['rsp_prt_disable_sms___1'] = '1';
            $rsp_form['rsp_prt_disable_email___1'] = '0';
            $log_msg = "Converted survey_preference of $survey_preference to receive the daily survey by email.";
        }

        $target_instrument = $this->getProjectSetting('target-instrument',$project_id);

        $repeat_instance = 1;  //hardcoding as 1 since only have one config.

        $this->saveForm($project_id,$record, $event_id, $rsp_form, $target_instrument,$repeat_instance);

        //add entry into redcap logging about saved form
        REDCap::logEvent(
            "Survey Preference updated by Snyder Covid EM",  //action
            $log_msg, //change msg
            NULL, //sql optional
            $record, //record optional
            $event_id, //event optional
            $project_id //project ID optional
        );
    }


    function autocreateRSPForm($project_id, $record, $instrument, $event_id)
    {

        $target_form = $this->getProjectSetting('triggering-instrument', $this->projectId);
        $config_event = $this->getProjectSetting('trigger-event-name', $this->projectId);


        //chaeck that instrument is the correct targeting form and event
        if (($instrument != $target_form) || ($event_id != $config_event)) {
            return;
        }

        $autocreate_logic = $this->getProjectSetting('autocreate-rsp-participant-page-logic', $this->projectId);

        //check the autocreate logic
        if (!empty($autocreate_logic)) {
            $result = REDCap::evaluateLogic($autocreate_logic, $project_id, $record, $event_id);
            if ($result !== true) {
                $this->emLog("Record $record failed autocreate logic: " . $autocreate_logic);
                return;
            }
        }

        $config_field = $this->getProjectSetting('config-field',
            $this->projectId); //name of the field that contains the config id in the participant form i.e. 'rsp_prt_config_id
        $config_id = $this->getProjectSetting('portal-config-name',
            $this->projectId); //name of the config entered in the portal ME config
        $target_instrument = $this->getProjectSetting('target-instrument', $this->projectId);


        //get the relevant data fields to check
        $params = array(
            'project_id' => $this->projectId,
            'return_format' => 'json',
            'records' => $record,
            'fields' => array(REDCap::getRecordIdField(), 'rsp_prt_config_id', $target_instrument),
            'events' => $config_event
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);


        //check that the RSP participant hasn't already been created
        //check that the config field, 'rsp_prt_config_id' has an entry with the  survey config id passed in  $config_field
        $daily_config_set = array_search($config_id, array_column($results, $config_field));
        $this->emDebug("RECID: " . $record . " KEY: " . $daily_config_set . " KEY IS NULL: " . empty($daily_config_set) . " : " . isset($daily_config_set));

        //this config name was not fouund in any instnace of rsp_participant_instance
        if (empty($daily_config_set)) {
            //creating a new instance
            $this->updateRSPParticipantInfoForm($project_id,$config_id, $record, $event_id);

        }


    }


    /**
     * Return the next instance id for this survey instrument
     *
     * Using the getDAta with return_format = 'array'
     * the returned nested array :
     *  $record
     *    'repeat_instances'
     *       $event
     *          $instrument
     *
     *
     * @return int|mixed
     */
    public function getNextRepeatingInstanceID($record, $instrument, $event) {


        $this->emDebug($record . " instrument: ".  $instrument. " event: ".$event);
        //getData for all surveys for this reocrd
        //get the survey for this day_number and survey_data
        //TODO: return_format of 'array' returns nothing if using repeating events???
        //$get_data = array('redcap_repeat_instance');
        $params = array(
            'project_id' => $this->projectId,
            'return_format' => 'array',
            'fields' => array('redcap_repeat_instance', 'rsp_prt_start_date', $instrument . "_complete"),
            'records' => $record
            //'events'              => $this->portalConfig->surveyEventID
        );
        $q = REDCap::getData($params);
        //$results = json_decode($q, true);

        $instances = $q[$record]['repeat_instances'][$event][$instrument];
        //$this->emDebug($params, $q, $instances);


        ///this one is for standard using array
        $max_id = max(array_keys($instances));

        //this one is for longitudinal using json
        //$max_id = max(array_column($results, 'redcap_repeat_instance'));

        return $max_id + 1;
    }



    function updateRSPParticipantInfoForm($project_id, $config_id, $record, $event_id)
    {
        //$target_form          = $this->getProjectSetting('triggering-instrument');
        // $config_field         = $this->getProjectSetting('portal-config-name');

        $config_event = $this->getProjectSetting('trigger-event-name', $this->projectId);
        $target_instrument = $this->getProjectSetting('target-instrument', $this->projectId);

        //get the date to enter for the start date
        $default_date = $this->getProjectSetting('default-start-date', $this->projectId);

        //format the default date of the survey portal start
        $start_date = new DateTime($default_date);
        $start_date_str = $start_date->format('Y-m-d');

        //get the email and phone number from the consent form.
        $email_field = $this->getProjectSetting('email-field', $this->projectId);
        $phone_field = $this->getProjectSetting('phone-field', $this->projectId);

        $params = array(
            'project_id' => $this->projectId,
            'return_format' => 'json',
            'records' => $record,
            'fields' => array($email_field, $phone_field),
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);
        $enter_data = current($results);

        //todo should this just be hardcoded to 1?
        //$next_repeat_instance = $this->getNextRepeatingInstanceID($record, $target_instrument,$config_event);
        $next_repeat_instance = 1;
        $this->emDebug("NEXT Repeating Instance ID for  ".$record ." IS ".$next_repeat_instance);


        if (!isset($target_instrument)) {
            $this->emError("Target instrument is not set in the EM config. Data will not be transferred. Set config for target-instrument.");
            return false;
        }

        $data_array = array(
            'rsp_prt_portal_email' => $enter_data[$email_field],
            'rsp_prt_portal_phone' => $enter_data[$phone_field],
            'rsp_prt_start_date'  => $start_date_str,
            'rsp_prt_disable_sms___1'   => '1',  //when initially created, set disable to true (this will reset in participant info form
            'rsp_prt_disable_email___1' => '1',  //ditto
            'rsp_prt_config_id'         => $config_id //i.e. 'daily'
        );


        //save the data
        $save_msg = $this->saveForm($project_id, $record, $config_event, $data_array, $target_instrument,$next_repeat_instance);

        //trigger the hash creation and sending of the email by triggering the redcap_save_record hook on  the rsp_participant_info form
        // \Hooks::call('redcap_save_record', array($child_pid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
        \Hooks::call('redcap_save_record', array($project_id, $record, $target_instrument, $config_event, null, null, null, $next_repeat_instance));
    }

    /*******************************************************************************************************************/
    /*  METHODS                                                                                                        */
    /***************************************************************************************************************** */

    function getFieldValue($project_id, $record, $event_id,  $get_field) {
        $params = array(
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => $record,
            'fields' => array($get_field),
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        //$results = json_decode($q, true);
        //$entered_data = current($results);

        //return the field
        return $q[$record][$event_id][$get_field];


    }


    function checkCheckbox($project_id, $instrument, $record, $event_id, $checkbox_field, $repeating = false) {
        //set the checkbox in the form
        $event_name = REDCap::getEventNames(true, false, $event_id);

        $checkboxes = array();

        foreach ($checkbox_field as $k => $v) {
            $checkboxes[$v."___1"] = 1;
        }

        $save_data = array(
            'record_id'         => $record,
            'redcap_event_name' => $event_name,
        );

        if ($repeating) {
            $repeat_array = array(
                "redcap_repeat_instance" => 1,
                "redcap_repeat_instrument" => $instrument
            );
        } else {
            $repeat_array = array();
        }

        //$save_data = array_replace($save_data, $checkboxes, $repeating ? array("redcap_repeat_instance"=>1) : array());
        $save_data = array_replace($save_data, $checkboxes, $repeat_array);

        $status = REDCap::saveData('json', json_encode(array($save_data)));


        if (!empty($status['errors'])) {
            $this->emDebug("Error trying to save this data",$save_data,  $status['errors']);
        }

    }

    function saveForm($project_id, $record_id, $event_id, $data_array, $instrument,$repeat_instance)
    {
        //$instrument = 'rsp_participant_info';

        //because we will hit this code from different project context we need to get the correct event name to save.
        $proj = new \Project($this->projectId);
        $name = $proj->getUniqueEventNames($event_id);


        $params = array(
            REDCap::getRecordIdField() => $record_id,
            'redcap_event_name' => $name,
            'redcap_repeat_instrument' => $instrument,
            'redcap_repeat_instance' => $repeat_instance
        );

        $data = array_merge($params, $data_array);

        $result = REDCap::saveData($this->projectId, 'json', json_encode(array($data)));
        if ($result['errors']) {
            $this->emError($result['errors'], $params);
            $msg = "Error while trying to save date to  $instrument instance $repeat_instance.";
            //return false;
        } else {
            $msg = "Successfully saved data to $instrument instance $repeat_instance.";
        }

        //add entry into redcap logging about saved form
        REDCap::logEvent(
            "RSP Participant Info page created by Snyder Covid EM",  //action
            $msg,  //change msg
            NULL, //sql optional
            $record_id, //record optional
            $event_id, //event optional
            $project_id //project ID optional
        );

        return $msg;

    }



}
