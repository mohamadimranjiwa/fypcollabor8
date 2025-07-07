<?php
session_start();
include 'connection.php';

// Ensure coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$coordinatorID = $_SESSION['user_id'];

// Fetch coordinator's details
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$coordinator = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$coordinator) {
    die("Error: No coordinator found.");
}

$personalInfo = [
    'full_name' => $coordinator['full_name'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message
$message = "";

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery);
if ($currentSemesterResult && $currentSemesterResult->num_rows > 0) {
    $currentSemester = $currentSemesterResult->fetch_assoc()['semester_name'];
} else {
    // Fallback: get the latest semester from deliverables if no current semester is set
    $latestSemesterQuery = "SELECT semester FROM deliverables ORDER BY semester DESC LIMIT 1";
    $latestSemesterResult = $conn->query($latestSemesterQuery);
    $currentSemester = ($latestSemesterResult && $latestSemesterResult->num_rows > 0)
        ? $latestSemesterResult->fetch_assoc()['semester']
        : null;
}

// Fetch all semesters for filter
$semestersQuery = "SELECT semester_name FROM semesters ORDER BY start_date DESC";
$semestersResult = $conn->query($semestersQuery);
$semesters = $semestersResult ? $semestersResult->fetch_all(MYSQLI_ASSOC) : [];

// Handle semester filter from GET
$selectedSemester = isset($_GET['semester']) && trim($_GET['semester']) !== '' ? trim($_GET['semester']) : $currentSemester;

// Fetch deliverables for the selected semester only
$deliverables = [];
if ($selectedSemester) {
    $deliverablesQuery = "SELECT id, name, semester, weightage FROM deliverables WHERE semester = ? ORDER BY name";
    $stmt = $conn->prepare($deliverablesQuery);
    $stmt->bind_param("s", $selectedSemester);
    $stmt->execute();
    $deliverablesResult = $stmt->get_result();
    $deliverables = $deliverablesResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Selected deliverable (default to first deliverable or URL parameter)
$selectedDeliverableId = $_GET['deliverable_id'] ?? ($deliverables[0]['id'] ?? null);

// Fetch rubrics for the selected deliverable
$rubrics = [];
if ($selectedDeliverableId) {
    $rubricsQuery = "
        SELECT r.id, r.criteria, r.component, r.created_at, c.full_name AS coordinator_name
        FROM rubrics r
        LEFT JOIN coordinators c ON r.coordinator_id = c.id
        WHERE r.deliverable_id = ?
        ORDER BY r.id";
    $stmt = $conn->prepare($rubricsQuery);
    $stmt->bind_param("i", $selectedDeliverableId);
    $stmt->execute();
    $rubricsResult = $stmt->get_result();
    $rubrics = $rubricsResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch score ranges for all rubrics
$scoreRangesQuery = "
    SELECT id, rubric_id, score_range, description
    FROM rubric_score_ranges
    ORDER BY rubric_id, FIELD(score_range, '0-2', '3-4', '5-6', '7-8', '9-10')";
$scoreRangesResult = $conn->query($scoreRangesQuery) or die("Error fetching score ranges: " . htmlspecialchars($conn->error));
$scoreRanges = [];
while ($row = $scoreRangesResult->fetch_assoc()) {
    $scoreRanges[$row['rubric_id']][] = $row;
}

// Define fixed score ranges
$fixedScoreRanges = ['0-2', '3-4', '5-6', '7-8', '9-10'];

// Handle rubric addition/editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rubric'])) {
    $rubric_id = intval($_POST['rubric_id']);
    $deliverable_id = intval($_POST['deliverable_id']);
    $criteria = trim($_POST['criteria']);
    $component = trim($_POST['component']);
    $max_score = 10;

    $conn->begin_transaction();
    try {
        if ($rubric_id == 0) { // New rubric
            $sql = "INSERT INTO rubrics (coordinator_id, deliverable_id, criteria, component, max_score) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissi", $coordinatorID, $deliverable_id, $criteria, $component, $max_score);
            $stmt->execute();
            $rubric_id = $conn->insert_id;
            $stmt->close();

            // Insert default score ranges for new rubric
            $insertRangeSql = "INSERT INTO rubric_score_ranges (rubric_id, score_range, description) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insertRangeSql);
            $emptyDescription = '';
            foreach ($fixedScoreRanges as $range) {
                $stmt->bind_param("iss", $rubric_id, $range, $emptyDescription);
                $stmt->execute();
            }
            $stmt->close();
        } else { // Update rubric
            $sql = "UPDATE rubrics SET criteria = ?, component = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $criteria, $component, $rubric_id);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        $message = "<div class='alert alert-success'>Rubric " . ($rubric_id == 0 ? "added" : "updated") . " successfully!</div>";
        header("Refresh:1; url=coormanagerubrics.php?deliverable_id=$deliverable_id");
    } catch (Exception $e) {
        $conn->rollback();
        $message = "<div class='alert alert-danger'>Failed to save rubric: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Handle score range description updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_score_ranges'])) {
    $rubric_id = intval($_POST['rubric_id']);
    $descriptions = $_POST['description'] ?? [];

    // Validate descriptions
    $errors = [];
    if (count($descriptions) !== count($fixedScoreRanges)) {
        $errors[] = "All score ranges must have a description.";
    }
    foreach ($descriptions as $i => $desc) {
        if (empty(trim($desc))) {
            $errors[] = "Description for range " . $fixedScoreRanges[$i] . " is required.";
        }
    }

    if (!empty($errors)) {
        $message = "<div class='alert alert-danger'>Errors: <ul><li>" . implode("</li><li>", array_map('htmlspecialchars', $errors)) . "</li></ul></div>";
    } else {
        $conn->begin_transaction();
        try {
            // Check if score ranges exist, insert if missing
            $checkSql = "SELECT score_range FROM rubric_score_ranges WHERE rubric_id = ?";
            $stmt = $conn->prepare($checkSql);
            $stmt->bind_param("i", $rubric_id);
            $stmt->execute();
            $existingRanges = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'score_range');
            $stmt->close();

            $missingRanges = array_diff($fixedScoreRanges, $existingRanges);
            if (!empty($missingRanges)) {
                $insertSql = "INSERT INTO rubric_score_ranges (rubric_id, score_range, description) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($insertSql);
                $emptyDescription = '';
                foreach ($missingRanges as $range) {
                    $stmt->bind_param("iss", $rubric_id, $range, $emptyDescription);
                    $stmt->execute();
                }
                $stmt->close();
            }

            // Update descriptions for existing score ranges
            $updateSql = "UPDATE rubric_score_ranges SET description = ? WHERE rubric_id = ? AND score_range = ?";
            $stmt = $conn->prepare($updateSql);
            foreach ($fixedScoreRanges as $i => $range) {
                $description = trim($descriptions[$i]);
                $stmt->bind_param("sis", $description, $rubric_id, $range);
                if (!$stmt->execute()) {
                    error_log("Failed to update score range $range for rubric $rubric_id: " . $stmt->error);
                }
            }
            $stmt->close();
            $conn->commit();
            $message = "<div class='alert alert-success'>Score range descriptions updated successfully!</div>";
            header("Refresh:1; url=coormanagerubrics.php?deliverable_id=$selectedDeliverableId");
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Failed to save score range descriptions: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("Exception in save_score_ranges: " . $e->getMessage());
        }
    }
}

// Handle rubric deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rubric'])) {
    $rubric_id = intval($_POST['delete_rubric_id']);
    $checkSql = "SELECT id FROM rubrics WHERE id = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param("i", $rubric_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $conn->begin_transaction();
        try {
            // Delete score ranges first
            $deleteRangesSql = "DELETE FROM rubric_score_ranges WHERE rubric_id = ?";
            $stmt = $conn->prepare($deleteRangesSql);
            $stmt->bind_param("i", $rubric_id);
            $stmt->execute();
            $stmt->close();

            // Delete rubric
            $deleteSql = "DELETE FROM rubrics WHERE id = ?";
            $stmt = $conn->prepare($deleteSql);
            $stmt->bind_param("i", $rubric_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $message = "<div class='alert alert-success'>Rubric deleted successfully!</div>";
            header("Refresh:1; url=coormanagerubrics.php?deliverable_id=$selectedDeliverableId");
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Failed to delete rubric: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Rubric not found!</div>";
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Coordinator - Manage Rubrics</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <style>
        .rubric-table, .score-range-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px; 
        }
        .rubric-table th, .rubric-table td, 
        .score-range-table th, .score-range-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        .rubric-table th, .score-range-table th { 
            background-color: #f8f9fc; 
            font-weight: bold; 
        }
        .rubric-table tr:nth-child(even), 
        .score-range-table tr:nth-child(even) { 
            background-color: #f8f9fc; 
        }
        .rubric-table tr:hover, 
        .score-range-table tr:hover { 
            background-color: #e2e6ea; 
        }
        .rubric-table th:nth-child(1), .rubric-table td:nth-child(1) { /* Criteria */
            width: 30%;
        }
        .rubric-table th:nth-child(2), .rubric-table td:nth-child(2) { /* Component */
            width: 50%;
        }
        .rubric-table th:nth-child(3), .rubric-table td:nth-child(3) { /* Actions */
            width: 20%;
        }
        .score-range-table th:nth-child(1), .score-range-table td:nth-child(1) { /* Score Range */
            width: 20%;
        }
        .score-range-table th:nth-child(2), .score-range-table td:nth-child(2) { /* Description */
            width: 80%;
        }
        .modal-body { 
            max-height: 70vh; 
            overflow-y: auto; 
        }
        .range-group { 
            margin-bottom: 15px; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
        }
        .table-responsive { 
            overflow-x: auto; 
        }
        .btn-icon-split.btn-sm .icon {
            padding: 0.25rem 0.5rem;
        }
        .btn-icon-split.btn-sm .text {
            padding: 0.25rem 0.5rem;
        }
        .action-buttons {
            display: inline-flex;
            align-items: center;
        }
        .action-buttons .btn {
            margin-right: 5px;
        }
        .action-buttons .btn:last-child {
            margin-right: 0;
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
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Coordinator Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo"
                    aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student <br>Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors & <br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                    </div>
                </div>
            </li>
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseUtilities"
                    aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities"
                    data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">FYP Evaluation:</h6>
                        <a class="collapse-item" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item" href="coorviewstudentdetails.php">View Student Details</a>
                        <a class="collapse-item active" href="coormanagerubrics.php">Manage Rubrics</a>
                        <a class="collapse-item" href="coorassignassessment.php">Assign Assessment</a>
                        <!-- <a class="collapse-item" href="coorevaluatestudent.php">Evaluate Students</a> -->
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages"
                    aria-expanded="true" aria-controls="collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Resources & Communication</span>
                </a>
                <div id="collapsePages" class="collapse" aria-labelledby="headingPages" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Support Tools:</h6>
                        <a class="collapse-item" href="coormanageannouncement.php">Manage Announcement</a>
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
        <!-- End of Sidebar -->

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="coorprofile.php"><i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>Profile</a>
                                <a class="dropdown-item" href="#"><i class="fas fa-cogs fa-sm fa-fw mr-2 text-gray-400"></i>Settings</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal"><i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>Logout</a>
                            </div>
                        </li>
                    </ul>
                </nav>
                <!-- End of Topbar -->

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Manage Rubrics</h1>
                    </div>
                    <?= $message ?>
                    <div class="row">
                        <div class="col-lg-4">
                            <!-- Semester Filter Card -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Filter by Semester</h6></div>
                                <div class="card-body">
                                    <form method="GET" action="">
                                        <div class="form-group">
                                            <label for="semester">Semester</label>
                                            <select class="form-control" id="semester" name="semester" onchange="this.form.submit()">
                                                <?php if (empty($semesters)): ?>
                                                    <option value="">No semesters available</option>
                                                <?php else: ?>
                                                    <?php foreach ($semesters as $semester): ?>
                                                        <option value="<?= htmlspecialchars($semester['semester_name']) ?>" <?= $selectedSemester === $semester['semester_name'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($semester['semester_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <?php if ($selectedDeliverableId): ?>
                                            <input type="hidden" name="deliverable_id" value="<?= htmlspecialchars($selectedDeliverableId) ?>">
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                            <!-- Select Deliverable -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Select Deliverable</h6></div>
                                <div class="card-body">
                                    <form method="GET" action="">
                                        <div class="form-group">
                                            <label for="deliverable_id">Deliverable</label>
                                            <select class="form-control" id="deliverable_id" name="deliverable_id" onchange="this.form.submit()">
                                                <?php if (empty($deliverables)): ?>
                                                    <option value="">No deliverables available</option>
                                                <?php else: ?>
                                                    <?php foreach ($deliverables as $deliverable): ?>
                                                        <option value="<?= $deliverable['id'] ?>" <?= $selectedDeliverableId == $deliverable['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($deliverable['name'] . ' (' . $deliverable['semester'] . ') - Weightage: ' . number_format($deliverable['weightage'], 2) . '%') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <input type="hidden" name="semester" value="<?= htmlspecialchars($selectedSemester) ?>">
                                    </form>
                                    <?php if ($selectedDeliverableId): ?>
                                        <?php foreach ($deliverables as $deliverable): ?>
                                            <?php if ($deliverable['id'] == $selectedDeliverableId): ?>
                                                <p><strong>Weightage:</strong> <?= number_format($deliverable['weightage'], 2) ?>%</p>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Add/Edit Rubric -->
                            <?php if ($selectedDeliverableId): ?>
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Add/Edit Rubric</h6></div>
                                    <div class="card-body">
                                        <form method="POST" action="">
                                            <input type="hidden" name="deliverable_id" value="<?= $selectedDeliverableId ?>">
                                            <div class="form-group">
                                                <label for="rubric_id">Select Rubric (Leave 0 for new)</label>
                                                <select class="form-control" id="rubric_id" name="rubric_id" onchange="populateFields(this)">
                                                    <option value="0">-- New Rubric --</option>
                                                    <?php foreach ($rubrics as $rubric): ?>
                                                        <option value="<?= $rubric['id'] ?>" 
                                                                data-criteria="<?= htmlspecialchars($rubric['criteria']) ?>" 
                                                                data-component="<?= htmlspecialchars($rubric['component'] ?? '') ?>">
                                                            <?= htmlspecialchars($rubric['criteria']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="criteria">Criteria</label>
                                                <input type="text" class="form-control" id="criteria" name="criteria" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="component">Component</label>
                                                <textarea class="form-control" id="component" name="component" rows="4"></textarea>
                                            </div>
                                            <button type="submit" name="save_rubric" class="btn btn-primary btn-icon-split">
                                                <span class="icon text-white-50"><i class="fas fa-upload"></i></span>
                                                <span class="text">Save Rubric</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-8">
                            <?php if ($selectedDeliverableId): ?>
                                <!-- Rubrics for Selected Deliverable -->
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Rubrics for Selected Deliverable</h6></div>
                                    <div class="card-body">
                                        <?php if (empty($rubrics)): ?>
                                            <p class="text-center">No rubrics found for this deliverable.</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="rubric-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Criteria</th>
                                                            <th>Component</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($rubrics as $rubric): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($rubric['criteria']) ?></td>
                                                                <td><?= htmlspecialchars($rubric['component'] ?? 'N/A') ?></td>
                                                                <td>
                                                                    <div class="action-buttons">
                                                                        <button class="btn btn-info btn-icon-split btn-sm" 
                                                                                data-toggle="modal" 
                                                                                data-target="#scoreRangeModal"
                                                                                onclick='openScoreRangeModal(<?= $rubric["id"] ?>, "<?= addslashes(htmlspecialchars($rubric["criteria"])) ?>", <?= isset($scoreRanges[$rubric["id"]]) ? json_encode($scoreRanges[$rubric["id"]], JSON_HEX_QUOT | JSON_HEX_APOS) : "[]" ?>)'>
                                                                            <span class="icon text-white-50">
                                                                                <i class="fas fa-list"></i>
                                                                            </span>
                                                                            <span class="text">Score Ranges</span>
                                                                        </button>
                                                                        <button class="btn btn-danger btn-sm" 
                                                                                data-toggle="modal" 
                                                                                data-target="#deleteRubricModal"
                                                                                onclick="setDeleteRubricModal(<?= $rubric['id'] ?>, '<?= htmlspecialchars($rubric['criteria']) ?>')">
                                                                            <i class="fas fa-trash"></i>
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Score Ranges for Rubrics -->
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Score Ranges for Rubrics</h6></div>
                                    <div class="card-body">
                                        <?php if (empty($rubrics)): ?>
                                            <p class="text-center">No score ranges available. Add rubrics first.</p>
                                        <?php else: ?>
                                            <?php foreach ($rubrics as $rubric): ?>
                                                <h6><?= htmlspecialchars($rubric['criteria']) ?></h6>
                                                <table class="score-range-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Score Range</th>
                                                            <th>Description</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $ranges = isset($scoreRanges[$rubric['id']]) ? $scoreRanges[$rubric['id']] : [];
                                                        $rangeMap = array_column($ranges, 'description', 'score_range');
                                                        foreach ($fixedScoreRanges as $range): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($range) ?></td>
                                                                <td><?= htmlspecialchars($rangeMap[$range] ?? 'No description') ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                <hr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="card shadow mb-4">
                                    <div class="card-body">
                                        <p class="text-center">Please select a deliverable from the left panel.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright © FYPCollabor8 2025</span>
                    </div>
                </div>
            </footer>
            <!-- End of Footer -->

            <!-- Score Range Modal -->
            <div class="modal fade" id="scoreRangeModal" tabindex="-1" role="dialog" aria-labelledby="scoreRangeModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="scoreRangeModalLabel">Manage Score Range Descriptions for <span id="scoreRangeCriteria"></span></h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="" id="scoreRangeForm">
                                <input type="hidden" name="rubric_id" id="scoreRangeRubricId">
                                <div id="rangeContainer">
                                    <!-- Score range description fields will be added here -->
                                </div>
                                <hr>
                                <button type="submit" name="save_score_ranges" class="btn btn-primary">Save Descriptions</button>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Rubric Modal -->
            <div class="modal fade" id="deleteRubricModal" tabindex="-1" role="dialog" aria-labelledby="deleteRubricModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteRubricModalLabel">Confirm Deletion</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
                        </div>
                        <div class="modal-body">
                            Are you sure you want to delete the rubric <strong id="deleteRubricCriteria"></strong>? This action cannot be undone.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <form id="confirmDeleteRubricForm" method="POST" action="">
                                <input type="hidden" name="delete_rubric_id" id="confirmDeleteRubricId">
                                <button type="submit" name="delete_rubric" class="btn btn-danger">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logout Modal -->
            <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
                        </div>
                        <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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

            <script>
                function populateFields(select) {
                    const criteriaField = document.getElementById('criteria');
                    const componentField = document.getElementById('component');

                    if (select.value === '0') {
                        criteriaField.value = '';
                        componentField.value = '';
                    } else {
                        const option = select.options[select.selectedIndex];
                        criteriaField.value = option.dataset.criteria;
                        componentField.value = option.dataset.component;
                    }
                }

                function setDeleteRubricModal(id, criteria) {
                    document.getElementById('deleteRubricCriteria').textContent = criteria || 'this rubric';
                    document.getElementById('confirmDeleteRubricId').value = id;
                }

                function addRangeField(range, description = '') {
                    const container = document.getElementById('rangeContainer');
                    const rangeDiv = document.createElement('div');
                    rangeDiv.className = 'range-group';
                    rangeDiv.innerHTML = `
                        <div class="form-group">
                            <label>Score Range: ${range}</label>
                            <textarea class="form-control" name="description[]" rows="3" placeholder="Enter description for ${range}" required>${description}</textarea>
                        </div>
                    `;
                    container.appendChild(rangeDiv);
                }

                function openScoreRangeModal(id, criteria, scoreRanges) {
                    try {
                        console.log('Opening modal for rubric_id:', id, 'criteria:', criteria, 'scoreRanges:', scoreRanges);
                        
                        // Set rubric ID and criteria
                        document.getElementById('scoreRangeRubricId').value = id;
                        document.getElementById('scoreRangeCriteria').textContent = criteria || 'Unknown Rubric';
                        
                        // Clear existing fields
                        const container = document.getElementById('rangeContainer');
                        container.innerHTML = '';

                        // Fixed score ranges
                        const fixedRanges = ['0-2', '3-4', '5-6', '7-8', '9-10'];

                        // Parse scoreRanges if it's a string (in case JSON is passed as string)
                        let ranges = scoreRanges;
                        if (typeof scoreRanges === 'string') {
                            try {
                                ranges = JSON.parse(scoreRanges);
                            } catch (e) {
                                console.error('Failed to parse scoreRanges JSON:', e);
                                ranges = [];
                            }
                        }

                        // Map existing descriptions to fixed ranges
                        const descriptions = {};
                        if (Array.isArray(ranges) && ranges.length > 0) {
                            ranges.forEach(range => {
                                if (range.score_range && range.description !== undefined) {
                                    descriptions[range.score_range] = range.description || '';
                                }
                            });
                        }
                        console.log('Mapped descriptions:', descriptions);

                        // Add fields for each fixed range
                        fixedRanges.forEach(range => {
                            const description = descriptions[range] || '';
                            console.log('Adding field for range:', range, 'with description:', description);
                            addRangeField(range, description);
                        });

                        // Show the modal
                        $('#scoreRangeModal').modal('show');
                    } catch (error) {
                        console.error('Error in openScoreRangeModal:', error);
                        alert('An error occurred while opening the score range modal. Please try again.');
                    }
                }
            </script>
        </div>
    </div>
    <a class="scroll-to-top rounded" href="#page-top"><i class="fas fa-angle-up"></i></a>
</body>
</html>