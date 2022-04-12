<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The questiontype class for the flashcard question type.
 *
 * @package    mod_etherpadlite
 * @copyright  2022 University of Vienna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_etherpadlite\observer;

use mod_etherpadlite\task\delete_moodle_group_pad;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_flashcards.
 */
class group {
    /**
     * Triggered via group_created core event
     *
     * @param \core\event\group_created $event
     */
    public static function group_created(\core\event\group_created $event) {
        global $DB;
        $etherpads = $DB->get_records('etherpadlite', ['course' => $data['courseid']]);
        if(!$etherpads) {
            return;
        }
        $config = get_config('etherpadlite');
        $instance = new \mod_etherpadlite\client($config->apikey, $config->url .'api');

        $data = $event->get_data();
        $etherpads = $DB->get_records('etherpadlite', ['course' => $data['courseid']]);
        $mgroupdb = [];
        foreach ($etherpads as $etherpad) {
            $padid = $etherpad->uri;
            $groupid = explode('$', $padid);
            $groupid = $groupid[0];

            $cm = get_coursemodule_from_instance('etherpadlite', $etherpad->id);

            if ($cm->groupmode != 0) {
                $mgroup = [];
                $mgroup['padid'] = $etherpad->id;
                $mgroup['groupid'] = $event->objectid;
                try {
                    $padid = $instance->create_group_pad($groupid, $config->padname . $event->objectid);
                } catch (Exception $e) {
                    throw $e;
                }
                array_push($mgroupdb, $mgroup);
            }
        }
        $DB->insert_records('etherpadlite_mgroups', $mgroupdb);
    }

    /**
     * Triggered via group_deleted core event
     *
     * @param \core\event\group_deleted $event
     */
    public static function group_deleted(\core\event\group_deleted $event) {
        global $DB;

        $config = get_config("etherpadlite");

        $sql = 'SELECT mg.id, mg.groupid, e.id, e.uri
                  FROM {etherpadlite_mgroups} mg
                  LEFT JOIN {etherpadlite} e ON e.id  = mg.padid
                 WHERE e.course = :courseid AND mg.groupid = :groupid';
        $records = $DB->get_records_sql($sql, ['courseid' => $event->courseid, 'groupid' => $event->objectid]);
        $nextruntime = self::get_next_runtime($config->deletemgrouppad);

        foreach ($records as $record) {
            self::delete_mgroup_pad_adhoc_task($record->uri, $record->id, $record, $nextruntime);
        }
    }

    /**
     * Triggered via grouping_group_assigned core event
     *
     * @param \core\event\grouping_group_assigned $event
     */
    public static function grouping_group_assigned(\core\event\grouping_group_assigned $event) {
        global $DB;


        $etherpads = $DB->get_records('etherpadlite', ['course' => $event->courseid]);
	if(!$etherpads) {
            return;
        }
        $other = $event->other;
        $config = get_config('etherpadlite');
        $instance = new \mod_etherpadlite\client($config->apikey, $config->url .'api');

        foreach ($etherpads as $etherpad) {
            $padid = $etherpad->uri;
            $groupid = explode('$', $padid);
            $groupid = $groupid[0];
            $cm = get_coursemodule_from_instance('etherpadlite', $etherpad->id);
            if ($cm->groupmode != 0 && $cm->groupingid == $event->objectid) {
                $mgroup = [];
                $mgroup['padid'] = $etherpad->id;
                $mgroup['groupid'] = $other['groupid'];
                try {
                    $padid = $instance->create_group_pad($groupid, $config->padname . $event->objectid);
                } catch (Exception $e) {
                    throw $e;
                }
                $DB->insert_record('etherpadlite_mgroups', $mgroup);
            }
        }
    }

