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
    error_log("Lecturer Query Prepare Failed: " . $conn->error);
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$result = $stmt->get_result();
$lecturer = $result->fetch_assoc();
$stmt->close();

if (!$lecturer) {
    error_log("No lecturer found with ID: $lecturerID");
    die("Error: No lecturer found.");
}

$personalInfo = [
    'full_name' => $lecturer['full_name'] ?? 'N/A',
    'profile_picture' => $lecturer['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Role-based access control
$roleID = $lecturer['role_id'] ?? 1;
$isSupervisor = in_array($roleID, [3, 4]);
$isAssessor = in_array($roleID, [2, 3]);

// Initialize message
$evaluationMessage = "";

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
    $currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
    $result = $conn->query($currentSemesterQuery);
    $currentSemester = $result->fetch_assoc();
    $selectedSemester = $currentSemester ? $currentSemester['semester_name'] : ($semesters[0]['semester_name'] ?? null); // Fallback to first semester
}

// Handle filters
$searchUsername = isset($_GET['username']) ? trim($_GET['username']) : ''; // Changed from student_name
$selectedGroup = isset($_GET['group_name']) ? $_GET['group_name'] : '';

// Fetch groups for filter
$groupsQuery = "
    SELECT DISTINCT g.id, g.name 
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE g.lecturer_id = ? AND g.status = 'Approved'";
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
$studentsQuery = $isSupervisor 
    ? "SELECT s.id, s.full_name AS name, g.id AS group_id, g.name AS group_name 
       FROM students s 
       LEFT JOIN group_members gm ON s.id = gm.student_id 
       LEFT JOIN groups g ON gm.group_id = g.id 
       JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
       WHERE g.lecturer_id = ? AND g.status = 'Approved'"
    : "SELECT s.id, s.full_name AS name, g.id AS group_id, g.name AS group_name 
       FROM students s 
       LEFT JOIN group_members gm ON s.id = gm.student_id 
       LEFT JOIN groups g ON gm.group_id = g.id 
       JOIN semesters sem ON s.id = gm.student_id 
       WHERE (g.status = 'Approved' OR g.id IS NULL)";
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

// Fetch groups with unevaluated group deliverables (for supervisors only)
$groupsWithGroupDeliverables = [];
if ($isSupervisor) {
    $groupsQuery = "
        SELECT DISTINCT g.id AS group_id, g.name AS group_name
        FROM groups g
        JOIN group_members gm ON g.id = gm.group_id
        JOIN students s ON gm.student_id = s.id
        JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
        WHERE g.lecturer_id = ? AND g.status = 'Approved'
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
                AND (ge.supervisor_id = ? OR ge.assessor_id = ?)
                AND ge.type = 'Group'
            )
        )";
    $params = [$lecturerID, $lecturerID, $lecturerID];
    $paramTypes = "iii";
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
}

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

