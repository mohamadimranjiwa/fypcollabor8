<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

include 'connection.php';

// Ensure the coordinator is logged in
if (isset($_SESSION['user_id'])) {
    $coordinatorID = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if ($coordinatorID === false) {
        header("Location: index.html");
        exit();
    }
} else {
    header("Location: index.html");
    exit();
}

// Fetch the coordinator's full name and profile picture
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();
$stmt->close();

if (!$coordinator) {
    die("Error: No coordinator found with ID $coordinatorID.");
}

$personalInfo = [
    'full_name' => $coordinator['full_name'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
} else {
    $message = "";
}

// Fetch semesters
$semestersQuery = "SELECT semester_name, is_current FROM semesters ORDER BY semester_name DESC";
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . htmlspecialchars($conn->error));
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery);
$currentSemester = $currentSemesterResult->fetch_assoc()['semester_name'] ?? '';

// Get filter values
$filterSemester = $_GET['filter_semester'] ?? $currentSemester;
$filterType = $_GET['filter_type'] ?? 'all';

// Build WHERE clause for deliverables
$whereClauses = [];
$params = [];
$types = '';

if (!empty($filterSemester)) {
    $whereClauses[] = 'semester = ?';
    $params[] = $filterSemester;
    $types .= 's';
}
if ($filterType !== 'all') {
    $whereClauses[] = 'submission_type = ?';
    $params[] = $filterType;
    $types .= 's';
}

$whereSQL = '';
if ($whereClauses) {
    $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
}

$deliverablesQuery = "
    SELECT id, name, semester, feedback, submission_type, weightage
    FROM deliverables
    $whereSQL
    ORDER BY name ASC";

$stmt = $conn->prepare($deliverablesQuery);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$deliverablesResult = $stmt->get_result();
$deliverables = $deliverablesResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check for invalid IDs
$invalid_ids_found = false;
foreach ($deliverables as $deliverable) {
    if ($deliverable['id'] <= 0) {
        $invalid_ids_found = true;
        break;
    }
}
if ($invalid_ids_found) {
    $message = "<div class='alert alert-danger'>Error: Invalid deliverable IDs found. Contact the administrator.</div>";
}

// Initialize edit form variables
$editFormError = false;
$editFormValues = [
    'component_id' => '',
    'name' => '',
    'semester' => '',
    'submission_type' => 'individual',
    'weightage' => '',
    'feedback' => ''
];

// Handle deliverable addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_component'])) {
    $name = trim($_POST['name']);
    $semester = trim($_POST['semester']);
    $feedback = trim($_POST['feedback']) ?: null;
    $submission_type = trim($_POST['submission_type']) === 'group' ? 'group' : 'individual';
    $weightage = floatval($_POST['weightage']);
    error_log("Add deliverable: " . json_encode($_POST));

    if ($weightage < 0 || $weightage > 100) {
        $message = "<div class='alert alert-danger'>Error: Weightage must be between 0 and 100.</div>";
    } else {
        $checkDuplicateQuery = "SELECT id FROM deliverables WHERE name = ? AND semester = ?";
        $stmt = $conn->prepare($checkDuplicateQuery);
        $stmt->bind_param("ss", $name, $semester);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $message = "<div class='alert alert-danger'>Error: A deliverable with this name already exists in $semester.</div>";
            $stmt->close();
        } else {
            $stmt->close();
            $totalWeightageQuery = "SELECT SUM(weightage) as total FROM deliverables WHERE semester = ?";
            $stmt = $conn->prepare($totalWeightageQuery);
            $stmt->bind_param("s", $semester);
            $stmt->execute();
            $totalWeightage = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
            $stmt->close();

            if ($totalWeightage + $weightage > 100) {
                $message = "<div class='alert alert-danger'>Error: Total weightage for $semester cannot exceed 100%. Current: " . number_format($totalWeightage, 2) . "% + Proposed: " . number_format($weightage, 2) . "%.</div>";
            } else {
                $conn->begin_transaction();
                try {
                    $sql = "INSERT INTO deliverables (name, semester, feedback, submission_type, weightage) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssssd", $name, $semester, $feedback, $submission_type, $weightage);

                    if ($stmt->execute()) {
                        $_SESSION['message'] = "<div class='alert alert-success'>Deliverable added successfully!</div>";
                        header("Refresh:1");
                    } else {
                        $message = "<div class='alert alert-danger'>Failed to add deliverable: " . htmlspecialchars($stmt->error) . "</div>";
                        error_log("Add deliverable failed: " . $stmt->error);
                    }
                    $stmt->close();
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "<div class='alert alert-danger'>Failed to add deliverable: " . htmlspecialchars($e->getMessage()) . "</div>";
                    error_log("Exception during add: " . $e->getMessage());
                }
            }
        }
    }
}

