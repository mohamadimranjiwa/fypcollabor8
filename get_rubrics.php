<?php
session_start();
header('Content-Type: application/json');

include 'connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['rubrics' => [], 'deliverable_weightage' => 0, 'message' => 'Not authenticated']);
    exit();
}

$submissionId = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;

if (!$submissionId) {
    echo json_encode(['rubrics' => [], 'deliverable_weightage' => 0, 'message' => 'Submission ID is required']);
    exit();
}

$conn->begin_transaction();

try {
    // Get deliverable_id and its weightage from submission_id
    $deliverableInfoStmt = $conn->prepare("
        SELECT d.id as deliverable_id, d.weightage 
        FROM deliverable_submissions ds
        JOIN deliverables d ON ds.deliverable_id = d.id
        WHERE ds.id = ?
    ");
    if (!$deliverableInfoStmt) {
        throw new Exception("Failed to prepare deliverable info query: " . $conn->error);
    }
    $deliverableInfoStmt->bind_param("i", $submissionId);
    $deliverableInfoStmt->execute();
    $deliverableInfoResult = $deliverableInfoStmt->get_result()->fetch_assoc();
    $deliverableInfoStmt->close();

    if (!$deliverableInfoResult) {
        throw new Exception('Deliverable not found for the given submission ID.');
    }

    $deliverableId = $deliverableInfoResult['deliverable_id'];
    $deliverableWeightage = floatval($deliverableInfoResult['weightage']);

    // Fetch rubrics for the deliverable
    $rubricsQuery = "SELECT id, criteria, component, max_score FROM rubrics WHERE deliverable_id = ?";
    $rubricsStmt = $conn->prepare($rubricsQuery);
    if (!$rubricsStmt) {
        throw new Exception("Failed to prepare rubrics query: " . $conn->error);
    }
    $rubricsStmt->bind_param("i", $deliverableId);
    $rubricsStmt->execute();
    $rubricsResult = $rubricsStmt->get_result();
    $rubrics = $rubricsResult->fetch_all(MYSQLI_ASSOC);
    $rubricsStmt->close();

    $rubricsDataOrganized = [];
    foreach ($rubrics as $rubric) {
        $scoreRangesQuery = "SELECT score_range, description FROM rubric_score_ranges WHERE rubric_id = ? ORDER BY FIELD(score_range, '0-2', '3-4', '5-6', '7-8', '9-10')";
        $scoreRangesStmt = $conn->prepare($scoreRangesQuery);
        if (!$scoreRangesStmt) {
            throw new Exception("Failed to prepare score ranges query: " . $conn->error);
        }
        $scoreRangesStmt->bind_param("i", $rubric['id']);
        $scoreRangesStmt->execute();
        $scoreRangesResult = $scoreRangesStmt->get_result();
        $ranges = [];
        while ($range = $scoreRangesResult->fetch_assoc()) {
            $ranges[$range['score_range']] = $range['description'];
        }
        $scoreRangesStmt->close();
        
        $rubricsDataOrganized[] = [
            'id' => $rubric['id'],
            'criteria' => $rubric['criteria'],
            'component' => $rubric['component'],
            'max_score' => $rubric['max_score'],
            'score_ranges' => $ranges
        ];
    }

    $conn->commit();
    echo json_encode([
        'rubrics' => $rubricsDataOrganized,
        'deliverable_weightage' => $deliverableWeightage
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Error in get_rubrics.php: " . $e->getMessage());
    echo json_encode(['rubrics' => [], 'deliverable_weightage' => 0, 'message' => 'Error fetching rubric data: ' . $e->getMessage()]);
}

$conn->close();
?> 