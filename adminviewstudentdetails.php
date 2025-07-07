<?php
session_start();
include 'connection.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: adminindex.html");
    exit();
}
$adminID = $_SESSION['user_id'];

// Verify database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Handle AJAX request for fetching groups
if (isset($_GET['action']) && $_GET['action'] === 'fetch_groups') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'groups' => []];
    
    $semester = isset($_GET['semester']) ? trim($_GET['semester']) : '';
    
    $groupsQuery = "
        SELECT DISTINCT g.name 
        FROM groups g
        JOIN group_members gm ON g.id = gm.group_id
        JOIN students s ON gm.student_id = s.id
        WHERE g.name IS NOT NULL";
    $groupsParams = [];
    $groupsParamTypes = "";
    if ($semester) {
        $groupsQuery .= " AND CONCAT(s.intake_month, ' ', s.intake_year) = ?";
        $groupsParams[] = $semester;
        $groupsParamTypes .= "s";
    }
    $groupsQuery .= " ORDER BY g.name ASC";
    
    $stmt = $conn->prepare($groupsQuery);
    if ($stmt === false) {
        $response['message'] = 'Prepare failed for groups query: ' . $conn->error;
        echo json_encode($response);
        exit();
    }
    if (!empty($groupsParams)) {
        $stmt->bind_param($groupsParamTypes, ...$groupsParams);
    }
    $stmt->execute();
    $groupsResult = $stmt->get_result();
    $groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $groupsResult->free();
    
    $response['success'] = true;
    $response['groups'] = $groups;
    echo json_encode($response);
    $conn->close();
    exit();
}

// Fetch the admin's details
$sql = "SELECT full_name, profile_picture FROM admins WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $adminID);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if (!$admin) {
    die("Error: No admin found with the provided ID.");
}

$personalInfo = [
    'full_name' => $admin['full_name'] ?? 'N/A',
    'profile_picture' => $admin['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message for warnings
$message = "";

// Input validation for filter parameters
$selectedSemester = isset($_GET['semester']) && trim($_GET['semester']) !== '' ? trim($_GET['semester']) : '';
$searchStudent = isset($_GET['username']) && trim($_GET['username']) !== '' ? trim($_GET['username']) : '';
$selectedGroup = isset($_GET['group_name']) && trim($_GET['group_name']) !== '' ? trim($_GET['group_name']) : '';
$selectedDeliverable = isset($_GET['deliverable_id']) && is_numeric($_GET['deliverable_id']) ? intval($_GET['deliverable_id']) : 0;

// Validate semester format (e.g., "January 2023")
if ($selectedSemester && !preg_match('/^[A-Za-z]+ \d{4}$/', $selectedSemester)) {
    $selectedSemester = '';
    $message .= "<div class='alert alert-warning'>Invalid semester format. Please select a valid semester.</div>";
}

// Fetch semesters for the filter (exclude N/A)
$semestersQuery = "SELECT semester_name FROM semesters WHERE semester_name IS NOT NULL AND semester_name != 'N/A' ORDER BY semester_name DESC";
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . $conn->error);
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);
$semestersResult->free();

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery) or die("Error in current semester query: " . $conn->error);
$currentSemester = $currentSemesterResult->fetch_assoc();
$currentSemesterName = $currentSemester ? $currentSemester['semester_name'] : (count($semesters) > 0 ? $semesters[0]['semester_name'] : 'No Semester');
$currentSemesterResult->free();

// Set default semester to current or first available if none selected
if (!$selectedSemester && count($semesters) > 0) {
    $selectedSemester = $currentSemesterName;
}

// Fetch group names for the filter, dependent on selected semester
$groupsQuery = "
    SELECT DISTINCT g.name 
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id
    WHERE g.name IS NOT NULL";
$groupsParams = [];
$groupsParamTypes = "";
if ($selectedSemester) {
    $groupsQuery .= " AND CONCAT(s.intake_month, ' ', s.intake_year) = ?";
    $groupsParams[] = $selectedSemester;
    $groupsParamTypes .= "s";
}
$groupsQuery .= " ORDER BY g.name ASC";
$stmt = $conn->prepare($groupsQuery);
if ($stmt === false) {
    die("Prepare failed for groups query: " . $conn->error);
}
if (!empty($groupsParams)) {
    $stmt->bind_param($groupsParamTypes, ...$groupsParams);
}
$stmt->execute();
$groupsResult = $stmt->get_result();
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$groupsResult->free();

