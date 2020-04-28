<?php
namespace Stanford\ProjSnyderCovid;

use ExternalModules\ExternalModules;
use \REDCap;
use DateTime;
use DateInterval;

require_once "emLoggerTrait.php";

class ProjSnyderCovid extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}


    /*******************************************************************************************************************/
    /* HOOK METHODS                                                                                                    */
    /***************************************************************************************************************** */

    function redcap_save_record($project_id, $record, $instrument, $event_id) {

        //make sure the auto create is turned on
        $config_autocreate = $this->getProjectSetting('autocreate_rsp_participant_page');

        if ($config_autocreate == true) {
            $this->autocreateRSPForm($project_id, $record, $instrument, $event_id);
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
        $dd = REDCap::getDataDictionary($origin_pid, 'array', false, false, $instruments);
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
                $new_event = REDCap::getEventNames(true, false,$this->getProjectSetting('main-event'));

                //in enrollment arm, copy  consent_date_v2 to 'rsp_prt_start_date'
                // add 'daily' to 'rsp_prt_config_id'
                $prt_form[REDCap::getRecordIdField()] = $v[REDCap::getRecordIdField()];
                $prt_form['rsp_prt_config_id'] = 'daily';
                $prt_form['rsp_prt_start_date'] = $this->getProjectSetting('default-start-date');

                // copy email_address_v2   to 'rsp_prt_portal_email'  if not blank
                $prt_form['rsp_prt_portal_email'] = $v['email_address_v2'];

                // copy phone_num_v2    to 'rsp_prt_portal_phone'      if not blank
                $prt_form['rsp_prt_portal_phone'] = $v['phone_num_v2'];
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

                //add in the completion status
                $v['consent_complete'] = $v['consent_form_2_complete'];
                $v['screening_complete'] = $v['consent_form_2_complete'];

            }

            if (substr( $incoming_event, 0, 4 ) === "day_") {
                //there are typos where the are two underscores in a row
                str_replace('__', '_', $incoming_event);

                //grok out the day number from the event name
                preg_match_all($re, $incoming_event, $matches, PREG_SET_ORDER, 0);
                $day_num = $matches[0]['daynum'];
                $new_event = REDCap::getEventNames(true, false, $this->getProjectSetting('diary-event'));
                //$v['redcap_repeat_instrument'] = 'daily_checkin_email';
                $v['redcap_repeat_instance'] = $day_num;


                //in daily arm so add the survey meta data
                $v['rsp_survey_config'] = 'daily';
                $v['rsp_survey_date'] = $v['date'];
                $v['rsp_survey_day_number'] = $day_num;

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

            if (substr( $incoming_event, 0, 6 ) === "enroll") {
                //need to copy over the signature field
                //just hardcoding the signature fields.
                $file_fields = array('signature_v2', 'signature_2', 'signature');
                //TODO: Ask andy about signature files
                //$sig_status = $this->copyOverSigFields($origin_pid, $this->getProjectId(), $rec_id, $file_fields, $this->getProjectSetting('orig-sig-event'));
            }


        }


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
        $final_event = $this->getProjectSetting('main-event');

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

                //check if already exists;
                $check_sql = sprintf("select count(*) from redcap_data where project_id = '%s' and " .
                                     "event_id = '%s' and record = '%s' and field_name = '%s'",
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
            $insert_sql = "INSERT INTO redcap_data (project_id, event_id,record,field_name,value) VALUES  " . $value_str . ';';
            $sig_status = db_query($insert_sql);
        }

        return $sig_status;
    }

    /*******************************************************************************************************************/
    /* AUTOCREATE METHODS                                                                                              */
    /***************************************************************************************************************** */



    function autocreateRSPForm($project_id, $record, $instrument, $event_id) {

        $target_form          = $this->getProjectSetting('triggering-instrument');
        $config_event         = $this->getProjectSetting('trigger-event-name');


        //chaeck that instrument is the correct targeting form and event
        if (($instrument != $target_form) || ($event_id != $config_event)) {
            return;
        }

        $autocreate_logic     = $this->getProjectSetting('autocreate-rsp-participant-page-logic');

        //check the autocreate logic
        if (!empty($autocreate_logic)) {
            $result = REDCap::evaluateLogic($autocreate_logic,$project_id,$record, $event_id);
            if ($result !== true)  {
                $this->emLog("Record $record failed autocreate logic: ". $autocreate_logic);
                return;
            }
        }

        $config_field         = $this->getProjectSetting('config-field'); //name of the field that contains the config id in the participant form i.e. 'rsp_prt_config_id
        $config_id            = $this->getProjectSetting('portal-config-name'); //name of the config entered in the portal ME config
        $target_instrument    = $this->getProjectSetting('target-instrument');


        //get the relevant data fields to check
        $params = array(
            'return_format' => 'json',
            'records' => $record,
            'fields' => array(REDCap::getRecordIdField(),'rsp_prt_config_id', $target_instrument),
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
            $this->updateRSPParticipantInfoForm($config_id, $record, $event_id);

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
            'return_format'       => 'array',
            'fields'              => array('redcap_repeat_instance','rsp_prt_start_date',$instrument."_complete"),
            'records'             => $record
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



    function updateRSPParticipantInfoForm($config_id, $record, $event_id)
    {
        //$target_form          = $this->getProjectSetting('triggering-instrument');
       // $config_field         = $this->getProjectSetting('portal-config-name');

        $config_event         = $this->getProjectSetting('trigger-event-name');
        $target_instrument    = $this->getProjectSetting('target-instrument');

        //get the date to enter for the start date
        $default_date         = $this->getProjectSetting('default-start-date');

        //format the default date of the survey portal start
        $start_date     = new DateTime($default_date);
        $start_date_str = $start_date->format('Y-m-d');

        //get the email and phone number from the consent form.
        $email_field    = $this->getProjectSetting('email-field');
        $phone_field    = $this->getProjectSetting('phone-field');

        $params = array(
            'return_format'       => 'json',
            'records'             => $record,
            'fields'              => array($email_field,$phone_field),
            'events'              => $event_id
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);
        $enter_data = current($results);

        $next_repeat_instance = $this->getNextRepeatingInstanceID($record, $target_instrument,$config_event);
        $this->emDebug("NEXT Repeating Instance ID for  ".$record ." IS ".$next_repeat_instance);


        if (!isset($target_instrument)) {
            $this->emError("Target instrument is not set in the EM config. Data will not be transferred. Set config for target-instrument.");
            return false;
        }

        $data_array = array(
            'rsp_prt_portal_email' => $enter_data[$email_field],
            'rsp_prt_portal_phone' => $enter_data[$phone_field],
            'rsp_prt_start_date'  => $start_date_str,
            'rsp_prt_config_id'    => $config_id //i.e. 'daily'
        );

        //save the data
        $this->saveForm($record, $config_event, $data_array, $target_instrument,$next_repeat_instance);

        //trigger the hash creation and sending of the email by triggering the redcap_save_record hook on  the rsp_participant_info form
        // \Hooks::call('redcap_save_record', array($child_pid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
        \Hooks::call('redcap_save_record', array($this->getProjectId(), $record, $target_instrument, $config_event, null, null, null, $next_repeat_instance));
    }

    /*******************************************************************************************************************/
    /*  METHODS                                                                                                        */
    /***************************************************************************************************************** */


    function saveForm($record_id, $event_id, $data_array, $instrument,$repeat_instance) {
        //$instrument = 'rsp_participant_info';

        $params = array(
            REDCap::getRecordIdField()                => $record_id,
            'redcap_event_name'                       => REDCap::getEventNames(true, false, $event_id),
            'redcap_repeat_instrument'                => $instrument,
            'redcap_repeat_instance'                  => $repeat_instance
        );

        $data = array_merge($params, $data_array);

        $result = REDCap::saveData('json', json_encode(array($data)));
        if ($result['errors']) {
            $this->emError($result['errors'], $params);
            $msg[] = "Error while trying to add $instrument form.";
            //return false;
        } else {
            $msg[] = "Successfully saved data to $instrument.";
        }

    }
}
