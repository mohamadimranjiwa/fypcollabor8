<?php
session_start();
include 'connection.php';

// Ensure the lecturer is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$lecturerID = $_SESSION['user_id'];

// Handle Approve/Reject Actions (Group Creation, Initial Title, and Change Requests)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];

    if ($action === 'approve_group' || $action === 'reject_group') {
        $groupId = intval($_GET['id']);
        $checkQuery = "SELECT id FROM groups WHERE id = ? AND lecturer_id = ? AND status = 'Pending'";
        $stmt = $conn->prepare($checkQuery);
        if (!$stmt) {
            header("Location: lecttitleproposal.php?error=Prepare failed: " . $conn->error);
            exit();
        }
        $stmt->bind_param("ii", $groupId, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            header("Location: lecttitleproposal.php?error=Unauthorized or invalid group");
            exit();
        }

        if ($action === 'approve_group') {
            // Verify group has members and a project
            $checkMembersQuery = "SELECT COUNT(*) AS member_count FROM group_members WHERE group_id = ?";
            $stmt = $conn->prepare($checkMembersQuery);
            $stmt->bind_param("i", $groupId);
            $stmt->execute();
            $memberCount = $stmt->get_result()->fetch_assoc()['member_count'];

            $checkProjectQuery = "SELECT COUNT(*) AS project_count FROM projects WHERE group_id = ?";
            $stmt = $conn->prepare($checkProjectQuery);
            $stmt->bind_param("i", $groupId);
            $stmt->execute();
            $projectCount = $stmt->get_result()->fetch_assoc()['project_count'];

            if ($memberCount === 0 || $projectCount === 0) {
                header("Location: lecttitleproposal.php?error=Cannot approve group: No members or project associated");
                exit();
            }

            $updateQuery = "UPDATE groups SET status = 'Approved' WHERE id = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("i", $groupId);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Group approved successfully";
            } else {
                $message = "Failed to approve group: " . $stmt->error;
            }
        } else { // reject_group
            $conn->begin_transaction();
            try {
                $deleteMembersQuery = "DELETE FROM group_members WHERE group_id = ?";
                $stmt = $conn->prepare($deleteMembersQuery);
                $stmt->bind_param("i", $groupId);
                $stmt->execute();

                $deleteProjectsQuery = "DELETE FROM projects WHERE group_id = ?";
                $stmt = $conn->prepare($deleteProjectsQuery);
                $stmt->bind_param("i", $groupId);
                $stmt->execute();

                $deleteGroupQuery = "DELETE FROM groups WHERE id = ?";
                $stmt = $conn->prepare($deleteGroupQuery);
                $stmt->bind_param("i", $groupId);
                $stmt->execute();

                $conn->commit();
                $message = "Group rejected and deleted successfully";
            } catch (Exception $e) {
                $conn->rollback();
                header("Location: lecttitleproposal.php?error=Failed to reject group: " . $e->getMessage());
                exit();
            }
        }
    } elseif ($action === 'approve_initial' || $action === 'reject_initial') {
        $projectId = intval($_GET['id']);
        $checkQuery = "SELECT p.project_id FROM projects p JOIN groups g ON p.group_id = g.id WHERE p.project_id = ? AND g.lecturer_id = ? AND g.status = 'Approved'";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ii", $projectId, $lecturerID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            header("Location: lecttitleproposal.php?error=Unauthorized or invalid project");
            exit();
        }

        $newDescription = ($action === 'approve_initial') ? 'Title approved by lecturer' : 'Title rejected by lecturer';
        $updateQuery = "UPDATE projects SET description = ? WHERE project_id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("si", $newDescription, $projectId);
        $message = ($action === 'approve_initial') ? "Initial title approved successfully" : "Initial title rejected successfully";
    } elseif ($action === 'approve_change' || $action === 'reject_change') {
        $projectId = intval($_GET['id']);
        $checkQuery = "SELECT p.project_id FROM projects p JOIN groups g ON p.group_id = g.id WHERE p.project_id = ? AND g.status = 'Approved'";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            header("Location: lecttitleproposal.php?error=Unauthorized or invalid project");
            exit();
        }

        if ($action === 'approve_change') {
            $updateQuery = "UPDATE projects SET title = pending_title, description = pending_description, pending_title = NULL, pending_description = NULL WHERE project_id = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("i", $projectId);
            $message = "Title change approved successfully";
        } else { // reject_change
            $updateQuery = "UPDATE projects SET pending_title = NULL, pending_description = NULL WHERE project_id = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("i", $projectId);
            $message = "Title change rejected successfully";
        }
    }

    if ($stmt->execute()) {
        header("Location: lecttitleproposal.php?success=" . urlencode($message));
    } else {
        header("Location: lecttitleproposal.php?error=Failed to process request: " . $stmt->error);
    }
    $stmt->close();
    exit();
}

