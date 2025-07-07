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
$sql = "SELECT full_name, profile_picture, role_id FROM lecturers WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
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

// Restrict access to Assessors only
if (!$isAssessor) {
    header("Location: lecturerdashboard.php?error=Unauthorized");
    exit();
}

// Initialize message for warnings
$message = "";

// Fetch semesters for the filter
$semestersQuery = "SELECT semester_name, start_date FROM semesters ORDER BY start_date DESC";
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . $conn->error);
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);

// Fetch current semester
$currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
$currentSemesterResult = $conn->query($currentSemesterQuery) or die("Error in current semester query: " . $conn->error);
$currentSemester = $currentSemesterResult->fetch_assoc();
$currentSemesterName = $currentSemester ? $currentSemester['semester_name'] : ($semesters[0]['semester_name'] ?? 'N/A');

// Initialize filter parameters
$selectedSemester = isset($_GET['semester']) && trim($_GET['semester']) !== '' ? trim($_GET['semester']) : $currentSemesterName;
$searchUsername = isset($_GET['username']) ? trim($_GET['username']) : '';
$selectedGroup = isset($_GET['group_name']) ? trim($_GET['group_name']) : '';

// Fetch groups for the filter (assessor-based and semester-based)
$groupsConditions = [];
$groupsParams = [];
$groupsParamTypes = "";

$groupsConditions[] = "g.assessor_id = ?";
$groupsParams[] = $lecturerID;
$groupsParamTypes .= "i";
$groupsConditions[] = "g.status = 'Approved'";

if ($selectedSemester) {
    $groupsConditions[] = "s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date) AND sem.semester_name = ?";
    $groupsParams[] = $selectedSemester;
    $groupsParamTypes .= "s";
}

$groupsQuery = "
    SELECT DISTINCT g.name
    FROM groups g
    LEFT JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN students s ON gm.student_id = s.id
    LEFT JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)";
if (!empty($groupsConditions)) {
    $groupsQuery .= " WHERE " . implode(" AND ", $groupsConditions);
}
$groupsQuery .= " ORDER BY g.name ASC";
error_log("Groups Query: $groupsQuery");
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

// Fetch total students (assessor-based filtering)
$totalStudentsConditions = [];
$totalStudentsParams = [];
$totalStudentsParamTypes = "";

$totalStudentsConditions[] = "g.assessor_id = ?";
$totalStudentsParams[] = $lecturerID;
$totalStudentsParamTypes .= "i";
$totalStudentsConditions[] = "g.status = 'Approved'";

if ($selectedSemester) {
    $totalStudentsConditions[] = "s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date) AND sem.semester_name = ?";
    $totalStudentsParams[] = $selectedSemester;
    $totalStudentsParamTypes .= "s";
}
if ($searchUsername) {
    $totalStudentsConditions[] = "s.username LIKE ?";
    $totalStudentsParams[] = "%$searchUsername%";
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
    LEFT JOIN group_members gm ON s.id = gm.student_id
    LEFT JOIN groups g ON gm.group_id = g.id
    LEFT JOIN projects p ON g.id = p.group_id
    LEFT JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)";
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

// Fetch total projects (assessor-based filtering)
$totalProjectsConditions = [];
$totalProjectsParams = [];
$totalProjectsParamTypes = "";

$totalProjectsConditions[] = "g.assessor_id = ?";
$totalProjectsParams[] = $lecturerID;
$totalProjectsParamTypes .= "i";
$totalProjectsConditions[] = "g.status = 'Approved'";

if ($selectedSemester) {
    $totalProjectsConditions[] = "s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date) AND sem.semester_name = ?";
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
    LEFT JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN students s ON gm.student_id = s.id
    LEFT JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)";
if (!empty($totalProjectsConditions)) {
    $totalProjectsQuery .= " WHERE " . implode(" AND ", $totalProjectsConditions);
}
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

// Fetch student details with group ID and total evaluation grades (including group evaluations)
$studentDetailsConditions = [];
$studentDetailsParams = [];
$studentDetailsParamTypes = "";

$studentDetailsConditions[] = "g.assessor_id = ?";
$studentDetailsParams[] = $lecturerID;
$studentDetailsParamTypes .= "i";
$studentDetailsConditions[] = "g.status = 'Approved'";

