<?php
session_start();
include 'connection.php';

// Set timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$coordinatorID = $_SESSION['user_id'];

// Fetch coordinator details
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Coordinator Query Prepare Failed: " . $conn->error);
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();
$stmt->close();

if (!$coordinator) {
    error_log("No coordinator found with ID: $coordinatorID");
    die("Error: No coordinator found.");
}

$personalInfo = [
    'full_name' => $coordinator['full_name'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message
$evaluationMessage = "";

// Display session message if set
if (isset($_SESSION['evaluation_message'])) {
    $evaluationMessage = $_SESSION['evaluation_message'];
    unset($_SESSION['evaluation_message']);
}

// Fetch semesters for filter
$semestersQuery = "SELECT semester_name, is_current FROM semesters ORDER BY semester_name DESC"; // Assuming semester_name is like 'May 2025'
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . $conn->error);
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);

// Determine current semester
$currentSemesterName = '';
foreach ($semesters as $sem) {
    if ($sem['is_current']) {
        $currentSemesterName = $sem['semester_name'];
        break;
    }
}
if (!$currentSemesterName && !empty($semesters)) {
    $currentSemesterName = $semesters[0]['semester_name']; // Default to the first semester if none is current
}

// Initialize filter parameters
$selectedSemester = isset($_GET['semester']) && trim($_GET['semester']) !== '' ? trim($_GET['semester']) : $currentSemesterName;
$searchUsername = isset($_GET['username']) ? trim($_GET['username']) : '';
$selectedGroupFilter = isset($_GET['group_name']) ? trim($_GET['group_name']) : '';

// Fetch all groups for filter dropdown
$allGroupsQuery = "SELECT DISTINCT name FROM groups WHERE status = 'Approved' ORDER BY name ASC";
$allGroupsResult = $conn->query($allGroupsQuery) or die("Error in all groups query: " . $conn->error);
$filterGroups = $allGroupsResult->fetch_all(MYSQLI_ASSOC);

// Fetch all students with group info based on filters
$studentsQueryParts = [
    "SELECT s.id, s.full_name AS name, g.id AS group_id, g.name AS group_name",
    "FROM students s",
    "LEFT JOIN group_members gm ON s.id = gm.student_id",
    "LEFT JOIN groups g ON gm.group_id = g.id",
    "WHERE (g.status = 'Approved' OR g.id IS NULL)"
];
$studentsParams = [];
$studentsParamTypes = "";

if ($selectedSemester) {
    $studentsQueryParts[] = "AND CONCAT(s.intake_month, ' ', s.intake_year) = ?";
    $studentsParams[] = $selectedSemester;
    $studentsParamTypes .= "s";
}
if ($searchUsername) {
    $studentsQueryParts[] = "AND s.username LIKE ?";
    $studentsParams[] = "%$searchUsername%";
    $studentsParamTypes .= "s";
}
if ($selectedGroupFilter) {
    $studentsQueryParts[] = "AND g.name = ?";
    $studentsParams[] = $selectedGroupFilter;
    $studentsParamTypes .= "s";
}
$studentsQueryParts[] = "ORDER BY s.full_name ASC";
$studentsQuery = implode(" ", $studentsQueryParts);

$stmtStudents = $conn->prepare($studentsQuery);
if ($stmtStudents === false) {
    die("Prepare failed for students query: " . $conn->error);
}
if (!empty($studentsParams)) {
    $stmtStudents->bind_param($studentsParamTypes, ...$studentsParams);
}
$stmtStudents->execute();
$studentsResult = $stmtStudents->get_result();
$students = $studentsResult->fetch_all(MYSQLI_ASSOC);
$stmtStudents->close();

// Fetch groups with unevaluated group deliverables based on filters
$groupsQueryParts = [
    "SELECT g.id AS group_id, g.name AS group_name",
    "FROM groups g",
    "WHERE g.status = 'Approved'",
    "AND EXISTS (",
    "    SELECT 1 FROM deliverable_submissions ds",
    "    JOIN deliverables d ON ds.deliverable_id = d.id",
    "    WHERE ds.group_id = g.id",
    "    AND d.submission_type = 'group'",
    "    AND ds.submitted_at IS NOT NULL"
];
$groupsParams = [];
$groupsParamTypes = "";

if ($selectedSemester) {
    $groupsQueryParts[] = "    AND d.semester = ?";
    $groupsParams[] = $selectedSemester;
    $groupsParamTypes .= "s";
}

$groupsQueryParts[] = "    AND NOT EXISTS (";
$groupsQueryParts[] = "        SELECT 1 FROM group_evaluations ge";
$groupsQueryParts[] = "        WHERE ge.deliverable_id = ds.deliverable_id";
$groupsQueryParts[] = "        AND ge.group_id = g.id";
$groupsQueryParts[] = "        AND ge.coordinator_id = ?";
$groupsParams[] = $coordinatorID;
$groupsParamTypes .= "i";
$groupsQueryParts[] = "        AND ge.supervisor_id IS NULL";
$groupsQueryParts[] = "        AND ge.assessor_id IS NULL";
$groupsQueryParts[] = "        AND ge.type = 'Group'";
$groupsQueryParts[] = "    )";
$groupsQueryParts[] = ")";

