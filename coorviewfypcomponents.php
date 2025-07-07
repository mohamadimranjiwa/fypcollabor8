<?php
session_start();

include 'connection.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Ensure the coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}
$coordinatorID = $_SESSION['user_id'];

// Verify database connection
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Connection failed: Please contact the administrator.");
}

// Fetch coordinator details
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed (Coordinator Info): " . $conn->error);
    die("Error preparing coordinator query: " . $conn->error);
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
    'full_name' => htmlspecialchars($coordinator['full_name'] ?? 'N/A'),
    'profile_picture' => htmlspecialchars($coordinator['profile_picture'] ?? 'img/undraw_image.svg')
];

// Initialize message
$message = '';

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1";
$currentSemesterResult = $conn->query($currentSemesterQuery);
if ($currentSemesterResult === false) {
    error_log("Current semester query failed: " . $conn->error);
    $message .= "<div class='alert alert-error'>Error fetching current semester.</div>";
}
$currentSemester = $currentSemesterResult && $currentSemesterResult->num_rows > 0 
    ? $currentSemesterResult->fetch_assoc()['semester_name'] 
    : 'May 2025';

// Fetch semesters for the filter
$semestersQuery = "SELECT semester_name FROM semesters ORDER BY semester_name DESC";
$semestersResult = $conn->query($semestersQuery);
if ($semestersResult === false) {
    error_log("Semesters query failed: " . $conn->error);
    $message .= "<div class='alert alert-error'>Error fetching semesters.</div>";
}
$semesters = $semestersResult ? $semestersResult->fetch_all(MYSQLI_ASSOC) : [];

// Initialize filter parameters
$selectedSemester = isset($_GET['semester']) && !empty($_GET['semester']) ? trim($_GET['semester']) : $currentSemester;
$searchStudent = isset($_GET['student_name']) ? trim($_GET['student_name']) : '';
$selectedDeliverable = isset($_GET['deliverable_id']) && is_numeric($_GET['deliverable_id']) ? intval($_GET['deliverable_id']) : 0;
$selectedGroup = isset($_GET['group_id']) && is_numeric($_GET['group_id']) ? intval($_GET['group_id']) : 0;

