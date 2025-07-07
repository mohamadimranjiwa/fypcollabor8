<?php
session_start();
include 'connection.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the lecturer is logged in
if (isset($_SESSION['user_id'])) {
    $lecturerID = $_SESSION['user_id'];
} else {
    header("Location: index.html");
    exit();
}

// Define color palette for calendar
$colorPalette = [
    '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
    '#6610f2', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997'
];

// Fetch group colors
$groupColors = [];
$defaultColor = '#6c757d';
$stmt = $conn->prepare("SELECT id FROM groups WHERE lecturer_id = ?");
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $groupID = $row['id'];
    $colorIndex = $groupID % count($colorPalette);
    $groupColors[$groupID] = $colorPalette[$colorIndex];
}
$stmt->close();
$groupColors['default'] = $defaultColor;

// Handle AJAX request for student details
if (isset($_POST['action']) && $_POST['action'] == 'get_student_details') {
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    if ($student_id <= 0) {
        echo json_encode(['error' => 'Invalid student ID']);
        exit();
    }
    $response = [];
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
    $stmt = $conn->prepare("
        SELECT g.id, g.name, p.title
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
    $conn->close();
    exit();
}

// Fetch lecturer's details including role
$sql = "SELECT full_name, profile_picture, role_id FROM lecturers WHERE id = ?";
$stmt = $conn->prepare($sql);
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
$roleID = $lecturer['role_id'] ?? 1;
$isSupervisor = in_array($roleID, [3, 4]);
$isAssessor = in_array($roleID, [2, 3]);

// Fetch total students (supervised or assessed)
$totalStudents = 0;
if ($isSupervisor || $isAssessor) {
    $sql = "SELECT COUNT(DISTINCT s.student_id) as count 
            FROM (
                SELECT student_id FROM group_members gm 
                JOIN groups g ON gm.group_id = g.id 
                WHERE g.lecturer_id = ? AND ? = 1
                UNION
                SELECT student_id FROM evaluation e 
                WHERE e.assessor_id = ? AND ? = 1
            ) s";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed (total students): " . $conn->error);
    }
    $stmt->bind_param("iiii", $lecturerID, $isSupervisor, $lecturerID, $isAssessor);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $totalStudents = $row['count'];
    }
    $stmt->close();
}

// Fetch upcoming meetings count and earliest group name
$upcomingMeetings = 0;
$upcomingGroupName = 'None';
if ($isSupervisor || $isAssessor) {
    $sql = "SELECT COUNT(*) as count, MIN(m.meeting_date) as min_date
            FROM meetings m
            WHERE m.lecturer_id = ? 
            AND m.meeting_date >= CURDATE() 
            AND m.meeting_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND m.status = 'Confirmed'";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed (upcoming meetings): " . $conn->error);
    }
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $upcomingMeetings = $row['count'];
        $minDate = $row['min_date'];
        if ($minDate) {
            $stmtGroup = $conn->prepare("
                SELECT g.name
                FROM meetings m
                JOIN groups g ON m.group_id = g.id
                WHERE m.lecturer_id = ? 
                AND m.meeting_date = ?
                AND m.status = 'Confirmed'
                LIMIT 1
            ");
            if ($stmtGroup === false) {
                die("Prepare failed (group name): " . $conn->error);
            }
            $stmtGroup->bind_param("is", $lecturerID, $minDate);
            $stmtGroup->execute();
            $resultGroup = $stmtGroup->get_result();
            if ($rowGroup = $resultGroup->fetch_assoc()) {
                $upcomingGroupName = $rowGroup['name'];
            }
            $stmtGroup->close();
        }
    }
    $stmt->close();
}

// Fetch total deliverable submissions (for supervised students)
$deliverableSubmissions = 0;
if ($isSupervisor) {
    $sql = "SELECT COUNT(*) as count 
            FROM deliverable_submissions ds 
            JOIN group_members gm ON ds.student_id = gm.student_id 
            JOIN groups g ON gm.group_id = g.id 
            WHERE g.lecturer_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed for deliverable submissions: " . $conn->error);
    }
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $deliverableSubmissions = $row['count'];
    }
    $stmt->close();
}