// Fetch lecturer's full name, profile picture, and role_id from the database
$sql = "SELECT full_name, profile_picture, role_id FROM lecturers WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (Lecturer Info): " . $conn->error);
}
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$result = $stmt->get_result();
$lecturer = $result->fetch_assoc();
$stmt->close();

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

// Total Requests (Groups + Projects)
$totalRequestsQuery = "
    SELECT (
        SELECT COUNT(*) FROM groups WHERE lecturer_id = ? AND status = 'Pending'
    ) + (
        SELECT COUNT(*) FROM projects p JOIN groups g ON p.group_id = g.id 
        WHERE g.lecturer_id = ? AND p.description = 'Description not provided yet.' AND g.status = 'Approved'
    ) + (
        SELECT COUNT(*) FROM projects p JOIN groups g ON p.group_id = g.id 
        WHERE g.lecturer_id = ? AND p.pending_title IS NOT NULL AND g.status = 'Approved'
    ) AS total_requests";
$stmt = $conn->prepare($totalRequestsQuery);
$stmt->bind_param("iii", $lecturerID, $lecturerID, $lecturerID);
$stmt->execute();
$totalRequestsResult = $stmt->get_result();
$totalRequests = $totalRequestsResult->fetch_assoc()['total_requests'];
$stmt->close();