    /**
     * Triggered via grouping_group_unassigned core event
     *
     * @param \core\event\grouping_group_unassigned $event
     */
    public static function grouping_group_unassigned(\core\event\grouping_group_unassigned $event) {
        global $DB;

        $config = get_config("etherpadlite");

        $other = $event->other;
        $sql = 'SELECT mg.id, mg.groupid, e.id, e.uri
                  FROM {etherpadlite_mgroups} mg
                  LEFT JOIN {etherpadlite} e ON e.id  = mg.padid
                  LEFT JOIN {course_modules} cm ON e.id  = cm.instance
                 WHERE e.course = :courseid AND mg.groupid = :groupid AND cm.groupingid = :groupingid';
        $records = $DB->get_records_sql($sql,
                    ['courseid' => $event->courseid, 'groupid' => $other['groupid'], 'groupingid' => $event->objectid]);
        $nextruntime = self::get_next_runtime($config->deletemgrouppad);
        foreach ($records as $record) {
            self::delete_mgroup_pad_adhoc_task($record->uri, $record->id, $record, $nextruntime);
        }

    }

    /**
     * Triggered via course_module_updated core event
     *
     * @param \core\event\course_module_updated $event
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        global $DB;

        $eventdata = $event->get_data();
        $other = $eventdata['other'];

        if ($other['modulename'] == 'etherpadlite') {
            $cm = $DB->get_record('course_modules', ['id' => $eventdata['objectid']]);
            $etherpadlite = $DB->get_record('etherpadlite', ['id' => $other['instanceid']]);
            // if groupmode is not set anymore, delete mgroupspads if exist
            $data = [];
            $data['groupmode'] = $cm->groupmode;
            $data['course'] = $eventdata['courseid'];
            $config = get_config("etherpadlite");
            $instance = new \mod_etherpadlite\client($config->apikey, $config->url.'api');
            if ($data['groupmode'] == 0) {
                $mgrouppads = $DB->get_records('etherpadlite_mgroups', ['padid' => $etherpadlite->id]);
                if ($mgrouppads) {
                    $nextruntime = self::get_next_runtime($config->deletemgrouppad);
                    if ($nextruntime) {
                        foreach ($mgrouppads as $mgrouppad) {
                            self::delete_mgroup_pad_adhoc_task($etherpadlite->uri,
                                $etherpadlite->id, $mgrouppad, $nextruntime);
                        }
                    }
                }
            } else {
                $config = get_config("etherpadlite");
                $groups = groups_get_all_groups($data['course'], 0, $cm->groupingid);

                $groupid = explode('$', $etherpadlite->uri);
                $groupid = $groupid[0];

                $mgroupdb = [];
                foreach ($groups as $group) {
                    $mgroup = [];
                    if (!$DB->record_exists('etherpadlite_mgroups', ['padid' => $etherpadlite->id, 'groupid' => $group->id])) {
                        $mgroup->padid = $etherpadlite->id;
                        $mgroup->groupid = $group->id;
                        array_push($mgroupdb, $mgroup);
                        try {
                            $padid = $instance->create_group_pad($groupid, $config->padname . $group->id);
                        } catch (Exception $e) {
                            throw $e;
                        }
                    }
                }
                $DB->insert_records('etherpadlite_mgroups', $mgroupdb);
            }
        }
    }

    /**
     *
     * @param int $configtime
     * @return number
     */
    public static function get_next_runtime(int $configtime) {
        $timenow = time();
        $nextruntime = 0;
        switch($configtime) {
            case 0:
                $nextruntime = null;
                break;
            case 1:
                $nextruntime = $timenow;
            case 2:
                $nextruntime = $timenow + 3600;
                break;
            case 3:
                $nextruntime = $timenow + 12 * 3600;
                break;
            case 4:
                $nextruntime = $timenow + 24 * 3600;
                break;
        }
        return $nextruntime;
    }

    public static function delete_mgroup_pad_adhoc_task($paduri, $padid, $mgrouppad, $nextruntime) {
        $deletepad = new delete_moodle_group_pad();
        $deletepad->set_custom_data([
            'paduri' => $paduri . $mgrouppad->groupid,
            'etherpadliteid' => $padid,
            'mrouppadid' => $mgrouppad->id,
            'mgroupid' => $mgrouppad->groupid
        ]);
        $deletepad->set_next_run_time($nextruntime);
        \core\task\manager::queue_adhoc_task($deletepad, true);
    }
}