// Fetch pending project title proposals (for supervisors)
$pendingProposals = 0;
if ($isSupervisor) {
    $sql = "SELECT COUNT(*) as count 
            FROM projects p 
            JOIN groups g ON p.group_id = g.id 
            WHERE g.lecturer_id = ? 
            AND (p.pending_title IS NOT NULL OR p.pending_description IS NOT NULL)";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed (pending proposals): " . $conn->error);
    }
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $pendingProposals = $row['count'];
    }
    $stmt->close();
}

// Fetch total diary entries (for supervisors)
$totalDiaries = 0;
if ($isSupervisor) {
    $sql = "SELECT COUNT(*) as count 
            FROM diary d 
            JOIN group_members gm ON d.student_id = gm.student_id 
            JOIN groups g ON gm.group_id = g.id 
            WHERE g.lecturer_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed (total diaries): " . $conn->error);
    }
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $totalDiaries = $row['count'];
    }
    $stmt->close();
}

// Fetch evaluation progress (for assessors or supervisors)
$evaluationProgress = 0;
if ($isSupervisor || $isAssessor) {
    $sql = "SELECT (COUNT(e.id) / GREATEST(1, COUNT(DISTINCT s.student_id))) * 100 as eval_progress 
            FROM (
                SELECT gm.student_id 
                FROM group_members gm 
                JOIN groups g ON gm.group_id = g.id 
                WHERE g.lecturer_id = ? AND ? = 1
                UNION
                SELECT e2.student_id 
                FROM evaluation e2 
                WHERE e2.assessor_id = ? AND ? = 1
            ) AS s 
            LEFT JOIN evaluation e ON s.student_id = e.student_id";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed for evaluation progress: " . $conn->error);
    }
    $stmt->bind_param("iiii", $lecturerID, $isSupervisor, $lecturerID, $isAssessor);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $evaluationProgress = round($row['eval_progress'] ?? 0);
    }
    $stmt->close();
}

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

// Fetch meeting events
$meetings = [];
if ($isSupervisor || $isAssessor) {
    $sql = "SELECT m.title, m.meeting_date, m.meeting_time, m.topic, m.status, m.group_id, 
                   COALESCE(g.name, 'Unknown') AS group_name, s.full_name AS student_name
            FROM meetings m 
            LEFT JOIN groups g ON m.group_id = g.id
            LEFT JOIN students s ON m.student_id = s.id
            WHERE m.lecturer_id = ? AND m.status = 'Confirmed'";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Prepare failed (meetings): " . $conn->error);
    }
    $stmt->bind_param("i", $lecturerID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $meetings = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name, start_date FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery) or die("Error in current semester query: " . $conn->error);
$currentSemester = $currentSemesterResult->fetch_assoc();
$currentSemesterName = $currentSemester ? $currentSemester['semester_name'] : null;

// Fetch students for this lecturer (only from current semester)
$studentsQuery = "
    SELECT DISTINCT s.full_name, s.username as id, s.intake_year, s.intake_month, gm.group_id
    FROM students s
    JOIN group_members gm ON s.id = gm.student_id
    JOIN groups g ON gm.group_id = g.id
    WHERE (
        (g.lecturer_id = ? AND ? = 1) OR
        (g.assessor_id = ? AND ? = 1)
    )
    AND s.intake_year = YEAR(?) 
    AND s.intake_month = MONTHNAME(?)
    ORDER BY s.full_name";