if ($selectedSemester) {
    $studentDetailsConditions[] = "s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date) AND sem.semester_name = ?";
    $studentDetailsParams[] = $selectedSemester;
    $studentDetailsParamTypes .= "s";
}
if ($searchUsername) {
    $studentDetailsConditions[] = "s.username LIKE ?";
    $studentDetailsParams[] = "%$searchUsername%";
    $studentDetailsParamTypes .= "s";
}
if ($selectedGroup) {
    $studentDetailsConditions[] = "g.name = ?";
    $studentDetailsParams[] = $selectedGroup;
    $studentDetailsParamTypes .= "s";
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
        (
            COALESCE((SELECT SUM(e2.evaluation_grade) FROM evaluation e2 WHERE e2.student_id = s.id), 0)
            +
            COALESCE((SELECT SUM(ge2.evaluation_grade) FROM group_evaluations ge2 WHERE ge2.group_id = g.id), 0)
        ) AS total_marks
    FROM students s
    LEFT JOIN group_members gm ON s.id = gm.student_id
    LEFT JOIN groups g ON gm.group_id = g.id
    LEFT JOIN lecturers ls ON g.lecturer_id = ls.id
    LEFT JOIN lecturers la ON g.assessor_id = la.id
    LEFT JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)";
if (!empty($studentDetailsConditions)) {
    $studentDetailsQuery .= " WHERE " . implode(" AND ", $studentDetailsConditions);
}
$studentDetailsQuery .= " GROUP BY s.id, s.full_name, s.email, s.username, s.no_ic, s.no_tel, s.intake_year, s.intake_month, g.id, g.name, ls.full_name, la.full_name
    ORDER BY s.full_name";