// Fetch deliverables for the filter
$deliverablesQuery = "SELECT id, name FROM deliverables ORDER BY name ASC";
$deliverablesResult = $conn->query($deliverablesQuery) or die("Error in deliverables query: " . $conn->error);
$deliverables = $deliverablesResult->fetch_all(MYSQLI_ASSOC);
$deliverablesResult->free();

// Fetch total students
$totalStudentsConditions = [];
$totalStudentsParams = [];
$totalStudentsParamTypes = "";
$totalStudentsConditions[] = "g.status = 'Approved'";
if ($selectedSemester) {
    $totalStudentsConditions[] = "CONCAT(s.intake_month, ' ', s.intake_year) = ?";
    $totalStudentsParams[] = $selectedSemester;
    $totalStudentsParamTypes .= "s";
}
if ($searchStudent) {
    $totalStudentsConditions[] = "s.username LIKE ?";
    $totalStudentsParams[] = "%$searchStudent%";
    $totalStudentsParamTypes .= "s";
}
if ($selectedGroup) {
    $totalStudentsConditions[] = "g.name = ?";
    $totalStudentsParams[] = $selectedGroup;
    $totalStudentsParamTypes .= "s";
}
$totalStudentsQuery = "
    SELECT COUNT(DISTINCT s.id) AS total_students 
    FROM students s
    JOIN group_members gm ON s.id = gm.student_id
    JOIN groups g ON gm.group_id = g.id
    JOIN projects p ON g.id = p.group_id";
if (!empty($totalStudentsConditions)) {
    $totalStudentsQuery .= " WHERE " . implode(" AND ", $totalStudentsConditions);
}
$stmt = $conn->prepare($totalStudentsQuery);
if ($stmt === false) {
    die("Prepare failed for total students query: " . $conn->error);
}
if (!empty($totalStudentsParams)) {
    $stmt->bind_param($totalStudentsParamTypes, ...$totalStudentsParams);
}
$stmt->execute();
$totalStudentsResult = $stmt->get_result();
$totalStudents = ($totalStudentsResult && $row = $totalStudentsResult->fetch_assoc()) ? $row['total_students'] : 0;
$stmt->close();
$totalStudentsResult->free();

// Fetch total projects
$totalProjectsConditions = [];
$totalProjectsParams = [];
$totalProjectsParamTypes = "";
$totalProjectsConditions[] = "g.status = 'Approved'";
if ($selectedSemester) {
    $totalProjectsConditions[] = "CONCAT(s.intake_month, ' ', s.intake_year) = ?";
    $totalProjectsParams[] = $selectedSemester;
    $totalProjectsParamTypes .= "s";
}
if ($selectedGroup) {
    $totalProjectsConditions[] = "g.name = ?";
    $totalProjectsParams[] = $selectedGroup;
    $totalProjectsParamTypes .= "s";
}
$totalProjectsQuery = "
    SELECT COUNT(DISTINCT p.project_id) AS total_projects 
    FROM projects p
    JOIN groups g ON p.group_id = g.id
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id";
if (!empty($totalProjectsConditions)) {
    $totalProjectsQuery .= " WHERE " . implode(" AND ", $totalProjectsConditions);
}
error_log("Total Projects Query: $totalProjectsQuery, Params: " . json_encode($totalProjectsParams));
$stmt = $conn->prepare($totalProjectsQuery);
if ($stmt === false) {
    die("Prepare failed for total projects query: " . $conn->error);
}
if (!empty($totalProjectsParams)) {
    $stmt->bind_param($totalProjectsParamTypes, ...$totalProjectsParams);
}
$stmt->execute();
$totalProjectsResult = $stmt->get_result();
$totalProjects = ($totalProjectsResult && $row = $totalProjectsResult->fetch_assoc()) ? $row['total_projects'] : 0;
$stmt->close();
$totalProjectsResult->free();

