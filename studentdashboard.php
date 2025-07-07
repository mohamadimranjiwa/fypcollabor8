<?php
session_start();
include 'connection.php';

// Ensure the student is logged in
if (isset($_SESSION['user_id'])) {
    $studentID = $_SESSION['user_id'];
} else {
    die("Error: No student logged in. Please log in to access your profile.");
}

// Define a predefined color palette (same as studmeetingschedule.php)
$colorPalette = [
    '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
    '#6610f2', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997'
];

// Fetch all group IDs and assign colors dynamically
$groupColors = [];
$defaultColor = '#6c757d'; // Default color for null or unmapped group_id
$stmt = $conn->prepare("SELECT id FROM groups ORDER BY id ASC");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groupID = $row['id'];
        $colorIndex = $groupID % count($colorPalette);
        $groupColors[$groupID] = $colorPalette[$colorIndex];
    }
    $stmt->close();
} else {
    error_log("Prepare failed (Fetch Groups): " . $conn->error);
}
$groupColors['default'] = $defaultColor;

// Fetch the student's full name and profile picture
$sql = "SELECT full_name, profile_picture FROM students WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $studentID);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Error: No student found with the provided ID.");
}

$personalInfo = [
    'full_name' => $student['full_name'] ?? 'N/A',
    'profile_picture' => $student['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Fetch group, project info, and supervisor_id for the group
$groupInfo = null;
$supervisorIdForCalendar = null; // Initialize

$sql_group_details = "SELECT g.id AS group_id, g.name AS group_name, p.title AS project_title, g.lecturer_id
                      FROM group_members gm
                      JOIN groups g ON g.id = gm.group_id
                      LEFT JOIN projects p ON g.id = p.group_id
                      WHERE gm.student_id = ?";
$stmt_group_details = $conn->prepare($sql_group_details);
if ($stmt_group_details) {
    $stmt_group_details->bind_param("i", $studentID);
    $stmt_group_details->execute();
    $result_group_details = $stmt_group_details->get_result();
    if ($row_group_details = $result_group_details->fetch_assoc()) {
        $groupInfo = $row_group_details;
        $supervisorIdForCalendar = $groupInfo['lecturer_id'] ?? null;
    }
    $stmt_group_details->close();
} else {
    error_log("Prepare failed (Fetch Group Details for Calendar): " . $conn->error);
}

// Fetch meeting count for the current month
$meetingCount = 0;
$sql = "SELECT COUNT(*) as count 
        FROM meetings 
        WHERE student_id = ? AND MONTH(meeting_date) = MONTH(CURRENT_DATE()) AND YEAR(meeting_date) = YEAR(CURRENT_DATE())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $studentID);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $meetingCount = $row['count'];
}
$stmt->close();

// Fetch deliverables progress based on total deliverables and submissions
$deliverablesProgress = 0;
$totalDeliverables = 0;
$submittedDeliverables = 0;

// Get number of submitted deliverables for the student
$sql = "SELECT COUNT(DISTINCT CASE 
            WHEN d.submission_type = 'Individual' AND ds.student_id = ? THEN d.name
            WHEN d.submission_type = 'Group' AND (
                ds.student_id IN (
                    SELECT gm2.student_id 
                    FROM group_members gm
                    JOIN group_members gm2 ON gm2.group_id = gm.group_id
                    WHERE gm.student_id = ?
                )
            ) THEN d.name
            END) as submitted,
        COUNT(DISTINCT d.name) as total
        FROM deliverables d
        LEFT JOIN deliverable_submissions ds ON d.name = ds.deliverable_name
        WHERE d.semester = (
            SELECT CONCAT(intake_month, ' ', intake_year) 
            FROM students 
            WHERE id = ?
        )";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $studentID, $studentID, $studentID);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $submittedDeliverables = $row['submitted'];
    $totalDeliverables = $row['total'];
}
$stmt->close();

// Calculate progress percentage
if ($totalDeliverables > 0) {
    $deliverablesProgress = round(($submittedDeliverables / $totalDeliverables) * 100);
} else {
    $deliverablesProgress = 0;
}

// Fetch diary progress (count of entries for the student)
$diaryProgress = 0;
$sql = "SELECT COUNT(*) as count 
        FROM diary 
        WHERE student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $studentID);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $diaryProgress = $row['count'];
}
$stmt->close();

// Fetch announcements
$announcements = [];
$sql = "SELECT title, details, created_at 
        FROM announcements 
        ORDER BY created_at DESC 
        LIMIT 3";