$stmt = $conn->prepare($studentDetailsQuery);
if ($stmt === false) {
    die("Prepare failed for student details query: " . $conn->error);
}
if (!empty($studentDetailsParams)) {
    $stmt->bind_param($studentDetailsParamTypes, ...$studentDetailsParams);
}
$stmt->execute();
$studentDetailsResult = $stmt->get_result();
$studentDetails = $studentDetailsResult ? $studentDetailsResult->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Fetch project, submission, and evaluation details for each group
$groupDetails = [];
foreach ($studentDetails as $detail) {
    if (!isset($groupDetails[$detail['group_id']]) && $detail['group_id']) {
        // Fetch project details
        $projectConditions = [];
        $projectParams = [$detail['group_id']];
        $projectParamTypes = "i";
        $projectConditions[] = "g.status = 'Approved'";
        $projectQuery = "
            SELECT 
                g.name AS group_name,
                p.title AS project_title,
                p.description AS project_description
            FROM projects p
            JOIN groups g ON p.group_id = g.id
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
            'group_name' => 'N/A',
            'project_title' => 'N/A',
            'project_description' => 'N/A'
        ];
        $stmt->close();

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
            } else {
                $submission['group_members'] = [];
                $submission['submitter_name'] = $submission['submitter_name'] ?? 'N/A';
            }
        }
        unset($submission);

        // Fetch evaluation details (individual and group)
        $evaluationsQuery = "
            -- Individual Evaluations
            SELECT 
                e.id AS evaluation_id,
                e.student_id,
                NULL AS group_id,
                e.evaluation_grade,
                e.feedback,
                e.date,
                'Individual' AS type,
                d.name AS deliverable_name,
                COALESCE(ls.full_name, la.full_name, c.full_name, 'N/A') AS marker_name
            FROM evaluation e
            JOIN deliverables d ON e.deliverable_id = d.id
            LEFT JOIN lecturers ls ON e.supervisor_id = ls.id
            LEFT JOIN lecturers la ON e.assessor_id = la.id
            LEFT JOIN coordinators c ON e.coordinator_id = c.id
            WHERE e.student_id IN (
                SELECT student_id FROM group_members WHERE group_id = ?
            )
            GROUP BY e.id, e.student_id, e.evaluation_grade, e.feedback, e.date, d.name, ls.full_name, la.full_name, c.full_name
            
            UNION ALL
            
            -- Group Evaluations
            SELECT 
                ge.id AS evaluation_id,
                NULL AS student_id,
                ge.group_id,
                ge.evaluation_grade,
                ge.feedback,
                ge.date,
                'Group' AS type,
                d.name AS deliverable_name,
                COALESCE(c.full_name, ls.full_name, la.full_name, 'N/A') AS marker_name
            FROM group_evaluations ge
            JOIN deliverables d ON ge.deliverable_id = d.id
            LEFT JOIN coordinators c ON ge.coordinator_id = c.id
            LEFT JOIN lecturers ls ON ge.supervisor_id = ls.id
            LEFT JOIN lecturers la ON ge.assessor_id = la.id
            WHERE ge.group_id = ?
            GROUP BY ge.id, ge.group_id, ge.evaluation_grade, ge.feedback, ge.date, d.name, c.full_name, ls.full_name, la.full_name
            ORDER BY date DESC";
        error_log("Evaluations Query for group ID {$detail['group_id']}: $evaluationsQuery");
        $stmt = $conn->prepare($evaluationsQuery);
        if ($stmt === false) {
            error_log("Prepare failed for evaluations query: " . $conn->error);
            $message .= "<div class='alert alert-warning'>Warning: Failed to fetch evaluations for group ID {$detail['group_id']}.</div>";
            continue;
        }
        $stmt->bind_param("ii", $detail['group_id'], $detail['group_id']);
        $stmt->execute();
        $evaluationsResult = $stmt->get_result();
        $evaluations = $evaluationsResult->fetch_all(MYSQLI_ASSOC);
        error_log("Evaluations fetched for group ID {$detail['group_id']}: " . json_encode($evaluations));
        $stmt->close();

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
$invalidGroupIds = array_filter(array_keys($groupDetails), fn($id) => $id <= 0);
if (!empty($invalidGroupIds)) {
    $message .= "<div class='alert alert-warning'>Warning: Invalid group IDs detected in group details: " . implode(', ', $invalidGroupIds) . ".</div>";
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

    <title>Assessor - View Student Details</title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">

    <!-- Custom styles for this page -->
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
        .modal-header {
            background-color: #f8f9fa;
        }
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
            <li class="nav-item">
                <a class="nav-link" href="lecturerdashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Supervisor Portal</div>
            <li class="nav-item">
                <a class="nav-link collapsed <?= !$isSupervisor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Academic Oversight</span>
                </a>
                <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Project Details:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lecttitleproposal.php">Title Proposal</a>
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
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectmanagemeetings.php">Meetings</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewdiary.php">Student Diaries</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectevaluatestudent.php">Evaluate Students</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewstudentdetails.php">View Students</a>
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Assessor Portal</div>
            <li class="nav-item active">
                <a class="nav-link <?= !$isAssessor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Oversight Panel</span>
                </a>
                <div id="collapsePages" class="collapse show" aria-labelledby="headingPages" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Performance Review:</h6>
                        <a class="collapse-item active <?= !$isAssessor ? 'disabled' : '' ?>" href="assviewstudentdetails.php">View Student Details</a>
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
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu">
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
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <?= $message ?>
                    <!-- Page Heading -->
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

                    <!-- Filter Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Student Details</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <!-- Semester Filter -->
                                    <div class="col-md-4 mb-3">
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
                                    <!-- Student Username Search -->
                                    <div class="col-md-4 mb-3">
                                        <label for="username">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?= htmlspecialchars($searchUsername) ?>" placeholder="Enter student username">
                                    </div>
                                    <!-- Group Name Filter -->
                                    <div class="col-md-4 mb-3">
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
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="assviewstudentdetails.php" class="btn btn-secondary">Clear Filters</a>
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
                                            <th>Group</th>
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
                                                            'group_name' => 'N/A',
                                                            'project_title' => 'N/A',
                                                            'project_description' => 'N/A',
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
                                                    <td><?php echo htmlspecialchars($detail['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['username']); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['no_ic'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['no_tel'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['intake_year']); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['intake_month']); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['group_name'] ?? 'Not Assigned'); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['supervisor_name'] ?? 'Not Assigned'); ?></td>
                                                    <td><?php echo htmlspecialchars($detail['assessor_name'] ?? 'Not Assigned'); ?></td>
                                                    <td><?= number_format($detail['total_marks'], 2) ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="11" class="text-center">No students found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; FYPCollabor8 2025</span>
                    </div>
                </div>
            </footer>
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
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Select "Logout" below if you are ready to end your current session.
                </div>
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
                                <th>Name</th>
                                <th>Submitter</th>
                                <th>File Name</th>
                                <th>Submitted At</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody id="modal-submissions"></tbody>
                    </table>
                    <h6>Markings</h6>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Marker</th>
                                <th>Type</th>
                                <th>Grade</th>
                                <th>Feedback</th>
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
    <script src="vendor/jquery-easing/js/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages -->
    <script src="js/sb-admin-js/sb-admin-2.min.js"></script>

    <!-- Page level plugins -->
    <script src="vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/js/dataTables.bootstrap4.min.js"></script>

    <!-- Page level custom scripts -->
    <script src="js/pages/demo/datatables-demo.js"></script>

    <!-- Custom script for modal -->
    <script>
$(document).ready(function() {
    $('.info-icon').on('click', function() {
        var studentId = $(this).data('student-id');
        var groupId = $(this).data('group-id');
        var groupName = $(this).data('group-name');
        var projectTitle = $(this).data('project-title');
        var projectDescription = $(this).data('project-description');
        var submissions = $(this).data('submissions');
        var evaluations = $(this).data('evaluations');

        console.log('Student ID:', studentId);
        console.log('Group ID:', groupId);
        console.log('Group Name:', groupName);
        console.log('Submissions:', submissions);
        console.log('Evaluations:', evaluations);

        // Populate group information
        $('#modal-group-name').text(groupName || 'N/A');
        $('#modal-project-title').text(projectTitle || 'N/A');
        $('#modal-project-description').text(projectDescription || 'N/A');

        // Populate submissions table
        var submissionsTable = $('#modal-submissions');
        submissionsTable.empty();
        if (submissions && Array.isArray(submissions) && submissions.length > 0) {
            submissions.forEach(function(submission) {
                if (submission.submission_type === 'group' || 
                    (submission.submission_type === 'individual' && submission.student_id == studentId)) {
                    var submittedAt = submission.submitted_at ? 
                        new Date(submission.submitted_at).toLocaleString() : 'Not Submitted';
                    var submitter = submission.submission_type === 'group' ? 
                        (submission.group_members && submission.group_members.length > 0 ? 
                            submission.group_members.join(', ') : 'Group Members N/A') : 
                        (submission.submitter_name || 'N/A');
                    var fileName = submission.file_path ? 
                        submission.file_path.split('/').pop() : 'No File';
                    var fileLink = submission.file_path ? 
                        `<a href="${submission.file_path}" target="_blank">${fileName}</a>` : 
                        'No File';
                    var row = `
                        <tr>
                            <td>${submission.deliverable_name || 'N/A'}</td>
                            <td>${submitter}</td>
                            <td>${fileLink}</td>
                            <td>${submittedAt}</td>
                            <td>${submission.submission_type.charAt(0).toUpperCase() + submission.submission_type.slice(1)}</td>
                        </tr>`;
                    submissionsTable.append(row);
                }
            });
            if (submissionsTable.children().length === 0) {
                submissionsTable.append('<tr><td colspan="5">No submissions found.</td></tr>');
            }
        } else {
            console.log('No submissions data or invalid format');
            submissionsTable.append('<tr><td colspan="5">No submissions found.</td></tr>');
        }

        // Populate evaluations table
        var evaluationsTable = $('#modal-evaluations');
        evaluationsTable.empty();
        console.log('Starting evaluations processing for student ID:', studentId, 'and group ID:', groupId);
        if (evaluations && Array.isArray(evaluations) && evaluations.length > 0) {
            var hasEvaluations = false;
            evaluations.forEach(function(evaluation, index) {
                console.log(`Processing evaluation[${index}]:`, evaluation);
                if ((evaluation.student_id == studentId) || (evaluation.group_id == groupId)) {
                    hasEvaluations = true;
                    var evalDate = evaluation.date ? new Date(evaluation.date).toLocaleDateString() : 'N/A';
                    var grade = evaluation.evaluation_grade !== null && evaluation.evaluation_grade !== undefined ? 
                        Number(evaluation.evaluation_grade).toFixed(2) : 'N/A';
                    var row = `
                        <tr>
                            <td>${evaluation.deliverable_name || 'N/A'}</td>
                            <td>${evaluation.marker_name || 'N/A'}</td>
                            <td>${evaluation.type || 'N/A'}</td>
                            <td>${grade}</td>
                            <td>${evaluation.feedback || 'No feedback provided'}</td>
                            <td>${evalDate}</td>
                        </tr>`;
                    evaluationsTable.append(row);
                    console.log(`Added evaluation row for student ID ${studentId} or group ID ${groupId}:`, evaluation);
                } else {
                    console.log(`Skipped evaluation[${index}]: student_id[${evaluation.student_id}] does not match studentId[${studentId}], and group_id[${evaluation.group_id}] does not match groupId[${groupId}]`);
                }
            });
            if (!hasEvaluations) {
                evaluationsTable.append('<tr><td colspan="6">No evaluations found for this student or group.</td></tr>');
                console.log('No evaluations matched student ID:', studentId, 'or group ID:', groupId);
            }
        } else {
            console.log('No evaluation data received or invalid format');
            evaluationsTable.append('<tr><td colspan="6">No evaluations found.</td></tr>');
        }
    });
});
</script>

</body>
</html>