// Fetch student details with group ID and total evaluation grades
$studentDetailsConditions = [];
$studentDetailsParams = [];
$studentDetailsParamTypes = "";
$studentDetailsConditions[] = "g.status = 'Approved'";
if ($selectedSemester) {
    $studentDetailsConditions[] = "CONCAT(s.intake_month, ' ', s.intake_year) = ?";
    $studentDetailsParams[] = $selectedSemester;
    $studentDetailsParamTypes .= "s";
}
if ($searchStudent) {
    $studentDetailsConditions[] = "s.username LIKE ?";
    $studentDetailsParams[] = "%$searchStudent%";
    $studentDetailsParamTypes .= "s";
}
if ($selectedGroup) {
    $studentDetailsConditions[] = "g.name = ?";
    $studentDetailsParams[] = $selectedGroup;
    $studentDetailsParamTypes .= "s";
}
if ($selectedDeliverable > 0) {
    $studentDetailsConditions[] = "d.id = ?";
    $studentDetailsParams[] = $selectedDeliverable;
    $studentDetailsParamTypes .= "i";
}
$studentDetailsQuery = "
    SELECT 
        s.id AS student_id,
        s.full_name,
        s.email,
        s.username,
        s.no_ic,
        s.no_tel,
        s.intake_year,
        s.intake_month,
        g.id AS group_id,
        g.name AS group_name,
        ls.full_name AS supervisor_name,
        la.full_name AS assessor_name,
        COALESCE(SUM(e.evaluation_grade), 0) AS total_marks
    FROM students s
    LEFT JOIN group_members gm ON s.id = gm.student_id
    LEFT JOIN groups g ON gm.group_id = g.id
    LEFT JOIN lecturers ls ON g.lecturer_id = ls.id
    LEFT JOIN projects p ON g.id = p.group_id
    LEFT JOIN lecturers la ON p.lecturer_id = la.id
    LEFT JOIN evaluation e ON s.id = e.student_id
    LEFT JOIN deliverables d ON e.deliverable_id = d.id";
if (!empty($studentDetailsConditions)) {
    $studentDetailsQuery .= " WHERE " . implode(" AND ", $studentDetailsConditions);
}
$studentDetailsQuery .= " GROUP BY s.id, s.full_name, s.email, s.username, s.no_ic, s.no_tel, s.intake_year, s.intake_month, g.id, g.name, ls.full_name, la.full_name
    ORDER BY g.name, s.full_name";
$stmt = $conn->prepare($studentDetailsQuery);
if ($stmt === false) {
    die("Prepare failed for student details query: " . $conn->error);
}
if (!empty($studentDetailsParams)) {
    $stmt->bind_param($studentDetailsParamTypes, ...$studentDetailsParams);
}
$stmt->execute();
$studentDetailsResult = $stmt->get_result();
$studentDetails = $studentDetailsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$studentDetailsResult->free();