$stmt = $conn->prepare($studentsQuery);
if (!$stmt) {
    error_log("Students Query preparation failed: " . $conn->error);
    die("Prepare failed (Students): " . $conn->error);
}
$stmt->bind_param("iiisss", $lecturerID, $isSupervisor, $lecturerID, $isAssessor, $currentSemester['start_date'], $currentSemester['start_date']);
$stmt->execute();
$studentsResult = $stmt->get_result();
$students = $studentsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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

    <title>Lecturer - Dashboard</title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">

    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <!-- DataTables -->
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

    <!-- Page Wrapper -->
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
            <li class="nav-item active">
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

            <!-- Main Content -->
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
                                <a class="dropdown-item" href="lectprofile.php">
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
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
                    </div>

                    <?php if ($isSupervisor || $isAssessor): ?>
                    <!-- Content Row (Top Cards) -->
                    <div class="row">
                        <!-- View Student Detail -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="lectviewstudentdetails.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                    View Student Detail</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalStudents ?> Students</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-users fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <?php if ($isSupervisor): ?>
                        <!-- Upcoming Meetings -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="lectmanagemeetings.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-success shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                    Upcoming Meetings</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $upcomingMeetings ?> with <?= htmlspecialchars($upcomingGroupName) ?></div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-calendar-check fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php else: ?>
                        <!-- View FYP Component (For Assessors) -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="assfypcomponents.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-success shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                    View FYP Component</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $deliverableSubmissions ?> Submissions</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <!-- Evaluate Students -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <?php /* <a href="<?= $isSupervisor ? 'lectevaluatestudent.php' : 'assevaluatestudent.php' ?>" style="text-decoration: none; color: inherit;"> */ ?>
                            <a href="#" style="text-decoration: none; color: inherit;" onclick="alert('Evaluation is now done directly via FYP Components page.'); return false;">
                                <div class="card border-left-info shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Evaluate Students</div>
                                                <div class="row no-gutters align-items-center">
                                                    <div class="col-auto">
                                                        <div class="h5 mb-0 mr-3 font-weight-bold text-gray-800"><?= $evaluationProgress ?>%</div>
                                                    </div>
                                                    <div class="col">
                                                        <div class="progress progress-sm mr-2">
                                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $evaluationProgress ?>%" aria-valuenow="<?= $evaluationProgress ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-star fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Supervisor-Specific Row -->
                    <?php if ($isSupervisor): ?>
                    <div class="row">
                        <!-- View FYP Component -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="lectfypcomponents.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-info shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">View Student Submissions</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $deliverableSubmissions ?> Submissions</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <!-- Title Proposal -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="lecttitleproposal.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-dark shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                                    Title Proposal</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $pendingProposals ?> Pending</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-file-alt fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <!-- View Student Diary -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <a href="lectviewdiary.php" style="text-decoration: none; color: inherit;">
                                <div class="card border-left-warning shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                    View Student Diary</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalDiaries ?> Entries</div>
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
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Announcements -->
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

                    <?php if ($isSupervisor || $isAssessor): ?>
                    <!-- Content Row (Calendar and Student Details) -->
                    <div class="row">
                        <?php if ($isSupervisor): ?>
                        <!-- Meeting Schedule -->
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
                        <?php endif; ?>

                        <!-- Student Details -->
                        <div class="col-lg-<?= $isSupervisor ? '6' : '12' ?>">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Student Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Student ID</th>
                                                    <th>Intake Year</th>
                                                    <th>Intake Month</th>
                                                </tr>
                                            </thead>
                                            <tfoot>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Student ID</th>
                                                    <th>Intake Year</th>
                                                    <th>Intake Month</th>
                                                </tr>
                                            </tfoot>
                                            <tbody>
                                                <?php if (empty($students)): ?>
                                                    <tr><td colspan="4">No students assigned.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($students as $student): ?>
                                                        <tr>
                                                            <td>
                                                                <?= htmlspecialchars($student['full_name']) ?>
                                                                <?php if (isset($student['group_id']) && $student['group_id'] > 0): ?>
                                                                    <i class="fas fa-info-circle info-icon ml-2"
                                                                       data-toggle="modal"
                                                                       data-target="#groupModal"
                                                                       data-student-id="<?= htmlspecialchars($student['id']) ?>"
                                                                       data-group-id="<?= htmlspecialchars($student['group_id']) ?>"></i>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?= htmlspecialchars($student['id']) ?></td>
                                                            <td><?= htmlspecialchars($student['intake_year'] ?? 'N/A') ?></td>
                                                            <td><?= htmlspecialchars($student['intake_month'] ?? 'N/A') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Group Details Modal -->
                    <div class="modal fade" id="groupModal" tabindex="-1" role="dialog" aria-labelledby="groupModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="groupModalLabel">Group Project Details</h5>
                                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </div>
                                <div class="modal-body">
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
                                        </tbody>
                                    </table>
                                    <h6>Submissions</h6>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Deliverable Name</th>
                                                <th>Submitter</th>
                                                <th>File</th>
                                                <th>Submitted At</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody id="modal-submissions"></tbody>
                                    </table>
                                    <h6>Marking Details</h6>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Deliverable Name</th>
                                                <th>Marker</th>
                                                <th>Grade</th>
                                                <th>Feedback</th>
                                                <th>Rubric Details</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody id="modal-evaluations"></tbody>
                                    </table>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Calendar Event Modal -->
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
                <!-- End of Main Content -->

                <!-- Footer -->
                <footer class="sticky-footer bg-white">
                    <div class="container my-auto">
                        <div class="copyright text-center my-auto">
                            <span>Copyright © FYPCollabor8 2025</span>
                        </div>
                    </div>
                </footer>
                <!-- End of Footer -->

            </div>
            <!-- End of Content Wrapper -->

        </div>
        <!-- End of Page Wrapper -->

        <!-- Scroll to Top Button -->
        <a class="scroll-to-top rounded" href="#page-top">
            <i class="fas fa-angle-up"></i>
        </a>

        <!-- Logout Modal -->
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

        <!-- Bootstrap core JavaScript -->
        <script src="vendor/jquery/jquery.min.js"></script>
        <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

        <!-- Core plugin JavaScript -->
        <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

        <!-- Custom scripts for all pages -->
        <script src="js/sb-admin-2.min.js"></script>

        <!-- Page level plugins -->
        <script src="vendor/datatables/jquery.dataTables.min.js"></script>
        <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

        <!-- Page level custom scripts -->
        <script src="js/demo/datatables-demo.js"></script>

        <!-- FullCalendar and Modal script -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const calendarEl = document.getElementById('calendar');
                if (calendarEl) {
                    const groupColors = <?php echo json_encode($groupColors); ?>;
                    const calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        height: 'auto',
                        events: [
                            <?php foreach ($meetings as $meeting): ?>{
                                title: '<?= addslashes($meeting['title']) ?>',
                                start: '<?= $meeting['meeting_date'] ?>T<?= $meeting['meeting_time'] ?>',
                                extendedProps: {
                                    studentName: '<?= addslashes($meeting['student_name'] ?? 'N/A') ?>',
                                    groupName: '<?= addslashes($meeting['group_name']) ?>',
                                    meetingDate: '<?= $meeting['meeting_date'] ?>',
                                    meetingTime: '<?= $meeting['meeting_time'] ?>',
                                    topic: '<?= addslashes($meeting['topic']) ?>',
                                    status: '<?= addslashes($meeting['status']) ?>'
                                },
                                backgroundColor: groupColors[<?= $meeting['group_id'] ?? "'default'" ?>],
                                borderColor: groupColors[<?= $meeting['group_id'] ?? "'default'" ?>]
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
                }

                // Handle group details modal
                $(document).ready(function() {
                    console.log('Modal script initialized');

                    $('.info-icon').on('click', function() {
                        console.log('Info icon clicked');
                        var studentId = $(this).data('student-id');
                        var groupName = $(this).data('group-name');
                        var projectTitle = $(this).data('project-title');
                        var projectDescription = $(this).data('project-description');
                        var submissions = $(this).data('submissions');
                        var evaluations = $(this).data('evaluations');

                        console.log('Student ID:', studentId);
                        console.log('Group Name:', groupName);
                        console.log('Submissions:', submissions);
                        console.log('Evaluations:', evaluations);

                        $('#modal-group-name').text(groupName || 'N/A');
                        $('#modal-project-title').text(projectTitle || 'N/A');
                        $('#modal-project-description').text(projectDescription || 'N/A');

                        var submissionsTable = $('#modal-submissions');
                        submissionsTable.empty();
                        try {
                            if (submissions && Array.isArray(submissions) && submissions.length > 0) {
                                submissions.forEach(function(submission) {
                                    if (submission.submission_type === 'group' || 
                                        (submission.submission_type === 'individual' && submission.student_id == studentId)) {
                                        var submittedAt = submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : 'Not Submitted';
                                        var submitter = submission.submission_type === 'group' ? 
                                            (submission.group_members && submission.group_members.length > 0 ? 
                                                submission.group_members.join(', ') : 'Group Members N/A') : 
                                            (submission.submitter_name || 'N/A');
                                        var fileLink = submission.file_path ? 
                                            `<a href="${submission.file_path}" target="_blank">${submission.file_path.split('/').pop()}</a>` : 
                                            'No File';
                                        var row = `
                                            <tr>
                                                <td>${submission.deliverable_name || 'N/A'}</td>
                                                <td>${submitter}</td>
                                                <td>${fileLink}</td>
                                                <td>${submittedAt}</td>
                                                <td>${submission.submission_type.charAt(0).toUpperCase() + submission.submission_type.slice(1)}</td>
                                            </tr>
                                        `;
                                        submissionsTable.append(row);
                                    }
                                });
                                if (submissionsTable.children().length === 0) {
                                    submissionsTable.append('<tr><td colspan="5">No relevant submissions found.</td></tr>');
                                }
                            } else {
                                console.log('No submissions data or invalid format');
                                submissionsTable.append('<tr><td colspan="5">No submissions found.</td></tr>');
                            }
                        } catch (e) {
                            console.error('Error processing submissions:', e);
                            submissionsTable.append('<tr><td colspan="5">Error loading submissions.</td></tr>');
                        }

                        var evaluationsTable = $('#modal-evaluations');
                        evaluationsTable.empty();
                        try {
                            if (evaluations && Array.isArray(evaluations) && evaluations.length > 0) {
                                var hasEvaluations = false;
                                evaluations.forEach(function(evaluation, index) {
                                    console.log(`Processing evaluation ${index}:`, evaluation);
                                    console.log(`Evaluation student_id: ${evaluation.student_id}, Modal studentId: ${studentId}`);
                                    if (evaluation.student_id == studentId) {
                                        hasEvaluations = true;
                                        var evalDate = evaluation.date ? new Date(evaluation.date).toLocaleDateString() : 'N/A';
                                        var grade = evaluation.evaluation_grade !== null && evaluation.evaluation_grade !== undefined ? 
                                            parseFloat(evaluation.evaluation_grade).toFixed(2) : 'N/A';
                                        var rubricDetails = evaluation.rubric_details ? 
                                            evaluation.rubric_details.split('; ').join('<br>') : 'No rubric details';
                                        var row = `
                                            <tr>
                                                <td>${evaluation.deliverable_name || 'N/A'}</td>
                                                <td>${evaluation.marker_name || 'N/A'}</td>
                                                <td>${grade}</td>
                                                <td>${evaluation.feedback || 'No feedback provided'}</td>
                                                <td>${rubricDetails}</td>
                                                <td>${evalDate}</td>
                                            </tr>
                                        `;
                                        evaluationsTable.append(row);
                                        console.log(`Added evaluation row for student ID ${studentId}:`, evaluation);
                                    } else {
                                        console.log(`Skipping evaluation ${index}: student_id ${evaluation.student_id} does not match modal studentId ${studentId}`);
                                    }
                                });
                                if (!hasEvaluations) {
                                    evaluationsTable.append('<tr><td colspan="6">No evaluations found for this student.</td></tr>');
                                    console.log('No evaluations matched student ID:', studentId);
                                }
                            } else {
                                console.log('No evaluations data received or invalid format');
                                evaluationsTable.append('<tr><td colspan="6">No evaluations found.</td></tr>');
                            }
                        } catch (e) {
                            console.error('Error processing evaluations:', e);
                            evaluationsTable.append('<tr><td colspan="6">Error loading evaluations.</td></tr>');
                        }

                        $('#groupModal').modal('show');
                    });
                });
            });
        </script>

    </body>
</html>