$result = $conn->query($sql);
if ($result) {
    $announcements = $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch teaching materials
$teachingMaterials = [];
$sql = "SELECT title, description, file_path 
        FROM teaching_materials 
        ORDER BY uploaded_at DESC 
        LIMIT 3";
$result = $conn->query($sql);
if ($result) {
    $teachingMaterials = $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch the student's intake semester
$studentSemester = '';
$sql_student_semester = "SELECT intake_month, intake_year FROM students WHERE id = ?";
$stmt_student_semester = $conn->prepare($sql_student_semester);
if ($stmt_student_semester) {
    $stmt_student_semester->bind_param("i", $studentID);
    $stmt_student_semester->execute();
    $result_student_semester = $stmt_student_semester->get_result();
    if ($result_student_semester && $row_student_semester = $result_student_semester->fetch_assoc()) {
        if (!empty($row_student_semester['intake_month']) && !empty($row_student_semester['intake_year'])) {
            $studentSemester = $row_student_semester['intake_month'] . ' ' . $row_student_semester['intake_year'];
        }
    }
    $stmt_student_semester->close();
} else {
    error_log("Prepare failed (Fetch Student Semester): " . $conn->error);
}

// Fetch deliverables list and submission status for the student's semester
$deliverables = [];
if (!empty($studentSemester)) {
    $sql_deliverables = "SELECT 
            d.name,
            d.semester,
            d.submission_type,
            CASE 
                WHEN d.submission_type = 'Individual' AND ds.student_id = ? THEN 'Submitted'
                WHEN d.submission_type = 'Group' AND (
                    ds.student_id IN (
                        SELECT gm2.student_id 
                        FROM group_members gm
                        JOIN group_members gm2 ON gm2.group_id = gm.group_id
                        WHERE gm.student_id = ?
                    )
                ) THEN 'Submitted'
                ELSE 'Not Submitted'
            END as submission_status
        FROM deliverables d
        LEFT JOIN deliverable_submissions ds ON d.name = ds.deliverable_name
        WHERE d.semester = ?
        GROUP BY d.name
        ORDER BY d.name";
    $stmt_deliverables = $conn->prepare($sql_deliverables);
    if ($stmt_deliverables) {
        $stmt_deliverables->bind_param("iis", $studentID, $studentID, $studentSemester);
        $stmt_deliverables->execute();
        $result_deliverables = $stmt_deliverables->get_result();
        if ($result_deliverables) {
            $deliverables = $result_deliverables->fetch_all(MYSQLI_ASSOC);
            foreach ($deliverables as &$deliverable) {
                $deliverable['submitted'] = ($deliverable['submission_status'] === 'Submitted');
            }
            unset($deliverable);
        }
        $stmt_deliverables->close();
    } else {
        error_log("Prepare failed (Fetch Deliverables): " . $conn->error);
    }
}

// Fetch meeting events for FullCalendar based on supervisor
$meetings = [];
if ($supervisorIdForCalendar) {
    $sql_meetings_cal = "SELECT m.title, m.meeting_date, m.meeting_time, m.topic, m.status, m.group_id,
                               COALESCE(g.name, 'Unknown') AS group_name, s.full_name AS student_name
                        FROM meetings m
                        INNER JOIN students s ON m.student_id = s.id
                        LEFT JOIN groups g ON m.group_id = g.id
                        WHERE m.lecturer_id = ? AND m.status != 'Cancelled'
                        ORDER BY m.meeting_date ASC, m.meeting_time ASC";
    $stmt_meetings_cal = $conn->prepare($sql_meetings_cal);
    if ($stmt_meetings_cal) {
        $stmt_meetings_cal->bind_param("i", $supervisorIdForCalendar);
        $stmt_meetings_cal->execute();
        $result_meetings_cal = $stmt_meetings_cal->get_result();
        if ($result_meetings_cal) {
            $meetings = $result_meetings_cal->fetch_all(MYSQLI_ASSOC);
        }
        $stmt_meetings_cal->close();
    } else {
        error_log("Prepare failed (Fetch Meetings for Calendar): " . $conn->error);
    }
}

// Close the connection
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
    <title>Student - Dashboard</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        /* Styling for calendar event modal table */
        #calendarEventTable th, #calendarEventTable td {
            vertical-align: middle;
        }
        #calendarEventTable .confirmed { color: #28a745; }
        #calendarEventTable .pending { color: #ffc107; }
        #calendarEventTable .cancelled { color: #dc3545; }
    </style>
</head>
<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="studentdashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item active">
                <a class="nav-link" href="studentdashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Student Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Project Management</span>
                </a>
                <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Core Elements:</h6>
                        <a class="collapse-item" href="studprojectoverview.php">Project Overview</a>
                        <a class="collapse-item" href="studdeliverables.php">Deliverables</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Documentation</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Support Resources:</h6>
                        <a class="collapse-item" href="studdiaryprogress.php">Diary Progress</a>
                        <a class="collapse-item" href="studteachingmaterials.php">Teaching Materials</a>
                        <a class="collapse-item" href="studmeetingschedule.php">Meeting Schedule</a>
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
                                <a class="dropdown-item" href="studprofile.php">
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
                    <div class="row">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <a href="studprojectoverview.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                    Project Overview</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                    <?= htmlspecialchars($groupInfo['group_name'] ?? 'No Group') ?>                                                    
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-calendar fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <a href="studmeetingschedule.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-success shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                    Meeting Schedule</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                    <?= $meetingCount ?> This Month
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-calendar-check fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <a href="studdeliverables.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-info shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Deliverables</div>
                                                <div class="row no-gutters align-items-center">
                                                    <div class="col-auto">
                                                        <div class="h5 mb-0 mr-3 font-weight-bold text-gray-800"><?= $deliverablesProgress ?>%</div>
                                                    </div>
                                                    <div class="col">
                                                        <div class="progress progress-sm mr-2">
                                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $deliverablesProgress ?>%" aria-valuenow="<?= $deliverablesProgress ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <a href="studdiaryprogress.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-warning shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                    Diary Progress</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                    <?= $diaryProgress ?> Entries
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-book fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6">
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
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Teaching Materials</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($teachingMaterials)): ?>
                                        <p>No teaching materials available.</p>
                                    <?php else: ?>
                                        <?php foreach ($teachingMaterials as $index => $material): ?>
                                            <div class="mb-3">
                                                <h6 class="font-weight-bold"><?= htmlspecialchars($material['title']) ?></h6>
                                                <p><?= htmlspecialchars($material['description'] ?? 'No description') ?></p>
                                                <a href="<?= htmlspecialchars($material['file_path']) ?>" class="btn btn-primary btn-icon-split">
                                                    <span class="icon text-white-50">
                                                        <i class="fas fa-download"></i>
                                                    </span>
                                                    <span class="text">View</span>
                                                </a>
                                            </div>
                                            <?php if ($index < count($teachingMaterials) - 1): ?>
                                                <hr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Project Deliverables</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($deliverables)): ?>
                                        <p>No deliverables assigned.</p>
                                    <?php else: ?>
                                        <h4 class="small font-weight-bold">Overall Progress <span class="float-right"><?= $deliverablesProgress ?>%</span></h4>
                                        <div class="progress mb-4">
                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $deliverablesProgress ?>%" aria-valuenow="<?= $deliverablesProgress ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <?php foreach ($deliverables as $deliverable): ?>
                                            <?php
                                            $progress = $deliverable['submitted'] ? 100 : 0;
                                            $barClass = $deliverable['submitted'] ? 'bg-success' : 'bg-danger';
                                            ?>
                                            <h4 class="small font-weight-bold"><?= htmlspecialchars($deliverable['name']) ?> <span class="float-right"><?= $progress ?>%</span></h4>
                                            <div class="progress mb-4">
                                                <div class="progress-bar <?= $barClass ?>" role="progressbar" style="width: <?= $progress ?>%" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Meeting Schedule</h6>
                                </div>
                                <div class="card-body">
                                    <div id="calendar"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- New Calendar Event Modal -->
                    <div class="modal fade" id="calendarEventModal" tabindex="-1" role="dialog" aria-labelledby="calendarEventModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="calendarEventModalLabel">Meeting Details</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="calendarEventTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Group Name</th>
                                                    <th>Meeting Date</th>
                                                    <th>Meeting Time</th>
                                                    <th>Topic</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="calendarEventTableBody">
                                                <!-- Populated dynamically via JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright © Your Website 2021</span>
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
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script>
        const groupColors = <?php echo json_encode($groupColors); ?>;
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                events: [
                    <?php foreach ($meetings as $meeting): ?>{
                        title: '<?= addslashes($meeting['topic']) ?>',
                        start: '<?= $meeting['meeting_date'] ?>T<?= $meeting['meeting_time'] ?>',
                        extendedProps: {
                            studentName: '<?= addslashes($meeting['student_name']) ?>',
                            groupName: '<?= addslashes($meeting['group_name']) ?>',
                            meetingDate: '<?= $meeting['meeting_date'] ?>',
                            meetingTime: '<?= $meeting['meeting_time'] ?>',
                            topic: '<?= addslashes($meeting['topic']) ?>',
                            status: '<?= addslashes($meeting['status']) ?>'
                        },
                        backgroundColor: groupColors['<?= $meeting['group_id'] ?>'] || groupColors['default'],
                        borderColor: groupColors['<?= $meeting['group_id'] ?>'] || groupColors['default']
                    },
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    // Clear previous table content
                    const tableBody = document.getElementById('calendarEventTableBody');
                    tableBody.innerHTML = '';

                    // Create table row
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${info.event.extendedProps.studentName}</td>
                        <td>${info.event.extendedProps.groupName}</td>
                        <td>${info.event.extendedProps.meetingDate}</td>
                        <td>${info.event.extendedProps.meetingTime}</td>
                        <td>${info.event.extendedProps.topic}</td>
                        <td class="${info.event.extendedProps.status.toLowerCase()}">
                            <span class="${info.event.extendedProps.status.toLowerCase() === 'confirmed' ? 'text-success' : 
                                          info.event.extendedProps.status.toLowerCase() === 'pending' ? 'text-warning' : 'text-danger'}">
                                ${info.event.extendedProps.status}
                            </span>
                        </td>
                    `;
                    tableBody.appendChild(row);

                    // Show the modal
                    $('#calendarEventModal').modal('show');
                }
            });
            calendar.render();
        });
    </script>
</body>
</html>