if ($selectedGroupFilter) { // Filter group list by name if selectedGroupFilter is applied
    $groupsQueryParts[] = "AND g.name = ?";
    $groupsParams[] = $selectedGroupFilter;
    $groupsParamTypes .= "s";
}

$groupsQuery = implode(" ", $groupsQueryParts);
$stmtGroups = $conn->prepare($groupsQuery);
if ($stmtGroups === false) {
    die("Prepare failed for groups query: " . $conn->error . " Query: " . $groupsQuery);
}
$stmtGroups->bind_param($groupsParamTypes, ...$groupsParams);
$stmtGroups->execute();
$groupsResult = $stmtGroups->get_result();
$groupsWithGroupDeliverables = $groupsResult->fetch_all(MYSQLI_ASSOC);
$stmtGroups->close();

// Check for evaluation type and parameters
$evalType = isset($_GET['eval_type']) && $_GET['eval_type'] === 'group' ? 'group' : 'individual';
$isGroupEvaluation = $evalType === 'group';
$selectedGroupId = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
$selectedStudentId = $isGroupEvaluation ? 0 : (isset($_GET['student_id']) ? intval($_GET['student_id']) : 0);

// Initialize variables for modal
$selectedStudentName = '';
$selectedGroupName = '';
$submissions = [];
$evaluationMarks = [];
$rubrics = [];
$scoreRanges = [];
$rubricsData = [];