// Fetch deliverables for the filter
$deliverablesSemester = $selectedSemester ?: $currentSemester;
$deliverablesQuery = "SELECT id, name FROM deliverables WHERE semester = ? ORDER BY name ASC";
$stmt = $conn->prepare($deliverablesQuery);
if (!$stmt) {
    error_log("Prepare failed (Deliverables Query): " . $conn->error);
    $message .= "<div class='alert alert-error'>Error preparing deliverables query.</div>";
} else {
    $stmt->bind_param("s", $deliverablesSemester);
    $stmt->execute();
    $deliverablesResult = $stmt->get_result();
    $deliverables = $deliverablesResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch groups for the filter
$groupsQuery = "
    SELECT DISTINCT g.id, g.name 
    FROM groups g
    JOIN students s_leader ON g.leader_id = s_leader.id
    WHERE s_leader.intake_month = SUBSTRING_INDEX(?, ' ', 1)
    AND s_leader.intake_year = CAST(SUBSTRING_INDEX(?, ' ', -1) AS UNSIGNED)
    AND g.status = 'Approved'
    ORDER BY g.name ASC";
$groupsStmt = $conn->prepare($groupsQuery);
if ($groupsStmt === false) {
    error_log("Prepare failed (Groups Query): " . $conn->error);
    $message .= "<div class='alert alert-error'>Error preparing groups query.</div>";
} else {
    $groupsStmt->bind_param("ss", $selectedSemester, $selectedSemester);
    $groupsStmt->execute();
    $groupsResult = $groupsStmt->get_result();
    $groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
    $groupsStmt->close();
}

// Check for empty results
if (empty($deliverables)) {
    $message .= "<div class='alert alert-warning'>No deliverables found for semester: " . htmlspecialchars($deliverablesSemester) . "</div>";
}
if (empty($groups)) {
    $message .= "<div class='alert alert-warning'>No groups found for semester: " . htmlspecialchars($selectedSemester) . "</div>";
}

// Build submissions query
$groupConditions = [];
$individualConditions = [];
$groupParams = [];
$groupParamTypes = "";
$individualParams = [];
$individualParamTypes = "";

// Semester filter
$effectiveSemester = $selectedSemester ?: $currentSemester;
$groupConditions[] = "d.semester = ?";
$groupParams[] = $effectiveSemester;
$groupParamTypes .= "s";
$individualConditions[] = "d.semester = ?";
$individualParams[] = $effectiveSemester;
$individualParamTypes .= "s";

// Deliverable filter
if ($selectedDeliverable > 0) {
    $groupConditions[] = "d.id = ?";
    $groupParams[] = $selectedDeliverable;
    $groupParamTypes .= "i";
    $individualConditions[] = "d.id = ?";
    $individualParams[] = $selectedDeliverable;
    $individualParamTypes .= "i";
}

// Group filter
if ($selectedGroup > 0) {
    $groupConditions[] = "g.id = ?";
    $groupParams[] = $selectedGroup;
    $groupParamTypes .= "i";
    $individualConditions[] = "g.id = ?";
    $individualParams[] = $selectedGroup;
    $individualParamTypes .= "i";
}

// Student or group name search
if ($searchStudent) {
    $groupConditions[] = "g.name LIKE ?";
    $groupParams[] = "%$searchStudent%";
    $groupParamTypes .= "s";
    $individualConditions[] = "(s.username LIKE ? OR s.full_name LIKE ? OR g.name LIKE ?)";
    $individualParams[] = "%$searchStudent%";
    $individualParams[] = "%$searchStudent%";
    $individualParams[] = "%$searchStudent%";
    $individualParamTypes .= "sss";
}

// Submissions query with semester filtering
$submissionsQuery = "
    -- Group submissions
    SELECT 
        d.id AS deliverable_id, 
        d.name AS deliverable_name, 
        d.semester, 
        d.submission_type, 
        g.id AS group_id, 
        g.name AS group_name, 
        NULL AS student_id, 
        NULL AS student_name, 
        ds.id AS submission_id, 
        ds.file_path, 
        ds.submitted_at
    FROM deliverables d
    JOIN groups g ON g.status = 'Approved'
    JOIN students s_leader ON g.leader_id = s_leader.id
    LEFT JOIN deliverable_submissions ds 
        ON d.id = ds.deliverable_id 
        AND d.submission_type = 'group' 
        AND ds.group_id = g.id
    WHERE d.submission_type = 'group'
        AND s_leader.intake_month = SUBSTRING_INDEX(?, ' ', 1)
        AND s_leader.intake_year = CAST(SUBSTRING_INDEX(?, ' ', -1) AS UNSIGNED)";
if (!empty($groupConditions)) {
    $submissionsQuery .= " AND " . implode(" AND ", $groupConditions);
}
$submissionsQuery .= "
    UNION
    -- Individual submissions
    SELECT 
        d.id AS deliverable_id, 
        d.name AS deliverable_name, 
        d.semester, 
        d.submission_type, 
        g.id AS group_id, 
        g.name AS group_name, 
        s.id AS student_id, 
        s.full_name AS student_name, 
        ds.id AS submission_id, 
        ds.file_path, 
        ds.submitted_at
    FROM deliverables d
    JOIN groups g ON g.status = 'Approved'
    JOIN students s_leader ON g.leader_id = s_leader.id
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id
    LEFT JOIN deliverable_submissions ds 
        ON d.id = ds.deliverable_id 
        AND d.submission_type = 'individual' 
        AND ds.student_id = s.id 
        AND ds.group_id = g.id
    WHERE d.submission_type = 'individual'
        AND s_leader.intake_month = SUBSTRING_INDEX(?, ' ', 1)
        AND s_leader.intake_year = CAST(SUBSTRING_INDEX(?, ' ', -1) AS UNSIGNED)";
if (!empty($individualConditions)) {
    $submissionsQuery .= " AND " . implode(" AND ", $individualConditions);
}
$submissionsQuery .= " ORDER BY deliverable_name, group_name, student_name, submitted_at DESC";

// Combine parameters
$finalParams = array_merge(
    [$effectiveSemester, $effectiveSemester], // Group semester filter
    $groupParams,
    [$effectiveSemester, $effectiveSemester], // Individual semester filter
    $individualParams
);
$finalParamTypes = "ss" . $groupParamTypes . "ss" . $individualParamTypes;

error_log("Submissions Query: $submissionsQuery");
error_log("Final Params: " . json_encode($finalParams));
error_log("Final ParamTypes: $finalParamTypes");

$stmt = $conn->prepare($submissionsQuery);
if ($stmt === false) {
    error_log("Prepare failed (Submissions Query): " . $conn->error);
    $message .= "<div class='alert alert-error'>Error preparing submissions query: " . htmlspecialchars($conn->error) . "</div>";
} else {
    if (!empty($finalParams)) {
        $stmt->bind_param($finalParamTypes, ...$finalParams);
    }
    $stmt->execute();
    $submissionsResult = $stmt->get_result();
    $submissions = $submissionsResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch group members
$groupMembers = [];
foreach ($submissions as &$submission) {
    $groupId = $submission['group_id'];
    if (!isset($groupMembers[$groupId])) {
        $membersQuery = "
            SELECT s.full_name
            FROM group_members gm
            JOIN students s ON gm.student_id = s.id
            WHERE gm.group_id = ?
            ORDER BY s.full_name ASC";
        $stmt = $conn->prepare($membersQuery);
        if ($stmt === false) {
            error_log("Prepare failed (Members Query): " . $conn->error);
            $submission['group_members'] = ['Error fetching members'];
            continue;
        }
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $membersResult = $stmt->get_result();
        $groupMembers[$groupId] = array_column($membersResult->fetch_all(MYSQLI_ASSOC), 'full_name');
        $stmt->close();
    }
    $submission['group_members'] = $groupMembers[$groupId] ?? [];
}
unset($submission);

// Calculate statistics (Updated to match lecturer script)
$totalSubmissions = count(array_filter($submissions, fn($s) => !empty($s['submission_id'])));
$pendingSubmissions = count(array_filter($submissions, fn($s) => empty($s['submission_id'])));
$completedSubmissions = $totalSubmissions;
$completedPercentage = count($submissions) > 0 ? round(($completedSubmissions / count($submissions)) * 100) : 0;
$uncompletedSubmissions = $pendingSubmissions;

// Check for invalid IDs
$invalid_ids_found = false;
foreach ($submissions as $submission) {
    if ($submission['deliverable_id'] <= 0 || $submission['group_id'] <= 0) {
        $invalid_ids_found = true;
        break;
    }
}
if ($invalid_ids_found) {
    $message .= "<div class='alert alert-error'>Error: Invalid IDs (deliverable or group) found. Please contact the administrator.</div>";
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
    <title>Coordinator - View Student Submissions</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
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
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors &<br> Assessors</a>
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
                        <a class="collapse-item active" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item" href="coorviewstudentdetails.php">View Student Details</a>
                        <a class="collapse-item" href="coormanagerubrics.php">Manage Rubrics</a>
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
        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3" aria-label="Toggle Sidebar">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= $personalInfo['full_name'] ?></span>
                                <img class="img-profile rounded-circle" src="<?= $personalInfo['profile_picture'] ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
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
                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">View Student Submissions</h1>
                    </div>
                    <?= $message ?>
                    <!-- Statistics Cards (Updated to match lecturer script) -->
                    <div class="row">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Total Submissions</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalSubmissions) ?> Submissions
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Pending Submissions</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($pendingSubmissions) ?> Pending
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-hourglass-start fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Completed Submissions</div>
                                            <div class="row no-gutters align-items-center">
                                                <div class="col-auto">
                                                    <div class="h5 mb-0 mr-3 font-weight-bold text-gray-800">
                                                        <?= htmlspecialchars($completedPercentage) ?>%
                                                    </div>
                                                </div>
                                                <div class="col">
                                                    <div class="progress progress-sm mr-2">
                                                        <div class="progress-bar bg-info" role="progressbar" 
                                                             style="width: <?= htmlspecialchars($completedPercentage) ?>%" 
                                                             aria-valuenow="<?= htmlspecialchars($completedPercentage) ?>" 
                                                             aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Uncompleted Submissions</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($uncompletedSubmissions) ?> Uncompleted
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Filters Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Submissions</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label for="semester">Semester</label>
                                        <select class="form-control" id="semester" name="semester">
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?= htmlspecialchars($semester['semester_name']) ?>" <?= $selectedSemester === $semester['semester_name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($semester['semester_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="student_name">Username</label>
                                        <input type="text" class="form-control" id="student_name" name="student_name" value="<?= htmlspecialchars($searchStudent) ?>" placeholder="Enter student username">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="deliverable_id">Deliverable</label>
                                        <select class="form-control" id="deliverable_id" name="deliverable_id">
                                            <option value="0">-- All Deliverables --</option>
                                            <?php foreach ($deliverables as $deliverable): ?>
                                                <option value="<?= $deliverable['id'] ?>" <?= $selectedDeliverable === $deliverable['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($deliverable['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="group_id">Group</label>
                                        <select class="form-control" id="group_id" name="group_id">
                                            <option value="0">-- All Groups --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?= $group['id'] ?>" <?= $selectedGroup === $group['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="coorviewfypcomponents.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>
                    <!-- Submissions Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Student Submissions</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Submitter</th>
                                            <th>Deliverable Name</th>
                                            <th>Semester</th>
                                            <th>File</th>
                                            <th>Submitted At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($submissions)): ?>
                                            <?php foreach ($submissions as $submission): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($submission['submission_type'] === 'group'): ?>
                                                            <?= htmlspecialchars($submission['group_name'] ?? 'Group ' . $submission['group_id']) ?>
                                                            <br>
                                                            <small>(Members: <?= htmlspecialchars(!empty($submission['group_members']) ? implode(', ', $submission['group_members']) : 'None') ?>)</small>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars($submission['student_name'] ?? 'N/A') ?>
                                                            <br>
                                                            <small>(Group: <?= htmlspecialchars($submission['group_name'] ?? 'N/A') ?>)</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($submission['deliverable_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($submission['semester'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <?php if (!empty($submission['file_path']) && $submission['submission_id']): ?>
                                                            <a href="<?= htmlspecialchars($submission['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary">View File</a>
                                                        <?php else: ?>
                                                            <span class="text-danger">No File</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($submission['submitted_at'] && $submission['submission_id']): ?>
                                                            <?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($submission['submitted_at']))) ?>
                                                        <?php else: ?>
                                                            <span class="text-danger">Not Submitted</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center">No submissions or deliverables found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
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
        </div>
    </div>
    <!-- Scroll to Top Button -->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>
    <!-- Logout Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="dialog">
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
    <!-- Bootstrap core JavaScript -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="js/demo/datatables-demo.js"></script>
    <script>
        $(document).ready(function() {
            if (!$.fn.DataTable.isDataTable('#dataTable')) {
                $('#dataTable').DataTable({
                    responsive: true,
                    paging: true,
                    searching: true,
                    ordering: true,
                    info: true,
                    autoWidth: false
                });
            }
        });
    </script>
</body>
</html>