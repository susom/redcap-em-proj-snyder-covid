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

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1 ) {

        //make sure the auto create is turned on
        $config_autocreate = $this->getProjectSetting('autocreate_rsp_participant_page');

        if ($config_autocreate == true) {
            $this->autocreateRSPForm($project_id, $record, $instrument, $event_id);
        }


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