// Fetch project, submission, and evaluation details for each group
$groupDetails = [];
foreach ($studentDetails as $detail) {
    if (!isset($groupDetails[$detail['group_id']]) && $detail['group_id']) {
        // Fetch project details
        $projectConditions = [];
        $projectParams = [$detail['group_id']];
        $projectParamTypes = "i";
        $projectConditions[] = "g.status = 'Approved'";
        if ($selectedSemester) {
            $projectConditions[] = "CONCAT(s.intake_month, ' ', s.intake_year) = ?";
            $projectParams[] = $selectedSemester;
            $projectParamTypes .= "s";
        }
        $projectQuery = "
            SELECT 
                g.name AS group_name,
                p.title AS project_title,
                p.description AS project_description
            FROM projects p
            JOIN groups g ON p.group_id = g.id
            JOIN group_members gm ON g.id = gm.group_id
            JOIN students s ON gm.student_id = s.id
            WHERE g.id = ?";
        if (!empty($projectConditions)) {
            $projectQuery .= " AND " . implode(" AND ", $projectConditions);
        }
        $stmt = $conn->prepare($projectQuery);
        if ($stmt === false) {
            error_log("Prepare failed for project query: " . $conn->error);
            $message .= "<div class='alert alert-warning'>Warning: Failed to fetch project details for group ID {$detail['group_id']}.</div>";
            continue;
        }
        $stmt->bind_param($projectParamTypes, ...$projectParams);
        $stmt->execute();
        $projectResult = $stmt->get_result();
        $project = $projectResult->fetch_assoc() ?: [
            'group_name' => 'No Group',
            'project_title' => 'No Project',
            'project_description' => 'No Description'
        ];
        $stmt->close();
        $projectResult->free();

        // Fetch submissions
        $submissionsConditions = [];
        $submissionsParams = [$detail['group_id']];
        $submissionsParamTypes = "i";
        $submissionsConditions[] = "g.status = 'Approved'";
        if ($selectedSemester) {
            $submissionsConditions[] = "d.semester = ?";
            $submissionsParams[] = $selectedSemester;
            $submissionsParamTypes .= "s";
        }
        if ($selectedDeliverable > 0) {
            $submissionsConditions[] = "d.id = ?";
            $submissionsParams[] = $selectedDeliverable;
            $submissionsParamTypes .= "i";
        }
        $submissionsQuery = "
            SELECT 
                ds.deliverable_name,
                ds.file_path,
                ds.submitted_at,
                ds.student_id,
                d.submission_type,
                s.full_name AS submitter_name
            FROM deliverable_submissions ds
            JOIN deliverables d ON ds.deliverable_id = d.id
            JOIN groups g ON ds.group_id = g.id
            LEFT JOIN students s ON ds.student_id = s.id
            WHERE ds.group_id = ?";
        if (!empty($submissionsConditions)) {
            $submissionsQuery .= " AND " . implode(" AND ", $submissionsConditions);
        }
        $submissionsQuery .= " ORDER BY ds.submitted_at DESC";
        error_log("Submissions Query for group ID {$detail['group_id']}: $submissionsQuery");
        $stmt = $conn->prepare($submissionsQuery);
        if ($stmt === false) {
            error_log("Prepare failed for submissions query: " . $conn->error);
            $message .= "<div class='alert alert-warning'>Warning: Failed to fetch submissions for group ID {$detail['group_id']}.</div>";
            continue;
        }
        $stmt->bind_param($submissionsParamTypes, ...$submissionsParams);
        $stmt->execute();
        $submissionsResult = $stmt->get_result();
        $submissions = $submissionsResult->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $submissionsResult->free();

        // Fetch group members for group submissions
        foreach ($submissions as &$submission) {
            if ($submission['submission_type'] === 'group') {
                $membersQuery = "
                    SELECT s.full_name
                    FROM group_members gm
                    JOIN students s ON gm.student_id = s.id
                    WHERE gm.group_id = ?
                    ORDER BY s.full_name ASC";
                $stmt = $conn->prepare($membersQuery);
                if ($stmt === false) {
                    error_log("Prepare failed for group members query: " . $conn->error);
                    $submission['group_members'] = ['Error fetching members'];
                    continue;
                }
                $stmt->bind_param("i", $detail['group_id']);
                $stmt->execute();
                $membersResult = $stmt->get_result();
                $submission['group_members'] = array_column($membersResult->fetch_all(MYSQLI_ASSOC), 'full_name');
                $stmt->close();
                $membersResult->free();
            } else {
                $submission['group_members'] = [];
                $submission['submitter_name'] = $submission['submitter_name'] ?? 'No Submitter';
            }
        }
        unset($submission); // Unset reference to avoid issues

        // Fetch evaluation details for all students in the group
        $evaluationsQuery = "
            SELECT 
                e.id AS evaluation_id,
                e.student_id,
                e.evaluation_grade,
                e.feedback,
                e.date,
                e.type,
                d.name AS deliverable_name,
                COALESCE(c.full_name, ls.full_name, la.full_name, 'No Marker') AS marker_name,
                GROUP_CONCAT(CONCAT(r.criteria, ': ', ers.score, '/', r.max_score) ORDER BY r.id SEPARATOR '; ') AS rubric_details
            FROM evaluation e
            JOIN deliverables d ON e.deliverable_id = d.id
            LEFT JOIN coordinators c ON e.coordinator_id = c.id
            LEFT JOIN lecturers ls ON e.supervisor_id = ls.id
            LEFT JOIN lecturers la ON e.assessor_id = la.id
            LEFT JOIN evaluation_rubric_scores ers ON e.id = ers.evaluation_id
            LEFT JOIN rubrics r ON ers.rubric_id = r.id
            WHERE e.student_id IN (
                SELECT student_id FROM group_members WHERE group_id = ?
            )";
        $evaluationsParams = [$detail['group_id']];
        $evaluationsParamTypes = "i";
        if ($selectedDeliverable > 0) {
            $evaluationsQuery .= " AND d.id = ?";
            $evaluationsParams[] = $selectedDeliverable;
            $evaluationsParamTypes .= "i";
        }
        if ($selectedSemester) {
            $evaluationsQuery .= " AND d.semester = ?";
            $evaluationsParams[] = $selectedSemester;
            $evaluationsParamTypes .= "s";
        }
        $evaluationsQuery .= " GROUP BY e.id, e.student_id, e.evaluation_grade, e.feedback, e.date, e.type, d.name, c.full_name, ls.full_name, la.full_name
            ORDER BY e.date DESC";
        error_log("Evaluations Query for group ID {$detail['group_id']}: $evaluationsQuery");
        $stmt = $conn->prepare($evaluationsQuery);
        if ($stmt === false) {
            error_log("Prepare failed for evaluations query: " . $conn->error);
            $message .= "<div class='alert alert-warning'>Warning: Failed to fetch evaluations for group ID {$detail['group_id']}.</div>";
            continue;
        }
        $stmt->bind_param($evaluationsParamTypes, ...$evaluationsParams);
        $stmt->execute();
        $evaluationsResult = $stmt->get_result();
        $evaluations = $evaluationsResult->fetch_all(MYSQLI_ASSOC);
        error_log("Evaluations fetched for group ID {$detail['group_id']}: " . json_encode($evaluations));
        $stmt->close();
        $evaluationsResult->free();

        $groupDetails[$detail['group_id']] = [
            'group_name' => $project['group_name'],
            'project_title' => $project['project_title'],
            'project_description' => $project['project_description'],
            'submissions' => $submissions,
            'evaluations' => $evaluations
        ];
    }
}