if ($isGroupEvaluation && $selectedGroupId && $isSupervisor) {
    // Fetch group name
    $stmt = $conn->prepare("SELECT name FROM groups WHERE id = ? AND lecturer_id = ?");
    $stmt->bind_param("ii", $selectedGroupId, $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $selectedGroupName = $group['name'] ?? 'Not Assigned';
    $stmt->close();

    // Fetch unevaluated group submissions
    $submissionsQuery = "
        SELECT DISTINCT ds.id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.deliverable_id, d.weightage 
        FROM deliverable_submissions ds 
        JOIN deliverables d ON ds.deliverable_id = d.id 
        JOIN groups g ON ds.group_id = g.id
        JOIN group_members gm ON g.id = gm.group_id
        JOIN students s ON gm.student_id = s.id
        JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
        WHERE ds.submitted_at IS NOT NULL 
          AND ds.group_id = ? 
          AND d.submission_type = 'group'
          AND NOT EXISTS (
              SELECT 1 FROM group_evaluations ge 
              WHERE ge.deliverable_id = ds.deliverable_id 
                AND ge.group_id = ? 
                AND (ge.supervisor_id = ? OR ge.assessor_id = ?)
                AND ge.type = 'Group'
          )
          AND sem.semester_name = ?";
    $params = [$selectedGroupId, $selectedGroupId, $lecturerID, $lecturerID, $selectedSemester];
    $paramTypes = "iiiis";
    $stmt = $conn->prepare($submissionsQuery);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
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
            AND (ge.supervisor_id = ? OR ge.assessor_id = ?)
            AND ge.type = 'Group'
        WHERE d.semester = ?";
    $stmt = $conn->prepare($allDeliverablesQuery);
    if ($stmt) {
        $stmt->bind_param("iiiis", $selectedGroupId, $selectedGroupId, $lecturerID, $lecturerID, $selectedSemester);
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
        SELECT DISTINCT ds.id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.deliverable_id, d.weightage 
        FROM deliverable_submissions ds 
        JOIN deliverables d ON ds.deliverable_id = d.id 
        JOIN groups g ON ds.group_id = g.id
        JOIN group_members gm ON g.id = gm.group_id
        JOIN students s ON gm.student_id = s.id
        JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
        WHERE ds.submitted_at IS NOT NULL 
          AND ds.group_id = ? 
          AND d.submission_type = 'individual'
          AND ds.student_id = ?
          AND NOT EXISTS (
              SELECT 1 FROM evaluation e 
              WHERE e.deliverable_id = ds.deliverable_id 
                AND e.student_id = ? 
                AND (e.supervisor_id = ? OR e.assessor_id = ?)
                AND e.type = 'Individual'
          )
          AND sem.semester_name = ?";
    $params = [$selectedGroupId, $selectedStudentId, $selectedStudentId, $lecturerID, $lecturerID, $selectedSemester];
    $paramTypes = "iiiiis";
    $stmt = $conn->prepare($submissionsQuery);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
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
            AND (e.supervisor_id = ? OR e.assessor_id = ?)
            AND e.type = 'Individual'
        WHERE d.semester = ?";
    $stmt = $conn->prepare($allDeliverablesQuery);
    if ($stmt) {
        $stmt->bind_param("iiiiss", $selectedGroupId, $selectedStudentId, $selectedStudentId, $lecturerID, $lecturerID, $selectedSemester);
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
        error_log("All Deliverables: " . json_encode($evaluationMarks));
        $stmt->close();
    } else {
        error_log("All Deliverables Query Prepare Failed: " . $conn->error);
    }
}

// Fetch rubrics and score ranges for submitted deliverables
$deliverableIds = array_unique(array_column($submissions, 'deliverable_id'));
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
$rubricsData = [];
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

    if ($evalType === 'group' && $isSupervisor) {
        // Validate group deliverable
        $validateQuery = "
            SELECT ds.deliverable_id, d.submission_type 
            FROM deliverable_submissions ds 
            JOIN deliverables d ON ds.deliverable_id = d.id 
            JOIN groups g ON ds.group_id = g.id
            JOIN group_members gm ON g.id = gm.group_id
            JOIN students s ON gm.student_id = s.id
            JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
            WHERE ds.id = ? 
              AND ds.group_id = ? 
              AND g.lecturer_id = ?
              AND d.submission_type = 'group'
              AND ds.submitted_at IS NOT NULL 
              AND NOT EXISTS (
                  SELECT 1 FROM group_evaluations ge 
                  WHERE ge.deliverable_id = ds.deliverable_id 
                    AND ge.group_id = ? 
                    AND (ge.supervisor_id = ? OR ge.assessor_id = ?)
                    AND ge.type = 'Group'
              )
              AND sem.semester_name = ?";
        $params = [$deliverableSubmissionId, $selectedGroupId, $lecturerID, $selectedGroupId, $lecturerID, $lecturerID, $selectedSemester];
        $paramTypes = "iiiiis";
        $stmt = $conn->prepare($validateQuery);
        $stmt->bind_param($paramTypes, ...$params);
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
                    $supervisorId = $isSupervisor ? $lecturerID : null;
                    $assessorId = $isAssessor ? $lecturerID : null;
                    $stmt = $conn->prepare("
                        INSERT INTO group_evaluations (group_id, supervisor_id, assessor_id, deliverable_id, evaluation_grade, feedback, type, date) 
                        VALUES (?, ?, ?, ?, ?, ?, 'Group', ?)");
                    $stmt->bind_param("iiiddss", $selectedGroupId, $supervisorId, $assessorId, $deliverableId, $totalGrade, $feedback, $evaluationDate);
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
            JOIN groups g ON ds.group_id = g.id
            JOIN group_members gm ON g.id = gm.group_id
            JOIN students s ON gm.student_id = s.id
            JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
            WHERE ds.id = ? 
              AND ds.group_id = ? 
              AND d.submission_type = 'individual'
              AND ds.student_id = ?
              AND ds.submitted_at IS NOT NULL 
              AND NOT EXISTS (
                  SELECT 1 FROM evaluation e 
                  WHERE e.deliverable_id = ds.deliverable_id 
                    AND e.student_id = ? 
                    AND (e.supervisor_id = ? OR e.assessor_id = ?)
                    AND e.type = 'Individual'
              )
              AND sem.semester_name = ?";
        $params = [$deliverableSubmissionId, $selectedGroupId, $selectedStudentId, $selectedStudentId, $lecturerID, $lecturerID, $selectedSemester];
        $paramTypes = "iiiiis";
        $stmt = $conn->prepare($validateQuery);
        $stmt->bind_param($paramTypes, ...$params);
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
                    $supervisorId = $isSupervisor ? $lecturerID : null;
                    $assessorId = $isAssessor ? $lecturerID : null;
                    $stmt = $conn->prepare("
                        INSERT INTO evaluation (student_id, supervisor_id, assessor_id, deliverable_id, evaluation_grade, feedback, type, date) 
                        VALUES (?, ?, ?, ?, ?, ?, 'Individual', ?)");
                    $stmt->bind_param("iiiddss", $selectedStudentId, $supervisorId, $assessorId, $deliverableId, $totalGrade, $feedback, $evaluationDate);
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
    header("Location: lectevaluatestudent.php?semester=" . urlencode($selectedSemester) . "&username=" . urlencode($searchUsername) . "&group_name=" . urlencode($selectedGroup));
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
    <title>Lecturer - Evaluate Student</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
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

            <!-- Supervisor Portal -->
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Mentorship Tools</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Guidance Resources:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectmanagemeetings.php">Manage Meetings</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewdiary.php">View Student Diary</a>
                        <a class="collapse-item active <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectevaluatestudent.php">Evaluate Students</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewstudentdetails.php">View Student Details</a>
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider">

            <!-- Assessor Portal -->
            <div class="sidebar-heading">Assessor Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed <?= !$isAssessor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Oversight Panel</span>
                </a>
                <div id="collapsePages" class="collapse" aria-labelledby="headingPages" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Performance Review:</h6>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assevaluatestudent.php">Evaluate Students</a>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assviewstudentdetails.php">View Student Details</a>
                        <div class="collapse-divider"></div>
                        <h6 class="collapse-header">Component Analysis:</h6>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assmanagemeetings.php">Manage Meetings</a>
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider d-none d-md-block">

            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>
        </ul>
        <!-- End of Sidebar -->
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
                                <a class="dropdown-item" href="lectprofile.php">
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
                                            <?php if (!$selectedSemester): ?>
                                                <option value="" disabled selected>-- Select Semester --</option>
                                            <?php endif; ?>
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?php echo htmlspecialchars($semester['semester_name']); ?>" 
                                                        <?php echo $selectedSemester === $semester['semester_name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Student Name Search -->
                                    <div class="col-md-4 mb-3">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo htmlspecialchars($searchUsername); ?>" placeholder="Enter student username">
                                    </div>
                                    <!-- Group Name Filter -->
                                    <div class="col-md-4 mb-3">
                                        <label for="group_name">Group</label>
                                        <select class="form-control" id="group_name" name="group_name">
                                            <option value="">-- All Groups --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?php echo htmlspecialchars($group['name']); ?>" 
                                                        <?php echo $selectedGroup === $group['name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($group['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="lectevaluatestudent.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>
                    <!-- Warning if no semester -->
                    <?php if (!$selectedSemester): ?>
                        <div class="alert alert-warning">No active semester defined. Please select a semester.</div>
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
                                                        <a href="lectevaluatestudent.php?student_id=<?= $student['id'] ?>&group_id=<?= $student['group_id'] ?? 0 ?>&semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroup) ?>" 
                                                           class="btn btn-primary btn-sm evaluate-btn">
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
                    <?php if ($isSupervisor): ?>
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
                                                        <a href="lectevaluatestudent.php?group_id=<?= $group['group_id'] ?>&eval_type=group&semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroup) ?>" 
                                                           class="btn btn-primary btn-sm evaluate-btn">
                                                            Evaluate Group
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="2" class="text-center">No groups with unevaluated group deliverables found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
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
                                        <th>Submission Type</th>
                                        <th>File Path</th>
                                        <th>Submission Date</th>
                                        <th>Grade</th>
                                        <th>Feedback</th>
                                    </tr>
                                </thead>
                                <tbody id="modal-submissions">
                                    <?php if (empty($evaluationMarks)): ?>
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
                                                <td><?= $mark['submitted_at'] ? date('Y-m-d H:i:s', strtotime($mark['submitted_at'])) : 'N/A' ?></td>
                                                <td><?= htmlspecialchars($mark['evaluation_grade']) ?></td>
                                                <td><?= htmlspecialchars($mark['feedback']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div class="evaluation-form mt-4">
                                <form method="POST" action="lectevaluatestudent.php" id="evaluationForm">
                                    <div class="form-group">
                                        <label for="deliverableId">Select Deliverable</label>
                                        <select class="form-control" id="deliverableId" name="deliverable_id" onchange="updateRubricScoring(this)" required>
                                            <option value="">Select a Deliverable</option>
                                            <?php 
                                            $uniqueDeliverables = [];
                                            foreach ($submissions as $submission):
                                                $key = $submission['deliverable_id'] . '-' . $submission['deliverable_name'];
                                                if (!isset($uniqueDeliverables[$key])):
                                                    $uniqueDeliverables[$key] = true;
                                            ?>
                                                <option value="<?= $submission['id'] ?>" 
                                                        data-deliverable-id="<?= $submission['deliverable_id'] ?>" 
                                                        data-weightage="<?= $submission['weightage'] / 100 ?>">
                                                    <?= htmlspecialchars($submission['deliverable_name']) ?>
                                                </option>
                                            <?php endif; endforeach; ?>
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
                                    <input type="hidden" name="group_name" value="<?= htmlspecialchars($selectedGroup) ?>">
                                    <button type="submit" name="submit_evaluation" class="btn btn-primary">Submit Evaluation</button>
                                    <a href="lectevaluatestudent.php?semester=<?= urlencode($selectedSemester) ?>&username=<?= urlencode($searchUsername) ?>&group_name=<?= urlencode($selectedGroup) ?>" class="btn btn-secondary">Cancel</a>
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