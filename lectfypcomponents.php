<?php
session_start();

include 'connection.php';

// Ensure the lecturer is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$lecturerID = $_SESSION['user_id'];

// Verify database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Fetch lecturer's details
$lecturerSQL = "SELECT full_name, profile_picture, role_id FROM lecturers WHERE id = ?";
$lecturerStmt = $conn->prepare($lecturerSQL);
if ($lecturerStmt === false) {
    die("Prepare failed (Lecturer Query): " . $conn->error);
}
$lecturerStmt->bind_param("i", $lecturerID);
$lecturerStmt->execute();
$lecturerResult = $lecturerStmt->get_result();
$lecturer = $lecturerResult->fetch_assoc();
$lecturerStmt->close();

if (!$lecturer) {
    die("Error: No lecturer found with the provided ID.");
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
$message = "";

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery) or die("Error in current semester query: " . htmlspecialchars($conn->error));
$currentSemester = $currentSemesterResult->num_rows > 0 ? $currentSemesterResult->fetch_assoc()['semester_name'] : 'May 2025';

// Fetch all semesters for the filter
$semestersQuery = "SELECT semester_name FROM semesters ORDER BY start_date DESC";
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . htmlspecialchars($conn->error));
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);

// Initialize filter parameters
$selectedSemester = isset($_GET['semester']) && !empty($_GET['semester']) ? trim($_GET['semester']) : $currentSemester;
$searchUsername = isset($_GET['username']) ? trim($_GET['username']) : '';
$selectedDeliverable = isset($_GET['deliverable_id']) && is_numeric($_GET['deliverable_id']) ? intval($_GET['deliverable_id']) : 0;
$selectedGroup = isset($_GET['group_id']) && is_numeric($_GET['group_id']) ? intval($_GET['group_id']) : 0;

// Debug filter values
error_log("Filters: Semester=$selectedSemester, Deliverable=$selectedDeliverable, Group=$selectedGroup, Username=$searchUsername");

// Fetch deliverables for the filter - simplified query
$deliverablesQuery = "SELECT id, name FROM deliverables WHERE semester = ? ORDER BY name ASC";
$deliverablesStmt = $conn->prepare($deliverablesQuery);
if ($deliverablesStmt === false) {
    die("Prepare failed (Deliverables Query): " . $conn->error);
}
$deliverablesStmt->bind_param("s", $selectedSemester);
$deliverablesStmt->execute();
$deliverablesResult = $deliverablesStmt->get_result();
$deliverables = $deliverablesResult->fetch_all(MYSQLI_ASSOC);
$deliverablesStmt->close();

// Fetch groups for the filter - simplified query
$groupsQuery = "
    SELECT DISTINCT g.id, g.name 
    FROM groups g 
    LEFT JOIN students s_leader ON g.leader_id = s_leader.id 
    WHERE g.lecturer_id = ? 
    AND s_leader.intake_month = SUBSTRING_INDEX(?, ' ', 1)
    AND s_leader.intake_year = CAST(SUBSTRING_INDEX(?, ' ', -1) AS UNSIGNED)
    ORDER BY g.name ASC";
$groupsStmt = $conn->prepare($groupsQuery);
if ($groupsStmt === false) {
    die("Prepare failed (Groups Query): " . $conn->error);
}
$groupsStmt->bind_param("iss", $lecturerID, $selectedSemester, $selectedSemester);
$groupsStmt->execute();
$groupsResult = $groupsStmt->get_result();
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
$groupsStmt->close();

// Debug: Check if deliverables or groups are empty
if (empty($deliverables)) {
    $message .= "<div class='alert alert-warning'>No deliverables found for semester: " . htmlspecialchars($selectedSemester) . "</div>";
}
if (empty($groups)) {
    $message .= "<div class='alert alert-warning'>No groups found for semester: " . htmlspecialchars($selectedSemester) . "</div>";
}