// Check for data consistency
$invalidGroupIds = array_filter(array_keys($groupDetails), fn($id) => !is_numeric($id) || $id <= 0);
if (!empty($invalidGroupIds)) {
    $message .= "<div class='alert alert-warning'>Warning: Invalid group IDs detected: " . implode(', ', $invalidGroupIds) . ".</div>";
}

// Check if no students were found
if (empty($studentDetails) && ($selectedSemester || $searchStudent || $selectedGroup || $selectedDeliverable)) {
    $message .= "<div class='alert alert-info'>No students match the selected filters.</div>";
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
    <title>Admin - View Student Details</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

    <!-- Custom CSS for modal, icon, and loading spinner -->
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
        .loading-spinner {
            display: none;
            margin-left: 10px;
        }
    </style>
</head>
<body id="page-top">
    <div id="wrapper">
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="admindashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="admindashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Admin Portal</div>
            <li class="nav-item">
                <a class="nav-link" href="adminmanagelecturers.php">
                    <i class="fas fa-fw fa-users"></i>
                    <span>Manage Lecturers</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="adminmanagestudents.php">
                    <i class="fas fa-fw fa-user-graduate"></i>
                    <span>Manage Students</span></a>
            </li>
            <li class="nav-item active">
                <a class="nav-link" href="adminviewstudentdetails.php">
                    <i class="fas fa-fw fa-user"></i>
                    <span>View Student Details</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="adminmanageannouncement.php">
                    <i class="fas fa-fw fa-bullhorn"></i>
                    <span>Manage Announcement</span></a>
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
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle"
                                    src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>"
                                    onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="adminprofile.php">
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

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <?= $message ?>
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">View Student Details</h1>
                        
                    </div>

                    <!-- Dashboard Cards -->
                    <div class="row">
                        <!-- Total Students Card -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Total Students</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalStudents) ?> Students
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-users fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Projects Card -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Total Projects</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalProjects) ?> Projects
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-project-diagram fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Current Semester Card -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Current Semester</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($currentSemesterName) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Students</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" id="filterForm">
                                <div class="row">
                                    <!-- Semester Filter -->
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
                                    <!-- Username Search -->
                                    <div class="col-md-3 mb-3">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?= htmlspecialchars($searchStudent) ?>" placeholder="Enter username">
                                    </div>
                                    <!-- Group Name Filter -->
                                    <div class="col-md-3 mb-3">
                                        <label for="group_name">Group Name</label>
                                        <select class="form-control" id="group_name" name="group_name">
                                            <option value="">-- All Groups --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?= htmlspecialchars($group['name']) ?>" 
                                                        <?= $selectedGroup === $group['name'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    </div>
                                    <!-- Deliverable Filter -->
                                    <div class="col-md-3 mb-3">
                                        <label for="deliverable_id">Deliverable</label>
                                        <select class="form-control" id="deliverable_id" name="deliverable_id">
                                            <option value="0">-- All Deliverables --</option>
                                            <?php foreach ($deliverables as $deliverable): ?>
                                                <option value="<?= $deliverable['id'] ?>" 
                                                        <?= $selectedDeliverable === $deliverable['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($deliverable['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" id="applyFilters">Apply Filters</button>
                                <a href="adminviewstudentdetails.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>

                    <!-- Student Details Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Student Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Username</th>
                                            <th>IC Number</th>
                                            <th>Phone Number</th>
                                            <th>Intake Year</th>
                                            <th>Intake Month</th>
                                            <th>Group Name</th>
                                            <th>Supervisor</th>
                                            <th>Assessor</th>
                                            <th>Total Marks (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($studentDetails)): ?>
                                            <?php foreach ($studentDetails as $detail): ?>
                                                <?php
                                                $groupId = $detail['group_id'] ?? 0;
                                                $studentId = $detail['student_id'];
                                                $groupInfo = isset($groupDetails[$groupId]) ? $groupDetails[$groupId] : [
                                                    'group_name' => 'No Group',
                                                    'project_title' => 'No Project',
                                                    'project_description' => 'No Description',
                                                    'submissions' => [],
                                                    'evaluations' => []
                                                ];
                                                $submissionsJson = json_encode($groupInfo['submissions']);
                                                $evaluationsJson = json_encode($groupInfo['evaluations']);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?= htmlspecialchars($detail['full_name']) ?>
                                                        <?php if ($groupId > 0): ?>
                                                            <i class="fas fa-info-circle info-icon"
                                                               data-toggle="modal"
                                                               data-target="#groupModal"
                                                               data-student-id="<?= $studentId ?>"
                                                               data-group-id="<?= $groupId ?>"
                                                               data-group-name="<?= htmlspecialchars($groupInfo['group_name']) ?>"
                                                               data-project-title="<?= htmlspecialchars($groupInfo['project_title']) ?>"
                                                               data-project-description="<?= htmlspecialchars($groupInfo['project_description']) ?>"
                                                               data-submissions='<?= htmlspecialchars($submissionsJson) ?>'
                                                               data-evaluations='<?= htmlspecialchars($evaluationsJson) ?>'></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($detail['email']) ?></td>
                                                    <td><?= htmlspecialchars($detail['username']) ?></td>
                                                    <td><?= htmlspecialchars($detail['no_ic'] ?? 'No IC') ?></td>
                                                    <td><?= htmlspecialchars($detail['no_tel'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($detail['intake_year']) ?></td>
                                                    <td><?= htmlspecialchars($detail['intake_month']) ?></td>
                                                    <td><?= htmlspecialchars($detail['group_name'] ?? 'Not Assigned') ?></td>
                                                    <td><?= htmlspecialchars($detail['supervisor_name'] ?? 'Not Assigned') ?></td>
                                                    <td><?= htmlspecialchars($detail['assessor_name'] ?? 'Not Assigned') ?></td>
                                                    <td><?= number_format($detail['total_marks'], 2) ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="11" class="text-center">No students found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End of Page Content -->
            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto"><span>Copyright &copy; FYPCollabor8 2025</span></div>
                </div>
            </footer>
            <!-- End of Footer -->
        </div>
        <!-- End of Content Wrapper -->
    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button -->
    <a class="scroll-to-top rounded" href="#page-top"><i class="fas fa-angle-up"></i></a>

    <!-- Logout Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
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

    <!-- Group Details Modal -->
    <div class="modal fade" id="groupModal" tabindex="-1" role="dialog" aria-labelledby="groupModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupModalLabel">Group Project Details</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <h6>Group Information</h6>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Group Name</th>
                                <th>Project Title</th>
                                <th>Project Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="modal-group-name"></td>
                                <td id="modal-project-title"></td>
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

    <!-- Custom script for modal and filters -->
    <script>
    $(document).ready(function() {
        // Modal population
        $('.info-icon').on('click', function() {
            var studentId = $(this).data('student-id');
            var groupName = $(this).data('group-name');
            var projectTitle = $(this).data('project-title');
            var projectDescription = $(this).data('project-description');
            var submissions;
            var evaluations;

            try {
                submissions = JSON.parse($(this).data('submissions'));
                evaluations = JSON.parse($(this).data('evaluations'));
            } catch (e) {
                console.error('Error parsing JSON:', e);
                submissions = [];
                evaluations = [];
            }

            // Populate group information
            $('#modal-group-name').text(groupName || 'No Group');
            $('#modal-project-title').text(projectTitle || 'No Project');
            $('#modal-project-description').text(projectDescription || 'No Description');

            // Populate submissions table
            var submissionsTable = $('#modal-submissions');
            submissionsTable.empty();
            if (submissions && Array.isArray(submissions) && submissions.length > 0) {
                submissions.forEach(function(submission) {
                    if (submission.submission_type === 'group' || 
                        (submission.submission_type === 'individual' && submission.student_id == studentId)) {
                        var submittedAt = submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : 'Not Submitted';
                        var submitter = submission.submission_type === 'group' ? 
                            (submission.group_members && submission.group_members.length > 0 ? 
                                submission.group_members.join(', ') : 'Group Members N/A') : 
                            (submission.submitter_name || 'No Submitter');
                        var fileLink = submission.file_path ? 
                            `<a href="${submission.file_path}" target="_blank">${submission.file_path.split('/').pop()}</a>` : 
                            'No File';
                        var row = `
                            <tr>
                                <td>${submission.deliverable_name || 'No Deliverable'}</td>
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
                submissionsTable.append('<tr><td colspan="5">No submissions found.</td></tr>');
            }

            // Populate evaluations table
            var evaluationsTable = $('#modal-evaluations');
            evaluationsTable.empty();
            if (evaluations && Array.isArray(evaluations) && evaluations.length > 0) {
                var hasEvaluations = false;
                evaluations.forEach(function(evaluation) {
                    if (evaluation.student_id == studentId) {
                        hasEvaluations = true;
                        var evalDate = evaluation.date ? new Date(evaluation.date).toLocaleDateString() : 'No Date';
                        var rubricDetails = evaluation.rubric_details ? 
                            evaluation.rubric_details.split('; ').join('<br>') : 'No rubric details';
                        var grade = evaluation.evaluation_grade !== null && evaluation.evaluation_grade !== undefined ? 
                            parseFloat(evaluation.evaluation_grade).toFixed(2) : 'No Grade';
                        var row = `
                            <tr>
                                <td>${evaluation.deliverable_name || 'No Deliverable'}</td>
                                <td>${evaluation.marker_name || 'No Marker'}</td>
                                <td>${grade}</td>
                                <td>${evaluation.feedback || 'No feedback provided'}</td>
                                <td>${rubricDetails}</td>
                                <td>${evalDate}</td>
                            </tr>
                        `;
                        evaluationsTable.append(row);
                    }
                });
                if (!hasEvaluations) {
                    evaluationsTable.append('<tr><td colspan="6">No evaluations found for this student.</td></tr>');
                }
            } else {
                evaluationsTable.append('<tr><td colspan="6">No evaluations found.</td></tr>');
            }
        });

        // Dynamic group filter update
        $('#semester').on('change', function() {
            var semester = $(this).val();
            var groupSelect = $('#group_name');
            var spinner = $('.loading-spinner');

            // Show loading spinner
            spinner.show();

            // Clear existing group options
            groupSelect.html('<option value="">-- All Groups --</option>');

            // Fetch groups for the selected semester via AJAX
            $.ajax({
                url: 'adminviewstudentdetails.php',
                method: 'GET',
                data: { action: 'fetch_groups', semester: semester },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.groups) {
                        response.groups.forEach(function(group) {
                            groupSelect.append(
                                `<option value="${group.name}">${group.name}</option>`
                            );
                        });
                    } else {
                        console.error('Error fetching groups:', response.message);
                    }
                    spinner.hide();
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    spinner.hide();
                }
            });
        });

        // Handle Enter key on username input
        $('#username').on('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $('#filterForm').submit();
            }
        });
    });
    </script>
</body>
</html>