if ($isGroupEvaluation && $selectedGroupId) {
    // Fetch group name
    $stmt = $conn->prepare("SELECT name FROM groups WHERE id = ?");
    $stmt->bind_param("i", $selectedGroupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $selectedGroupName = $group['name'] ?? 'Not Assigned';
    $stmt->close();

    // Fetch unevaluated group submissions
    $submissionsQuery = "
        SELECT ds.id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.deliverable_id, d.weightage 
        FROM deliverable_submissions ds 
        JOIN deliverables d ON ds.deliverable_id = d.id 
        WHERE ds.submitted_at IS NOT NULL 
          AND ds.group_id = ? 
          AND d.submission_type = 'group'
          AND d.semester = ? 
          AND NOT EXISTS (
              SELECT 1 FROM group_evaluations ge 
              WHERE ge.deliverable_id = ds.deliverable_id 
                AND ge.group_id = ? 
                AND ge.coordinator_id = ? 
                AND ge.supervisor_id IS NULL 
                AND ge.assessor_id IS NULL 
                AND ge.type = 'Group'
          )";
    $stmt = $conn->prepare($submissionsQuery);
    if ($stmt) {
        $stmt->bind_param("isii", $selectedGroupId, $selectedSemester, $selectedGroupId, $coordinatorID);
        $stmt->execute();
        $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        error_log("Group Submissions Fetched: " . json_encode($submissions));
        $stmt->close();
    } else {
        error_log("Group Submissions Query Prepare Failed: " . $conn->error);
    }

    // Fetch all group deliverables (submitted or not) for the submissions table
    $allDeliverablesQuery = "
        SELECT d.id AS deliverable_id, d.name AS deliverable_name, d.submission_type, d.weightage,
               ds.id AS submission_id, ds.file_path, ds.submitted_at, 
               ge.evaluation_grade, ge.feedback
        FROM deliverables d 
        LEFT JOIN deliverable_submissions ds ON d.id = ds.deliverable_id 
            AND ds.group_id = ? 
            AND d.submission_type = 'group'
        LEFT JOIN group_evaluations ge ON d.id = ge.deliverable_id 
            AND ge.group_id = ? 
            AND ge.coordinator_id = ? 
            AND ge.supervisor_id IS NULL 
            AND ge.assessor_id IS NULL 
            AND ge.type = 'Group'
        WHERE d.semester = ?";
    $stmt = $conn->prepare($allDeliverablesQuery);
    if ($stmt) {
        $stmt->bind_param("iiis", $selectedGroupId, $selectedGroupId, $coordinatorID, $selectedSemester);
        $stmt->execute();
        $marksResult = $stmt->get_result();
        while ($row = $marksResult->fetch_assoc()) {
            $evaluationMarks[] = [
                'deliverable_id' => $row['deliverable_id'],
                'deliverable_name' => $row['deliverable_name'],
                'submission_type' => $row['submission_type'], // Added submission_type
                'file_path' => $row['file_path'] ?? 'N/A',
                'submitted_at' => $row['submitted_at'],
                'evaluation_grade' => $row['evaluation_grade'] !== null ? number_format($row['evaluation_grade'], 2) . '%' : 'Not Evaluated',
                'feedback' => $row['feedback'] ?? 'N/A',
                'submission_id' => $row['submission_id']
            ];
        }
        error_log("All Group Deliverables: " . json_encode($evaluationMarks));
        $stmt->close();
    } else {
        error_log("All Group Deliverables Query Prepare Failed: " . $conn->error);
    }
} elseif ($selectedStudentId && $selectedGroupId) {
    // Fetch student name
    $stmt = $conn->prepare("SELECT full_name FROM students WHERE id = ?");
    $stmt->bind_param("i", $selectedStudentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $selectedStudentName = $student['full_name'] ?? 'Unknown';
    $stmt->close();

    // Fetch group name
    $stmt = $conn->prepare("SELECT name FROM groups WHERE id = ?");
    $stmt->bind_param("i", $selectedGroupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $selectedGroupName = $group['name'] ?? 'Not Assigned';
    $stmt->close();

    // Fetch unevaluated submissions for dropdown (only individual submissions)
    $submissionsQuery = "
        SELECT ds.id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.deliverable_id, d.weightage 
        FROM deliverable_submissions ds 
        JOIN deliverables d ON ds.deliverable_id = d.id 
        WHERE ds.submitted_at IS NOT NULL 
          AND ds.group_id = ? 
          AND d.submission_type = 'individual'
          AND ds.student_id = ?
          AND d.semester = ? 
          AND NOT EXISTS (
              SELECT 1 FROM evaluation e 
              WHERE e.deliverable_id = ds.deliverable_id 
                AND e.student_id = ? 
                AND e.coordinator_id = ? 
                AND e.supervisor_id IS NULL 
                AND e.assessor_id IS NULL 
                AND e.type = 'Individual'
          )";
    $stmt = $conn->prepare($submissionsQuery);
    if ($stmt) {
        $stmt->bind_param("iisii", $selectedGroupId, $selectedStudentId, $selectedSemester, $selectedStudentId, $coordinatorID);
        $stmt->execute();
        $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        error_log("Submissions Fetched: " . json_encode($submissions));
        $stmt->close();
    } else {
        error_log("Submissions Query Prepare Failed: " . $conn->error);
    }

    // Fetch all deliverables (submitted or not) for the submissions table
    $allDeliverablesQuery = "
        SELECT d.id AS deliverable_id, d.name AS deliverable_name, d.submission_type, d.weightage,
               ds.id AS submission_id, ds.file_path, ds.submitted_at, 
               e.evaluation_grade, e.feedback
        FROM deliverables d 
        LEFT JOIN deliverable_submissions ds ON d.id = ds.deliverable_id 
            AND ds.group_id = ? 
            AND (d.submission_type = 'group' OR ds.student_id = ?)
        LEFT JOIN evaluation e ON d.id = e.deliverable_id 
            AND e.student_id = ? 
            AND e.coordinator_id = ? 
            AND e.supervisor_id IS NULL 
            AND e.assessor_id IS NULL 
            AND e.type = 'Individual'
        WHERE d.semester = ?";
    $stmt = $conn->prepare($allDeliverablesQuery);
    if ($stmt) {
        $stmt->bind_param("iiiis", $selectedGroupId, $selectedStudentId, $selectedStudentId, $coordinatorID, $selectedSemester);
        $stmt->execute();
        $marksResult = $stmt->get_result();
        while ($row = $marksResult->fetch_assoc()) {
            $evaluationMarks[] = [
                'deliverable_id' => $row['deliverable_id'],
                'deliverable_name' => $row['deliverable_name'],
                'submission_type' => $row['submission_type'], // Added submission_type
                'file_path' => $row['file_path'] ?? 'N/A',
                'submitted_at' => $row['submitted_at'],
                'evaluation_grade' => $row['evaluation_grade'] !== null ? number_format($row['evaluation_grade'], 2) . '%' : 'Not Evaluated',
                'feedback' => $row['feedback'] ?? 'N/A',
                'submission_id' => $row['submission_id']
            ];
        }
        error_log("All Deliverables: " . json_encode($evaluationMarks));
        $stmt->close();
    } else {
        error_log("All Deliverables Query Prepare Failed: " . $conn->error);
    }
}

// Fetch rubrics and score ranges for submitted deliverables
foreach ($submissions as $submission) {
    $deliverableId = $submission['deliverable_id'];
    $rubricsQuery = "SELECT id, criteria, component, max_score FROM rubrics WHERE deliverable_id = ?";
    $stmt = $conn->prepare($rubricsQuery);
    $stmt->bind_param("i", $deliverableId);
    $stmt->execute();
    $rubricsResult = $stmt->get_result();
    while ($rubric = $rubricsResult->fetch_assoc()) {
        $rubrics[$deliverableId][] = $rubric;
    }
    $stmt->close();

    $scoreRangesQuery = "SELECT rubric_id, score_range, description 
                         FROM rubric_score_ranges 
                         WHERE rubric_id IN (SELECT id FROM rubrics WHERE deliverable_id = ?) 
                         ORDER BY rubric_id, FIELD(score_range, '0-2', '3-4', '5-6', '7-8', '9-10')";
    $stmt = $conn->prepare($scoreRangesQuery);
    $stmt->bind_param("i", $deliverableId);
    $stmt->execute();
    $scoreRangesResult = $stmt->get_result();
    while ($range = $scoreRangesResult->fetch_assoc()) {
        $scoreRanges[$range['rubric_id']][$range['score_range']] = $range['description'];
    }
    $stmt->close();
}

// Prepare rubrics data for JavaScript
foreach ($rubrics as $deliverableId => $rubricList) {
    foreach ($rubricList as $rubric) {
        $rubricsData[$deliverableId][] = [
            'id' => $rubric['id'],
            'criteria' => $rubric['criteria'],
            'component' => $rubric['component'],
            'max_score' => $rubric['max_score'],
            'score_ranges' => $scoreRanges[$rubric['id']] ?? []
        ];
    }
}

// Handle evaluation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $deliverableSubmissionId = intval($_POST['deliverable_id']);
    $selectedStudentId = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    $selectedGroupId = intval($_POST['group_id']);
    $feedback = trim($_POST['feedback']);
    $evaluationDate = date('Y-m-d');
    $rubricScores = $_POST['rubric_scores'] ?? [];
    $evalType = $_POST['eval_type'] ?? 'individual';

    error_log("Submission Attempt: deliverable_id=$deliverableSubmissionId, student_id=$selectedStudentId, group_id=$selectedGroupId, eval_type=$evalType");

    if ($evalType === 'group') {
        // Validate group deliverable
        $validateQuery = "
            SELECT ds.deliverable_id, d.submission_type 
            FROM deliverable_submissions ds 
            JOIN deliverables d ON ds.deliverable_id = d.id 
            WHERE ds.id = ? 
              AND ds.group_id = ? 
              AND d.submission_type = 'group'
              AND ds.submitted_at IS NOT NULL 
              AND NOT EXISTS (
                  SELECT 1 FROM group_evaluations ge 
                  WHERE ge.deliverable_id = ds.deliverable_id 
                    AND ge.group_id = ? 
                    AND ge.coordinator_id = ? 
                    AND ge.supervisor_id IS NULL 
                    AND ge.assessor_id IS NULL 
                    AND ge.type = 'Group'
              )";
        $stmt = $conn->prepare($validateQuery);
        $stmt->bind_param("iiii", $deliverableSubmissionId, $selectedGroupId, $selectedGroupId, $coordinatorID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $evaluationMessage = "Error: Invalid or already evaluated group deliverable.";
            error_log("Group Validation Failed: No rows returned or already evaluated");
        } else {
            $deliverable = $result->fetch_assoc();
            $deliverableId = $deliverable['deliverable_id'];
            $stmt->close();

            // Fetch weightage
            $stmt = $conn->prepare("SELECT weightage FROM deliverables WHERE id = ?");
            $stmt->bind_param("i", $deliverableId);
            $stmt->execute();
            $result = $stmt->get_result();
            $deliverableWeight = $result->num_rows > 0 ? floatval($result->fetch_assoc()['weightage']) / 100 : 1.0;
            $stmt->close();

            // Validate rubric scores
            $totalGrade = 0;
            $validScores = true;
            $rubricsQuery = "SELECT id, criteria, max_score FROM rubrics WHERE deliverable_id = ?";
            $stmt = $conn->prepare($rubricsQuery);
            $stmt->bind_param("i", $deliverableId);
            $stmt->execute();
            $deliverableRubrics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $numRubrics = count($deliverableRubrics);
            $rubricWeight = $numRubrics > 0 ? 1.0 / $numRubrics : 1.0;

            foreach ($deliverableRubrics as $rubric) {
                $rubricId = $rubric['id'];
                if (!isset($rubricScores[$rubricId])) {
                    $validScores = false;
                    $evaluationMessage = "Error: Missing score for " . htmlspecialchars($rubric['criteria']) . ".";
                    error_log("Missing Score for Rubric ID: $rubricId");
                    break;
                }
                $score = intval($rubricScores[$rubricId]);
                $maxScore = intval($rubric['max_score']) ?: 10;
                if ($score > $maxScore || $score < 0) {
                    $validScores = false;
                    $evaluationMessage = "Error: Invalid score for " . htmlspecialchars($rubric['criteria']) . " (0–$maxScore).";
                    error_log("Invalid Score for Rubric ID: $rubricId, Score: $score, Max: $maxScore");
                    break;
                }
                $normalizedScore = ($score / $maxScore) * 100 * $rubricWeight * $deliverableWeight;
                $totalGrade += $normalizedScore;
            }

            if ($validScores) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO group_evaluations (group_id, coordinator_id, deliverable_id, evaluation_grade, feedback, type, date) 
                        VALUES (?, ?, ?, ?, ?, 'Group', ?)");
                    $stmt->bind_param("iiidss", $selectedGroupId, $coordinatorID, $deliverableId, $totalGrade, $feedback, $evaluationDate);
                    $stmt->execute();
                    $groupEvaluationId = $conn->insert_id;
                    $stmt->close();

                    foreach ($rubricScores as $rubricId => $score) {
                        $stmt = $conn->prepare("
                            INSERT INTO group_evaluation_rubric_scores (group_evaluation_id, rubric_id, score) 
                            VALUES (?, ?, ?)");
                        $stmt->bind_param("iii", $groupEvaluationId, $rubricId, $score);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $conn->commit();
                    $evaluationMessage = "Group evaluation submitted successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $evaluationMessage = "Error submitting group evaluation: " . $e->getMessage();
                    error_log("Group Transaction Rolled Back: " . $e->getMessage());
                }
            }
        }
    } else {
        // Validate deliverable for individual evaluation
        $validateQuery = "
            SELECT ds.deliverable_id, d.submission_type 
            FROM deliverable_submissions ds 
            JOIN deliverables d ON ds.deliverable_id = d.id 
            WHERE ds.id = ? 
              AND ds.group_id = ? 
              AND d.submission_type = 'individual'
              AND ds.student_id = ?
              AND ds.submitted_at IS NOT NULL 
              AND NOT EXISTS (
                  SELECT 1 FROM evaluation e 
                  WHERE e.deliverable_id = ds.deliverable_id 
                    AND e.student_id = ? 
                    AND e.coordinator_id = ? 
                    AND e.supervisor_id IS NULL 
                    AND e.assessor_id IS NULL 
                    AND e.type = 'Individual'
              )";
        $stmt = $conn->prepare($validateQuery);
        $stmt->bind_param("iiiii", $deliverableSubmissionId, $selectedGroupId, $selectedStudentId, $selectedStudentId, $coordinatorID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $evaluationMessage = "Error: Invalid or already evaluated deliverable.";
            error_log("Validation Failed: No rows returned or already evaluated");
        } else {
            $deliverable = $result->fetch_assoc();
            $deliverableId = $deliverable['deliverable_id'];
            $stmt->close();

            // Fetch weightage
            $stmt = $conn->prepare("SELECT weightage FROM deliverables WHERE id = ?");
            $stmt->bind_param("i", $deliverableId);
            $stmt->execute();
            $result = $stmt->get_result();
            $deliverableWeight = $result->num_rows > 0 ? floatval($result->fetch_assoc()['weightage']) / 100 : 1.0;
            $stmt->close();

            // Validate rubric scores
            $totalGrade = 0;
            $validScores = true;
            $rubricsQuery = "SELECT id, criteria, max_score FROM rubrics WHERE deliverable_id = ?";
            $stmt = $conn->prepare($rubricsQuery);
            $stmt->bind_param("i", $deliverableId);
            $stmt->execute();
            $deliverableRubrics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $numRubrics = count($deliverableRubrics);
            $rubricWeight = $numRubrics > 0 ? 1.0 / $numRubrics : 1.0;

            foreach ($deliverableRubrics as $rubric) {
                $rubricId = $rubric['id'];
                if (!isset($rubricScores[$rubricId])) {
                    $validScores = false;
                    $evaluationMessage = "Error: Missing score for " . htmlspecialchars($rubric['criteria']) . ".";
                    error_log("Missing Score for Rubric ID: $rubricId");
                    break;
                }
                $score = intval($rubricScores[$rubricId]);
                $maxScore = intval($rubric['max_score']) ?: 10;
                if ($score > $maxScore || $score < 0) {
                    $validScores = false;
                    $evaluationMessage = "Error: Invalid score for " . htmlspecialchars($rubric['criteria']) . " (0–$maxScore).";
                    error_log("Invalid Score for Rubric ID: $rubricId, Score: $score, Max: $maxScore");
                    break;
                }
                $normalizedScore = ($score / $maxScore) * 100 * $rubricWeight * $deliverableWeight;
                $totalGrade += $normalizedScore;
            }

            if ($validScores) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO evaluation (student_id, coordinator_id, deliverable_id, evaluation_grade, feedback, type, date) 
                        VALUES (?, ?, ?, ?, ?, 'Individual', ?)");
                    $stmt->bind_param("iiidss", $selectedStudentId, $coordinatorID, $deliverableId, $totalGrade, $feedback, $evaluationDate);
                    $stmt->execute();
                    $evaluationId = $conn->insert_id;
                    $stmt->close();

                    foreach ($rubricScores as $rubricId => $score) {
                        $stmt = $conn->prepare("
                            INSERT INTO evaluation_rubric_scores (evaluation_id, rubric_id, score) 
                            VALUES (?, ?, ?)");
                        $stmt->bind_param("iii", $evaluationId, $rubricId, $score);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $conn->commit();
                    $evaluationMessage = "Evaluation submitted successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $evaluationMessage = "Error submitting evaluation: " . $e->getMessage();
                    error_log("Transaction Rolled Back: " . $e->getMessage());
                }
            }
        }
    }
    $_SESSION['evaluation_message'] = $evaluationMessage;
    header("Location: coorevaluatestudent.php?semester=" . urlencode($selectedSemester) . "&username=" . urlencode($searchUsername) . "&group_name=" . urlencode($selectedGroupFilter));
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Coordinator - Evaluate Student</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .evaluation-form label { font-weight: bold; }
        .confirmation-text { font-weight: bold; color: #007bff; }
        .submission-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .submission-table th, .submission-table td { 
            border: 1px solid #dee2e6; 
            padding: 12px; 
            text-align: left; 
            vertical-align: middle; 
        }
        .submission-table th { background-color: #f8f9fa; font-weight: bold; }
        .submission-table tr:nth-child(even) { background-color: #f9f9f9; }
        .submission-table tr:hover { background-color: #f1f1f1; }
        .rubric-scoring-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .rubric-scoring-table th, .rubric-scoring-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            height: 40px; 
        }
        .rubric-scoring-table th { background-color: #f8f9fa; text-align: left; }
        .rubric-scoring-table th:first-child { text-align: center; }
        .rubric-scoring-table .criteria-row { background-color: #E6F0FA; font-weight: bold; }
        .rubric-scoring-table .component-row td:first-child { text-align: center; }
        .rubric-scoring-table .score-cell { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 0 10px; 
        }
        .rubric-scoring-table input[type="radio"] { transform: scale(1.2); margin-right: 5px; }
        .rubric-scoring-table .score-option { 
            flex: 1; 
            text-align: center; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .rubric-scoring-table .total-score-row, .rubric-scoring-table .final-score-row { 
            background-color: #EDE9FE; 
            font-weight: bold; 
        }
        .info-icon { 
            cursor: pointer; 
            color: #007bff; 
            margin-left: 10px; 
        }
        #evaluationModal .modal-content { border-radius: 0.35rem; }
        #evaluationModal .modal-body { max-height: 75vh; overflow-y: auto; }
        #evaluationModal .modal-dialog { 
            max-width: 1500px;
            margin: 1.75rem auto;
        }
        #evaluationModal .modal-content { 
            min-height: 850px;
        }
        .score-range-table { width: 100%; border-collapse: collapse; }
        .score-range-table th, .score-range-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        .score-range-table th { background-color: #f8f9fa; }
        .evaluate-btn { font-size: 0.9rem; padding: 5px 10px; }
        .table { border: 1px solid #dee2e6; }
        .table th, .table td { vertical-align: middle; }
        .modal {
            display: none;
        }
        .modal.fade .modal-dialog {
            transition: transform 0.3s ease-out;
            transform: translate(0, -50px);
            opacity: 1;
        }
        .modal.show .modal-dialog {
            transform: translate(0, 0);
            opacity: 1;
        }
        .modal.fade .modal-backdrop {
            transition: opacity 0.3s ease-out;
            opacity: 0;
        }
        .modal.show .modal-backdrop {
            opacity: 0.5;
        }
    </style>
</head>
<body id="page-top">
    <div id="wrapper">
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="coordinatordashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="coordinatordashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Coordinator Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student <br>Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors & <br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                    </div>
                </div>
            </li>
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse show" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">FYP Evaluation:</h6>
                        <a class="collapse-item" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item" href="coorviewstudentdetails.php">View Student Details</a>
                        <a class="collapse-item" href="coormanagerubrics.php">Manage Rubrics</a>
                        <a class="collapse-item" href="coorassignassessment.php">Assign Assessment</a>
                        <a class="collapse-item active" href="coorevaluatestudent.php">Evaluate Students</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Resources & Communication</span>
                </a>
                <div id="collapsePages" class="collapse" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Support Tools:</h6>
                        <a class="collapse-item" href="coormanageannouncement.php">Manage Announcements</a>
                        <a class="collapse-item" href="coormanageteachingmaterials.php">Manage Teaching <br>Materials</a>
                        <!-- <a class="collapse-item" href="coorsetsemester.php">Manage Semester</a> -->
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider d-none d-md-block">
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>
        </ul>
        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in">
                                <a class="dropdown-item" href="coorprofile.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Profile
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Logout
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Evaluate Students</h1>
                    </div>
                    <?php if ($evaluationMessage): ?>
                        <div class="alert alert-info"><?= htmlspecialchars($evaluationMessage) ?></div>
                    <?php endif; ?>

                    <!-- Filter Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Evaluations</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <!-- Semester Filter -->
                                    <div class="col-md-4 mb-3">
                                        <label for="semester">Semester</label>
                                        <select class="form-control" id="semester" name="semester" required>
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?= htmlspecialchars($semester['semester_name']) ?>" 
                                                        <?= $selectedSemester === $semester['semester_name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($semester['semester_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Student Name Search -->
                                    <div class="col-md-4 mb-3">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?= htmlspecialchars($searchUsername) ?>" placeholder="Enter student username">
                                    </div>
                                    <!-- Group Name Filter -->
                                    <div class="col-md-4 mb-3">
                                        <label for="group_name">Group</label>
                                        <select class="form-control" id="group_name" name="group_name">
                                            <option value="">-- All Groups --</option>
                                            <?php foreach ($filterGroups as $group): ?>
                                                <option value="<?= htmlspecialchars($group['name']) ?>" 
                                                        <?= $selectedGroupFilter === $group['name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="coorevaluatestudent.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Student List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Full Name</th>
                                            <th>Group Name</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($students)): ?>
                                            <?php foreach ($students as $student): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($student['name']) ?></td>
                                                    <td><?= htmlspecialchars($student['group_name'] ?? 'Not Assigned') ?></td>
                                                    <td>
                                                        <a href="coorevaluatestudent.php?student_id=<?= $student['id'] ?>&group_id=<?= $student['group_id'] ?? 0 ?>&semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroupFilter) ?>" 
                                                           class="btn btn-primary btn-sm evaluate-btn">
                                                            Evaluate Individual
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No students found matching your criteria.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Group List for Evaluation</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="groupTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Group Name</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($groupsWithGroupDeliverables)): ?>
                                            <?php foreach ($groupsWithGroupDeliverables as $group): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($group['group_name']) ?></td>
                                                    <td>
                                                        <a href="coorevaluatestudent.php?group_id=<?= $group['group_id'] ?>&eval_type=group&semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroupFilter) ?>" 
                                                           class="btn btn-primary btn-sm evaluate-btn">
                                                            Evaluate Group
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="2" class="text-center">No groups with unevaluated group deliverables found matching your criteria.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Evaluation Modal -->
            <div class="modal fade" id="evaluationModal" tabindex="-1" role="dialog" aria-labelledby="evaluationModalLabel">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="evaluationModalLabel">
                                <?php if ($isGroupEvaluation): ?>
                                    Evaluate Group: <span class="confirmation-text"><?= htmlspecialchars($selectedGroupName) ?></span>
                                <?php else: ?>
                                    Evaluate Student: <span class="confirmation-text"><?= htmlspecialchars($selectedStudentName) ?></span>
                                <?php endif; ?>
                            </h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <h6>Submissions</h6>
                            <table class="table table-bordered submission-table" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>Deliverable Name</th>
                                        <th>Submission Type</th> <!-- Added Submission Type column -->
                                        <th>File Path</th>
                                        <th>Submission Date</th>
                                        <th>Grade</th>
                                        <th>Feedback</th>
                                    </tr>
                                </thead>
                                <tbody id="modal-submissions">
                                    <?php if (empty($evaluationMarks)): ?>
                                        <tr>
                                            <td colspan="6" class="text-left">No submissions available.</td> <!-- Updated colspan to 6 -->
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($evaluationMarks as $mark): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($mark['deliverable_name']) ?></td>
                                                <td><?= htmlspecialchars(ucfirst($mark['submission_type'])) ?></td> <!-- Display submission type -->
                                                <td>
                                                    <?php if ($mark['file_path'] !== 'N/A'): ?>
                                                        <a href="<?= htmlspecialchars($mark['file_path']) ?>" target="_blank">View</a>
                                                    <?php else: ?>
                                                        Not Submitted
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $mark['submitted_at'] ? date('Y-m-d H:i:s', strtotime($mark['submitted_at'])) : 'N/A' ?></td>
                                                <td><?= htmlspecialchars($mark['evaluation_grade']) ?></td>
                                                <td><?= htmlspecialchars($mark['feedback']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div class="evaluation-form mt-4">
                                <form method="POST" action="coorevaluatestudent.php" id="evaluationForm">
                                    <div class="form-group">
                                        <label for="deliverableId">Select Deliverable</label>
                                        <select class="form-control" id="deliverableId" name="deliverable_id" onchange="updateRubricScoring(this)" required>
                                            <option value="">Select a Deliverable</option>
                                            <?php foreach ($submissions as $submission): ?>
                                                <option value="<?= $submission['id'] ?>" 
                                                        data-deliverable-id="<?= $submission['deliverable_id'] ?>" 
                                                        data-weightage="<?= $submission['weightage'] / 100 ?>">
                                                    <?= htmlspecialchars($submission['deliverable_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" id="rubricScoringContainer">
                                        <label>Rubric Scores</label>
                                        <p>Please select a deliverable to view rubric scoring options.</p>
                                    </div>
                                    <div class="form-group">
                                        <label for="feedback">Feedback</label>
                                        <textarea class="form-control" id="feedback" name="feedback" rows="4" required></textarea>
                                    </div>
                                    <input type="hidden" id="modal-student-id" name="student_id" value="<?= $selectedStudentId ?>">
                                    <input type="hidden" id="modal-group-id" name="group_id" value="<?= $selectedGroupId ?>">
                                    <input type="hidden" name="eval_type" value="<?= $evalType ?>">
                                    <input type="hidden" name="semester" value="<?= htmlspecialchars($selectedSemester) ?>">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($searchUsername) ?>">
                                    <input type="hidden" name="group_name" value="<?= htmlspecialchars($selectedGroupFilter) ?>">
                                    <button type="submit" name="submit_evaluation" class="btn btn-primary">Submit Evaluation</button>
                                    <a href="coorevaluatestudent.php?semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroupFilter) ?>" class="btn btn-secondary">Cancel</a>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Rubric Description Modal -->
            <div class="modal fade" id="rubricDescriptionModal" tabindex="-1" role="dialog" aria-labelledby="rubricDescriptionModalLabel">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="rubricDescriptionModalLabel">Rubric Score Descriptions</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <h6 id="modalCriteria"></h6>
                            <p><strong>Component:</strong> <span id="modalComponent"></span></p>
                            <table class="score-range-table">
                                <thead>
                                    <tr>
                                        <th>Score Range</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody id="modalScoreRanges"></tbody>
                            </table>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright © FYPCollabor8 2025</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                    <a class="btn btn-primary" href="index.html">Logout</a>
                </div>
            </div>
        </div>
    </div>
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="js/demo/datatables-demo.js"></script>
    <script>
        // Rubrics data from PHP
        const rubricsData = <?= json_encode($rubricsData) ?>;

        function updateRubricScoring(select) {
            const selectedOption = select.options[select.selectedIndex];
            const deliverableId = selectedOption ? selectedOption.dataset.deliverableId : null;
            const weightage = selectedOption ? parseFloat(selectedOption.dataset.weightage) || 1.0 : 1.0;
            const container = document.getElementById('rubricScoringContainer');
            container.innerHTML = '<label>Rubric Scores</label>';
            if (!deliverableId || !rubricsData[deliverableId]) {
                container.innerHTML += '<p>No rubrics available for this deliverable.</p>';
                return;
            }
            const deliverableRubrics = rubricsData[deliverableId];
            let maxTotalRawScore = 0;
            deliverableRubrics.forEach(rubric => {
                maxTotalRawScore += parseInt(rubric.max_score) || 10;
            });
            let tableHtml = `
                <table class="rubric-scoring-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Component</th>
                            <th>Score (1-10)</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            deliverableRubrics.forEach((rubric, index) => {
                const maxScore = parseInt(rubric.max_score) || 10;
                tableHtml += `
                    <tr class="criteria-row">
                        <td colspan="3">
                            ${rubric.criteria}
                            <i class="fas fa-info-circle info-icon" data-rubric-id="${rubric.id}" data-deliverable-id="${deliverableId}" title="View score descriptions"></i>
                        </td>
                    </tr>
                    <tr class="component-row">
                        <td>${index + 1}</td>
                        <td>${rubric.component || 'N/A'}</td>
                        <td class="score-cell">
                `;
                for (let i = 1; i <= maxScore; i++) {
                    tableHtml += `
                        <span class="score-option">
                            <input type="radio" name="rubric_scores[${rubric.id}]" value="${i}" class="rubric-score" data-max-score="${maxScore}" required>
                            ${i}
                        </span>
                    `;
                }
                tableHtml += `</td></tr>`;
            });
            tableHtml += `
                <tr class="total-score-row">
                    <td colspan="2">Total Score</td>
                    <td id="totalRawScore">0/${maxTotalRawScore}</td>
                </tr>
                <tr class="final-score-row">
                    <td colspan="2">Final Score (with ${weightage * 100}% weightage)</td>
                    <td id="finalScore">0.00%</td>
                </tr>
                </tbody>
                </table>
            `;
            container.innerHTML += tableHtml;
        }

        function showRubricDescriptions(deliverableId, rubricId) {
            const rubric = rubricsData[deliverableId]?.find(r => r.id == rubricId);
            if (!rubric) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Rubric data not found.'
                });
                return;
            }
            $('#modalCriteria').text(rubric.criteria);
            $('#modalComponent').text(rubric.component || 'N/A');
            const scoreRanges = rubric.score_ranges || {};
            let rangesHtml = '';
            const fixedRanges = ['0-2', '3-4', '5-6', '7-8', '9-10'];
            fixedRanges.forEach(range => {
                rangesHtml += `
                    <tr>
                        <td>${range}</td>
                        <td>${scoreRanges[range] || 'No description available'}</td>
                    </tr>
                `;
            });
            $('#modalScoreRanges').html(rangesHtml);
            $('#rubricDescriptionModal').modal('show');
        }

        $(document).ready(function() {
            // Initialize DataTables for group table
            $('#groupTable').DataTable();

            // Handle rubric score changes
            $(document).on('change', '.rubric-score', function() {
                let totalRawScore = 0;
                let totalGrade = 0;
                const deliverableId = $('#deliverableId').find(':selected').data('deliverable-id');
                const weightage = parseFloat($('#deliverableId').find(':selected').data('weightage')) || 1.0;
                const numRubrics = rubricsData[deliverableId]?.length || 1;
                const rubricWeight = numRubrics > 0 ? 1.0 / numRubrics : 1.0;
                let maxTotalRawScore = 0;
                if (rubricsData[deliverableId]) {
                    rubricsData[deliverableId].forEach(rubric => {
                        maxTotalRawScore += parseInt(rubric.max_score) || 10;
                    });
                }
                $('.rubric-score:checked').each(function() {
                    const score = parseInt($(this).val());
                    const maxScore = parseInt($(this).data('max-score')) || 10;
                    totalRawScore += score;
                    const normalizedScore = maxScore > 0 ? (score / maxScore) * 100 * rubricWeight * weightage : 0;
                    totalGrade += normalizedScore;
                });
                $('#totalRawScore').text(`${totalRawScore}/${maxTotalRawScore}`);
                $('#finalScore').text(`${totalGrade.toFixed(2)}%`);
            });

            // Handle form submission
            $('#evaluationForm').on('submit', function(e) {
                let allScored = true;
                $('.rubric-scoring-table .component-row').each(function() {
                    if (!$(this).find('input[type="radio"]:checked').length) {
                        allScored = false;
                        return false;
                    }
                });
                if (!allScored || !$('#deliverableId').val()) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Incomplete Form',
                        text: 'Please select a deliverable and provide scores for all rubric criteria.'
                    });
                }
            });

            // Handle info icon click
            $(document).on('click', '.info-icon', function() {
                const rubricId = $(this).data('rubric-id');
                const deliverableId = $(this).data('deliverable-id');
                showRubricDescriptions(deliverableId, rubricId);
            });

            // Auto-open modal if parameters are in URL
            <?php if ($selectedStudentId || ($isGroupEvaluation && $selectedGroupId)): ?>
                $('#evaluationModal').modal('show');
            <?php endif; ?>
        });
    </script>
</body>
</html>