// Handle deliverable editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_component'])) {
    error_log("Edit deliverable POST: " . json_encode($_POST));
    $editFormValues['component_id'] = intval($_POST['component_id']);
    $editFormValues['name'] = trim($_POST['name']);
    $editFormValues['semester'] = trim($_POST['semester']);
    $editFormValues['feedback'] = trim($_POST['feedback']) ?: null;
    $editFormValues['submission_type'] = trim($_POST['submission_type']) === 'group' ? 'group' : 'individual';
    $editFormValues['weightage'] = floatval($_POST['weightage']);

    $component_id = $editFormValues['component_id'];
    $name = $editFormValues['name'];
    $semester = $editFormValues['semester'];
    $feedback = $editFormValues['feedback'];
    $submission_type = $editFormValues['submission_type'];
    $weightage = $editFormValues['weightage'];

    // Validate inputs
    if ($weightage < 0 || $weightage > 100) {
        $message = "<div class='alert alert-danger'>Error: Weightage must be between 0 and 100.</div>";
        $editFormError = true;
        error_log("Edit validation failed: Invalid weightage $weightage");
    } elseif ($component_id <= 0) {
        $message = "<div class='alert alert-danger'>Error: Please select a valid deliverable.</div>";
        $editFormError = true;
        error_log("Edit validation failed: Invalid component_id $component_id");
    } else {
        // Calculate total weightage excluding current deliverable
        $totalWeightageQuery = "SELECT SUM(weightage) as total FROM deliverables WHERE semester = ? AND id != ?";
        $stmt = $conn->prepare($totalWeightageQuery);
        $stmt->bind_param("si", $semester, $component_id);
        $stmt->execute();
        $totalWeightage = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        if ($totalWeightage + $weightage > 100) {
            $message = "<div class='alert alert-danger'>Error: Total weightage for $semester cannot exceed 100%. Current: " . number_format($totalWeightage, 2) . "% + Proposed: " . number_format($weightage, 2) . "%.</div>";
            $editFormError = true;
            error_log("Edit failed: Weightage exceeds 100% (Current: $totalWeightage, Proposed: $weightage)");
        } else {
            $conn->begin_transaction();
            try {
                $sql = "UPDATE deliverables SET name = ?, semester = ?, feedback = ?, submission_type = ?, weightage = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssdi", $name, $semester, $feedback, $submission_type, $weightage, $component_id);

                if ($stmt->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>Deliverable updated successfully!</div>";
                    error_log("Deliverable ID $component_id updated successfully to name '$name'");
                    header("Refresh:1");
                } else {
                    $message = "<div class='alert alert-danger'>Failed to update deliverable: " . htmlspecialchars($stmt->error) . "</div>";
                    $editFormError = true;
                    error_log("Update deliverable ID $component_id failed: " . $stmt->error);
                }
                $stmt->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $message = "<div class='alert alert-danger'>Failed to update deliverable: " . htmlspecialchars($e->getMessage()) . "</div>";
                $editFormError = true;
                error_log("Exception during update ID $component_id: " . $e->getMessage());
            }
        }
    }
}

// Handle deliverable deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_component'])) {
    $component_id = isset($_POST['component_id']) && $_POST['component_id'] !== '' ? intval($_POST['component_id']) : 0;
    error_log("Delete deliverable ID: $component_id");

    if ($component_id > 0 && in_array($component_id, array_column($deliverables, 'id'))) {
        $conn->begin_transaction();
        try {
            $sql = "DELETE FROM deliverables WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $component_id);

            if ($stmt->execute()) {
                $_SESSION['message'] = "<div class='alert alert-success'>Deliverable deleted successfully!</div>";
                header("Refresh:1");
            } else {
                $message = "<div class='alert alert-danger'>Failed to delete deliverable: " . htmlspecialchars($stmt->error) . "</div>";
                error_log("Delete deliverable ID $component_id failed: " . $stmt->error);
            }
            $stmt->close();
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Failed to delete deliverable: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("Exception during delete ID $component_id: " . $e->getMessage());
        }
    } else {
        $message = "<div class='alert alert-warning'>Please select a valid deliverable to delete.</div>";
        error_log("Delete failed: Invalid component_id $component_id");
    }
}

// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <title>Coordinator - Assign Assessment</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

    <style>
        #editSubmitButton:disabled {
            cursor: not-allowed;
            opacity: 0.65;
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
                        <a class="collapse-item" href="coormanagerubrics.php">Manage Rubrics</a>
                        <a class="collapse-item active" href="coorassignassessment.php">Assign Assessment</a>
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
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle"
                                    src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>"
                                    onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
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
                <!-- End of Topbar -->

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Assign Assessment</h1>
                        <a href="coorsetsemester.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-calendar-alt fa-sm text-white-50"></i> Manage Semester
                        </a>
                    </div>
                    <?= $message ?>
                    <!-- Add and Edit Deliverable Section -->
                    <div class="row">
                        <!-- Add Deliverable Card -->
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Add Deliverable</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="coorassignassessment.php">
                                        <div class="form-group">
                                            <label for="add_name">Deliverable Name</label>
                                            <input type="text" class="form-control" id="add_name" name="name" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="add_semester">Semester</label>
                                            <select class="form-control" id="add_semester" name="semester" required>
                                                <option value="">-- Select Semester --</option>
                                                <?php foreach ($semesters as $semester): ?>
                                                    <option value="<?= htmlspecialchars($semester['semester_name']) ?>">
                                                        <?= htmlspecialchars($semester['semester_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="add_submission_type">Submission Type</label>
                                            <select class="form-control" id="add_submission_type" name="submission_type" required>
                                                <option value="individual">Individual (Each student submits)</option>
                                                <option value="group">Group (One submission per group)</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="add_weightage">Weightage (%)</label>
                                            <input type="number" step="0.1" min="0" max="100" class="form-control" id="add_weightage" name="weightage" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="add_feedback">Feedback (Optional)</label>
                                            <textarea class="form-control" id="add_feedback" name="feedback" rows="3"></textarea>
                                        </div>
                                        <button type="submit" name="add_component" class="btn btn-primary btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-upload"></i>
                                            </span>
                                            <span class="text">Add Deliverable</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Edit Deliverable Card -->
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Edit Deliverable</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($deliverables)): ?>
                                        <form method="POST" id="edit_form" action="coorassignassessment.php">
                                            <div class="form-group">
                                                <label for="component_id">Select Deliverable</label>
                                                <select class="form-control" id="component_id" name="component_id" onchange="populateEditFields(this)" required>
                                                    <option value="">-- Select a deliverable --</option>
                                                    <?php foreach ($deliverables as $deliverable): ?>
                                                        <option value="<?= $deliverable['id'] ?>" 
                                                                data-name="<?= htmlspecialchars($deliverable['name']) ?>" 
                                                                data-semester="<?= htmlspecialchars($deliverable['semester']) ?>" 
                                                                data-feedback="<?= htmlspecialchars($deliverable['feedback'] ?? '') ?>" 
                                                                data-submission-type="<?= htmlspecialchars($deliverable['submission_type']) ?>" 
                                                                data-weightage="<?= htmlspecialchars($deliverable['weightage']) ?>"
                                                                <?= $editFormError && $editFormValues['component_id'] == $deliverable['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($deliverable['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_name">Name</label>
                                                <input type="text" class="form-control" id="edit_name" name="name" value="<?= htmlspecialchars($editFormValues['name']) ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_semester">Semester</label>
                                                <select class="form-control" id="edit_semester" name="semester" required>
                                                    <option value="">-- Select Semester --</option>
                                                    <?php foreach ($semesters as $semester): ?>
                                                        <option value="<?= htmlspecialchars($semester['semester_name']) ?>" 
                                                                <?= $editFormError && $editFormValues['semester'] == $semester['semester_name'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($semester['semester_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_submission_type">Submission Type</label>
                                                <select class="form-control" id="edit_submission_type" name="submission_type" required>
                                                    <option value="individual" <?= $editFormError && $editFormValues['submission_type'] == 'individual' ? 'selected' : '' ?>>Individual (Each student submits)</option>
                                                    <option value="group" <?= $editFormError && $editFormValues['submission_type'] == 'group' ? 'selected' : '' ?>>Group (One submission per group)</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_weightage">Weightage (%)</label>
                                                <input type="number" step="0.1" min="0" max="100" class="form-control" id="edit_weightage" name="weightage" value="<?= htmlspecialchars($editFormValues['weightage']) ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_feedback">Feedback (Optional)</label>
                                                <textarea class="form-control" id="edit_feedback" name="feedback" rows="3"><?= htmlspecialchars($editFormValues['feedback']) ?></textarea>
                                            </div>
                                            <button type="submit" name="edit_component" class="btn btn-success btn-icon-split" id="editSubmitButton">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-check"></i>
                                                </span>
                                                <span class="text">Update Deliverable</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p class="text-muted">No deliverables available to edit.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Filters Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Deliverables</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="coorassignassessment.php">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="filter_semester">Semester</label>
                                        <select class="form-control" id="filter_semester" name="filter_semester">
                                            <option value="">All Semesters</option>
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?= htmlspecialchars($semester['semester_name']) ?>" <?= $filterSemester == $semester['semester_name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($semester['semester_name']) ?><?= $semester['is_current'] ? ' (Current)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="filter_type">Submission Type</label>
                                        <select class="form-control" id="filter_type" name="filter_type">
                                            <option value="all" <?= $filterType == 'all' ? 'selected' : '' ?>>All</option>
                                            <option value="individual" <?= $filterType == 'individual' ? 'selected' : '' ?>>Individual</option>
                                            <option value="group" <?= $filterType == 'group' ? 'selected' : '' ?>>Group</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="coorassignassessment.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>
                    <!-- Deliverables Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Deliverables List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Semester</th>
                                            <th>Feedback</th>
                                            <th>Submission Type</th>
                                            <th>Weightage (%)</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($deliverables)): ?>
                                            <?php foreach ($deliverables as $deliverable): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($deliverable['name']) ?></td>
                                                    <td><?= htmlspecialchars($deliverable['semester']) ?></td>
                                                    <td><?= htmlspecialchars($deliverable['feedback'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars(ucfirst($deliverable['submission_type'])) ?></td>
                                                    <td><?= number_format($deliverable['weightage'], 2) ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-danger btn-sm btn-icon-split" 
                                                                data-toggle="modal" data-target="#deleteConfirmModal"
                                                                onclick="setDeleteModalContent('<?= $deliverable['id'] ?>', '<?= htmlspecialchars($deliverable['name']) ?>')">
                                                            <span class="icon text-white-50">
                                                                <i class="fas fa-trash"></i>
                                                            </span>
                                                            <span class="text">Delete</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center">No deliverables found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            Are you sure you want to delete the deliverable <strong id="deleteDeliverableName"></strong>? This action cannot be undone.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <form id="confirmDeleteForm" method="POST" action="coorassignassessment.php">
                                <input type="hidden" name="component_id" id="confirmDeleteComponentId">
                                <button type="submit" name="delete_component" class="btn btn-danger">Delete</button>
                            </form>
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

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="logoutModalLabel">Ready to Leave?</h5>
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

    <!-- Custom script -->
    <script>
        function populateEditFields(select) {
            console.log('Selected deliverable ID:', select.value);
            const nameField = document.querySelector('#edit_name');
            const semesterField = document.querySelector('#edit_semester');
            const feedbackField = document.querySelector('#edit_feedback');
            const submissionTypeField = document.querySelector('#edit_submission_type');
            const weightageField = document.querySelector('#edit_weightage');
            const submitButton = document.querySelector('#editSubmitButton');

            if (select.value === '') {
                nameField.value = '';
                semesterField.value = '';
                feedbackField.value = '';
                submissionTypeField.value = 'individual';
                weightageField.value = '';
                submitButton.disabled = true;
                console.log('Cleared form fields');
            } else {
                const option = select.options[select.selectedIndex];
                nameField.value = option.dataset.name || '';
                semesterField.value = option.dataset.semester || '';
                feedbackField.value = option.dataset.feedback || '';
                submissionTypeField.value = option.dataset.submissionType || 'individual';
                weightageField.value = option.dataset.weightage || '';
                submitButton.disabled = false;
                console.log('Populated form:', {
                    name: option.dataset.name,
                    semester: option.dataset.semester,
                    feedback: option.dataset.feedback,
                    submissionType: option.dataset.submissionType,
                    weightage: option.dataset.weightage
                });
            }
        }

        function setDeleteModalContent(id, name) {
            console.log('Setting delete modal for ID:', id, 'Name:', name);
            document.querySelector('#deleteDeliverableName').textContent = name || 'this deliverable';
            document.querySelector('#confirmDeleteComponentId').value = id;
        }

        // Initialize form
        <?php if ($editFormError): ?>
            const select = document.querySelector('#component_id');
            if (select.value) {
                populateEditFields(select);
                console.log('Initialized form with error values');
            }
        <?php endif; ?>

        // Enable/disable submit button
        document.addEventListener('DOMContentLoaded', function() {
            const select = document.querySelector('#component_id');
            const submitButton = document.querySelector('#editSubmitButton');
            submitButton.disabled = !select.value;
            console.log('Page loaded, submit button disabled:', submitButton.disabled);
        });
    </script>
</body>
</html>