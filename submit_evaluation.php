<?php
session_start();
header('Content-Type: application/json');

include 'connection.php';

// Ensure the lecturer is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$lecturerID = $_SESSION['user_id'];

// Get role information
$roleQuery = "SELECT role_id FROM lecturers WHERE id = ?";
$stmt = $conn->prepare($roleQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$result = $stmt->get_result();
$lecturer = $result->fetch_assoc();
$stmt->close();

if (!$lecturer) {
    echo json_encode(['success' => false, 'message' => 'Lecturer not found']);
    exit();
}

$roleID = $lecturer['role_id'];
$isSupervisor = in_array($roleID, [3, 4]);
$isAssessor = in_array($roleID, [2, 3]);

// Get POST data
$submissionId = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
$studentId = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
$groupId = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
$submissionType = $_POST['submission_type'] ?? '';
$rubricScores = isset($_POST['rubric_scores']) ? $_POST['rubric_scores'] : [];
$feedback = trim($_POST['feedback'] ?? '');

if (!$submissionId || !$groupId || !$feedback || empty($rubricScores)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Get deliverable information
    $deliverableQuery = "SELECT d.id, d.weightage FROM deliverable_submissions ds 
                        JOIN deliverables d ON ds.deliverable_id = d.id 
                        WHERE ds.id = ?";
    $stmt = $conn->prepare($deliverableQuery);
    $stmt->bind_param("i", $submissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $deliverable = $result->fetch_assoc();
    $stmt->close();

    if (!$deliverable) {
        throw new Exception('Deliverable not found');
    }

    $deliverableId = $deliverable['id'];
    $deliverableWeight = floatval($deliverable['weightage']) / 100;

    // Validate rubric scores
    $rubricsQuery = "SELECT id, max_score FROM rubrics WHERE deliverable_id = ?";
    $stmt = $conn->prepare($rubricsQuery);
    $stmt->bind_param("i", $deliverableId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rubrics = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($rubrics) === 0) {
        throw new Exception('No rubrics found for this deliverable');
    }

    $numRubrics = count($rubrics);
    $rubricWeight = 1.0 / $numRubrics;
    $totalGrade = 0;

    foreach ($rubrics as $rubric) {
        $rubricId = $rubric['id'];
        if (!isset($rubricScores[$rubricId])) {
            throw new Exception('Missing score for rubric');
        }

        $score = intval($rubricScores[$rubricId]);
        $maxScore = intval($rubric['max_score']) ?: 10;

        if ($score < 0 || $score > $maxScore) {
            throw new Exception('Invalid score value');
        }

        $normalizedScore = ($score / $maxScore) * 100 * $rubricWeight * $deliverableWeight;
        $totalGrade += $normalizedScore;
    }

    $evaluationDate = date('Y-m-d');

    if ($submissionType === 'group') {
        // Determine who is evaluating
        $supervisorId = $isSupervisor ? $lecturerID : null;
        $assessorId = $isAssessor ? $lecturerID : null;
        $evaluatorType = $isSupervisor ? 'supervisor' : ($isAssessor ? 'assessor' : '');

        // Delete existing group evaluation rubric scores first (for this evaluator)
        $deleteGroupEvalSql = "
            SELECT id FROM group_evaluations 
            WHERE group_id = ? AND deliverable_id = ?";
        if ($isSupervisor) {
            $deleteGroupEvalSql .= " AND supervisor_id = ? AND type = 'Group'";
        } elseif ($isAssessor) {
            $deleteGroupEvalSql .= " AND assessor_id = ? AND type = 'Group'";
        }
        $stmt = $conn->prepare($deleteGroupEvalSql);
        if ($isSupervisor) {
            $stmt->bind_param("iii", $groupId, $deliverableId, $lecturerID);
        } elseif ($isAssessor) {
            $stmt->bind_param("iii", $groupId, $deliverableId, $lecturerID);
        } else {
            $stmt->bind_param("ii", $groupId, $deliverableId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $groupEvaluationIds = [];
        while ($row = $result->fetch_assoc()) {
            $groupEvaluationIds[] = $row['id'];
        }
        $stmt->close();

        // Delete from group_evaluation_rubric_scores for those IDs
        if (!empty($groupEvaluationIds)) {
            $in = implode(',', array_fill(0, count($groupEvaluationIds), '?'));
            $types = str_repeat('i', count($groupEvaluationIds));
            $stmt = $conn->prepare("DELETE FROM group_evaluation_rubric_scores WHERE group_evaluation_id IN ($in)");
            $stmt->bind_param($types, ...$groupEvaluationIds);
            $stmt->execute();
            $stmt->close();
        }

        // Then delete the group evaluation(s)
        $deleteGroupEvalSql2 = "DELETE FROM group_evaluations WHERE group_id = ? AND deliverable_id = ?";
        if ($isSupervisor) {
            $deleteGroupEvalSql2 .= " AND supervisor_id = ? AND type = 'Group'";
        } elseif ($isAssessor) {
            $deleteGroupEvalSql2 .= " AND assessor_id = ? AND type = 'Group'";
        }
        $stmt = $conn->prepare($deleteGroupEvalSql2);
        if ($isSupervisor) {
            $stmt->bind_param("iii", $groupId, $deliverableId, $lecturerID);
        } elseif ($isAssessor) {
            $stmt->bind_param("iii", $groupId, $deliverableId, $lecturerID);
        } else {
            $stmt->bind_param("ii", $groupId, $deliverableId);
        }
        $stmt->execute();
        $stmt->close();

        // Insert group evaluation
        $stmt = $conn->prepare("
            INSERT INTO group_evaluations (
                group_id, supervisor_id, assessor_id, deliverable_id, 
                evaluation_grade, feedback, type, date
            ) VALUES (?, ?, ?, ?, ?, ?, 'Group', ?)
        ");
        $stmt->bind_param("iiiddss", $groupId, $supervisorId, $assessorId, $deliverableId, $totalGrade, $feedback, $evaluationDate);
        $stmt->execute();
        $groupEvaluationId = $conn->insert_id;
        $stmt->close();

        // Insert group evaluation rubric scores
        $stmt = $conn->prepare("
            INSERT INTO group_evaluation_rubric_scores (
                group_evaluation_id, rubric_id, score
            ) VALUES (?, ?, ?)
        ");
        foreach ($rubricScores as $rubricId => $score) {
            $stmt->bind_param("iii", $groupEvaluationId, $rubricId, $score);
            $stmt->execute();
        }
        $stmt->close();
    } else {
        // First, find all matching evaluation IDs for supervisor
        if ($isSupervisor) {
            $stmt = $conn->prepare("
                SELECT id FROM evaluation 
                WHERE student_id = ? AND deliverable_id = ? AND supervisor_id = ? AND type IN ('sv', 'supervisor', 'Individual')
            ");
            $stmt->bind_param("iii", $studentId, $deliverableId, $lecturerID);
            $stmt->execute();
            $result = $stmt->get_result();
            $evaluationIds = [];
            while ($row = $result->fetch_assoc()) {
                $evaluationIds[] = $row['id'];
            }
            $stmt->close();

            // Delete from evaluation_rubric_scores for those IDs
            if (!empty($evaluationIds)) {
                $in = implode(',', array_fill(0, count($evaluationIds), '?'));
                $types = str_repeat('i', count($evaluationIds));
                $stmt = $conn->prepare("DELETE FROM evaluation_rubric_scores WHERE evaluation_id IN ($in)");
                $stmt->bind_param($types, ...$evaluationIds);
                $stmt->execute();
                $stmt->close();
            }

            // Then delete from evaluation
            $stmt = $conn->prepare("
                DELETE FROM evaluation 
                WHERE student_id = ? AND deliverable_id = ? AND supervisor_id = ? AND type IN ('sv', 'supervisor', 'Individual')
            ");
            $stmt->bind_param("iii", $studentId, $deliverableId, $lecturerID);
            $stmt->execute();
            $stmt->close();
        }
        // First, find all matching evaluation IDs for assessor
        if ($isAssessor) {
            $stmt = $conn->prepare("
                SELECT id FROM evaluation 
                WHERE student_id = ? AND deliverable_id = ? AND assessor_id = ? AND type IN ('ass', 'assessor', 'Individual')
            ");
            $stmt->bind_param("iii", $studentId, $deliverableId, $lecturerID);
            $stmt->execute();
            $result = $stmt->get_result();
            $evaluationIds = [];
            while ($row = $result->fetch_assoc()) {
                $evaluationIds[] = $row['id'];
            }
            $stmt->close();

            // Delete from evaluation_rubric_scores for those IDs
            if (!empty($evaluationIds)) {
                $in = implode(',', array_fill(0, count($evaluationIds), '?'));
                $types = str_repeat('i', count($evaluationIds));
                $stmt = $conn->prepare("DELETE FROM evaluation_rubric_scores WHERE evaluation_id IN ($in)");
                $stmt->bind_param($types, ...$evaluationIds);
                $stmt->execute();
                $stmt->close();
            }

            // Then delete from evaluation
            $stmt = $conn->prepare("
                DELETE FROM evaluation 
                WHERE student_id = ? AND deliverable_id = ? AND assessor_id = ? AND type IN ('ass', 'assessor', 'Individual')
            ");
            $stmt->bind_param("iii", $studentId, $deliverableId, $lecturerID);
            $stmt->execute();
            $stmt->close();
        }
        // Insert individual evaluation
        $typeValue = $isSupervisor ? 'sv' : 'ass';
        $stmt = $conn->prepare("
            INSERT INTO evaluation (
                student_id, supervisor_id, assessor_id, deliverable_id, 
                evaluation_grade, feedback, type, date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $supervisorId = $isSupervisor ? $lecturerID : null;
        $assessorId = $isAssessor ? $lecturerID : null;
        $stmt->bind_param("iiiddsss", $studentId, $supervisorId, $assessorId, $deliverableId, $totalGrade, $feedback, $typeValue, $evaluationDate);
        $stmt->execute();
        $evaluationId = $conn->insert_id;
        $stmt->close();

        // Insert evaluation rubric scores
        $stmt = $conn->prepare("
            INSERT INTO evaluation_rubric_scores (
                evaluation_id, rubric_id, score
            ) VALUES (?, ?, ?)
        ");
        foreach ($rubricScores as $rubricId => $score) {
            $stmt->bind_param("iii", $evaluationId, $rubricId, $score);
            $stmt->execute();
        }
        $stmt->close();
    }

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?> 