// Simplified submissions query - build it step by step
$baseQuery = "
    SELECT DISTINCT
        d.id AS deliverable_id, 
        d.name AS deliverable_name, 
        d.semester, 
        d.submission_type, 
        g.id AS group_id, 
        g.name AS group_name, 
        CASE 
            WHEN d.submission_type = 'individual' THEN s.id 
            ELSE NULL 
        END AS student_id, 
        CASE 
            WHEN d.submission_type = 'individual' THEN s.full_name 
            ELSE NULL 
        END AS student_name, 
        ds.id AS submission_id, 
        ds.file_path, 
        ds.submitted_at,
        CASE 
            WHEN d.submission_type = 'group' THEN ge.evaluation_grade
            ELSE e.evaluation_grade 
        END AS evaluation_grade,
        CASE 
            WHEN d.submission_type = 'group' THEN ge.feedback
            ELSE e.feedback 
        END AS evaluation_feedback
    FROM deliverables d
    JOIN groups g ON g.lecturer_id = ? AND g.status = 'Approved'
    JOIN students s_leader ON g.leader_id = s_leader.id
        AND s_leader.intake_month = SUBSTRING_INDEX(d.semester, ' ', 1)
        AND s_leader.intake_year = CAST(SUBSTRING_INDEX(d.semester, ' ', -1) AS UNSIGNED)
    LEFT JOIN group_members gm ON g.id = gm.group_id AND d.submission_type = 'individual'
    LEFT JOIN students s ON gm.student_id = s.id AND d.submission_type = 'individual'
    LEFT JOIN deliverable_submissions ds ON (
        d.id = ds.deliverable_id 
        AND ds.group_id = g.id
        AND (
            (d.submission_type = 'group') OR 
            (d.submission_type = 'individual' AND ds.student_id = s.id)
        )
    )
    LEFT JOIN group_evaluations ge ON (
        d.submission_type = 'group' 
        AND d.id = ge.deliverable_id 
        AND ge.group_id = g.id
        AND ge.supervisor_id = ?
        AND ge.type = 'Group'
    )
    LEFT JOIN evaluation e ON (
        d.submission_type = 'individual' 
        AND d.id = e.deliverable_id 
        AND e.student_id = s.id
        AND e.supervisor_id = ?
        AND e.type = 'sv'
    )
    WHERE d.semester = ?";

// Initialize parameters
$params = [$lecturerID, $lecturerID, $lecturerID, $selectedSemester];
$paramTypes = "iiis";

// Add additional filters
$additionalConditions = [];

if ($selectedDeliverable > 0) {
    $additionalConditions[] = "d.id = ?";
    $params[] = $selectedDeliverable;
    $paramTypes .= "i";
}

if ($selectedGroup > 0) {
    $additionalConditions[] = "g.id = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "i";
}

if ($searchUsername) {
    $additionalConditions[] = "(g.name LIKE ? OR s.full_name LIKE ? OR s.username LIKE ?)";
    $params[] = "%$searchUsername%";
    $params[] = "%$searchUsername%";
    $params[] = "%$searchUsername%";
    $paramTypes .= "sss";
}

// Add additional conditions to query
if (!empty($additionalConditions)) {
    $baseQuery .= " AND " . implode(" AND ", $additionalConditions);
}

$baseQuery .= " ORDER BY d.name, g.name, s.full_name, ds.submitted_at DESC";

error_log("Submissions Query: $baseQuery");
error_log("Params: " . json_encode($params));
error_log("ParamTypes: $paramTypes");

$submissionsStmt = $conn->prepare($baseQuery);
if ($submissionsStmt === false) {
    die("Prepare failed (Submissions Query): " . $conn->error . "<br>Query: " . htmlspecialchars($baseQuery));
}

if (!empty($params)) {
    $submissionsStmt->bind_param($paramTypes, ...$params);
}
$submissionsStmt->execute();
$submissionsResult = $submissionsStmt->get_result();
$submissions = $submissionsResult->fetch_all(MYSQLI_ASSOC);
$submissionsStmt->close();

// Fetch group members for each submission
$groupMembers = [];
foreach ($submissions as &$submission) {
    $groupId = $submission['group_id'];
    if (!isset($groupMembers[$groupId])) {
        $membersQuery = "SELECT s.full_name FROM group_members gm JOIN students s ON gm.student_id = s.id WHERE gm.group_id = ? ORDER BY s.full_name ASC";
        $membersStmt = $conn->prepare($membersQuery);
        if ($membersStmt === false) {
            $submission['group_members'] = ['Error fetching members'];
            error_log("Prepare failed (Members Query): " . $conn->error);
            continue;
        }
        $membersStmt->bind_param("i", $groupId);
        $membersStmt->execute();
        $membersResult = $membersStmt->get_result();
        $groupMembers[$groupId] = array_column($membersResult->fetch_all(MYSQLI_ASSOC), 'full_name');
        $membersStmt->close();
    }
    $submission['group_members'] = $groupMembers[$groupId] ?? [];
}
unset($submission);

