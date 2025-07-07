<?php
session_start();

include 'connection.php';

// Ensure the lecturer is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$lecturerID = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'fetch_students') {
        $groupName = isset($_POST['group_name']) && $_POST['group_name'] !== '' ? $_POST['group_name'] : null;
        $semesterName = isset($_POST['semester']) && $_POST['semester'] !== '' ? $_POST['semester'] : null;

        // Query to filter students by semester
        $studentsQuery = "
            SELECT DISTINCT s.id, s.full_name AS name 
            FROM students s 
            JOIN group_members gm ON s.id = gm.student_id
            JOIN groups g ON gm.group_id = g.id
            JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
            WHERE g.lecturer_id = ? AND g.status = 'Approved'
        ";
        $params = [$lecturerID];
        $paramTypes = "i";
        if ($semesterName) {
            $studentsQuery .= " AND sem.semester_name = ?";
            $params[] = $semesterName;
            $paramTypes .= "s";
        }
        if ($groupName) {
            $studentsQuery .= " AND g.name = ?";
            $params[] = $groupName;
            $paramTypes .= "s";
        }
        $stmt = $conn->prepare($studentsQuery);
        if (!$stmt) {
            echo json_encode(['error' => 'Query preparation failed']);
            exit;
        }
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode($students);
        exit;
    }

    if ($_POST['action'] === 'fetch_diary') {
        $studentId = isset($_POST['student_id']) && is_numeric($_POST['student_id']) ? intval($_POST['student_id']) : null;
        $week = isset($_POST['week']) && is_numeric($_POST['week']) ? intval($_POST['week']) : null;
        $semesterName = isset($_POST['semester']) && $_POST['semester'] !== '' ? $_POST['semester'] : null;

        // Fetch semester start date
        $semesterQuery = "SELECT start_date FROM semesters WHERE is_current = 1 LIMIT 1";
        if ($semesterName) {
            $semesterQuery = "SELECT start_date FROM semesters WHERE semester_name = ? LIMIT 1";
            $stmt = $conn->prepare($semesterQuery);
            $stmt->bind_param("s", $semesterName);
            $stmt->execute();
            $result = $stmt->get_result();
            $semester = $result->fetch_assoc();
            $semesterStartDate = $semester ? $semester['start_date'] : null;
            $stmt->close();
        } else {
            $result = $conn->query($semesterQuery);
            $semester = $result->fetch_assoc();
            $semesterStartDate = $semester ? $semester['start_date'] : null;
        }

        if (!$studentId || !$week || !$semesterStartDate) {
            echo json_encode(['error' => 'Invalid input or no semester defined']);
            exit;
        }

        // Calculate date range for the selected week
        $startDate = new DateTime($semesterStartDate);
        $weekStart = clone $startDate;
        $weekStart->modify("+ " . (($week - 1) * 7) . " days");
        $weekEnd = clone $weekStart;
        $weekEnd->modify("+6 days");
        $weekStartStr = $weekStart->format('Y-m-d');
        $weekEndStr = $weekEnd->format('Y-m-d');

        $diaryQuery = "
            SELECT d.id, d.diary_content, d.title, d.status, d.entry_date,
                   s.full_name AS student_name
            FROM diary d
            JOIN group_members gm ON d.student_id = gm.student_id
            JOIN groups g ON gm.group_id = g.id
            JOIN students s ON d.student_id = s.id
            JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
            WHERE d.student_id = ? AND d.entry_date BETWEEN ? AND ? 
                  AND g.lecturer_id = ? AND g.status = 'Approved'
        ";
        if ($semesterName) {
            $diaryQuery .= " AND sem.semester_name = ?";
            $stmt = $conn->prepare($diaryQuery);
            $stmt->bind_param("issis", $studentId, $weekStartStr, $weekEndStr, $lecturerID, $semesterName);
        } else {
            $stmt = $conn->prepare($diaryQuery);
            $stmt->bind_param("issi", $studentId, $weekStartStr, $weekEndStr, $lecturerID);
        }
        if (!$stmt) {
            echo json_encode(['error' => 'Query preparation failed']);
            exit;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $diary = $result->fetch_assoc();
        $stmt->close();

        if ($diary) {
            echo json_encode([
                'title' => htmlspecialchars($diary['title']),
                'content' => nl2br(htmlspecialchars($diary['diary_content'])),
                'status' => htmlspecialchars($diary['status']),
                'entry_date' => htmlspecialchars($diary['entry_date']),
                'student_name' => htmlspecialchars($diary['student_name'])
            ]);
        } else {
            echo json_encode(['error' => 'No diary entry found']);
        }
        exit;
    }

    exit;
}