// Pending Group Creation Requests
$pendingGroupsQuery = "SELECT COUNT(*) AS pending_groups FROM groups WHERE lecturer_id = ? AND status = 'Pending'";
$stmt = $conn->prepare($pendingGroupsQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$pendingGroupsResult = $stmt->get_result();
$pendingGroups = $pendingGroupsResult->fetch_assoc()['pending_groups'];
$stmt->close();

// Pending Title Requests
$pendingTitlesQuery = "
    SELECT COUNT(*) AS pending_titles 
    FROM projects p JOIN groups g ON p.group_id = g.id 
    WHERE g.lecturer_id = ? AND p.description = 'Description not provided yet.' AND g.status = 'Approved'";
$stmt = $conn->prepare($pendingTitlesQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$pendingTitlesResult = $stmt->get_result();
$pendingTitles = $pendingTitlesResult->fetch_assoc()['pending_titles'];
$stmt->close();

// Pending Change Requests
$pendingChangesQuery = "
    SELECT COUNT(*) AS pending_changes 
    FROM projects p JOIN groups g ON p.group_id = g.id 
    WHERE g.lecturer_id = ? AND p.pending_title IS NOT NULL AND g.status = 'Approved'";
$stmt = $conn->prepare($pendingChangesQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$pendingChangesResult = $stmt->get_result();
$pendingChanges = $pendingChangesResult->fetch_assoc()['pending_changes'];
$stmt->close();

$pendingRequests = $pendingGroups + $pendingTitles + $pendingChanges;

// Completed Requests
$completedRequestsQuery = "
    SELECT (
        SELECT COUNT(*) FROM groups WHERE lecturer_id = ? AND status = 'Approved'
    ) + (
        SELECT COUNT(*) FROM projects p JOIN groups g ON p.group_id = g.id 
        WHERE g.lecturer_id = ? AND p.description != 'Description not provided yet.' AND g.status = 'Approved'
    ) AS completed_requests";
$stmt = $conn->prepare($completedRequestsQuery);
$stmt->bind_param("ii", $lecturerID, $lecturerID);
$stmt->execute();
$completedRequestsResult = $stmt->get_result();
$completedRequests = $completedRequestsResult->fetch_assoc()['completed_requests'];
$stmt->close();

$completedPercentage = ($totalRequests > 0) ? round(($completedRequests / $totalRequests) * 100, 2) : 0;

// Total Students
$totalStudentsQuery = "
    SELECT COUNT(DISTINCT gm.student_id) AS total_students 
    FROM group_members gm JOIN groups g ON gm.group_id = g.id 
    WHERE g.lecturer_id = ? AND g.status = 'Approved'";
$stmt = $conn->prepare($totalStudentsQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$totalStudentsResult = $stmt->get_result();
$totalStudents = $totalStudentsResult->fetch_assoc()['total_students'];
$stmt->close();

// Fetch approved groups for filter dropdown
$groupsQuery = "SELECT name FROM groups WHERE lecturer_id = ? AND status = 'Approved'";
$stmt = $conn->prepare($groupsQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$groupsResult = $stmt->get_result();
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch semesters for filter dropdown
$semestersQuery = "SELECT semester_name FROM semesters ORDER BY start_date DESC";
$stmt = $conn->prepare($semestersQuery);
if (!$stmt) {
    error_log("Semesters query preparation failed: " . $conn->error);
    die("Error preparing semesters query: " . $conn->error);
}
$stmt->execute();
$semestersResult = $stmt->get_result();
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery) or die("Error in current semester query: " . htmlspecialchars($conn->error));
$currentSemester = $currentSemesterResult->num_rows > 0 ? $currentSemesterResult->fetch_assoc()['semester_name'] : 'May 2025';

// Initialize filter parameters
$selectedSemester = isset($_GET['semester']) && !empty($_GET['semester']) ? trim($_GET['semester']) : $currentSemester;

// Handle filters
$searchUsername = isset($_GET['username']) ? trim($_GET['username']) : '';
$selectedGroup = isset($_GET['group_name']) ? $_GET['group_name'] : '';

// Log filter values for debugging
error_log("Filters applied: username='$searchUsername', group_name='$selectedGroup', semester='$selectedSemester'");

// Pending Group Creation Requests
$groupRequestsQuery = "
    SELECT g.id AS group_id, g.name AS group_name, p.title AS proposed_title, 
           p.description AS proposed_description, 'Group Creation' AS request_type 
    FROM groups g JOIN projects p ON g.id = p.group_id 
    WHERE g.lecturer_id = ? AND g.status = 'Pending'";
$stmt = $conn->prepare($groupRequestsQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$groupRequestsResult = $stmt->get_result();
$groupRequests = $groupRequestsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Title Requests (only pending)
$titleRequestsQuery = "
    SELECT g.name AS group_name, p.title AS proposed_title, p.project_id AS proposal_id, 
           'Initial Title' AS request_type 
    FROM projects p JOIN groups g ON p.group_id = g.id 
    WHERE g.lecturer_id = ? AND p.description = 'Description not provided yet.' AND g.status = 'Approved'";
$stmt = $conn->prepare($titleRequestsQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$titleRequestsResult = $stmt->get_result();
$titleRequests = $titleRequestsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Combine Group Creation and Initial Title Requests
$initialSetupRequests = array_merge($groupRequests, $titleRequests);

// Change Title Requests
$changeTitleRequestsQuery = "
    SELECT g.name AS group_name, p.pending_title AS proposed_title, p.pending_description AS proposed_description, 
           p.project_id AS change_id 
    FROM projects p JOIN groups g ON p.group_id = g.id 
    WHERE p.pending_title IS NOT NULL AND p.pending_description IS NOT NULL AND (g.status = 'Approved' OR g.status = 'Pending')";
$changeTitleRequestsResult = $conn->query($changeTitleRequestsQuery);
$changeTitleRequests = $changeTitleRequestsResult ? $changeTitleRequestsResult->fetch_all(MYSQLI_ASSOC) : [];

// Student Details (only approved groups) with filters
$studentDetailsQuery = "
    SELECT s.username AS student_username, s.full_name AS student_name, g.name AS group_name, 
           p.title AS project_title, p.description AS project_description, 
           CONCAT(s.intake_month, ' ', s.intake_year) AS semester 
    FROM students s 
    JOIN group_members gm ON s.id = gm.student_id 
    JOIN groups g ON gm.group_id = g.id 
    JOIN projects p ON g.id = p.group_id 
    WHERE g.lecturer_id = ? AND g.status = 'Approved'";
$params = [$lecturerID];
$paramTypes = "i";

if ($searchUsername) {
    $studentDetailsQuery .= " AND s.username LIKE ?";
    $params[] = "%$searchUsername%";
    $paramTypes .= "s";
}
if ($selectedGroup) {
    $studentDetailsQuery .= " AND g.name = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "s";
}
if ($selectedSemester) {
    // Match the month part of semester_name (e.g., 'June' from 'June 2025')
    $studentDetailsQuery .= " AND s.intake_month = SUBSTRING_INDEX(?, ' ', 1)";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}

// Log the query and parameters for debugging
error_log("Student Details Query: $studentDetailsQuery");
error_log("Parameters: " . json_encode($params));

$stmt = $conn->prepare($studentDetailsQuery);
if (!$stmt) {
    error_log("Student Details query preparation failed: " . $conn->error);
    die("Prepare failed (Student Details): " . $conn->error);
}
$stmt->bind_param($paramTypes, ...$params);
if (!$stmt->execute()) {
    error_log("Student Details query execution failed: " . $stmt->error);
    die("Execute failed (Student Details): " . $stmt->error);
}
$studentDetailsResult = $stmt->get_result();
$studentDetails = $studentDetailsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Debug: Log the number of rows returned
error_log("Student Details Query returned " . count($studentDetails) . " rows for lecturer ID " . $lecturerID);

$conn->close();

// Determine the current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <title>Lecturer - Title Proposal</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <style>
        #wrapper {
            min-height: 100vh;
            display: flex;
        }
        #content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        #content {
            flex: 1 0 auto;
        }
        .sticky-footer {
            flex-shrink: 0;
            width: 100%;
            position: relative;
            bottom: 0;
        }
        .sticky-footer .container {
            text-align: center;
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Academic Oversight</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Project Scope:</h6>
                        <a class="collapse-item active <?= !$isSupervisor ? 'disabled' : '' ?>" href="lecttitleproposal.php">Title Proposal</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectfypcomponents.php">View Student <br>Submissions</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed <?= !$isSupervisor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="false" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Mentorship Tools</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Guidance Resources:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectmanagemeetings.php">Manage Meetings</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewdiary.php">View Student Diary</a>
                        <?php /* <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectevaluatestudent.php">Evaluate Students</a> */ ?>
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
                        <?php /* <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assevaluatestudent.php">Evaluate Students</a> */ ?>
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

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo htmlspecialchars($personalInfo['full_name']); ?></span>
                                <img class="img-profile rounded-circle" src="<?php echo htmlspecialchars($personalInfo['profile_picture']); ?>" onerror="this.src='img/undraw_profile.svg';">
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

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Title Proposal</h1>
                    </div>

                    <?php if (isset($_GET['success'])) { ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
                    <?php } ?>
                    <?php if (isset($_GET['error'])) { ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                    <?php } ?>

                    <div class="row justify-content-center">
                        <div class="col-lg-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Requests</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($totalRequests); ?> Requests</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Students</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($totalStudents); ?> Students</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-users fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Student Details</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <!-- Username Search -->
                                    <div class="col-md-4 mb-3">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo htmlspecialchars($searchUsername); ?>" placeholder="Search here">
                                    </div>
                                    <!-- Group Name Filter -->
                                    <div class="col-md-4 mb-3">
                                        <label for="group_name">Group Name</label>
                                        <select class="form-control" id="group_name" name="group_name">
                                            <option value="">-- Select Group --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?php echo htmlspecialchars($group['name']); ?>" 
                                                        <?php echo $selectedGroup == $group['name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($group['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Semester Filter -->
                                    <div class="col-md-4 mb-3">
                                        <label for="semester">Semester</label>
                                        <select class="form-control" id="semester" name="semester">
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?php echo htmlspecialchars($semester['semester_name']); ?>" 
                                                        <?php echo $selectedSemester == $semester['semester_name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply</button>
                                <a href="lecttitleproposal.php" class="btn btn-secondary">Reset</a>
                            </form>
                        </div>
                    </div>

                    <!-- Student Details Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Student Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>Name</th>
                                            <th>Group Name</th>
                                            <th>Semester</th>
                                            <th>Project Title</th>
                                            <th>Project Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($studentDetails)) { ?>
                                            <?php foreach ($studentDetails as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['student_username']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['semester'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['project_title'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['project_description'] ?? 'N/A'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php } else { ?>
                                            <tr>
                                                <td colspan="6" class="text-center">No student details available for your approved groups.</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Pending Proposals</h6>
                                </div>
                                <div class="card-body">
                                    <p>Below are the pending requests for group creation and title approval:</p>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>Group Name</th>
                                                <th>Proposed Title</th>
                                                <th>Services</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($initialSetupRequests)) { ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">No pending proposals available.</td>
                                                </tr>
                                            <?php } else { ?>
                                                <?php foreach ($initialSetupRequests as $request): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($request['request_type']); ?></td>
                                                        <td><?php echo htmlspecialchars($request['group_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($request['proposed_title']); ?></td>
                                                        <td><?php echo htmlspecialchars($request['proposed_description'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <?php if ($request['request_type'] === 'Group Creation') { ?>
                                                                <a href="lecttitleproposal.php?action=approve_group&id=<?php echo $request['group_id']; ?>" class="btn btn-success btn-sm">Approve</a>
                                                                <a href="lecttitleproposal.php?action=reject_group&id=<?php echo $request['group_id']; ?>" class="btn btn-danger btn-sm">Reject</a>
                                                            <?php } else { ?>
                                                                <a href="lecttitleproposal.php?action=approve_initial&id=<?php echo $request['proposal_id']; ?>" class="btn btn-success btn-sm">Accept</a>
                                                                <a href="lecttitleproposal.php?action=reject_initial&id=<?php echo $request['proposal_id']; ?>" class="btn btn-danger btn-sm">Reject</a>
                                                            <?php } ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Change Title Requests</h6>
                                </div>
                                <div class="card-body">
                                    <p>Below are the pending title change requests:</p>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Group Name</th>
                                                <th>Proposed Title</th>
                                                <th>Proposed Description</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($changeTitleRequests)) { ?>
                                                <?php foreach ($changeTitleRequests as $row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['proposed_title']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['proposed_description']); ?></td>
                                                        <td>
                                                            <a href="lecttitleproposal.php?action=approve_change&id=<?php echo $row['change_id']; ?>" class="btn btn-success btn-sm">Accept</a>
                                                            <a href="lecttitleproposal.php?action=reject_change&id=<?php echo $row['change_id']; ?>" class="btn btn-danger btn-sm">Reject</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php } else { ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No change requests available.</td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; FYPCollabor8 2025</span>
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

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="js/demo/datatables-demo.js"></script>
</body>
</html>