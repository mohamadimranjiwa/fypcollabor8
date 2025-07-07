<?php
session_start();

include 'connection.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$coordinatorID = $_SESSION['user_id'];

// Handle AJAX request for student details
if (isset($_POST['action']) && $_POST['action'] == 'get_student_details') {
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    if ($student_id <= 0) {
        echo json_encode(['error' => 'Invalid student ID']);
        exit();
    }
    $response = [];
    // Fetch student details
    $stmt = $conn->prepare("
        SELECT s.id, s.full_name, s.intake_year, s.intake_month
        FROM students s
        WHERE s.id = ?
    ");
    if ($stmt === false) {
        echo json_encode(['error' => 'Query preparation failed']);
        exit();
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $response['student'] = $row;
    } else {
        echo json_encode(['error' => 'Student not found']);
        $stmt->close();
        exit();
    }
    $stmt->close();
    // Fetch group and project details
    $stmt = $conn->prepare("
        SELECT g.id, g.name, p.title, p.description
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.id
        LEFT JOIN projects p ON g.id = p.group_id
        WHERE gm.student_id = ?
    ");
    if ($stmt === false) {
        echo json_encode(['error' => 'Group query preparation failed']);
        exit();
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $response['group'] = $row;
    }
    $stmt->close();
    // Fetch group members
    if (isset($response['group']['id'])) {
        $group_id = $response['group']['id'];
        $stmt = $conn->prepare("
            SELECT s.full_name
            FROM group_members gm
            JOIN students s ON gm.student_id = s.id
            WHERE gm.group_id = ?
            ORDER BY s.full_name
        ");
        if ($stmt === false) {
            echo json_encode(['error' => 'Group members query preparation failed']);
            exit();
        }
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $response['group_members'] = [];
        while ($row = $result->fetch_assoc()) {
            $response['group_members'][] = $row['full_name'];
        }
        $stmt->close();
    }
    echo json_encode($response);
    exit();
}

// Fetch the coordinator's full name and profile picture from the database
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Coordinator Info Query preparation failed: " . $conn->error);
    die("Prepare failed (Coordinator Info): " . $conn->error);
}
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();
$stmt->close();

if (!$coordinator) {
    error_log("No coordinator found with ID: $coordinatorID");
    die("Error: No coordinator found with the provided ID.");
}

$personalInfo = [
    'full_name' => $coordinator['full_name'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Fetch Dashboard Metrics
// Total Students
$totalStudentsQuery = "SELECT COUNT(*) AS total_students FROM students";
$stmt = $conn->prepare($totalStudentsQuery);
$stmt->execute();
$totalStudentsResult = $stmt->get_result();
$totalStudents = $totalStudentsResult->fetch_assoc()['total_students'];
$stmt->close();

// Total Lecturers
$totalLecturersQuery = "SELECT COUNT(*) AS total_lecturers FROM lecturers";
$stmt = $conn->prepare($totalLecturersQuery);
$stmt->execute();
$totalLecturersResult = $stmt->get_result();
$totalLecturers = $totalLecturersResult->fetch_assoc()['total_lecturers'];
$stmt->close();

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name, start_date FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery) or die("Error in current semester query: " . $conn->error);
$currentSemester = $currentSemesterResult->fetch_assoc();
$currentSemesterName = $currentSemester ? $currentSemester['semester_name'] : null;

// Total Submissions
$totalSubmissionsQuery = "SELECT COUNT(*) AS total_submissions FROM deliverable_submissions";
$stmt = $conn->prepare($totalSubmissionsQuery);
$stmt->execute();
$totalSubmissionsResult = $stmt->get_result();
$totalSubmissions = $totalSubmissionsResult->fetch_assoc()['total_submissions'];
$stmt->close();

// Fetch All Announcements
$announcementsQuery = "
    SELECT title, details, created_at 
    FROM announcements 
    WHERE coordinator_id = ? 
    ORDER BY created_at DESC";
$stmt = $conn->prepare($announcementsQuery);
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$announcementsResult = $stmt->get_result();
$announcements = $announcementsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch Student Details
$studentDetailsQuery = "
    SELECT s.username AS student_id, s.full_name AS student_name, g.name AS group_name, 
           p.title AS project_title, p.description AS project_description,
           s.intake_year, s.intake_month
    FROM students s 
    JOIN group_members gm ON s.id = gm.student_id 
    JOIN groups g ON gm.group_id = g.id 
    LEFT JOIN projects p ON g.id = p.group_id 
    WHERE g.status = 'Approved'
    AND s.intake_year = YEAR(?)
    AND s.intake_month = MONTHNAME(?)
    ORDER BY s.full_name";
$stmt = $conn->prepare($studentDetailsQuery);
if (!$stmt) {
    error_log("Student Details Query preparation failed: " . $conn->error);
    die("Prepare failed (Student Details): " . $conn->error);
}
$stmt->bind_param("ss", $currentSemester['start_date'], $currentSemester['start_date']);
$stmt->execute();
$studentDetailsResult = $stmt->get_result();
$studentDetails = $studentDetailsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check for missing projects
$missingProjects = [];
foreach ($studentDetails as $row) {
    if (is_null($row['project_title']) || is_null($row['project_description'])) {
        $missingProjects[$row['group_name']] = true;
    }
}
$missingProjectsWarning = !empty($missingProjects)
    ? "Warning: The following groups are missing project details: " . implode(", ", array_keys($missingProjects)) . ". Please ensure projects are created for these groups."
    : "";

// Debug: Log student details query result
error_log("Student Details Query returned " . count($studentDetails) . " rows for coordinator ID " . $coordinatorID);

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
    <title>Coordinator - Dashboard</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <!-- Custom CSS for modal and icon -->
    <style>
        .info-icon {
            cursor: pointer;
            color: #4e73df;
            margin-left: 5px;
        }
        .info-icon:hover {
            color: #224abe;
        }
        .modal-body .table {
            margin-bottom: 0;
        }
        .modal-body h6 {
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="coordinatordashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item active">
                <a class="nav-link" href="coordinatordashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Coordinator Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
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
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">FYP Evaluation:</h6>
                        <a class="collapse-item" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item" href="coorviewstudentdetails.php">View Student Details</a>
                        <a class="collapse-item" href="coormanagerubrics.php">Manage Rubrics</a>
                        <a class="collapse-item" href="coorassignassessment.php">Assign Assessment</a>
                        <!-- <a class="collapse-item" href="coorevaluatestudent.php">Evaluate Students</a> -->
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
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

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="coorprofile.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Profile
                                </a>
                                <!-- Settings -->
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
                        <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
                    </div>

                    <?php if ($missingProjectsWarning) { ?>
                        <div class="alert alert-warning"><?php echo htmlspecialchars($missingProjectsWarning); ?></div>
                    <?php } ?>

                    <!-- First Row: Total Lecturers, Total Students, Current Semester -->
                    <div class="row">
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coorassignlecturers.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Lecturers</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= htmlspecialchars($totalLecturers) ?> Lecturers</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-users fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coormanagestudents.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-info shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Students</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= htmlspecialchars($totalStudents) ?> Students</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-user-graduate fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coorsetsemester.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-warning shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Current Semester</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= htmlspecialchars($currentSemesterName) ?></div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Second Row: View Student <br>Submissions, View Student Details, Manage Rubrics -->
                    <div class="row">
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coorviewfypcomponents.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-success shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">View Student Submissions</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= htmlspecialchars($totalSubmissions) ?> Submissions</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-folder-open fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coorviewstudentdetails.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-danger shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">View Student Details</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">View Details</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-user fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coormanagerubrics.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-dark shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">Manage Rubrics</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">Manage Rubrics</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-clipboard-check fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Third Row: Manage Announcement, Manage Teaching Materials, Assign Assessment -->
                    <div class="row">
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coormanageannouncement.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-secondary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Manage Announcement</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">Manage Announcement</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-bullhorn fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coormanageteachingmaterials.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-info shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Manage Teaching Materials</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">Manage Teaching Materials</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-book fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="coorassignassessment.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Assign Assessment</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">Assign Assessment</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-tasks fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Announcements</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($announcements)): ?>
                                <p>No announcements available.</p>
                            <?php else: ?>
                                <?php foreach ($announcements as $index => $announcement): ?>
                                    <div class="mb-3">
                                        <h6 class="font-weight-bold"><?= htmlspecialchars($announcement['title']) ?></h6>
                                        <p><?= htmlspecialchars($announcement['details']) ?></p>
                                        <small class="text-muted">Posted on: <?= date('F j, Y', strtotime($announcement['created_at'])) ?></small>
                                    </div>
                                    <?php if ($index < count($announcements) - 1): ?>
                                        <hr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <a href="coormanageannouncement.php" class="btn btn-primary btn-sm mt-2">Manage Announcements</a>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Student Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Student ID</th>
                                                    <th>Name</th>
                                                    <th>Group Name</th>
                                                    <th>Project Title</th>
                                                    <th>Project Description</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($studentDetails)): ?>
                                                    <?php foreach ($studentDetails as $row): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($row['student_id']) ?></td>
                                                            <td>
                                                                <?= htmlspecialchars($row['student_name']) ?>
                                                                <i class="fas fa-info-circle info-icon"
                                                                   data-toggle="modal"
                                                                   data-target="#studentModal"
                                                                   data-student-id="<?= $row['student_id'] ?>"></i>
                                                            </td>
                                                            <td><?= htmlspecialchars($row['group_name']) ?></td>
                                                            <td><?= htmlspecialchars($row['project_title'] ?? 'N/A') ?></td>
                                                            <td><?= htmlspecialchars($row['project_description'] ?? 'N/A') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center">No student details available.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Student Details Modal -->
                    <div class="modal fade" id="studentModal" tabindex="-1" role="dialog" aria-labelledby="studentModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="studentModalLabel">Student Details</h5>
                                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <h6>Student Information</h6>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Field</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Full Name</td>
                                                <td id="modal-student-name"></td>
                                            </tr>
                                            <tr>
                                                <td>Intake Year</td>
                                                <td id="modal-intake-year"></td>
                                            </tr>
                                            <tr>
                                                <td>Intake Month</td>
                                                <td id="modal-intake-month"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <h6>Group Information</h6>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Field</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Group Name</td>
                                                <td id="modal-group-name"></td>
                                            </tr>
                                            <tr>
                                                <td>Project Title</td>
                                                <td id="modal-project-title"></td>
                                            </tr>
                                            <tr>
                                                <td>Project Description</td>
                                                <td id="modal-project-description"></td>
                                            </tr>
                                            <tr>
                                                <td>Group Members</td>
                                                <td id="modal-group-members"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                                </div>
                            </div>
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

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
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

    <!-- JavaScript for Student Details Modal -->
    <script>
        $(document).ready(function() {
            $('.info-icon').on('click', function() {
                var studentId = $(this).data('student-id');

                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'get_student_details',
                        student_id: studentId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.error) {
                            alert('Error: ' + response.error);
                            return;
                        }

                        // Populate student information
                        $('#modal-student-name').text(response.student?.full_name || 'N/A');
                        $('#modal-intake-year').text(response.student?.intake_year || 'N/A');
                        $('#modal-intake-month').text(response.student?.intake_month || 'N/A');

                        // Populate group information
                        $('#modal-group-name').text(response.group?.name || 'N/A');
                        $('#modal-project-title').text(response.group?.title || 'N/A');
                        $('#modal-project-description').text(response.group?.description || 'N/A');
                        $('#modal-group-members').text(response.group_members?.length > 0 ? response.group_members.join(', ') : 'N/A');

                        $('#studentModal').modal('show');
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        alert('Error fetching student details.');
                    }
                });
            });
        });
    </script>
</body>
</html>