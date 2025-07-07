<?php
session_start();
include 'connection.php';

// Set timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the lecturer is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$lecturerID = $_SESSION['user_id'];

// Fetch lecturer details
$sql = "SELECT full_name, profile_picture, role_id FROM lecturers WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Lecturer query prepare failed: " . $conn->error);
    $evaluationMessage = "An error occurred fetching lecturer details.";
} else {
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    $lecturer = $result->fetch_assoc();
    $stmt->close();
}

if (!$lecturer) {
    error_log("No lecturer found with ID: $lecturerID");
    $evaluationMessage = "Error: Lecturer not found.";
}

$personalInfo = [
    'full_name' => $lecturer['full_name'] ?? 'N/A',
    'profile_picture' => $lecturer['profile_picture'] ?? 'img/undraw_profile.svg',
];
$roleID = $lecturer['role_id'] ?? 1;
$isSupervisor = in_array($roleID, [3, 4]);
$isAssessor = in_array($roleID, [2, 3]);

// Redirect if not an assessor
if (!$isAssessor) {
    $evaluationMessage = "Access denied: You are not authorized to evaluate as an assessor.";
    header("Location: lecturerdashboard.php");
    exit();
}

// Initialize message
$evaluationMessage = isset($evaluationMessage) ? $evaluationMessage : '';

// Display session message if set
if (isset($_SESSION['evaluation_message'])) {
    $evaluationMessage = $_SESSION['evaluation_message'];
    unset($_SESSION['evaluation_message']);
}

// Fetch semesters for filter
$semestersQuery = "SELECT semester_name, start_date, is_current FROM semesters ORDER BY start_date DESC";
$semestersResult = $conn->query($semestersQuery);
$semesters = $semestersResult ? $semestersResult->fetch_all(MYSQLI_ASSOC) : [];

// Default to current semester if none selected
$selectedSemester = isset($_GET['semester']) && $_GET['semester'] !== '' ? $_GET['semester'] : null;
if (!$selectedSemester) {
    $findSemesterQuery = "SELECT d.semester 
                         FROM deliverables d
                         JOIN deliverable_submissions ds ON d.id = ds.deliverable_id
                         JOIN groups g ON ds.group_id = g.id
                         WHERE g.assessor_id = ?
                         ORDER BY ds.submitted_at DESC LIMIT 1";
    $stmt = $conn->prepare($findSemesterQuery);
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $selectedSemester = $row ? $row['semester'] : ($semesters[0]['semester_name'] ?? null);
    $stmt->close();
}

// Handle filters
$searchUsername = isset($_GET['username']) ? trim($_GET['username']) : '';
$selectedGroup = isset($_GET['group_name']) ? $_GET['group_name'] : '';