// Calculate statistics
$totalSubmissions = count(array_filter($submissions, fn($s) => !empty($s['submission_id'])));
$pendingSubmissions = count(array_filter($submissions, fn($s) => empty($s['submission_id'])));
$completedSubmissions = $totalSubmissions;
$completedPercentage = count($submissions) > 0 ? round(($completedSubmissions / count($submissions)) * 100) : 0;
$uncompletedSubmissions = $pendingSubmissions;

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
    <title>Lecturer - View Student Submissions</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Academic Oversight</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Project Scope:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lecttitleproposal.php">Title Proposal</a>
                        <a class="collapse-item active <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectfypcomponents.php">View Student <br>Submissions</a>
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
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3" aria-label="Toggle Sidebar">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
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

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">View Student Submissions</h1>
                    </div>

                    <?= $message ?>

                    <!-- Statistics Cards -->
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

                    <!-- Filter Card -->
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
                                                <option value="<?= htmlspecialchars($semester['semester_name']) ?>" 
                                                        <?= $selectedSemester === $semester['semester_name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($semester['semester_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?= htmlspecialchars($searchUsername) ?>" placeholder="Enter student username">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="deliverable_id">Deliverable</label>
                                        <select class="form-control" id="deliverable_id" name="deliverable_id">
                                            <option value="0" <?= $selectedDeliverable === 0 ? 'selected' : '' ?>>-- All Deliverables --</option>
                                            <?php foreach ($deliverables as $deliverable): ?>
                                                <option value="<?= $deliverable['id'] ?>" 
                                                        <?= $selectedDeliverable === $deliverable['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($deliverable['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="group_id">Group</label>
                                        <select class="form-control" id="group_id" name="group_id">
                                            <option value="0" <?= $selectedGroup === 0 ? 'selected' : '' ?>>-- All Groups --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?= $group['id'] ?>" 
                                                        <?= $selectedGroup === $group['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="lectfypcomponents.php" class="btn btn-secondary">Clear Filters</a>
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
                                            <th>Marks</th>
                                            <th>Evaluation</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($submissions)): ?>
                                            <?php foreach ($submissions as $submission): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($submission['submission_type'] === 'group'): ?>
                                                            <?= htmlspecialchars($submission['group_name'] ?? 'N/A') ?>
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
                                                        <?php if (!empty($submission['file_path']) && !empty($submission['submission_id'])): ?>
                                                            <a href="<?= htmlspecialchars($submission['file_path']) ?>" 
                                                               target="_blank" class="btn btn-sm btn-primary">
                                                                View File
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-danger">No File</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($submission['submitted_at']) && !empty($submission['submission_id'])): ?>
                                                            <?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($submission['submitted_at']))) ?>
                                                        <?php else: ?>
                                                            <span class="text-danger">Not Submitted</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($submission['evaluation_grade'])): ?>
                                                            <?php
                                                                $grade = floatval($submission['evaluation_grade']);
                                                                $actual_deliverable_weightage = isset($submission['weightage']) && $submission['weightage'] !== null ? floatval($submission['weightage']) : 0;

                                                                // Color coding in the table is based on the raw grade percentage for that deliverable compared to standard thresholds.
                                                                // If you want table color based on its weighted contribution, the logic below would need to use $weightedGradeForTableColor = ($grade * $actual_deliverable_weightage) / 100;
                                                                // and then compare $weightedGradeForTableColor against 50, 30 etc.
                                                                // For now, keeping table color based on $grade itself as per implicit previous state before weighted contribution focus.
                                                                $gradeClass = '';
                                                                if ($grade >= 50) { // Defaulting to color based on grade itself for table
                                                                    $gradeClass = 'grade-high';
                                                                } elseif ($grade >= 30) {
                                                                    $gradeClass = 'grade-medium';
                                                                } else {
                                                                    $gradeClass = 'grade-low';
                                                                }
                                                            ?>
                                                            <span class="<?= $gradeClass ?>">
                                                                <?= number_format($grade, 2) ?>% 
                                                            </span>
                                                            <?php /* Weightage and feedback removed as per user request */ ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not Evaluated</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($submission['submission_id'])): ?>
                                                            <button type="button" class="btn btn-info btn-icon-split btn-sm" 
                                                                    onclick="openEvaluationModal(<?= $submission['submission_id'] ?>, 
                                                                                               <?= $submission['student_id'] ?? 'null' ?>, 
                                                                                               <?= $submission['group_id'] ?>, 
                                                                                               '<?= $submission['submission_type'] ?>')">
                                                                <span class="icon text-white-50">
                                                                    <i class="fas fa-clipboard-check"></i>
                                                                </span>
                                                                <span class="text">
                                                                    <?= !empty($submission['evaluation_grade']) ? 'Re-evaluate' : 'Evaluate' ?>
                                                                </span>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center">No submissions found.</td></tr>
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
                        <span>Copyright &copy; FYPCollabor8 2025</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Logout Modal-->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
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

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="js/demo/datatables-demo.js"></script>

    <!-- Evaluation Modal -->
    <div class="modal fade" id="evaluationModal" tabindex="-1" role="dialog" aria-labelledby="evaluationModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="evaluationModalLabel">Evaluate Submission</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="rubricScoringContainer">
                        <label>Rubric Scores</label>
                        <p>Loading rubric scoring options...</p>
                    </div>
                    <div class="form-group">
                        <label for="feedback">Feedback</label>
                        <textarea class="form-control" id="feedback" name="feedback" rows="4" required></textarea>
                    </div>
                    <input type="hidden" id="modal-submission-id" name="submission_id" value="">
                    <input type="hidden" id="modal-student-id" name="student_id" value="">
                    <input type="hidden" id="modal-group-id" name="group_id" value="">
                    <input type="hidden" id="modal-submission-type" name="submission_type" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="submitEvaluation()">Submit Evaluation</button>
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

    <style>
        .rubric-scoring-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px; 
        }
        .rubric-scoring-table th, .rubric-scoring-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            height: 40px; 
        }
        .rubric-scoring-table th { 
            background-color: #f8f9fa; 
            text-align: left; 
        }
        .rubric-scoring-table .criteria-row { 
            background-color: #E6F0FA; 
            font-weight: bold; 
        }
        .rubric-scoring-table .score-cell { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 0 10px; 
        }
        .rubric-scoring-table input[type="radio"] { 
            transform: scale(1.2); 
            margin-right: 5px; 
        }
        .rubric-scoring-table .score-option { 
            flex: 1; 
            text-align: center; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .info-icon { 
            cursor: pointer; 
            color: #007bff; 
            margin-left: 10px; 
        }
        .score-range-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .score-range-table th, .score-range-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        #evaluationModal .modal-dialog {
            max-width: 80%;
            margin: 1.75rem auto;
        }
        #evaluationModal .modal-content {
            min-height: 850px;
        }
        .grade-high {
            color: #1cc88a; /* Green */
            font-weight: bold;
        }
        .grade-medium {
            color: #f6c23e; /* Yellow */
            font-weight: bold;
        }
        .grade-low {
            color: #e74a3b; /* Red */
            font-weight: bold;
        }
        .feedback-preview {
            font-size: 0.85rem;
            color: #858796;
            margin-top: 4px;
        }
    </style>

    <script>
        let rubricsData = {};
        let currentDeliverableWeightage = 0; // To store the weightage of the deliverable being evaluated in the modal

        function openEvaluationModal(submissionId, studentId, groupId, submissionType) {
            $('#modal-submission-id').val(submissionId);
            $('#modal-student-id').val(studentId);
            $('#modal-group-id').val(groupId);
            $('#modal-submission-type').val(submissionType);
            $('#feedback').val('');

            // Fetch rubrics data and deliverable weightage
            $.ajax({
                url: 'get_rubrics.php',
                method: 'GET',
                data: {
                    submission_id: submissionId
                },
                success: function(response) {
                    if (response.message) { // Check for error message from get_rubrics.php
                        alert('Error: ' + response.message);
                        rubricsData = [];
                        currentDeliverableWeightage = 0;
                    } else {
                        rubricsData = response.rubrics || [];
                        currentDeliverableWeightage = parseFloat(response.deliverable_weightage) || 0;
                    }
                    updateRubricScoring();
                    $('#evaluationModal').modal('show');
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    alert('Error fetching rubrics data: ' + textStatus + ' - ' + errorThrown);
                    rubricsData = [];
                    currentDeliverableWeightage = 0;
                    updateRubricScoring(); // Still update to show 'No rubrics' message if needed
                }
            });
        }

        function updateRubricScoring() {
            const container = document.getElementById('rubricScoringContainer');
            container.innerHTML = '<label>Rubric Scores</label>';
            
            if (!rubricsData || Object.keys(rubricsData).length === 0) {
                container.innerHTML += '<p>No rubrics available for this submission.</p>';
                 // Clear scores if no rubrics
                document.getElementById('totalRawScore').textContent = '0/0';
                document.getElementById('finalScore').innerHTML = `<span class="grade-low">0.00%</span>`;
                return;
            }

            let tableHtml = `
                <table class="rubric-scoring-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Component</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            let maxTotalRawScore = 0;
            rubricsData.forEach(rubric => {
                maxTotalRawScore += parseInt(rubric.max_score) || 10;
            });

            rubricsData.forEach((rubric, index) => {
                const maxScore = parseInt(rubric.max_score) || 10;
                tableHtml += `
                    <tr class="criteria-row">
                        <td colspan="3">
                            ${rubric.criteria}
                            <i class="fas fa-info-circle info-icon" onclick="showRubricDescriptions(${index})" title="View score descriptions"></i>
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
                    <td colspan="2">Total Raw Score</td>
                    <td id="totalRawScore">0/${maxTotalRawScore}</td>
                </tr>
                <tr class="final-score-row">
                    <td colspan="2">Final Score</td>
                    <td id="finalScore"><span class="grade-low">0.00%</span></td>
                </tr>
                </tbody>
                </table>
            `;
            container.innerHTML += tableHtml;

            // Add event listeners for score changes
            document.querySelectorAll('.rubric-score').forEach(input => {
                input.addEventListener('change', calculateTotalScore);
            });
            // Initial calculation
            calculateTotalScore(); 
        }

        function calculateTotalScore() {
            let totalRawScore = 0;
            let totalRubricPercentage = 0; // Percentage based on rubric scores (0-100)
            const numRubrics = rubricsData.length || 1;
            const rubricWeight = numRubrics > 0 ? 1.0 / numRubrics : 1.0;
            let maxTotalRawScore = 0;

            if (!rubricsData || rubricsData.length === 0) {
                 document.getElementById('totalRawScore').textContent = '0/0';
                 document.getElementById('finalScore').innerHTML = `<span class="grade-low">0.00%</span>`;
                 return;
            }

            rubricsData.forEach(rubric => {
                maxTotalRawScore += parseInt(rubric.max_score) || 10;
            });

            document.querySelectorAll('.rubric-score:checked').forEach(input => {
                const score = parseInt(input.value);
                const maxScoreForThisRubric = parseInt(input.dataset.maxScore) || 10;
                totalRawScore += score;
                const normalizedScoreForRubricItem = maxScoreForThisRubric > 0 ? (score / maxScoreForThisRubric) * 100 * rubricWeight : 0;
                totalRubricPercentage += normalizedScoreForRubricItem;
            });

            document.getElementById('totalRawScore').textContent = `${totalRawScore}/${maxTotalRawScore}`;
            
            // Calculate the final score contribution based on the deliverable's overall weightage
            const finalScoreContribution = totalRubricPercentage * (currentDeliverableWeightage / 100);
            
            let gradeClass = '';
            const halfWeightage = currentDeliverableWeightage / 2;
            const quarterWeightage = currentDeliverableWeightage / 4;

            if (finalScoreContribution > halfWeightage) {
                gradeClass = 'grade-high'; // More than half of the deliverable's possible weighted score
            } else if (finalScoreContribution > quarterWeightage) {
                gradeClass = 'grade-medium'; // Between a quarter and half
            } else {
                gradeClass = 'grade-low'; // Quarter or less
            }
            
            document.getElementById('finalScore').innerHTML = `
                <span class="${gradeClass}">${finalScoreContribution.toFixed(2)}%</span>
            `;
        }

        function showRubricDescriptions(index) {
            const rubric = rubricsData[index];
            if (!rubric) {
                alert('Rubric data not found.');
                return;
            }

            $('#modalCriteria').text(rubric.criteria);
            $('#modalComponent').text(rubric.component || 'N/A');
            
            let rangesHtml = '';
            const scoreRanges = rubric.score_ranges || {};
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

        function submitEvaluation() {
            const submissionId = $('#modal-submission-id').val();
            const studentId = $('#modal-student-id').val();
            const groupId = $('#modal-group-id').val();
            const submissionType = $('#modal-submission-type').val();
            const feedback = $('#feedback').val();
            
            if (!feedback.trim()) {
                alert('Please provide feedback.');
                return;
            }

            const rubricScores = {};
            let allScored = true;
            
            document.querySelectorAll('.rubric-score:checked').forEach(input => {
                const rubricId = input.name.match(/\[(\d+)\]/)[1];
                rubricScores[rubricId] = parseInt(input.value);
            });

            if (Object.keys(rubricScores).length !== rubricsData.length) {
                alert('Please score all rubric criteria.');
                return;
            }

            $.ajax({
                url: 'submit_evaluation.php',
                method: 'POST',
                data: {
                    submission_id: submissionId,
                    student_id: studentId,
                    group_id: groupId,
                    submission_type: submissionType,
                    rubric_scores: rubricScores,
                    feedback: feedback
                },
                success: function(response) {
                    if (response.success) {
                        alert('Evaluation submitted successfully.');
                        $('#evaluationModal').modal('hide');
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error submitting evaluation');
                }
            });
        }
    </script>
</body>
</html>