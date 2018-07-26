<?php

namespace app\controllers\admin;

use app\controllers\AbstractController;
use app\libraries\Core;
use app\libraries\IniParser;
use app\libraries\Output;

class ConfigurationController extends AbstractController {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'view':
                $this->viewConfiguration();
                break;
            case 'update':
                $this->updateConfiguration();
                break;
            default:
                $this->core->getOutput()->showError("Invalid page request for controller");
                break;
        }
    }

    public function viewConfiguration() {
        $fields = array(
            'course_name'                    => $this->core->getConfig()->getCourseName(),
            'course_home_url'                => $this->core->getConfig()->getCourseHomeUrl(),
            'default_hw_late_days'           => $this->core->getConfig()->getDefaultHwLateDays(),
            'default_student_late_days'      => $this->core->getConfig()->getDefaultStudentLateDays(),
            'zero_rubric_grades'             => $this->core->getConfig()->shouldZeroRubricGrades(),
            'upload_message'                 => $this->core->getConfig()->getUploadMessage(),
            'keep_previous_files'            => $this->core->getConfig()->keepPreviousFiles(),
            'display_rainbow_grades_summary' => $this->core->getConfig()->displayRainbowGradesSummary(),
            'display_custom_message'         => $this->core->getConfig()->displayCustomMessage(),
            'course_email'                   => $this->core->getConfig()->getCourseEmail(),
            'vcs_base_url'                   => $this->core->getConfig()->getVcsBaseUrl(),
            'vcs_type'                       => $this->core->getConfig()->getVcsType(),
            'forum_enabled'				     => $this->core->getConfig()->isForumEnabled(),
            'regrade_enabled'                => $this->core->getConfig()->isRegradeEnabled(),
            'regrade_message'                => $this->core->getConfig()->getRegradeMessage(),
            'private_repository'             => $this->core->getConfig()->getPrivateRepository()
        );

        foreach (array('upload_message', 'course_email', 'regrade_message') as $key) {
            if (isset($_SESSION['request'][$key])) {
                $fields[$key] = htmlentities($_SESSION['request'][$key]);
            }
        }

        $fields['course_name'] = $this->core->getDisplayedCourseName();

        foreach (array('default_hw_late_days', 'default_student_late_days') as $key) {
            if (isset($_SESSION['request'][$key])) {
                $fields[$key] = intval($_SESSION['request'][$key]);
            }
        }

        foreach (array('zero_rubric_grades', 'keep_previous_files', 'display_rainbow_grades_summary', 'display_custom_message', 'regrade_enabled') as $key) {
            if (isset($_SESSION['request'][$key])) {
                $fields[$key] = ($_SESSION['request'][$key] == true) ? true : false;
            }
        }

        if (isset($_SESSION['request'])) {
            unset($_SESSION['request']);
        }

        $this->core->getOutput()->renderOutput(array('admin', 'Configuration'), 'viewConfig', $fields);
    }

    public function updateConfiguration() {
        if (!$this->core->checkCsrfToken($_POST['csrf_token'])) {
            $response = array('status' => 'fail', 'message' => 'Invalid CSRF token');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }

        if(!isset($_POST['name'])) {
            $response = array('status' => 'fail', 'message' => 'Name of config value not provided');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }
        $name = $_POST['name'];

        if(!isset($_POST['entry'])) {
            $response = array('status' => 'fail', 'message' => 'Name of config entry not provided');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }
        $entry = $_POST['entry'];

        if($name === "course_name" && $entry === "") {
            $response = array('status' => 'fail', 'message' => 'Course name cannot be blank');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }

        if(in_array($name, array('default_hw_late_days', 'default_student_late_days'))) {
            $entry = $entry === "" ? intval($entry) : 0;
        }

        if(in_array($name, array('zero_rubric_grades', 'keep_previous_files', 'display_rainbow_grades_summary', 'display_custom_message', 'forum_enabled', 'regrade_enabled'))) {
            $entry = $entry === "true" ? true : false;
        }

        if($name === 'upload_message') {
            $entry = nl2br($entry);
        }

        $config_ini = $this->core->getConfig()->readCourseIni();
        $config_ini['course_details'][$name] = $entry;
        $this->core->getConfig()->saveCourseIni($config_ini['course_details']);

        $response = array('status' => 'success', 'message' => 'Config updated');
        $this->core->getOutput()->renderJson($response);
        return $response;
    }
}