// Fetch groups for filter
$groupsQuery = "
    SELECT DISTINCT g.id, g.name 
    FROM groups g 
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE g.assessor_id = ? AND g.status = 'Approved'";
$params = [$lecturerID];
$paramTypes = "i";
if ($selectedSemester) {
    $groupsQuery .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
$stmt = $conn->prepare($groupsQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$groupsResult = $stmt->get_result();
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all students with group info
$studentsQuery = "
    SELECT s.id, s.full_name AS name, g.id AS group_id, g.name AS group_name 
    FROM students s 
    LEFT JOIN group_members gm ON s.id = gm.student_id 
    LEFT JOIN groups g ON gm.group_id = g.id 
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE g.assessor_id = ? AND g.status = 'Approved'";
$params = [$lecturerID];
$paramTypes = "i";
if ($selectedSemester) {
    $studentsQuery .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
if ($searchUsername) {
    $studentsQuery .= " AND s.username LIKE ?";
    $params[] = "%$searchUsername%";
    $paramTypes .= "s";
}
if ($selectedGroup) {
    $studentsQuery .= " AND g.name = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "s";
}
$studentsQuery .= " ORDER BY s.id ASC";
$stmt = $conn->prepare($studentsQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$studentsResult = $stmt->get_result();
$students = $studentsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch groups with unevaluated group deliverables
$groupsWithGroupDeliverables = [];
$groupsQuery = "
    SELECT DISTINCT g.id AS group_id, g.name AS group_name
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE g.assessor_id = ? AND g.status = 'Approved'
    AND EXISTS (
        SELECT 1 FROM deliverable_submissions ds
        JOIN deliverables d ON ds.deliverable_id = d.id 
        WHERE ds.group_id = g.id
        AND d.submission_type = 'group'
        AND ds.submitted_at IS NOT NULL 
        AND NOT EXISTS (
            SELECT 1 FROM group_evaluations ge
            WHERE ge.deliverable_id = ds.deliverable_id
            AND ge.group_id = g.id
            AND ge.assessor_id = ?
            AND ge.type = 'Group'
        )
    )";
$params = [$lecturerID, $lecturerID];
$paramTypes = "ii";
if ($selectedSemester) {
    $groupsQuery .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
$stmt = $conn->prepare($groupsQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$groupsResult = $stmt->get_result();
$groupsWithGroupDeliverables = $groupsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Initialize variables for rubrics
$rubrics = [];
$scoreRanges = [];
$rubricsData = [];

// Fetch all deliverables for the selected semester
$deliverablesQuery = "
    SELECT DISTINCT d.id
    FROM deliverables d
    JOIN deliverable_submissions ds ON d.id = ds.deliverable_id
    JOIN groups g ON ds.group_id = g.id
    WHERE g.assessor_id = ? AND d.semester = ?";
$stmt = $conn->prepare($deliverablesQuery);
$stmt->bind_param("is", $lecturerID, $selectedSemester);
$stmt->execute();
$result = $stmt->get_result();
$deliverableIds = [];
while ($row = $result->fetch_assoc()) {
    $deliverableIds[] = $row['id'];
}
$stmt->close();

// Fetch rubrics and score ranges for deliverables
foreach ($deliverableIds as $deliverableId) {
    $rubricsQuery = "SELECT DISTINCT id, criteria, component, max_score FROM rubrics WHERE deliverable_id = ?";
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
    $rubricsData[$deliverableId] = [];
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

// Initialize variables for evaluation
$selectedStudentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$selectedGroupId = isset($_GET['group']) ? intval($_GET['group']) : 0;
$isGroupEvaluation = isset($_GET['eval_type']) && $_GET['eval_type'] === 'group';
$selectedStudentName = '';
$selectedGroupName = '';

// Validate selected student and group
if ($selectedStudentId || $selectedGroupId) {
    if ($selectedStudentId) {
        $stmt = $conn->prepare("
            SELECT s.full_name 
            FROM students s 
            JOIN group_members gm ON s.id = gm.student_id 
            JOIN groups g ON gm.group_id = g.id 
            WHERE s.id = ? AND g.assessor_id = ?");
        $stmt->bind_param("ii", $selectedStudentId, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($student = $result->fetch_assoc()) {
            $selectedStudentName = $student['full_name'];
        } else {
            $selectedStudentId = 0;
            $evaluationMessage = "Error: Student is not assigned to you.";
        }
        $stmt->close();
    }
    if ($selectedGroupId) {
        $stmt = $conn->prepare("SELECT name FROM groups WHERE id = ? AND assessor_id = ?");
        $stmt->bind_param("ii", $selectedGroupId, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($group = $result->fetch_assoc()) {
            $selectedGroupName = $group['name'];
        } else {
            $selectedGroupId = 0;
            $evaluationMessage = "Error: Group is not assigned to you.";
        }
        $stmt->close();
    }
}

// Fetch submissions for modal if a student or group is selected
$submissions = [];
$evaluationMarks = [];
if ($selectedStudentId && $selectedGroupId && !$isGroupEvaluation) {
    $submissionsQuery = "
        SELECT DISTINCT ds.id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.deliverable_id, d.weightage 
        FROM deliverable_submissions ds 
        JOIN deliverables d ON ds.deliverable_id = d.id 
        JOIN groups g ON ds.group_id = g.id
        WHERE ds.student_id = ? AND ds.group_id = ? AND d.submission_type = 'individual' 
        AND ds.submitted_at IS NOT NULL AND g.assessor_id = ?
        AND NOT EXISTS (
            SELECT 1 FROM evaluation e 
            WHERE e.deliverable_id = ds.deliverable_id 
            AND e.student_id = ? 
            AND e.assessor_id = ?
            AND e.type = 'Individual'
        )";
    $stmt = $conn->prepare($submissionsQuery);
    $stmt->bind_param("iiiii", $selectedStudentId, $selectedGroupId, $lecturerID, $selectedStudentId, $lecturerID);
    $stmt->execute();
    $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $allDeliverablesQuery = "
        SELECT d.id AS deliverable_id, d.name AS deliverable_name, d.submission_type, d.weightage,
               ds.id AS submission_id, ds.file_path, ds.submitted_at, 
               e.evaluation_grade, e.feedback
        FROM deliverables d 
        LEFT JOIN deliverable_submissions ds ON d.id = ds.deliverable_id 
            AND ds.group_id = ? AND ds.student_id = ?
        LEFT JOIN groups g ON ds.group_id = g.id
        LEFT JOIN evaluation e ON d.id = e.deliverable_id 
            AND e.student_id = ? AND e.assessor_id = ? AND e.type = 'Individual'
        WHERE d.semester = ? AND (g.assessor_id = ? OR g.assessor_id IS NULL)";
    $stmt = $conn->prepare($allDeliverablesQuery);
    $stmt->bind_param("iiiisi", $selectedGroupId, $selectedStudentId, $selectedStudentId, $lecturerID, $selectedSemester, $lecturerID);
    $stmt->execute();
    $marksResult = $stmt->get_result();
    while ($row = $marksResult->fetch_assoc()) {
        $evaluationMarks[] = [
            'deliverable_id' => $row['deliverable_id'],
            'deliverable_name' => $row['deliverable_name'],
            'submission_type' => $row['submission_type'],
            'file_path' => $row['file_path'] ?? 'N/A',
            'submitted_at' => $row['submitted_at'],
            'evaluation_grade' => $row['evaluation_grade'] !== null ? number_format($row['evaluation_grade'], 2) . '%' : 'Not Evaluated',
            'feedback' => $row['feedback'] ?? 'N/A',
            'submission_id' => $row['submission_id']
        ];
    }
    $stmt->close();
} elseif ($isGroupEvaluation && $selectedGroupId) {
    $submissionsQuery = "
        SELECT DISTINCT ds.id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.deliverable_id, d.weightage 
        FROM deliverable_submissions ds 
        JOIN deliverables d ON ds.deliverable_id = d.id 
        JOIN groups g ON ds.group_id = g.id
        WHERE ds.group_id = ? AND d.submission_type = 'group' 
        AND ds.submitted_at IS NOT NULL AND g.assessor_id = ?
        AND NOT EXISTS (
            SELECT 1 FROM group_evaluations ge 
            WHERE ge.deliverable_id = ds.deliverable_id 
            AND ge.group_id = ? 
            AND ge.assessor_id = ?
            AND ge.type = 'Group'
        )";
    $stmt = $conn->prepare($submissionsQuery);
    $stmt->bind_param("iiii", $selectedGroupId, $lecturerID, $selectedGroupId, $lecturerID);
    $stmt->execute();
    $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $allDeliverablesQuery = "
        SELECT d.id AS deliverable_id, d.name AS deliverable_name, d.submission_type, d.weightage,
               ds.id AS submission_id, ds.file_path, ds.submitted_at, 
               ge.evaluation_grade, ge.feedback
        FROM deliverables d 
        LEFT JOIN deliverable_submissions ds ON d.id = ds.deliverable_id 
            AND ds.group_id = ?
        LEFT JOIN groups g ON ds.group_id = g.id
        LEFT JOIN group_evaluations ge ON d.id = ge.deliverable_id 
            AND ge.group_id = ? AND ge.assessor_id = ? AND ge.type = 'Group'
        WHERE d.semester = ? AND (g.assessor_id = ? OR g.assessor_id IS NULL)";
    $stmt = $conn->prepare($allDeliverablesQuery);
    $stmt->bind_param("iiiis", $selectedGroupId, $selectedGroupId, $lecturerID, $selectedSemester, $lecturerID);
    $stmt->execute();
    $marksResult = $stmt->get_result();
    while ($row = $marksResult->fetch_assoc()) {
        $evaluationMarks[] = [
            'deliverable_id' => $row['deliverable_id'],
            'deliverable_name' => $row['deliverable_name'],
            'submission_type' => $row['submission_type'],
            'file_path' => $row['file_path'] ?? 'N/A',
            'submitted_at' => $row['submitted_at'],
            'evaluation_grade' => $row['evaluation_grade'] !== null ? number_format($row['evaluation_grade'], 2) . '%' : 'Not Evaluated',
            'feedback' => $row['feedback'] ?? 'N/A',
            'submission_id' => $row['submission_id']
        ];
    }
    $stmt->close();
}

// Handle evaluation submission
$evaluationMessage = isset($evaluationMessage) ? $evaluationMessage : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $deliverableSubmissionId = intval($_POST['deliverable_id']);
    $selectedStudentId = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    $selectedGroupId = intval($_POST['group_id']);
    $feedback = trim($_POST['feedback']);
    $evaluationDate = date('Y-m-d');
    $rubricScores = $_POST['rubric_scores'] ?? [];
    $evalType = $_POST['eval_type'] ?? 'individual';

    // Validate group assignment
    $stmt = $conn->prepare("SELECT id FROM groups WHERE id = ? AND assessor_id = ?");
    $stmt->bind_param("ii", $selectedGroupId, $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $evaluationMessage = "Error: Group is not assigned to you.";
        $_SESSION['evaluation_message'] = $evaluationMessage;
        header("Location: assevaluatestudent.php?semester=" . urlencode($selectedSemester));
        exit();
    }
    $stmt->close();

    if ($evalType === 'group') {
        $validateQuery = "
            SELECT ds.deliverable_id, d.submission_type 
            FROM deliverable_submissions ds 
            JOIN deliverables d ON ds.deliverable_id = d.id 
            JOIN groups g ON ds.group_id = g.id 
            WHERE ds.id = ? 
            AND g.id = ? 
            AND g.assessor_id = ? 
            AND d.submission_type = 'group'
            AND ds.submitted_at IS NOT NULL 
            AND NOT EXISTS (
                SELECT 1 FROM group_evaluations ge 
                WHERE ge.deliverable_id = ds.deliverable_id 
                AND ge.group_id = g.id
                AND ge.assessor_id = ?
                AND ge.type = 'Group'
            )";
        $stmt = $conn->prepare($validateQuery);
        $stmt->bind_param("iiii", $deliverableSubmissionId, $selectedGroupId, $lecturerID, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $evaluationMessage = "Error: Invalid or already evaluated group deliverable.";
        } else {
            $deliverable = $result->fetch_assoc();
            $deliverableId = $deliverable['deliverable_id'];
            $stmt->close();

            // Fetch deliverable weightage
            $stmt = $conn->prepare("SELECT weightage FROM deliverables WHERE id = ?");
            $stmt->bind_param("i", $deliverableId);
            $stmt->execute();
            $result = $stmt->get_result();
            $deliverableWeight = $result->num_rows > 0 ? floatval($result->fetch_assoc()['weightage']) / 100 : 1.0;
            $stmt->close();

            // Validate rubric scores
            $totalGrade = 0;
            $validScores = true;
            $deliverableRubrics = $rubrics[$deliverableId] ?? [];
            $numRubrics = count($deliverableRubrics);
            $rubricWeight = $numRubrics > 0 ? 1.0 / $numRubrics : 1.0;

            foreach ($deliverableRubrics as $rubric) {
                $rubricId = $rubric['id'];
                if (!isset($rubricScores[$rubricId])) {
                    $validScores = false;
                    $evaluationMessage = "Error: Missing score for " . htmlspecialchars($rubric['criteria']) . ".";
                    break;
                }
                $score = intval($rubricScores[$rubricId]);
                $maxScore = intval($rubric['max_score']) ?: 10;
                if ($score > $maxScore || $score < 0) {
                    $validScores = false;
                    $evaluationMessage = "Error: Invalid score for " . htmlspecialchars($rubric['criteria']) . " (0–$maxScore).";
                    break;
                }
                $normalizedScore = ($score / $maxScore) * 100 * $rubricWeight * $deliverableWeight;
                $totalGrade += $normalizedScore;
            }

            if ($validScores) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO group_evaluations (group_id, assessor_id, deliverable_id, evaluation_grade, feedback, type, date) 
                        VALUES (?, ?, ?, ?, ?, 'Group', ?)");
                    $stmt->bind_param("iiidss", $selectedGroupId, $lecturerID, $deliverableId, $totalGrade, $feedback, $evaluationDate);
                    $stmt->execute();
                    $evaluationId = $conn->insert_id;
                    $stmt->close();

                    foreach ($rubricScores as $rubricId => $score) {
                        $stmt = $conn->prepare("
                            INSERT INTO group_evaluation_rubric_scores (group_evaluation_id, rubric_id, score) 
                            VALUES (?, ?, ?)");
                        $stmt->bind_param("iii", $evaluationId, $rubricId, $score);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $conn->commit();
                    $evaluationMessage = "Group evaluation submitted successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $evaluationMessage = "Error submitting group evaluation: " . $e->getMessage();
                    error_log("Group Transaction Error: " . $e->getMessage());
                }
            }
        }
    } else {
        $stmt = $conn->prepare("
            SELECT 1 
            FROM students s 
            JOIN group_members gm ON s.id = gm.student_id 
            JOIN groups g ON gm.group_id = g.id 
            WHERE s.id = ? AND g.id = ? AND g.assessor_id = ?");
        $stmt->bind_param("iii", $selectedStudentId, $selectedGroupId, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $evaluationMessage = "Error: Student is not assigned to you in this group.";
            $_SESSION['evaluation_message'] = $evaluationMessage;
            header("Location: assevaluatestudent.php?semester=" . urlencode($selectedSemester));
            exit();
        }
        $stmt->close();

        $validateQuery = "
            SELECT ds.deliverable_id, d.submission_type 
            FROM deliverable_submissions ds 
            JOIN deliverables d ON ds.deliverable_id = d.id 
            JOIN groups g ON ds.group_id = g.id 
            WHERE ds.id = ? 
            AND ds.student_id = ?
            AND ds.group_id = ?
            AND g.assessor_id = ? 
            AND d.submission_type = 'individual'
            AND ds.submitted_at IS NOT NULL 
            AND NOT EXISTS (
                SELECT 1 FROM evaluation e 
                WHERE e.deliverable_id = ds.deliverable_id 
                AND e.student_id = ? 
                AND e.assessor_id = ?
                AND e.type = 'Individual'
            )";
        $stmt = $conn->prepare($validateQuery);
        $stmt->bind_param("iiiiii", $deliverableSubmissionId, $selectedStudentId, $selectedGroupId, $lecturerID, $selectedStudentId, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $evaluationMessage = "Error: Invalid or already evaluated deliverable.";
        } else {
            $deliverable = $result->fetch_assoc();
            $deliverableId = $deliverable['deliverable_id'];
            $stmt->close();

            // Fetch deliverable weightage
            $stmt = $conn->prepare("SELECT weightage FROM deliverables WHERE id = ?");
            $stmt->bind_param("i", $deliverableId);
            $stmt->execute();
            $result = $stmt->get_result();
            $deliverableWeight = $result->num_rows > 0 ? floatval($result->fetch_assoc()['weightage']) / 100 : 1.0;
            $stmt->close();

            // Validate rubric scores
            $totalGrade = 0;
            $validScores = true;
            $deliverableRubrics = $rubrics[$deliverableId] ?? [];
            $numRubrics = count($deliverableRubrics);
            $rubricWeight = $numRubrics > 0 ? 1.0 / $numRubrics : 1.0;

            foreach ($deliverableRubrics as $rubric) {
                $rubricId = $rubric['id'];
                if (!isset($rubricScores[$rubricId])) {
                    $validScores = false;
                    $evaluationMessage = "Error: Missing score for " . htmlspecialchars($rubric['criteria']) . ".";
                    break;
                }
                $score = intval($rubricScores[$rubricId]);
                $maxScore = intval($rubric['max_score']) ?: 10;
                if ($score > $maxScore || $score < 0) {
                    $validScores = false;
                    $evaluationMessage = "Error: Invalid score for " . htmlspecialchars($rubric['criteria']) . " (0–$maxScore).";
                    break;
                }
                $normalizedScore = ($score / $maxScore) * 100 * $rubricWeight * $deliverableWeight;
                $totalGrade += $normalizedScore;
            }

            if ($validScores) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO evaluation (student_id, assessor_id, deliverable_id, evaluation_grade, feedback, type, date) 
                        VALUES (?, ?, ?, ?, ?, 'Individual', ?)");
                    $stmt->bind_param("iiidss", $selectedStudentId, $lecturerID, $deliverableId, $totalGrade, $feedback, $evaluationDate);
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
                    $evaluationMessage = "Student evaluation submitted successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $evaluationMessage = "Error submitting evaluation: " . $e->getMessage();
                    error_log("Transaction Error: " . $e->getMessage());
                }
            }
        }
    }

    if ($evaluationMessage && strpos($evaluationMessage, 'successfully') !== false) {
        $_SESSION['evaluation_message'] = $evaluationMessage;
        header("Location: assevaluatestudent.php?semester=" . urlencode($selectedSemester) . "&username=" . urlencode($searchUsername) . "&group_name=" . urlencode($selectedGroup));
        exit();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Assessor - Evaluate Students</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@200;200i;400;400i;600;600i;700;700i&display=swap" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="vendor/datatables/dataTable.bootstrap4.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .evaluation-form label { font-weight: bold; }
        .confirmation-text { font-weight: bold; color: #007bff; }
        .submission-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .submission-table th, .submission-table td { 
            border: 1px solid #dde2e6; 
            padding: 12px; 
            text-align: left; 
            vertical-align: middle; 
        }
        .submission-table th { background-color: #f8f9fa; font-weight: bold; }
        .submission-table tr:nth-child(even) { background-color: #f9f9f9; }
        .submission-table tr:hover { background-color: #f1f1f1; }
        .rubric-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .rubric-table th, .rubric-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            height: 40px; 
        }
        .rubric-table th { background-color: #f8f9fa; text-align: left; }
        .rubric-table th:first-child { text-align: center; }
        .rubric-table .criteria-row { background-color: #E6F0FA; font-weight: bold; }
        .rubric-table .component-row td:first-child { text-align: center; }
        .rubric-table .score-cell { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 0 10px; 
        }
        .rubric-table input[type="radio"] { transform: scale(1.2); margin-right: 5px; }
        .rubric-table .score-option { 
            flex: 1; 
            text-align: center; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .rubric-table .total-score-row, .rubric-table .final-score-row { 
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
        .table { border: 1px solid #dde2e6; }
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
        /* Footer and Font Fixes */
        .sticky-footer {
            width: 100%;
            flex-shrink: 0;
            position: relative;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
        }
        #content-wrapper {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        #content {
            flex: 1 0 auto;
        }
        body, h1, h2, h3, h4, h5, h6, p, span, a, button, input, select, textarea, .table, .modal, .alert, .btn {
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol' !important;
            font-weight: 400;
        }
        h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 {
            font-weight: 700;
        }
        .font-weight-bold {
            font-weight: 700 !important;
        }
        .font-weight-light {
            font-weight: 200 !important;
        }
    </style>
</head>
<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="lecturerdashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="lecturerdashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Supervisor Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed <?= !$isSupervisor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Academic Oversight</span>
                </a>
                <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Project Scope:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lecttitleproposal.php">Title Proposal</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectfypcomponents.php">View Student <br>Submissions</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed <?= !$isSupervisor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Mentorship Tools</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Guidance Resources:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectmanagemeetings.php">Manage Meetings</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewdiary.php">View Student Diary</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectevaluatestudent.php">Evaluate Students</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewstudentdetails.php">View Student Details</a>
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Assessor Portal</div>
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Oversight Panel</span>
                </a>
                <div id="collapsePages" class="collapse show" aria-labelledby="headingPages" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Performance Review:</h6>
                        <a class="collapse-item active" href="assevaluatestudent.php">Evaluate Students</a>
                        <a class="collapse-item" href="assviewstudentdetails.php">View Student Details</a>
                        <h6 class="collapse-header">Courses:</h6>
                        <a class="dropdown-item" href="courses.php">Courses</a>
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider d-none d-md-block">
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>
        </ul>
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" href="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="index.php" id="userDropdown" role="button" data-toggle="dropdown">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in">
                                <a class="dropdown-item" href="lectprofile.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Profile
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="index.html" data-toggle="modal" data-target="#logoutModal">
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
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Evaluations</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="semester">Semester</label>
                                        <select class="form-control" id="semester" name="semester" required>
                                            <?php if (!$selectedSemester): ?>
                                                <option value="" disabled selected>-- Select Semester --</option>
                                            <?php endif; ?>
                                            <?php foreach ($semesters as $semester): ?>
                                                    <option value="<?php echo htmlspecialchars($semester['semester_name']); ?>" <?php echo ($selectedSemester == $semester['semester_name']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="username">Student Name</label>
                                        <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($searchUsername) ?>" placeholder="Search by student username">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="group_name">Group</label>
                                        <select class="form-control" id="group_name" name="group_name">
                                            <option value="">-- Select Group --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?= htmlspecialchars($group['name']) ?>" <?= $selectedGroup === $group['name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="assevaluatestudent.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>
                    <?php if (!$selectedSemester): ?>
                        <div class="alert alert-warning">No active semester available. Please select a semester.</div>
                    <?php endif; ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Student List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Group ID</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($students)): ?>
                                            <?php foreach ($students as $student): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($student['name']) ?></td>
                                                    <td><?= htmlspecialchars($student['group_name'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <a href="assevaluatestudent.php?student_id=<?= $student['id'] ?>&group=<?= $student['group_id'] ?? 0 ?>&semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroup) ?>" class="btn btn-primary btn-sm evaluate-btn">
                                                            Evaluate Individual
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No students found.</td>
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
                                            <th>Group ID</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($groupsWithGroupDeliverables)): ?>
                                            <?php foreach ($groupsWithGroupDeliverables as $group): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($group['group_name']) ?></td>
                                                    <td>
                                                        <a href="assevaluatestudent.php?group=<?= $group['group_id'] ?>&eval_type=group&semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroup) ?>" class="btn btn-primary btn-sm evaluate-btn">
                                                            Evaluate Group
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="2" class="text-center">No groups with pending deliverables found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div>
            <div class="modal fade" id="evaluationModal" tabindex="0" role="dialog" aria-labelledby="students">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <h5 class="modal-title" id="modal-title">
                            <?php if ($isGroupEvaluation): ?>
                                Evaluate Group: <span class="confirmation-text"><?= htmlspecialchars($selectedGroupName) ?></span>
                            <?php else: ?>
                                Evaluate Student: <span class="confirmation-text"><?= htmlspecialchars($selectedStudentName) ?></span>
                            <?php endif; ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <h6>Submissions</h6>
                        <table class="table table-bordered table-hover submission-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Deliverable Name</th>
                                    <th>Submission Type</th>
                                    <th>File</th>
                                    <th>Submission Date</th>
                                    <th>Grade</th>
                                    <th>Feedback</th>
                                </tr>
                            </thead>
                            <tbody id="modal-submissions">
                                <?php if (empty($submissions) || empty($evaluationMarks)): ?>
                                    <tr>
                                        <td colspan="6" class="text-left">No submissions available.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($evaluationMarks as $mark): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($mark['deliverable_name']) ?></td>
                                            <td><?= htmlspecialchars(ucfirst($mark['submission_type'])) ?></td>
                                            <td>
                                                <?php if ($mark['file_path'] !== 'N/A'): ?>
                                                    <a href="<?= htmlspecialchars($mark['file_path']) ?>" target="_blank">View</a>
                                                <?php else: ?>
                                                    Not Submitted
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $mark['submitted_at'] ? date('d-m-Y H:i:s', strtotime($mark['submitted_at'])) : 'N/A'; ?></td>
                                            <td><?= htmlspecialchars($mark['evaluation_grade']) ?></td>
                                            <td><?= htmlspecialchars($mark['feedback']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </td>
                                    </tr>
                                    <div class="evaluation-form mt-4">
                                        <form method="POST" action="assevaluatestudent.php" id="evaluationForm">
                                    <div class="form-group">
                                        <label for="deliverableId">Select Deliverable</label>
                                        <select class="form-control" id="deliverableId" name="deliverable_id" onchange="updateRubricScore(this)" required>
                                            <option value="">Select a Deliverable</option>
                                            <?php foreach ($submissions as $submission): ?>
                                                <option value="<?= htmlspecialchars($submission['id']) ?>" 
                                                    data-deliverable-id="<?= htmlspecialchars($submission['deliverable_id']) ?>" 
                                                    data-weightage="<?= htmlspecialchars($submission['weightage'] / 100) ?>">
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
                                    <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                    <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                                    <input type="hidden" name="eval_type" value="<?= $isGroupEvaluation ? 'group' : 'individual' ?>">
                                    <input type="hidden" name="semester" value="<?= htmlspecialchars($selectedSemester) ?>">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($searchUsername) ?>">
                                    <input type="hidden" name="group_name" value="<?= htmlspecialchars($selectedGroup) ?>">
                                    <button type="submit" name="submit_evaluation" class="btn btn-primary">Submit Evaluation</button>
                                    <a href="assevaluatestudent.php?semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroup) ?>" class="btn btn-secondary">Cancel</a>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
        </div>
        <footer class="sticky-footer bg-white">
            <div class="container my-auto">
                <div class="copyright text-center my-auto">
                    <span>Copyright &copy; FYPCollabor8 2024</span>
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
                <h5 class="modal-title" id="modal-title">Ready to Log Out?</h5>
                <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">Select "Logout" below to end your current session.</p>
            </div>
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
<script src="js/modal/datatable-demo.js"></script>
<script>
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
                        <i class="fas fa-info-circle info-icon" data-rubric-id="${rubric.id}" data-deliverable-id="${deliverableId}" title="View Rubric Details"></i>
                    </td>
                </tr>
                <tr class="component-row">
                    <td>${index + 1}</td>
                    <td>${rubric.component || 'N/A'}</td>
                    <td class="score-cell">
            `;
            for (let score = 1; score <= maxScore; score++) {
                tableHtml += `
                    <span class="score-option">
                        <input type="radio" name="rubric_scores[${rubric.id}]" value="${score}" class="rubric-score" data-max-score="${maxScore}" required>
                        ${score}
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
        if (!rubricId) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Could not find rubric data.'
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
        $('#dataTable').DataTable();
        $('#groupTable').DataTable();
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
            $('.rubric-score').each(':checked').each(function() {
                const score = parseInt($(this).value());
                const maxScore = parseInt($(this).data('max-score')) || 10;
                totalRawScore += score;
                const normalizedScore = maxScore > 0 ? (score / maxScore) * 100 * rubricWeight * weightage : 0;
                totalGrade += normalizedScore;
            });
            $('#totalRawScore').text(`${totalRawScore}/${maxTotalRawScore}`);
            $('#finalScore').text(`${totalGrade.toFixed(2)}%`);
        });
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
                    text: 'Please select a deliverable and score all rubric criteria.'
                });
            }
        });
        $(document).on('click', '.info-icon', function() {
            const rubricId = $(this).data('rubric-id');
            const deliverableId = $(this).data('deliverable-id');
            showRubricDescriptions(deliverableId, rubricId);
        });
        <?php if ($selectedStudentId || ($isGroupEvaluation && $selectedGroupId)): ?>
            $('#evaluationModal').modal('show');
        <?php endif; ?>
    });
</script>
</body>
</html>