// Fetch lecturer info
$sql = "SELECT full_name, profile_picture, role_id FROM lecturers WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$result = $stmt->get_result();
$lecturer = $result->fetch_assoc();
$stmt->close();

if (!$lecturer) {
    die("Error: No lecturer found.");
}

$personalInfo = [
    'full_name' => $lecturer['full_name'] ?? 'N/A',
    'profile_picture' => $lecturer['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Role-based access
$roleID = $lecturer['role_id'] ?? 1;
$isSupervisor = in_array($roleID, [3, 4]);
$isAssessor = in_array($roleID, [2, 3]);

// Fetch semesters for filter
$semestersQuery = "SELECT semester_name, start_date FROM semesters ORDER BY start_date DESC";
$semestersResult = $conn->query($semestersQuery);
$semesters = $semestersResult ? $semestersResult->fetch_all(MYSQLI_ASSOC) : [];

// Default to current semester if none selected
$selectedSemester = isset($_GET['semester']) && $_GET['semester'] !== '' ? $_GET['semester'] : null;
if (!$selectedSemester) {
    $currentSemesterQuery = "SELECT semester_name FROM semesters WHERE is_current = 1 LIMIT 1";
    $result = $conn->query($currentSemesterQuery);
    $currentSemester = $result->fetch_assoc();
    $selectedSemester = $currentSemester ? $currentSemester['semester_name'] : null;
}

// Fetch approved groups for the selected semester
$groupsQuery = "
    SELECT DISTINCT g.id, g.name 
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    JOIN students s ON gm.student_id = s.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE g.lecturer_id = ? AND g.status = 'Approved'";
$params = [$lecturerID];
$paramTypes = "i";
if ($selectedSemester) {
    $groupsQuery .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
$stmt = $conn->prepare($groupsQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$groupsResult = $stmt->get_result();
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle filters
$searchStudent = isset($_GET['student_name']) ? trim($_GET['student_name']) : '';
$selectedGroup = isset($_GET['group_name']) ? $_GET['group_name'] : '';
$selectedWeek = isset($_GET['week']) && is_numeric($_GET['week']) ? intval($_GET['week']) : 0;

// Fetch semester start date
$semesterStartDate = null;
if ($selectedSemester) {
    $semesterQuery = "SELECT start_date FROM semesters WHERE semester_name = ? LIMIT 1";
    $stmt = $conn->prepare($semesterQuery);
    $stmt->bind_param("s", $selectedSemester);
    $stmt->execute();
    $result = $stmt->get_result();
    $semester = $result->fetch_assoc();
    $semesterStartDate = $semester ? $semester['start_date'] : null;
    $stmt->close();
}

// Summary statistics
$totalEntries = 0;
$pendingReviews = 0;
$approvedEntries = 0;

$query = "
    SELECT d.status
    FROM diary d
    JOIN group_members gm ON d.student_id = gm.student_id
    JOIN groups g ON gm.group_id = g.id
    JOIN students s ON d.student_id = s.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE g.lecturer_id = ? AND g.status = 'Approved'
";
$params = [$lecturerID];
$paramTypes = "i";
if ($selectedSemester) {
    $query .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
if ($searchStudent) {
    $query .= " AND s.full_name LIKE ?";
    $params[] = "%$searchStudent%";
    $paramTypes .= "s";
}
if ($selectedGroup) {
    $query .= " AND g.name = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "s";
}
if ($selectedWeek && $semesterStartDate) {
    $weekStart = (new DateTime($semesterStartDate))->modify("+ " . (($selectedWeek - 1) * 7) . " days")->format('Y-m-d');
    $weekEnd = (new DateTime($weekStart))->modify("+6 days")->format('Y-m-d');
    $query .= " AND d.entry_date BETWEEN ? AND ?";
    $params[] = $weekStart;
    $params[] = $weekEnd;
    $paramTypes .= "ss";
}
$stmt = $conn->prepare($query);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $totalEntries++;
    if ($row['status'] === 'Pending') {
        $pendingReviews++;
    } elseif ($row['status'] === 'Approved') {
        $approvedEntries++;
    }
}
$stmt->close();

// Student diary details
$studentDiaryQuery = "
    SELECT 
        g.name AS group_name,
        s.id AS student_id,
        s.full_name AS student_name,
        COUNT(DISTINCT d.id) AS total_submissions,
        SUM(CASE WHEN d.status = 'Pending' THEN 1 ELSE 0 END) AS pending_reviews,
        SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) AS approved_entries
    FROM students s
    JOIN group_members gm ON s.id = gm.student_id
    JOIN groups g ON gm.group_id = g.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    LEFT JOIN diary d ON s.id = d.student_id
    WHERE g.lecturer_id = ? AND g.status = 'Approved'
";
$params = [$lecturerID];
$paramTypes = "i";
if ($selectedSemester) {
    $studentDiaryQuery .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
if ($searchStudent) {
    $studentDiaryQuery .= " AND s.full_name LIKE ?";
    $params[] = "%$searchStudent%";
    $paramTypes .= "s";
}
if ($selectedGroup) {
    $studentDiaryQuery .= " AND g.name = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "s";
}
if ($selectedWeek && $semesterStartDate) {
    $weekStart = (new DateTime($semesterStartDate))->modify("+ " . (($selectedWeek - 1) * 7) . " days")->format('Y-m-d');
    $weekEnd = (new DateTime($weekStart))->modify("+6 days")->format('Y-m-d');
    $studentDiaryQuery .= " AND (d.entry_date BETWEEN ? AND ? OR d.entry_date IS NULL)";
    $params[] = $weekStart;
    $params[] = $weekEnd;
    $paramTypes .= "ss";
}
$studentDiaryQuery .= " GROUP BY s.id, s.full_name, g.name ORDER BY s.full_name";

$stmt = $conn->prepare($studentDiaryQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$studentDiaryDetails = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Submission status by week
$submissionStatusQuery = "
    SELECT 
        s.id AS student_id,
        s.full_name AS student_name,
        g.name AS group_name,
        d.entry_date
    FROM students s
    JOIN group_members gm ON s.id = gm.student_id
    JOIN groups g ON gm.group_id = g.id
    JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    LEFT JOIN diary d ON s.id = d.student_id
    WHERE g.lecturer_id = ? AND g.status = 'Approved'
";
$params = [$lecturerID];
$paramTypes = "i";
if ($selectedSemester) {
    $submissionStatusQuery .= " AND sem.semester_name = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}
if ($searchStudent) {
    $submissionStatusQuery .= " AND s.full_name LIKE ?";
    $params[] = "%$searchStudent%";
    $paramTypes .= "s";
}
if ($selectedGroup) {
    $submissionStatusQuery .= " AND g.name = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "s";
}
if ($selectedWeek && $semesterStartDate) {
    $weekStart = (new DateTime($semesterStartDate))->modify("+ " . (($selectedWeek - 1) * 7) . " days")->format('Y-m-d');
    $weekEnd = (new DateTime($weekStart))->modify("+6 days")->format('Y-m-d');
    $submissionStatusQuery .= " AND (d.entry_date BETWEEN ? AND ? OR d.entry_date IS NULL)";
    $params[] = $weekStart;
    $params[] = $weekEnd;
    $paramTypes .= "ss";
}
$submissionStatusQuery .= " ORDER BY s.id, d.entry_date";

$stmt = $conn->prepare($submissionStatusQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$maxWeeks = 12;
$submissionStatus = [];
while ($row = $result->fetch_assoc()) {
    $studentId = $row['student_id'];
    if (!isset($submissionStatus[$studentId])) {
        $submissionStatus[$studentId] = [
            'student_name' => $row['student_name'],
            'group_name' => $row['group_name'],
            'weeks' => array_fill(1, $maxWeeks, false)
        ];
    }
    if ($row['entry_date'] && $semesterStartDate) {
        $entryDate = new DateTime($row['entry_date']);
        $startDate = new DateTime($semesterStartDate);
        if ($entryDate >= $startDate) {
            $daysDifference = $startDate->diff($entryDate)->days;
            $weekNumber = floor($daysDifference / 7) + 1;
            if ($weekNumber >= 1 && $weekNumber <= $maxWeeks) {
                $submissionStatus[$studentId]['weeks'][$weekNumber] = true;
            }
        }
    }
}
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

    <title>Lecturer - View Student Diary</title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

    <style>
        .submitted { color: green; }
        .not-submitted { color: red; }
        .info-icon { color: blue; cursor: pointer; margin-left: 5px; }
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Mentorship Tools</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Guidance Resources:</h6>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectmanagemeetings.php">Manage Meetings</a>
                        <a class="collapse-item active <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewdiary.php">View Student Diary</a>
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
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">View Student Diary</h1>
                    </div>

                    <!-- Summary Cards -->
                    <div class="row">
                        <div class="col-xl-4 col-md-4 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Entries</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($totalEntries); ?> Entries</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-book fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-4 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Reviews</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($pendingReviews); ?> Pending</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-hourglass-half fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-4 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved Entries</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($approvedEntries); ?> Approved</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Diary Entries</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <!-- Semester Filter -->
                                    <div class="col-md-3 mb-3">
                                        <label for="semester">Semester</label>
                                        <select class="form-control" id="semester" name="semester" required>
                                            <?php if (!$selectedSemester): ?>
                                                <option value="" disabled selected>-- Select Semester --</option>
                                            <?php endif; ?>
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?php echo htmlspecialchars($semester['semester_name']); ?>" 
                                                        <?php echo $selectedSemester === $semester['semester_name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Student Name Search -->
                                    <div class="col-md-3 mb-3">
                                        <label for="student_name">Search Student</label>
                                        <input type="text" class="form-control" id="student_name" name="student_name" 
                                               value="<?php echo htmlspecialchars($searchStudent); ?>" placeholder="Enter student name">
                                    </div>
                                    <!-- Group Name Filter -->
                                    <div class="col-md-3 mb-3">
                                        <label for="group_name">Group</label>
                                        <select class="form-control" id="group_name" name="group_name">
                                            <option value="">-- All Groups --</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?php echo htmlspecialchars($group['name']); ?>" 
                                                        <?php echo $selectedGroup === $group['name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($group['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Week Filter -->
                                    <div class="col-md-3 mb-3">
                                        <label for="week">Week</label>
                                        <select class="form-control" id="week" name="week">
                                            <option value="0">-- All Weeks --</option>
                                            <?php for ($i = 1; $i <= $maxWeeks; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php echo $selectedWeek == $i ? 'selected' : ''; ?>>
                                                    Week <?php echo $i; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="lectviewdiary.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>

                    <!-- Warning if no semester -->
                    <?php if (!$semesterStartDate): ?>
                        <div class="alert alert-warning">No active semester defined. Please select a semester.</div>
                    <?php endif; ?>

                    <!-- Student Diary Details -->
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Student Diary Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Group</th>
                                                    <th>Student Name</th>
                                                    <th>Total Entries</th>
                                                    <th>Pending</th>
                                                    <th>Approved</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($studentDiaryDetails as $detail): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($detail['group_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($detail['student_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($detail['total_submissions'] ?? '0'); ?></td>
                                                        <td><?php echo htmlspecialchars($detail['pending_reviews'] ?? '0'); ?></td>
                                                        <td><?php echo htmlspecialchars($detail['approved_entries'] ?? '0'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submission Status by Week -->
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Submission Status by Week</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Group</th>
                                                    <th>Student Name</th>
                                                    <?php 
                                                    $weeksToShow = $selectedWeek ? [$selectedWeek] : range(1, $maxWeeks);
                                                    foreach ($weeksToShow as $i): ?>
                                                        <th>Week <?php echo $i; ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($submissionStatus as $studentId => $data): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($data['group_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($data['student_name'] ?? 'N/A'); ?></td>
                                                        <?php foreach ($weeksToShow as $i): ?>
                                                            <td class="<?php echo $data['weeks'][$i] ? 'submitted' : 'not-submitted'; ?>">
                                                                <?php echo $data['weeks'][$i] ? '✔ <i class="fas fa-info-circle info-icon" data-student-id="' . htmlspecialchars($studentId) . '" data-week="' . $i . '" data-semester="' . htmlspecialchars($selectedSemester) . '" data-toggle="modal" data-target="#diaryModal"></i>' : '✘'; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Diary Modal -->
            <div class="modal fade" id="diaryModal" tabindex="-1" role="dialog" aria-labelledby="diaryModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="diaryModalLabel">Diary Entry Details</h5>
                            <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body" id="diaryModalBody">
                            <p>Loading...</p>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
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

    <!-- Scripts -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="js/demo/datatables-demo.js"></script>

    <script>
        $(document).ready(function() {
            // Load students based on group and semester
            function loadStudents(groupName, semester) {
                $.ajax({
                    url: 'lectviewdiary.php',
                    type: 'POST',
                    data: { 
                        action: 'fetch_students', 
                        group_name: groupName, 
                        semester: semester 
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.error) {
                            console.error(data.error);
                        }
                    },
                    error: function() {
                        console.error('Failed to fetch students');
                    }
                });
            }

            // Update on group or semester change
            $('#group_name, #semester').on('change', function() {
                loadStudents($('#group_name').val(), $('#semester').val());
            });

            // Handle info icon click
            $(document).on('click', '.info-icon', function() {
                var studentId = $(this).data('student-id');
                var week = $(this).data('week');
                var semester = $(this).data('semester') || '';
                $('#diaryModalBody').html('<p>Loading...</p>');

                $.ajax({
                    url: 'lectviewdiary.php',
                    type: 'POST',
                    data: { 
                        action: 'fetch_diary', 
                        student_id: studentId, 
                        week: week, 
                        semester: semester 
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.error) {
                            $('#diaryModalBody').html(
                                '<p><strong>Error:</strong> ' + response.error + '</p>'
                            );
                        } else {
                            $('#diaryModalBody').html(`
                                <table class="table table-bordered">
                                    <tbody>
                                        <tr>
                                            <th scope="row" style="width: 30%; background-color: #f8f9fa;">Student</th>
                                            <td>${response.student_name ? response.student_name : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" style="background-color: #f8f9fa;">Week</th>
                                            <td>${week}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" style="background-color: #f8f9fa;">Title</th>
                                            <td>${response.title ? response.title : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" style="background-color: #f8f9fa;">Entry Date</th>
                                            <td>${response.entry_date ? response.entry_date : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" style="background-color: #f8f9fa;">Content</th>
                                            <td>${response.content ? response.content : 'No content'}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" style="background-color: #f8f9fa;">Status</th>
                                            <td>${response.status ? response.status : 'N/A'}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            `);
                        }
                    },
                    error: function() {
                        $('#diaryModalBody').html('<p>Error loading diary entry.</p>');
                    }
                });
            });
        });
    </script>

</body>
</html>