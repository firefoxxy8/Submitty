<?php

namespace app\controllers\grading;

use app\controllers\AbstractController;
use app\models\Team;
use app\models\User;
use \app\libraries\GradeableType;
use app\models\Gradeable;
use app\models\GradeableComponent;
use app\models\GradeableComponentMark;
use app\libraries\FileUtils;
use app\views\AutoGradingView;
use app\controllers\GradingController;


class ElectronicGraderController extends GradingController {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'details':
                $this->showDetails();
                break;
            case 'submit_team_form':
                $this->adminTeamSubmit();
                break;
            case 'export_teams':
                $this->exportTeams();
                break;
            case 'import_teams':
                $this->importTeams();
                break;    
            case 'grade':
                $this->showGrading();
                break;
            case 'save_one_component':
                $this->saveSingleComponent();
                break;
            case 'save_general_comment':
                $this->saveGeneralComment();
                break;
            case 'get_mark_data':
                $this->getMarkDetails();
                break;
            case 'get_gradeable_comment':
                $this->getGradeableComment();
                break;
            case 'get_marked_users':
                $this->getUsersThatGotTheMark();
                break;
            case 'add_one_new_mark':
                $this->addOneMark();
                break;
            case 'delete_one_mark':
                $this->deleteOneMark();
                break;
            case 'load_student_file':
                $this->ajaxGetStudentOutput();
                break;
            case 'verify_grader':
                $this->verifyGrader();
                break;
            case 'verify_all':
                $this->verifyGrader(true);
                break;
            case 'remove_empty':
                return $this->ajaxRemoveEmpty();
                break;
            case 'pdf_annotation_fullpage':
                $this->showPDFAnnotationFullPage();
                break;
            case 'pdf_annotation_embedded':
                $this->showEmbeddedPDFAnnotation();
                break;
            case 'save_pdf_annotation':
                $this->savePDFAnnotation();
                break;
            default:
                $this->showStatus();
                break;
        }
    }

    public function savePDFAnnotation(){
        //Save the annotation layer to a folder.
        $annotation_layer = $_POST['annotation_layer'];
        $annotation_info = $_POST['GENERAL_INFORMATION'];
        $course_path = $this->core->getConfig()->getCoursePath();
        $active_version = $this->core->getQueries()->getGradeable($annotation_info['gradeable_id'], $annotation_info['user_id'])->getActiveVersion();
        $annotation_gradeable_path = FileUtils::joinPaths($course_path, 'annotations', $annotation_info['gradeable_id']);
        if(!FileUtils::createDir($annotation_gradeable_path) && !is_dir($annotation_gradeable_path)){
            $this->core->addErrorMessage("Creating annotation gradeable folder failed");
            return false;
        }
        $annotation_user_path = FileUtils::joinPaths($annotation_gradeable_path, $annotation_info['user_id']);
        if(!FileUtils::createDir($annotation_user_path) && !is_dir($annotation_user_path)){
            $this->core->addErrorMessage("Creating annotation user folder failed");
            return false;
        }
        $annotation_version_path = FileUtils::joinPaths($annotation_user_path, $active_version);
        if(!FileUtils::createDir($annotation_version_path) && !is_dir($annotation_version_path)){
            $this->core->addErrorMessage("Creating annotation version folder failed");
            return false;
        }
        $new_file_name = preg_replace('/\\.[^.\\s]{3,4}$/', '', $annotation_info['file_name']) . '_annotation.json';
        file_put_contents(FileUtils::joinPaths($annotation_version_path, $new_file_name), $annotation_layer);
        return true;
    }

    public function showEmbeddedPDFAnnotation(){
        //This is the embedded pdf annotator that we built.
        $gradeable_id = $_POST['gradeable_id'] ?? NULL;
        $user_id = $_POST['user_id'] ?? NULL;
        $filename = $_POST['filename'] ?? NULL;
        $active_version = $this->core->getQueries()->getGradeable($gradeable_id, $user_id)->getActiveVersion();
        $annotation_file_name = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename). '_annotation.json';
        $annotation_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), 'annotations', $gradeable_id, $user_id, $active_version, $annotation_file_name);
        $annotation_json = is_file($annotation_path) ? file_get_contents($annotation_path) : "";
        $this->core->getOutput()->useFooter(false);
        $this->core->getOutput()->useHeader(false);
        //TODO: Add a new view
        return $this->core->getOutput()->renderTwigOutput('grading/electronic/PDFAnnotationEmbedded.twig', [
            'gradeable_id' => $gradeable_id,
            'user_id' => $user_id,
            'filename' => $filename,
            'annotation_json' => $annotation_json
        ]);
    }

    public function showPDFAnnotationFullPage(){
        //This shows the pdf-annotate.js library's default pdf annotator. It might be useful in the future to have
        //a full-sized annotator, so keeping this in for now.
        $this->core->getOutput()->useFooter(false);
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->renderOutput(array('grading', 'PDFAnnotation'), 'showAnnotationPage');
    }
      
    private function fetchGradeable($gradeable_id, $who_id) {
        // TODO: this is bad, but its the only way to do it until the new model
        $users = [$who_id];
        $team = $this->core->getQueries()->getTeamById($who_id);
        if ($team !== null) {
            $users = array_merge($team->getMembers(), $users);
        }
        $gradeables = $this->core->getQueries()->getGradeables($gradeable_id, $users);
        $gradeable = null;
        foreach ($gradeables as $g) {
            // Either this is the user requsted (non-team case) or its the gradeable instance for me or access grading
            if ($g->getUser() === $who_id || $g->getUser()->getId() === $this->core->getUser()->getId() || $this->core->getUser()->accessGrading()) {
                $gradeable = $g;
                break;
            }
        }
        return $gradeable;
    }

    public function ajaxRemoveEmpty(){
        //This function shows the empty spaces in the diffViewer
        //TODO: Need to add checks?
        $gradeable_id = $_REQUEST['gradeable_id'];
        $who_id = $_REQUEST['who_id'];
        $gradeable = $this->fetchGradeable($gradeable_id, $who_id);
        $gradeable->loadResultDetails();
        $testcase = $gradeable->getTestcases()[$_REQUEST['index']];
        //There are three options: original (Don't show empty space), escape (with escape codes), and unicode (with characters)
        $option = $_REQUEST['option'] ?? 'original';
        //There are currently two views, the view of student's code and the expected view.
        $which = $which = $_REQUEST['which'] ?? 'actual';
        $autocheck_cnt = isset($_REQUEST['autocheck_cnt'])  ? intval($_REQUEST['autocheck_cnt']) : 0;
        foreach ($testcase->getAutochecks() as $autocheck) {
            $diff_viewer = $autocheck->getDiffViewer();
            if($autocheck_cnt <= 0) {
                break;
            }
            $autocheck_cnt -= 1;
        }
        $html = "";
        if($which == "actual"){
            $html .= $diff_viewer->getDisplayActual($option);
        }
        else {
            $html .= $diff_viewer->getDisplayExpected($option);
        }
        $white_spaces = $diff_viewer->getWhiteSpaces();
        $this->core->getOutput()->renderJson(['html' => $html, 'whitespaces' => $white_spaces]);
    }

    private function verifyGrader($verifyAll = false){
        //check that I am able to verify.
        if ($verifyAll) {
            if (!$this->core->getAccess()->canI("grading.electronic.verify_all")) {
                $this->core->addErrorMessage("You do not have the proper privileges to verify this grade.");
                return;
            }
        } else {
            if (!$this->core->getAccess()->canI("grading.electronic.verify_grader")) {
                $this->core->addErrorMessage("You do not have the proper privileges to verify this grade.");
                return;
            }
        }

        $gradeable_id = $_POST['gradeable_id'];
        $component_id = $_POST['component_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);
        //stores whether or not we verified at least one gradeable. Should never be false at the end of an execution.
        $verified = false;
        //Search across all components for components to verify
        foreach ($gradeable->getComponents() as $component) {
            //If this component hasn't been graded, we can't verify it.
            if(!$component->getGrader()) {
                continue;
            }
            //If we are either verifying all components or this is the component we were asked to verify,
            //verify the component.
            if($verifyAll || $component->getId() == $component_id){
                //Only verify the component if we weren't already the grader.
                if($component->getGrader()->getId() !== $this->core->getUser()->getId()){
                    $component->setGrader($this->core->getUser());
                    $component->saveGradeableComponentData($gradeable->getGdId());
                    $verified = true;
                }
                //If we aren't verifying all, we have verified the only component we need to.
                if(!$verifyAll && $component->getId() == $component_id) {
                    break;
                }
            }
        }

        if($verified){
            return;
        }else{
            $this->core->addErrorMessage("Gradeable component does not exist or was previously verified by you.");
        }
    }
    /**
     * Shows statistics for the grading status of a given electronic submission. This is shown to all full access
     * graders. Limited access graders will only see statistics for the sections they are assigned to.
     */
    public function showStatus() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        if (!$this->core->getAccess()->canI("grading.electronic.status", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getName()}");
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        $gradeableUrl = $this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable_id));
        $this->core->getOutput()->addBreadcrumb("{$gradeable->getName()} Grading", $gradeableUrl);
        $peer = false;
        if ($gradeable->getPeerGrading() && ($this->core->getUser()->getGroup() == User::GROUP_STUDENT)) {
            $peer = true;
        }

        /*
         * we need number of students per section
         */

        $no_team_users = array();
        $graded_components = array();
        $graders = array();
        $average_scores = array();
        $sections = array();
        $total_users = array();
        $component_averages = array();
        $autograded_average = null;
        $overall_average = null;
        $num_submitted = array();
        $num_unsubmitted = 0 ;
        $total_indvidual_students = 0;
        $viewed_grade = 0;
        $regrade_requests = $this->core->getQueries()->getNumberRegradeRequests($gradeable_id);
        if ($peer) {
            $peer_grade_set = $gradeable->getPeerGradeSet();
            $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, 'registration_section');
            $num_components = $gradeable->getNumPeerComponents();
            $graded_components = $this->core->getQueries()->getGradedPeerComponentsByRegistrationSection($gradeable_id, $sections);
            $my_grading = $this->core->getQueries()->getNumGradedPeerComponents($gradeable->getId(), $this->core->getUser()->getId());
            $component_averages = array();
            $autograded_average = null;
            $overall_average = null;
            $section_key='registration_section';
        }
        else if ($gradeable->isGradeByRegistration()) {
            if(!$this->core->getAccess()->canI("grading.electronic.status.full")) {
                $sections = $this->core->getUser()->getGradingRegistrationSections();
            }
            else {
                $sections = $this->core->getQueries()->getRegistrationSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_registration_id'];
                }
            }
            $section_key='registration_section';
            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
            }
            $num_components = $gradeable->getNumTAComponents();
        }
        //grading by rotating section
        else {
            if(!$this->core->getAccess()->canI("grading.electronic.status.full")) {
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_rotating_id'];
                }
            }
            $section_key='rotating_section';
            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable_id, $sections);
            }
        }
        //Check if this is a team project or a single-user project
        if($gradeable->isTeamAssignment()){
            $num_submitted = $this->core->getQueries()->getSubmittedTeamCountByGradingSections($gradeable_id, $sections, 'registration_section');
        }
        else{
            $num_submitted = $this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable_id, $sections, $section_key);
        }
        if (count($sections) > 0) {
            if ($gradeable->isTeamAssignment()) {
                $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, $section_key);
                $no_team_users = $this->core->getQueries()->getUsersWithoutTeamByGradingSections($gradeable_id, $sections, $section_key);
                $team_users = $this->core->getQueries()->getUsersWithTeamByGradingSections($gradeable_id, $sections, $section_key);
            }
            else {
                $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, $section_key);
                $no_team_users = array();
                $team_users = array();
            }
            $graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, $section_key, $gradeable->isTeamAssignment());
            $component_averages = $this->core->getQueries()->getAverageComponentScores($gradeable_id, $section_key, $gradeable->isTeamAssignment());
            $autograded_average = $this->core->getQueries()->getAverageAutogradedScores($gradeable_id, $section_key, $gradeable->isTeamAssignment());
            $overall_average = $this->core->getQueries()->getAverageForGradeable($gradeable_id, $section_key, $gradeable->isTeamAssignment());
            $num_components = $gradeable->getNumTAComponents();
            $viewed_grade = $this->core->getQueries()->getNumUsersWhoViewedGrade($gradeable_id);
        }
        $sections = array();
        //Either # of teams or # of students (for non-team assignments). Either case
        // this is the max # of submitted copies for this gradeable.
        $total_submissions = 0;
        if (count($total_users) > 0) {
            foreach ($total_users as $key => $value) {
                if ($key == 'NULL') continue;
                $total_submissions += $value;
            }
            if ($peer) {
                $sections['stu_grad'] = array(
                    'total_components' => $num_components * $peer_grade_set,
                    'graded_components' => $my_grading,
                    'graders' => array()
                );
                $sections['all'] = array(
                    'total_components' => 0,
                    'graded_components' => 0,
                    'graders' => array()
                );
                foreach($total_users as $key => $value) {
                    if($key == 'NULL') continue;
                    $sections['all']['total_components'] += $value *$num_components*$peer_grade_set;
                    $sections['all']['graded_components'] += isset($graded_components[$key]) ? $graded_components[$key] : 0;
                }
                $sections['all']['total_components'] -= $peer_grade_set*$num_components;
                $sections['all']['graded_components'] -= $my_grading;
            }
            else {
                foreach ($total_users as $key => $value) {                           
                    if(array_key_exists($key, $num_submitted)){
                        $sections[$key] = array(
                            'total_components' => $num_submitted[$key] * $num_components,
                            'graded_components' => 0,
                            'graders' => array()
                        );
                    } else{
                        $sections[$key] = array(
                            'total_components' => 0,
                            'graded_components' => 0,
                            'graders' => array()
                        );
                    }
                    if ($gradeable->isTeamAssignment()) {
                        $sections[$key]['no_team'] = $no_team_users[$key];
                        $sections[$key]['team'] = $team_users[$key];
                    }
                    if (isset($graded_components[$key])) {
                        // Clamp to total components if unsubmitted assigment is graded for whatever reason
                        $sections[$key]['graded_components'] = min(intval($graded_components[$key]), $sections[$key]['total_components']);
                    }
                    if (isset($graders[$key])) {
                        $sections[$key]['graders'] = $graders[$key];

                        if ($key !== "NULL") {
                            $valid_graders = array();
                            foreach ($graders[$key] as $valid_grader) {
                                /* @var User $valid_grader */
                                if ($this->core->getAccess()->canUser($valid_grader, "grading.electronic.grade", ["gradeable" => $gradeable])) {
                                    $valid_graders[] = $valid_grader->getDisplayedFirstName();
                                }
                            }
                            $sections[$key]["valid_graders"] = $valid_graders;
                        }
                    }
                }
            }
        }
        $registered_but_not_rotating = count($this->core->getQueries()->getRegisteredUsersWithNoRotatingSection());
        $rotating_but_not_registered = count($this->core->getQueries()->getUnregisteredStudentsWithRotatingSection());

        $show_warnings = $this->core->getAccess()->canI("grading.electronic.status.warnings");

        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'statusPage', $gradeable, $sections, $component_averages, $autograded_average, $overall_average, $total_submissions, $registered_but_not_rotating, $rotating_but_not_registered, $viewed_grade, $section_key, $regrade_requests, $show_warnings);
    }
    public function showDetails() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        $gradeableUrl = $this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable_id));
        $this->core->getOutput()->addBreadcrumb("{$gradeable->getName()} Grading", $gradeableUrl);

        $this->core->getOutput()->addBreadcrumb('Student Index');

        if ($gradeable === null) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return;
        }
        $peer = ($gradeable->getPeerGrading() && $this->core->getUser()->getGroup() == User::GROUP_STUDENT);
        if (!$this->core->getAccess()->canI("grading.electronic.details", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getName()}");
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        //Checks to see if the Grader has access to all users in the course,
        //Will only show the sections that they are graders for if not TA or Instructor
        $can_show_all = $this->core->getAccess()->canI("grading.electronic.details.show_all");
        $show_all = isset($_GET['view']) && $_GET['view'] === "all" && $can_show_all;

        $students = array();
        //If we are peer grading, load in all students to be graded by this peer.
        if ($peer) {
            $student_ids = $this->core->getQueries()->getPeerAssignment($gradeable->getId(), $this->core->getUser()->getId());
            $graders = array();
            $section_key = "registration_section";
        }
        else if ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
            $sections = $this->core->getUser()->getGradingRegistrationSections();
            if (!$show_all) {
                $students = $this->core->getQueries()->getUsersByRegistrationSections($sections);
            }
            $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
        }
        else {
            $section_key = "rotating_section";
            $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id,
                $this->core->getUser()->getId());
            if (!$show_all) {
                $students = $this->core->getQueries()->getUsersByRotatingSections($sections);
            }
            $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable->getId(), $sections);
        }
        if ($show_all) {
            $students = $this->core->getQueries()->getAllUsers($section_key);
        }
        if(!$peer) {
            $student_ids = array_map(function(User $student) { return $student->getId(); }, $students);
        }

        $show_empty_teams = $this->core->getAccess()->canI("grading.electronic.details.show_empty_teams");
        $empty_teams = array();
        if ($gradeable->isTeamAssignment()) {
            // Only give getGradeables one User ID per team
            $all_teams = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
            foreach($all_teams as $team) {
                $student_ids = array_diff($student_ids, $team->getMembers());
                $team_section = $gradeable->isGradeByRegistration() ? $team->getRegistrationSection() : $team->getRotatingSection();
                if ($team->getSize() > 0 && (in_array($team_section, $sections) || $show_all)) {
                    $student_ids[] = $team->getLeaderId();
                }
                if ($team->getSize() === 0 && $show_empty_teams) {
                    $empty_teams[] = $team;
                }
            }
        }

        $rows = $this->core->getQueries()->getGradeables($gradeable_id, $student_ids, $section_key);
        if ($gradeable->isTeamAssignment()) {
            // Rearrange gradeables arrray into form (sec 1 teams, sec 1 individuals, sec 2 teams, sec 2 individuals, etc...)
            $sections = array();
            $individual_rows = array();
            $team_rows = array();
            foreach($rows as $row) {
                if ($gradeable->isGradeByRegistration()) {
                    $section = $row->getTeam() === null ? strval($row->getUser()->getRegistrationSection()) : strval($row->getTeam()->getRegistrationSection());
                }
                else {
                    $section = $row->getTeam() === null ? strval($row->getUser()->getRotatingSection()) : strval($row->getTeam()->getRotatingSection());
                }

                if ($section != null && !in_array($section, $sections)) {
                    $sections[] = $section;
                }

                if ($row->getTeam() === null) {
                    if (!isset($individual_rows[$section])) {
                        $individual_rows[$section] = array();
                    }
                    $individual_rows[$section][] = $row;
                }
                else {
                    if (!isset($team_rows[$section])) {
                        $team_rows[$section] = array();
                    }
                    $team_rows[$section][] = $row;
                }
            }

            asort($sections);
            $rows = array();
            foreach($sections as $section) {
                if (isset($team_rows[$section])) {
                    $rows = array_merge($rows, $team_rows[$section]);
                }
                if (isset($individual_rows[$section])) {
                    $rows = array_merge($rows, $individual_rows[$section]);
                }
            }
            // Put null section at end of array
            if (isset($team_rows[""])) {
                $rows = array_merge($rows, $team_rows[""]);
            }
            if (isset($individual_rows[""])) {
                $rows = array_merge($rows, $individual_rows[""]);
            }
        }

        if ($peer) {
            $grading_count = $gradeable->getPeerGradeSet();
        } else if ($gradeable->isGradeByRegistration()) {
            $grading_count = count($this->core->getUser()->getGradingRegistrationSections());
        } else {
            $grading_count = count($this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable->getId(), $this->core->getUser()->getId()));
        }

        $show_all_sections_button = $can_show_all;
        $show_edit_teams = $this->core->getAccess()->canI("grading.electronic.show_edit_teams") && $gradeable->isTeamAssignment();
        $show_import_teams_button = $show_edit_teams && (count($all_teams) > count($empty_teams));
        $show_export_teams_button = $show_edit_teams && (count($all_teams) == count($empty_teams));

        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'detailsPage', $gradeable, $rows, $graders, $empty_teams, $show_all_sections_button, $show_import_teams_button, $show_export_teams_button, $show_edit_teams);

        if ($show_edit_teams) {
            $all_reg_sections = $this->core->getQueries()->getRegistrationSections();
            $key = 'sections_registration_id';
            foreach ($all_reg_sections as $i => $section) {
                $all_reg_sections[$i] = $section[$key];
            }

            $all_rot_sections = $this->core->getQueries()->getRotatingSections();
            $key = 'sections_rotating_id';
            
            foreach ($all_rot_sections as $i => $section) {
                $all_rot_sections[$i] = $section[$key];
            }
            $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'adminTeamForm', $gradeable, $all_reg_sections, $all_rot_sections);
            $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'importTeamForm', $gradeable);
        }
    }

    public function importTeams() {
        $gradeable_id = (isset($_REQUEST['gradeable_id'])) ? $_REQUEST['gradeable_id'] : null;
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        $return_url = $this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action'=>'details','gradeable_id'=>$gradeable_id));

        if ($gradeable == null) {
            $this->core->addErrorMessage("Failed to load gradeable: {$gradeable_id}");
            $this->core->redirect($return_url);
        }

        if (!$this->core->getAccess()->canI("grading.electronic.import_teams", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to do that.");
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        if (!$gradeable->isTeamAssignment()) {
            $this->core->addErrorMessage("{$gradeable->getName()} is not a team assignment");
            $this->core->redirect($return_url);
        }

        if ($_FILES['upload_team']['name'] == "") {
            $this->core->addErrorMessage("No input file specified");
            $this->core->redirect($return_url);
        }

        $csv_file = $_FILES['upload_team']['tmp_name'];
        register_shutdown_function(
            function() use ($csv_file) {
                unlink($csv_file);
            }
        );
        ini_set("auto_detect_line_endings", true);

        $contents = file($csv_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            $this->core->addErrorMessage("File was not properly uploaded. Contact your sysadmin.");
            $this->core->redirect($return_url);
        }

        $row_num=1;
        $error_message="";
        $new_teams_members = array();
        foreach($contents as $content) {
            $vals = str_getcsv($content);
            $vals = array_map('trim', $vals);
            if(count($vals) != 6) {
                $error_message .= "ERROR on row {$row_num}, csv row do not follow specified format<br>";
                continue;
            }
            if($row_num == 1) {
                $row_num += 1;
                continue;
            }
            $team_id = $vals[3];
            $user_id = $vals[2];
            
            if ($this->core->getQueries()->getUserById($user_id) === null) {
                $error_message .= "ERROR on row {$row_num}, user_id doesn't exists<br>";
                continue;    
            }
            if(!array_key_exists($team_id, $new_teams_members)) {
                $new_teams_members[$team_id] = array();
            }
            array_push($new_teams_members[$team_id], $user_id);
        }

        if($error_message != "") {
            $this->core->addErrorMessage($error_message);
            $this->core->redirect($return_url);
        }

        $gradeable_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id);
        if (!FileUtils::createDir($gradeable_path)) {
            $this->core->addErrorMessage("Failed to make folder for this assignment");
            $this->core->redirect($return_url);
        }    

        foreach($new_teams_members as $team_id => $members) {
            $leader_id = $members[0];
            ElectronicGraderController::CreateTeamWithLeaderAndUsers($this->core, $gradeable, $leader_id, $members);
        }

        $this->core->addSuccessMessage("All Teams are imported to the gradeable");
        $this->core->redirect($return_url);
    }

    public function exportTeams() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        if (!$this->core->getAccess()->canI("grading.electronic.export_teams", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to do that.");
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        $all_teams = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
        $nl = "\n";
        $csvdata="First Name,Last Name,User ID,Team ID,Team Registration Section,Team Rotating Section".$nl;
        foreach ($all_teams as $team) {
            if( $team->getSize() != 0) {
                foreach(($team->getMembers()) as $member_id) {
                    $user = $this->core->getQueries()->getUserById($member_id);
                    $csvdata .= $user->getDisplayedFirstName().",".$user->getLastName().",".$member_id.",".$team->getId().",".$team->getRegistrationSection().",".$team->getRotatingSection().$nl;
                }
            }    
        }
        $filename = "";
        $filename = $this->core->getConfig()->getCourse()."_".$gradeable_id."_teams.csv";
        $this->core->getOutput()->renderFile($csvdata, $filename);
        return $csvdata;
    }    

    public function adminTeamSubmit() {
        if (!$this->core->getAccess()->canI("grading.electronic.submit_team_form")) {
            $this->core->addErrorMessage("You do not have permission to do that.");
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        $return_url = $this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action'=>'details','gradeable_id'=>$gradeable_id));
        if (isset($_POST['view'])) $return_url .= "&view={$_POST['view']}";

        if (!$gradeable->isTeamAssignment()) {
            $this->core->addErrorMessage("{$gradeable->getName()} is not a team assignment");
            $this->core->redirect($return_url);
        }

        $num_users = intval($_POST['num_users']);
        $user_ids = array();
        for ($i = 0; $i < $num_users; $i++) {
            $id = trim(htmlentities($_POST["user_id_{$i}"]));
            if (($id !== "") && !in_array($id, $user_ids)) {
                if ($this->core->getQueries()->getUserById($id) === null) {
                    $this->core->addErrorMessage("ERROR: {$id} is not a valid User ID");
                    $this->core->redirect($return_url);
                }
                $user_ids[] = $id;
                continue;
            }
            if(in_array($id, $user_ids)) {
                $this->core->addErrorMessage("ERROR: {$id} is already on this team");
                $this->core->redirect($return_url);
            }
        }
        $new_team = $_POST['new_team'] === 'true' ? true : false;

        if ($new_team) {
            $leader = $_POST['new_team_user_id'];
            ElectronicGraderController::CreateTeamWithLeaderAndUsers($this->core, $gradeable, $leader, $user_ids);
        }
        else {
            $team_id = $_POST['edit_team_team_id'];
            $team = $this->core->getQueries()->getTeamById($team_id);
            if ($team === null) {
                $this->core->addErrorMessage("ERROR: {$team_id} is not a valid Team ID");
                $this->core->redirect($return_url);
            }
            $team_members = $team->getMembers();
            $add_user_ids = array();
            foreach($user_ids as $id) {
                if (!in_array($id, $team_members)) {
                    if ($this->core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $id) !== null) {
                        $this->core->addErrorMessage("ERROR: {$id} is already on a team");
                        $this->core->redirect($return_url);
                    }
                    $add_user_ids[] = $id;
                }
            }
            $remove_user_ids = array();
            foreach($team_members as $id) {
                if (!in_array($id, $user_ids)) {
                    $remove_user_ids[] = $id;
                }
            }

            $reg_section = $_POST['reg_section'] === "NULL" ? null : $_POST['reg_section'];
            $rot_section = $_POST['rot_section'] === "NULL" ? null : intval($_POST['rot_section']);
            $this->core->getQueries()->updateTeamRegistrationSection($team_id, $reg_section);
            $this->core->getQueries()->updateTeamRotatingSection($team_id, $rot_section);
            foreach($add_user_ids as $id) {
                $this->core->getQueries()->declineAllTeamInvitations($gradeable_id, $id);
                $this->core->getQueries()->acceptTeamInvitation($team_id, $id);
            }
            foreach($remove_user_ids as $id) {
                $this->core->getQueries()->leaveTeam($team_id, $id);
            }
            $this->core->addSuccessMessage("Updated Team {$team_id}");

            $current_time = (new \DateTime('now', $this->core->getConfig()->getTimezone()))->format("Y-m-d H:i:sO")." ".$this->core->getConfig()->getTimezone()->getName();
            $settings_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id, $team_id, "user_assignment_settings.json");
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                $this->core->addErrorMessage("Failed to open settings file");
                $this->core->redirect($return_url);
            }
            foreach($add_user_ids as $id) {
                $json["team_history"][] = array("action" => "admin_add_user", "time" => $current_time,
                                                    "admin_user" => $this->core->getUser()->getId(), "added_user" => $id);
            }
            foreach($remove_user_ids as $id) {
                $json["team_history"][] = array("action" => "admin_remove_user", "time" => $current_time,
                                                    "admin_user" => $this->core->getUser()->getId(), "removed_user" => $id);
            }
            if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
                $this->core->addErrorMessage("Failed to write to team history to settings file");
            }
        }   
        
        $this->core->redirect($return_url);
    }

    static public function createTeamWithLeaderAndUsers($core, $gradeable, $leader, $user_ids){
        $team_leader_id = null;
        $gradeable_id = $gradeable->getId();
        foreach($user_ids as $id) {
            if($id === "undefined" || $id === "")
            {
                continue;
            }
            if ($core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $id) !== null) {
                $core->addErrorMessage("ERROR: {$id} is already on a team");
                return;
            }
            if ($id === $leader) {
                $team_leader_id = $id;
            }
        }
        if ($team_leader_id === null) {
            $core->addErrorMessage("ERROR: {$team_leader_id} must be on the team");
            return;
        }

        $registration_section = $core->getQueries()->getUserById($team_leader_id)->getRegistrationSection();
        $rotating_section = $core->getQueries()->getUserById($team_leader_id)->getRotatingSection();

        //overwrite sections if they are available in the post
        if(isset($_POST['section']) && $_POST['section'] !== "NULL"){
            if ($gradeable->isGradeByRegistration()) {
                $registration_section = $_POST['section'] === "NULL" ? null : $_POST['section'];
            }
            else {
                $rotating_section = $_POST['section'] === "NULL" ? null : intval($_POST['section']);
            }
        }

        $team_id = $core->getQueries()->createTeam($gradeable_id, $team_leader_id, $registration_section, $rotating_section);
        foreach($user_ids as $id) {
            if($id === "undefined" or $id === ""){
                continue;
            }
            $core->getQueries()->declineAllTeamInvitations($gradeable_id, $id);
            if ($id !== $team_leader_id) $core->getQueries()->acceptTeamInvitation($team_id, $id);
        }
        $core->addSuccessMessage("Created New Team {$team_id}");

        $gradeable_path = FileUtils::joinPaths($core->getConfig()->getCoursePath(), "submissions", $gradeable_id);
        if (!FileUtils::createDir($gradeable_path)) {
            $core->addErrorMessage("Failed to make folder for this assignment");
            return;
        }

        $user_path = FileUtils::joinPaths($gradeable_path, $team_id);
        if (!FileUtils::createDir($user_path)) {
            $core->addErrorMessage("Failed to make folder for this assignment for the team");
            return;
        }

        $current_time = (new \DateTime('now', $core->getConfig()->getTimezone()))->format("Y-m-d H:i:sO")." ".$core->getConfig()->getTimezone()->getName();
        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
        $json = array("team_history" => array(array("action" => "admin_create", "time" => $current_time,
                                                    "admin_user" => $core->getUser()->getId(), "first_user" => $team_leader_id)));
        foreach($user_ids as $id) {
            if ($id !== $team_leader_id) {
                $json["team_history"][] = array("action" => "admin_add_user", "time" => $current_time,
                                                "admin_user" => $core->getUser()->getId(), "added_user" => $id);
            }
        }
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            $core->addErrorMessage("Failed to write to team history to settings file");
        }
    }

    public function showGrading() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);
        $peer = false;
        if($gradeable->getPeerGrading() && $this->core->getUser()->getGroup() == User::GROUP_STUDENT) {
            $peer = true;
        }

        $gradeableUrl = $this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable_id));
        $this->core->getOutput()->addBreadcrumb("{$gradeable->getName()} Grading", $gradeableUrl);
        $indexUrl = $this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'action' => 'details', 'gradeable_id' => $gradeable_id));
        $this->core->getOutput()->addBreadcrumb('Student Index', $indexUrl);

        $graded = 0;
        $total = 0;
        $team = $gradeable->isTeamAssignment();
        if($peer) {
            $section_key = 'registration_section';
            $user_ids_to_grade = $this->core->getQueries()->getPeerAssignment($gradeable->getId(), $this->core->getUser()->getId());
            $total = $gradeable->getPeerGradeSet();
            $graded = $this->core->getQueries()->getNumGradedPeerComponents($gradeable->getId(), $this->core->getUser()->getId()) / $gradeable->getNumPeerComponents();
        }
        else if ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
            $sections = $this->core->getUser()->getGradingRegistrationSections();
            if ($this->core->getAccess()->canI("grading.electronic.grade.if_no_sections_exist") && $sections == null) {
                $sections = $this->core->getQueries()->getRegistrationSections();
                for ($i = 0; $i < count($sections); $i++) {
                    $sections[$i] = $sections[$i]['sections_registration_id'];
                }
            }
            if ($team) {
                $teams_to_grade = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
                //order teams first by registration section, then by leader id.
                usort($teams_to_grade, function(Team $a, Team $b) {
                    if($a->getRegistrationSection() == $b->getRegistrationSection())
                        return $a->getLeaderId() < $b->getLeaderId() ? -1 : 1;
                    return $a->getRegistrationSection() < $b->getRegistrationSection() ? -1 : 1;
                });

            }
            else {
                $users_to_grade = $this->core->getQueries()->getUsersByRegistrationSections($sections,$orderBy="registration_section,user_id;");
            }
            if($team){
                $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, 'registration_section',$team));
                $total = array_sum($this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'registration_section'));
                $total_submitted=array_sum($this->core->getQueries()->getSubmittedTeamCountByGradingSections($gradeable_id, $sections, 'registration_section'));
            }
            else {
                $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, 'registration_section', $team));
                $total = array_sum($this->core->getQueries()->getTotalUserCountByGradingSections($sections, 'registration_section'));
                $total_submitted=array_sum($this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable_id, $sections, 'registration_section'));
            }
        }
        else {
            $section_key = "rotating_section";
            $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            if ($this->core->getAccess()->canI("grading.electronic.grade.if_no_sections_exist") && $sections == null) {
                $sections = $this->core->getQueries()->getRotatingSections();
                for ($i = 0; $i < count($sections); $i++) {
                    $sections[$i] = $sections[$i]['sections_rotating_id'];
                }
            }
            if ($team) {
                $teams_to_grade = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
                //order teams first by rotating section, then by leader id.
                usort($teams_to_grade, function($a, $b) {
                    if($a->getRotatingSection() == $b->getRotatingSection())
                        return $a->getMembers()[0] < $b->getMembers()[0] ? -1 : 1;
                    return $a->getRotatingSection() < $b->getRotatingSection() ? -1 : 1;
                });
                //$total = array_sum($this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'rotating_section'));
                $total = array_sum($this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'rotating_section'));
                $total_submitted=array_sum($this->core->getQueries()->getSubmittedTeamCountByGradingSections($gradeable_id, $sections, 'rotating_section'));
            }
            else {
                $users_to_grade = $this->core->getQueries()->getUsersByRotatingSections($sections,$orderBy="rotating_section,user_id;");
                $total = array_sum($this->core->getQueries()->getTotalUserCountByGradingSections($sections, 'rotating_section'));
                $total_submitted=array_sum($this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable->getId(), $sections, 'rotating_section'));
            }
            $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, 'rotating_section', $team));
        }
        //multiplies users and the number of components a gradeable has together
        if($team) {
            $total_submitted = $total_submitted * count($gradeable->getComponents());
        }
        else {
            $total_submitted = $total_submitted * count($gradeable->getComponents());
        }
        if($total_submitted == 0) {
            $progress = 100;
        }
        else {
            $progress = round(($graded / $total_submitted) * 100, 1);
        }
        if(!$peer && !$team) {
            $user_ids_to_grade = array_map(function(User $user) { return $user->getId(); }, $users_to_grade);
        }
        if(!$peer && $team) {
            /* @var Team[] $teams_assoc */
            $teams_assoc = [];

            foreach ($teams_to_grade as $team_id) {
                $teams_assoc[$team_id->getId()] = $team_id;
                $user_ids_to_grade[] = $team_id->getId();
            }
        }
        
        //$gradeables_to_grade = $this->core->getQueries()->getGradeables($gradeable_id, $user_ids_to_grade, $section_key);

        $who_id = isset($_REQUEST['who_id']) ? $_REQUEST['who_id'] : "";
        //$who_id = isset($who_id[$_REQUEST['who_id']]) ? $who_id[$_REQUEST['who_id']] : "";

        $prev_id = "";
        $next_id = "";
        $break_next = false;
        if($who_id === ""){
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action'=>'details', 
                'gradeable_id' => $gradeable_id)));
        }
        
        $index = array_search($who_id, $user_ids_to_grade);
        $not_in_my_section = false;
        //If the student isn't in our list of students to grade.
        if($index === false){
            //If we are a full access grader, let us access the student anyway (but don't set next and previous)
            $prev_id = "";
            $next_id = "";
            $not_in_my_section = true;
        }
        else {
            //If the student is in our list of students to grade, set next and previous index appropriately
            if ($index > 0) {
                $prev_id = $user_ids_to_grade[$index - 1];
            }
            if ($index < count($user_ids_to_grade) - 1) {
                $next_id = $user_ids_to_grade[$index + 1];
            }
        }

        if ($team) {
            if ($teams_assoc[$who_id] === NULL) {
                $gradeable = NULL;
            } else {
                $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $teams_assoc[$who_id]->getLeaderId());
            }
        } else {
            $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $who_id);
        }

        if (!$this->core->getAccess()->canI("grading.electronic.grade", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("ERROR: You do not have access to grade the requested student.");
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'gradeable_id' => $gradeable_id)));
        }

        $gradeable->loadResultDetails();

        $can_verify = false;
        //check if verify all button should be shown or not
        foreach ($gradeable->getComponents() as $component) {
            if (!$component->getGrader()) {
                continue;
            }
            if ($component->getGrader()->getId() !== $this->core->getUser()->getId()) {
                $can_verify = true;
                break;
            }
        }
        $can_verify = $can_verify && $this->core->getAccess()->canI("grading.electronic.verify_grader");

        $this->core->getOutput()->addInternalCss('ta-grading.css');
        $show_hidden = $this->core->getAccess()->canI("autograding.show_hidden_cases", ["gradeable" => $gradeable]);
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'hwGradingPage', $gradeable, $progress, $prev_id, $next_id, $not_in_my_section, $show_hidden, $can_verify);
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'popupStudents');
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'popupNewMark');
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'popupSettings');
    }

    public function saveSingleComponent() {
        $grader_id = $this->core->getUser()->getId();
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);
        $overwrite = $_POST['overwrite'];
        $version_updated = "false"; //if the version is updated

        //find the component
        $component = null;
        foreach ($gradeable->getComponents() as $question) {
            if (is_array($question)) {
                if ($question[0]->getId() == $_POST['gradeable_component_id']) {
                    continue;
                }
                $found = false;
                foreach ($question as $peer) {
                    if ($peer->getGrader() === null) {
                        $component = $peer;
                        $found = true;
                        break;
                    }
                    if ($peer->getGrader()->getId() == $grader_id) {
                        $component = $peer;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $component = $this->core->getQueries()->getGradeableComponents($gradeable->getId())[$question[0]->getId()];
                    $marks = $this->core->getQueries()->getGradeableComponentsMarks($question->getId());
                    $component->setMarks($marks); //I think this does nothing
                }
                break;
            } else if ($question->getId() == $_POST['gradeable_component_id']) {
                $component = $question;
                break;
            }
        }

        //checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_one_component", ["gradeable" => $gradeable, "component" => $component])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }

        //checks if a component has changed, i.e. a mark has been selected or unselected since last time
        //also checks if all the marks are false
        $index = 0;
        $temp_mark_selected = false;
        $all_false = true;
        $debug = "";
        $mark_modified = false;
        foreach ($component->getMarks() as $mark) {
            if (isset($_POST['num_existing_marks'])) {
                if ($index >= $_POST['num_existing_marks']) {
                    break;
                }
            }
            $temp_mark_selected = ($_POST['marks'][$index]['selected'] == 'true') ? true : false;
            if($all_false === true && $temp_mark_selected === true) {
                $all_false = false;
            }
            if($temp_mark_selected !== $mark->getHasMark()) {
                $mark_modified = true;
            }
            $index++;
        }
        for ($i = $index; $i < $_POST['num_mark']; $i++) {
            if ($_POST['marks'][$i]['selected'] == 'true') {
                $all_false = false;
                $mark_modified = true;
                break;
            }
        }

        if($all_false === true) {
            if($_POST['custom_message'] != "" || floatval($_POST['custom_points']) != 0) {
                $all_false = false;
            }
        }

        if($mark_modified === false) {
            if (array_key_exists('custom_message', $_POST) && $component->getComment() != $_POST['custom_message']) {
                $mark_modified = true;
            }
            if (array_key_exists('custom_points', $_POST) && $component->getScore() != $_POST['custom_points']) {
                $mark_modified = true;
            }
        }
        //if no gradeable id exists adds one to the gradeable data
        if($gradeable->getGdId() == null) {
            $gradeable->saveGradeableData();
        }
        if($all_false === true) {
            $component->deleteData($gradeable->getGdId());
            $debug = 'delete';
        } else {
            //only change the component information is the mark was modified or componet and its gradeable are out of sync.
            if ($component->getGrader() === null || $overwrite === "true") {
                $component->setGrader($this->core->getUser());
            }
            $version_updated = "true";
            $component->setGradedVersion($_POST['active_version']);
            $component->setGradeTime(new \DateTime('now', $this->core->getConfig()->getTimezone()));
            $component->setComment($_POST['custom_message']);
            $component->setScore($_POST['custom_points']);
            $debug = $component->saveGradeableComponentData($gradeable->getGdId());
        }

        $index = 0;
        //delete marks that have been deleted
        // save existing marks
        if (array_key_exists('marks', $_POST)) {
            foreach ($_POST['marks'] as $post_mark) {
                if (isset($_POST['num_existing_marks'])) {
                    if ($index >= $_POST['num_existing_marks']) {
                        break;
                    }
                }
                $mark = null;
                foreach ($component->getMarks() as $cmark) {
                    if ($cmark->getId() == $post_mark['id']) {
                        $mark = $cmark;
                        break;
                    }
                }
                if ($mark != null) {
                    $mark->setId($post_mark['id']);
                    $mark->setPoints($post_mark['points']);
                    $mark->setNote($post_mark['note']);
                    $mark->setOrder($post_mark['order']);
                    $mark->setHasMark($post_mark['selected'] == 'true');
                    $mark->save();
                    if ($all_false === false) {
                        $mark->saveGradeableComponentMarkData($gradeable->getGdId(), $component->getId(), $component->getGrader()->getId());
                    }
                    $index++;
                }
            }
        }

        $gradeable->resetUserViewedDate();
        $response = array('status' => 'success', 'modified' => $mark_modified, 'all_false' => $all_false, 'database' => $debug, 'overwrite' => $overwrite, 'version_updated' => $version_updated);
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

    

    public function ajaxGetStudentOutput() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $who_id = $_REQUEST['who_id'];
        $gradeable = $this->fetchGradeable($gradeable_id, $who_id);

        $index = $_REQUEST['index'];

        //Turns off the header and footer so that it isn't displayed in the testcase output
        //Don't re-enable. 
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);

        $return = "";

        $popup_css = "{$this->core->getConfig()->getBaseUrl()}css/diff-viewer.css";
        if($this->core->getAccess()->canI("autograding.load_checks", ["gradeable" => $gradeable])){
            //display hidden testcases only if the user can view the entirety of this gradeable.
            $can_view_hidden = $this->core->getAccess()->canI("autograding.show_hidden_cases", ["gradeable" => $gradeable]);
            $return = $this->core->getOutput()->renderTemplate('AutoGrading', 'loadAutoChecks', $gradeable, $index, $popup_css, $who_id, $can_view_hidden);
        }
        //Returns the html to ajax.
        echo($return);
    }

    public function addOneMark() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);

        if (!$this->core->getAccess()->canI("grading.electronic.add_one_new_mark", ["gradeable" => $gradeable])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return;
        }

        $note = $_POST['note'];
        $points = $_POST['points'];
        foreach ($gradeable->getComponents() as $component) {
            if(is_array($component)) {
                if($component[0]->getId() != $_POST['gradeable_component_id']) {
                    continue;
                }
            } else if ($component->getId() != $_POST['gradeable_component_id']) {
                continue;
            }
            $order_counter = $this->core->getQueries()->getGreatestGradeableComponentMarkOrder($component);
            $order_counter++;
            $mark = new GradeableComponentMark($this->core);
            $mark->setGcId($component->getId());
            $mark->setPoints($points);
            $mark->setNote($note);
            $mark->setOrder($order_counter);
            $id = $mark->create();

            $marks=$component->getMarks();
            array_push($marks, $mark);
            $component->setMarks($marks);

            $response = ["id" => $id];
            $this->core->getOutput()->renderJson($response);
            return;
        }
        $this->core->getOutput()->renderJson(["status" => "failure"]);
        return;
    }
    public function deleteOneMark() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);

        if (!$this->core->getAccess()->canI("grading.electronic.delete_one_mark", ["gradeable" => $gradeable])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return;
        }

        $gcm_id = $_POST['gradeable_component_mark_id'];
        foreach ($gradeable->getComponents() as $component) {
            if ($component->getId() != $_POST['gradeable_component_id']) {
                continue;
            } else {
                foreach ($component->getMarks() as $mark) {
                    if ($mark->getId() == $gcm_id) {
                        $this->core->getQueries()->deleteGradeableComponentMark($mark);
                        $this->core->getOutput()->renderJson(["status" => "success"]);
                        return;
                    }
                }
            }
        }
        $this->core->getOutput()->renderJson(["status" => "failure"]);
        return;
    }
    public function saveGeneralComment() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);

        if (!$this->core->getAccess()->canI("grading.electronic.save_general_comment", ["gradeable" => $gradeable])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return;
        }

        $gradeable->setOverallComment($_POST['gradeable_comment']);
        $gradeable->saveGradeableData();
        $gradeable->resetUserViewedDate();
    }

    public function getMarkDetails() {
        //gets all the details from the database of a mark to readd it to the view
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);

        $component = null;
        foreach ($gradeable->getComponents() as $question) {
            if (is_array($question)) {
                if ($question[0]->getId() != $_POST['gradeable_component_id']) {
                    continue;
                }
                foreach ($question as $cmpt) {
                    if ($cmpt->getGrader() == null) {
                        $component = $cmpt;
                        break;
                    }
                    if ($cmpt->getGrader()->getId() == $this->core->getUser()->getId()) {
                        $component = $cmpt;
                        break;
                    }
                }
                break;
            } else {
                if ($question->getId() != $_POST['gradeable_component_id']) {
                    continue;
                }
                $component = $question;
                break;
            }
        }

        if (!$this->core->getAccess()->canI("grading.electronic.get_mark_data", ["gradeable" => $gradeable, "component" => $component])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }

        $return_data = array();
        foreach ($component->getMarks() as $mark) {
            $temp_array = array();
            $temp_array['id'] = $mark->getId();
            $temp_array['score'] = $mark->getPoints();
            $temp_array['note'] = $mark->getNote();
            $temp_array['has_mark'] = $mark->getHasMark();
            $temp_array['is_publish'] = $mark->getPublish();
            $temp_array['order'] = $mark->getOrder();
            $return_data[] = $temp_array;
        }
        $temp_array = array();
        $temp_array['custom_score'] = $component->getScore();
        $temp_array['custom_note'] = $component->getComment();
        $return_data[] = $temp_array;

        $response = array('status' => 'success', 'data' => $return_data);
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

    public function getGradeableComment() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $this->core->getQueries()->getUserFromAnon($_POST['anon_id'])[$_POST['anon_id']];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);

        if (!$this->core->getAccess()->canI("grading.electronic.get_gradeable_comment", ["gradeable" => $gradeable])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }

        $response = array('status' => 'success', 'data' => $gradeable->getOverallComment());
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

    public function getUsersThatGotTheMark() {
        $gradeable_id = $_POST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);
        $gcm_id = $_POST['gradeable_component_mark_id'];
        if (!$this->core->getAccess()->canI("grading.electronic.get_marked_users", ["gradeable" => $gradeable])) {
            $response = array('status' => 'failure');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }

        $return_data = [];
        $name_info = [];
        foreach ($gradeable->getComponents() as $component) {
            if ($component->getId() != $_POST['gradeable_component_id']) {
                continue;
            } else {
                foreach ($component->getMarks() as $mark) {
                    if ($mark->getId() == $gcm_id) {
                        $return_data = $this->core->getQueries()->getUsersWhoGotMark($component->getId(), $mark, $gradeable->isTeamAssignment());
                        $name_info['question_name'] = $component->getTitle();
                        $name_info['mark_note'] = $mark->getNote();
                    }
                }
            }
        }

        $sections = array();
        $this->getStats($gradeable, $sections);

        $response = array('status' => 'success', 'data' => $return_data, 'sections' => $sections, 'name_info' => $name_info);
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

    private function getStats($gradeable, &$sections, $graders=array(), $total_users=array(), $no_team_users=array(), $graded_components=array()) {
        $gradeable_id = $gradeable->getId();
        if ($gradeable->isGradeByRegistration()) {
            if(!$this->core->getAccess()->canI("grading.electronic.get_marked_users.full_stats")){
                $sections = $this->core->getUser()->getGradingRegistrationSections();
            }
            else {
                $sections = $this->core->getQueries()->getRegistrationSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_registration_id'];
                }
            }
            $section_key='registration_section';
            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
            }
        }
        else {
            if(!$this->core->getAccess()->canI("grading.electronic.get_marked_users.full_stats")){
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_rotating_id'];
                }
            }
            $section_key='rotating_section';
            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable_id, $sections);
            }
        }

        if (count($sections) > 0) {
            if ($gradeable->isTeamAssignment()) {
                $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, $section_key);
                $no_team_users = $this->core->getQueries()->getUsersWithoutTeamByGradingSections($gradeable_id, $sections, $section_key);
                $graded_components = $this->core->getQueries()->getGradedComponentsCountByTeamGradingSections($gradeable_id, $sections, $section_key, true);
            }
            else {
                $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, $section_key);
                $no_team_users = array();
                $graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, $section_key, false);
            }
        }

        $num_components = $this->core->getQueries()->getTotalComponentCount($gradeable_id);
        $sections = array();
        if (count($total_users) > 0) {
            foreach ($total_users as $key => $value) {
                $sections[$key] = array(
                    'total_components' => $value * $num_components,
                    'graded_components' => 0,
                    'graders' => array()
                );
                if ($gradeable->isTeamAssignment()) {
                    $sections[$key]['no_team'] = $no_team_users[$key];
                }
                if (isset($graded_components[$key])) {
                    $sections[$key]['graded_components'] = intval($graded_components[$key]);
                }
                if (isset($graders[$key])) {
                    $sections[$key]['graders'] = $graders[$key];
                }
            }
        }
    }
}



