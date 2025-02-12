<?php
// This file is part of CodeRunner - http://coderunner.org.nz/
//
// CodeRunner is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// CodeRunner is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with CodeRunner.  If not, see <http://www.gnu.org/licenses/>.

/*
 * qtype_coderunner external file. Allows webservice access by authenticated
 * users to the sandbox server (usually Jobe).
 *
 * @package    qtype_coderunner
 * @category   external
 * @copyright  2021 Richard Lobb, The University of Canterbury.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_coderunner\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
use external_api;
use external_function_parameters;
use external_value;
use context;
use qtype_coderunner_sandbox;
use qtype_coderunner_exception;

class run_in_sandbox extends external_api {

    /**
     * Returns description of method parameters. Used for validation.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            array(
                'contextid' => new external_value(PARAM_INT,
                        'The Moodle context ID of the originating web page',
                        VALUE_REQUIRED),
                'sourcecode' => new external_value(PARAM_RAW,
                        'The source code to be run', VALUE_REQUIRED),
                'language' => new external_value(PARAM_TEXT,
                        'The computer language of the sourcecode', VALUE_DEFAULT, 'python3'),
                'stdin' => new external_value(PARAM_RAW,
                        'The standard input to use for the run', VALUE_DEFAULT, ''),
                'files' => new external_value(PARAM_RAW,
                        'A JSON object in which attributes are filenames and values file contents',
                        VALUE_DEFAULT, ''),
                'params' => new external_value(PARAM_TEXT,
                        'A JSON object defining any sandbox parameters',
                        VALUE_DEFAULT, '')
            )
        );
    }


    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function execute_returns() {
        return new external_value(PARAM_RAW, 'The JSON-encoded Jobe server run result');
    }

    /**
     * Run a job in the sandbox (Jobe).
     * @param int $contextid The context from which the request was initiated.
     * @param string $sourcecode The source code to be run.
     * @param string $language The language of execution (default python3)
     * @param string $stdin The standard input for the run (default empty)
     * @param string $files A JSON object in which attributes are filenames and
     * attribute values are the corresponding file contents.
     * @param string $params A JSON-encoded string defining any required Jobe sandbox
     * parameters (cputime, memorylimit etc).
     * @return string JSON-encoded Jobe run-result object.
     * @throws qtype_coderunner_exception
     */
    public static function execute($contextid, $sourcecode, $language='python3',
            $stdin='', $files='', $params='') {
        global $USER;
        // First, see if the web service is enabled.
        if (!get_config('qtype_coderunner', 'wsenabled')) {
            throw new qtype_coderunner_exception(get_string('wsdisabled', 'qtype_coderunner'));
        }

        // Parameters validation.
        self::validate_parameters(self::execute_parameters(),
                array('contextid' => $contextid,
                      'sourcecode' => $sourcecode,
                      'language' => $language,
                      'stdin' => $stdin,
                      'files' => $files,
                      'params' => $params
                    ));

        // Now check if the user has the capability (usually meaning is logged in and not a guest).
        $context = context::instance_by_id($contextid);
        if (!has_capability('qtype/coderunner:sandboxwsaccess', $context, $USER->id)) {
            throw new qtype_coderunner_exception(get_string('wsnoaccess', 'qtype_coderunner'));
        }

        $sandbox = qtype_coderunner_sandbox::get_best_sandbox($language, true);
        if ($sandbox === null) {
            throw new qtype_coderunner_exception(get_string('wsnolanguage', 'qtype_coderunner', $language));
        }

        if (get_config('qtype_coderunner', 'wsloggingenabled')) {
            // Check if need to throttle this user, and if not allow the request and log it.
            $logmanger = get_log_manager();
            $readers = $logmanger->get_readers('\core\log\sql_reader');
            $reader = reset($readers);
            $maxhourlyrate = intval(get_config('qtype_coderunner', 'wsmaxhourlyrate'));
            if ($maxhourlyrate > 0) {
                $hourago = strtotime('-1 hour');
                $select = "userid = :userid AND eventname = :eventname AND timecreated > :since";
                $logparams = array('userid' => $USER->id, 'since' => $hourago,
                    'eventname' => '\qtype_coderunner\event\sandbox_webservice_exec');
                $currentrate = $reader->get_events_select_count($select, $logparams);
                if ($currentrate >= $maxhourlyrate) {
                    throw new qtype_coderunner_exception(get_string('wssubmissionrateexceeded', 'qtype_coderunner'));
                }
            }

            $event = \qtype_coderunner\event\sandbox_webservice_exec::create([
                'contextid' => $context->id]);
            $event->trigger();
        }

        try {
            $filesarray = $files ? json_decode($files, true) : array();
            $paramsarray = $params ? json_decode($params, true) : array();

            // Throws error for incorrect JSON formatting.
            if ($filesarray === null || $paramsarray === null) {
                throw new qtype_coderunner_exception(get_string('wsbadjson', 'qtype_coderunner'));
            }
            $maxcputime = intval(get_config('qtype_coderunner', 'wsmaxcputime'));  // Limit CPU time through this service.
            if (isset($paramsarray['cputime'])) {
                if ($paramsarray['cputime'] > $maxcputime) {
                    throw new qtype_coderunner_exception(get_string('wscputimeexcess', 'qtype_coderunner'));
                }
            } else {
                $paramsarray['cputime'] = $maxcputime;
            }
            $jobehostws = trim(get_config('qtype_coderunner', 'wsjobeserver'));
            if ($jobehostws !== '') {
                $paramsarray['jobeserver'] = $jobehostws;
            }
            $runresult = $sandbox->execute($sourcecode, $language, $stdin, $filesarray, $paramsarray);
        } catch (Exception $ex) {
            throw new qtype_coderunner_exception("Attempt to run job failed with error {$ex->message}");
        }
        $runresult->sandboxinfo = null; // Prevent leakage of info.
        return json_encode($runresult);
    }
}
