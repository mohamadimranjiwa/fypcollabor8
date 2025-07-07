<?php
session_start();
include 'connection.php';

// Ensure the coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}
$coordinatorID = $_SESSION['user_id'];

// Verify database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Fetch coordinator's details
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();
$stmt->close();

if (!$coordinator) {
    die("Error: No coordinator found with the provided ID.");
}

$personalInfo = [
    'full_name' => $coordinator['full_name'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message for warnings
$message = "";

// Fetch group names for the filter
$groupsQuery = "SELECT DISTINCT name FROM groups WHERE name IS NOT NULL ORDER BY name ASC";
$groupsResult = $conn->query($groupsQuery) or die("Error in groups query: " . $conn->error);
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);

// Fetch deliverables for the filter
$deliverablesQuery = "SELECT id, name FROM deliverables ORDER BY name ASC";
$deliverablesResult = $conn->query($deliverablesQuery) or die("Error in deliverables query: " . $conn->error);
$deliverables = $deliverablesResult->fetch_all(MYSQLI_ASSOC);
$deliverableMap = []; // Map deliverable IDs to names
foreach ($deliverables as $deliverable) {
    $deliverableMap[$deliverable['id']] = $deliverable['name'];
}

// Fetch semesters for the filter
$semestersQuery = "SELECT DISTINCT semester FROM deliverables WHERE semester IS NOT NULL ORDER BY semester DESC";
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . $conn->error);
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);

// Initialize filter parameters
$searchStudent = isset($_GET['student_name']) ? trim($_GET['student_name']) : '';
$selectedGroup = isset($_GET['group_name']) ? trim($_GET['group_name']) : '';
$selectedDeliverable = isset($_GET['deliverable_id']) ? intval($_GET['deliverable_id']) : 0;
$selectedSemester = isset($_GET['semester']) ? trim($_GET['semester']) : '';

// Define evaluator roles for columns
$evaluatorRoles = ['Supervisor', 'Assessor', 'Average'];

// Fetch all students, their groups, and all deliverables they should be evaluated on
$marksQuery = "
    SELECT DISTINCT
        s.id AS student_id,
        s.username AS student_username,
        s.full_name AS student_name,
        s.intake_year,
        s.intake_month,
        g.id AS group_id,
        g.name AS group_name,
        d.id AS deliverable_id,
        d.name AS deliverable_name,
        d.submission_type AS submission_type,
        d.semester AS semester,
        e.evaluation_grade AS individual_grade,
        e.supervisor_id AS individual_supervisor_id,
        e.assessor_id AS individual_assessor_id,
        ge.evaluation_grade AS group_grade,
        ge.supervisor_id AS group_supervisor_id,
        ge.assessor_id AS group_assessor_id,
        ls1.full_name AS individual_supervisor_name,
        la1.full_name AS individual_assessor_name,
        ls2.full_name AS group_supervisor_name,
        la2.full_name AS group_assessor_name
    FROM students s
    LEFT JOIN group_members gm ON s.id = gm.student_id
    LEFT JOIN groups g ON gm.group_id = g.id AND g.status = 'Approved'
    LEFT JOIN deliverables d ON CONCAT(s.intake_month, ' ', s.intake_year) = d.semester
    LEFT JOIN evaluation e ON s.id = e.student_id AND e.deliverable_id = d.id AND d.submission_type = 'individual'
    LEFT JOIN group_evaluations ge ON g.id = ge.group_id AND ge.deliverable_id = d.id AND d.submission_type = 'group'
    LEFT JOIN lecturers ls1 ON e.supervisor_id = ls1.id
    LEFT JOIN lecturers la1 ON e.assessor_id = la1.id
    LEFT JOIN lecturers ls2 ON ge.supervisor_id = ls2.id
    LEFT JOIN lecturers la2 ON ge.assessor_id = la2.id
    WHERE 1=1";

$conditions = [];
$params = [];
$paramTypes = "";

if ($searchStudent) {
    $conditions[] = "s.full_name LIKE ?";
    $params[] = "%$searchStudent%";
    $paramTypes .= "s";
}
if ($selectedGroup) {
    $conditions[] = "g.name = ?";
    $params[] = $selectedGroup;
    $paramTypes .= "s";
}
if ($selectedDeliverable > 0) {
    $conditions[] = "d.id = ?";
    $params[] = $selectedDeliverable;
    $paramTypes .= "i";
}
if ($selectedSemester) {
    $conditions[] = "d.semester = ?";
    $params[] = $selectedSemester;
    $paramTypes .= "s";
}

if (!empty($conditions)) {
    $marksQuery .= " AND (" . implode(" AND ", $conditions) . ")";
}
$marksQuery .= " ORDER BY s.full_name, d.name, d.submission_type";

$stmt = $conn->prepare($marksQuery);
if ($stmt === false) {
    die("Prepare failed for marks query: " . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$marksResult = $stmt->get_result();

// Organize data for row-based display
$studentData = [];
$uniqueDeliverables = [];
while ($row = $marksResult->fetch_assoc()) {
    $studentId = $row['student_id'];
    $deliverableId = $row['deliverable_id'];
    $submissionType = $row['submission_type'];

    // Skip if group_id is null for group deliverables
    if ($submissionType === 'group' && $row['group_id'] === null) {
        continue;
    }

    // Track unique deliverables
    $deliverableName = $row['deliverable_name'] . ($submissionType === 'group' ? ' (Group)' : '');
    $uniqueDeliverables[$deliverableName] = true;

    // Initialize student data if not already set
    if (!isset($studentData[$studentId])) {
        $studentData[$studentId] = [
            'student_name' => $row['student_name'],
            'student_username' => $row['student_username'],
            'group_id' => $row['group_id'],
            'group_name' => $row['group_name'],
            'deliverables' => []
        ];
    }

    // Create a unique key for the deliverable
    $delivKey = $deliverableId . '_' . $submissionType;

    if (!isset($studentData[$studentId]['deliverables'][$delivKey])) {
        $studentData[$studentId]['deliverables'][$delivKey] = [
            'deliverable_name' => $deliverableName,
            'supervisor' => 'N/A',
            'assessor' => 'N/A',
            'average' => 'N/A',
            'grades_for_average' => []
        ];
    }

    // Determine grade and evaluator based on submission type
    $grade = null;
    $supervisorId = null;
    $assessorId = null;

    if ($submissionType === 'individual') {
        $grade = $row['individual_grade'] !== null ? floatval($row['individual_grade']) : null;
        $supervisorId = $row['individual_supervisor_id'];
        $assessorId = $row['individual_assessor_id'];
    } elseif ($submissionType === 'group') {
        $grade = $row['group_grade'] !== null ? floatval($row['group_grade']) : null;
        $supervisorId = $row['group_supervisor_id'];
        $assessorId = $row['group_assessor_id'];
    }

    // Assign grade to the appropriate evaluator
    if ($supervisorId && $grade !== null) {
        $studentData[$studentId]['deliverables'][$delivKey]['supervisor'] = number_format($grade, 2);
        $studentData[$studentId]['deliverables'][$delivKey]['grades_for_average'][] = $grade;
    }
    if ($assessorId && $grade !== null) {
        $studentData[$studentId]['deliverables'][$delivKey]['assessor'] = number_format($grade, 2);
        $studentData[$studentId]['deliverables'][$delivKey]['grades_for_average'][] = $grade;
    }

    // Calculate average
    $grades = $studentData[$studentId]['deliverables'][$delivKey]['grades_for_average'];
    $studentData[$studentId]['deliverables'][$delivKey]['average'] = !empty($grades) ? number_format(array_sum($grades) / count($grades), 2) : 'N/A';
}
$stmt->close();

$uniqueDeliverables = array_keys($uniqueDeliverables);
sort($uniqueDeliverables);

// Handle CSV report generation
if (isset($_GET['generate_report'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Student_Marks_Report_' . date('Ymd_His') . '.csv"');

    ob_start();
    $output = fopen('php://output', 'w');

    // CSV Headers
    $csvHeaders = ['Student Name', 'Student ID'];
    foreach ($uniqueDeliverables as $deliverable) {
        $csvHeaders[] = $deliverable . ' - Supervisor';
        $csvHeaders[] = $deliverable . ' - Assessor';
        $csvHeaders[] = $deliverable . ' - Average';
    }
    fputcsv($output, $csvHeaders);

    // CSV Data
    foreach ($studentData as $studentId => $data) {
        $row = [
            $data['student_name'],
            $data['student_username']
        ];
        foreach ($uniqueDeliverables as $deliverable) {
            $found = false;
            foreach ($data['deliverables'] as $delivData) {
                if ($delivData['deliverable_name'] === $deliverable) {
                    $row[] = $delivData['supervisor'];
                    $row[] = $delivData['assessor'];
                    $row[] = $delivData['average'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $row[] = '-';
                $row[] = '-';
                $row[] = '-';
            }
        }
        fputcsv($output, $row);
    }

    fclose($output);
    ob_end_flush();
    exit();
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
    <title>Coordinator - View Student Marks</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

    <style>
        .info-icon {
            cursor: pointer;
            color: #4e73df;
            margin-left: 5px;
        }
        .info-icon:hover {
            color: #224abe;
        }
        /* Table container for fixed and scrollable parts */
        .table-wrapper {
            display: flex;
            width: 100%;
            margin: 1rem 0;
            border-radius: 0.35rem;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        /* Fixed table (Student Name, Student ID) */
        .fixed-table {
            flex-shrink: 0;
            border-right: 2px solid #e3e6f0;
        }
        /* Scrollable table (Deliverables) */
        .scrollable-table {
            flex-grow: 1;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* Table styles */
        .table-bordered {
            border: 1px solid #e3e6f0;
            margin-bottom: 0;
        }
        .table-bordered th,
        .table-bordered td {
            border: 1px solid #e3e6f0;
            padding: 1rem;
            vertical-align: middle;
            white-space: nowrap;
        }
        .table-bordered thead th {
            background-color: #f8f9fc;
            border-bottom: 2px solid #e3e6f0;
            font-weight: bold;
            min-width: 100px;
        }
        /* Fixed column widths */
        .fixed-table th:first-child,
        .fixed-table td:first-child {
            min-width: 200px; /* Student Name */
        }
        .fixed-table th:nth-child(2),
        .fixed-table td:nth-child(2) {
            min-width: 150px; /* Student ID */
        }
        /* Mark cells with colors */
        .mark-cell {
            text-align: center;
            min-width: 100px;
            padding: 1rem;
        }
        .mark-cell.supervisor {
            background-color: rgba(187, 222, 251, 0.5);
        }
        .mark-cell.assessor {
            background-color: rgba(200, 230, 201, 0.5);
        }
        .mark-cell.average {
            background-color: rgba(248, 187, 208, 0.5);
        }
        /* Synchronize row heights */
        .fixed-table th,
        .scrollable-table th,
        .fixed-table td,
        .scrollable-table td {
            height: 60px; /* Fixed height for consistency */
        }
        /* Card styling */
        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
            padding: 1.5rem;
        }
        .card {
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
        }
        .card-body {
            padding: 1.5rem;
        }
        /* Filter section styling */
        .form-control {
            font-size: 0.875rem;
            border-radius: 0.35rem;
            padding: 0.375rem 0.75rem;
            border: 1px solid #d1d3e2;
        }
        .form-control:focus {
            border-color: #bac8f3;
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        /* Legend for mark types */
        .marks-legend {
            display: flex;
            gap: 1rem;
            margin: 1.5rem 0;
            padding: 0.5rem;
            background-color: #f8f9fc;
            border-radius: 0.35rem;
            justify-content: center;
        }
        .legend-item {
            display: flex;
            align-items: center;
        }
        .legend-color {
            width: 30px;
            height: 30px;
            border-radius: 4px;
        }
        .legend-supervisor {
            background-color: rgba(187, 222, 251, 0.5);
        }
        .legend-assessor {
            background-color: rgba(200, 230, 201, 0.5);
        }
        .legend-average {
            background-color: rgba(248, 187, 208, 0.5);
        }
        /* Fix header alignment */
        .table thead th {
            vertical-align: middle;
            height: 50px; /* Set a fixed height for header rows */
        }
        
        .table thead tr:first-child th {
            border-bottom: none; /* Remove bottom border for first row */
        }
        
        .table thead tr:last-child th {
            border-top: none; /* Remove top border for second row */
        }
        
        /* Ensure empty row has proper height */
        .fixed-table thead tr:empty,
        .scrollable-table thead tr:empty {
            height: 50px;
            display: table-row;
        }
        
        /* Ensure consistent heights between fixed and scrollable tables */
        .fixed-table thead tr,
        .scrollable-table thead tr {
            height: 50px;
        }
        
        /* Adjust vertical alignment for rowspan cells */
        th[rowspan="2"] {
            vertical-align: middle !important;
        }
        
        /* Ensure second header row is visible */
        thead tr:nth-child(2) {
            height: 50px !important;
            visibility: visible !important;
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
                        <h6 class="collapse-header">Staff and Student <br> Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors &<br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                    </div>
                </div>
            </li>
            <li class="nav-item active">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities"
                    aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities"
                    data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">FYP Evaluation:</h6>
                        <a class="collapse-item" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item active" href="coorviewstudentdetails.php">View Student Details</a>
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

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <?= $message ?>
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">View Student Details > View Student Marks</h1>
                        <a href="coorviewstudentdetails.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back to View Student Details
                        </a>
                    </div>

                    <!-- Filters Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Marks</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row">
                                    <!-- Submission Type Filter -->
                                    <div class="col-md-3 mb-3">
                                        <label for="semester">Semester</label>
                                        <select class="form-control" id="semester" name="semester">
                                            <option value="">-- All Semesters --</option>
                                            <?php foreach ($semesters as $semester): ?>
                                                <option value="<?= htmlspecialchars($semester['semester']) ?>" 
                                                        <?= $selectedSemester === $semester['semester'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($semester['semester']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Student Name Search -->
                                    <div class="col-md-3 mb-3">
                                        <label for="student_name">Search Student Name</label>
                                        <input type="text" class="form-control" id="student_name" name="student_name" 
                                               value="<?= htmlspecialchars($searchStudent) ?>" placeholder="Enter student name">
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
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="coorstudentmarks.php" class="btn btn-secondary">Clear Filters</a>
                            </form>
                        </div>
                    </div>

                    <!-- Marks Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Student Marks</h6>
                            <a href="coorstudentmarks.php?generate_report=1&student_name=<?= urlencode($searchStudent) ?>&group_name=<?= urlencode($selectedGroup) ?>&deliverable_id=<?= $selectedDeliverable ?>&semester=<?= urlencode($selectedSemester) ?>" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                                <i class="fas fa-download fa-sm text-white-50"></i> Generate Report
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-wrapper">
                                <!-- Fixed Table (Student Name, Student ID) -->
                                <div class="fixed-table">
                                    <table class="table table-bordered" id="fixedTable">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" class="align-middle">Student Name</th>
                                                <th rowspan="2" class="align-middle">Student ID</th>
                                            </tr>
                                            <tr></tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($studentData)): ?>
                                                <?php foreach ($studentData as $studentId => $data): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($data['student_name']) ?></td>
                                                        <td><?= htmlspecialchars($data['student_username']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="2" class="text-center">No marks found.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Scrollable Table (Deliverables) -->
                                <div class="scrollable-table">
                                    <table class="table table-bordered" id="scrollableTable">
                                        <thead>
                                            <tr>
                                                <?php foreach ($uniqueDeliverables as $deliverable): ?>
                                                    <th colspan="3" class="text-center"><?= htmlspecialchars($deliverable) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <?php foreach ($uniqueDeliverables as $deliverable): ?>
                                                    <th>Supervisor</th>
                                                    <th>Assessor</th>
                                                    <th>Average</th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($studentData)): ?>
                                                <?php foreach ($studentData as $studentId => $data): ?>
                                                    <tr>
                                                        <?php 
                                                        foreach ($uniqueDeliverables as $deliverable):
                                                            $found = false;
                                                            foreach ($data['deliverables'] as $delivKey => $delivData):
                                                                if ($delivData['deliverable_name'] === $deliverable):
                                                                    $found = true;
                                                        ?>
                                                                    <td class="mark-cell supervisor"><?= htmlspecialchars($delivData['supervisor']) ?></td>
                                                                    <td class="mark-cell assessor"><?= htmlspecialchars($delivData['assessor']) ?></td>
                                                                    <td class="mark-cell average"><?= htmlspecialchars($delivData['average']) ?></td>
                                                        <?php 
                                                                    break;
                                                                endif;
                                                            endforeach;
                                                            if (!$found):
                                                        ?>
                                                                <td class="text-center">-</td>
                                                                <td class="text-center">-</td>
                                                                <td class="text-center">-</td>
                                                        <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="<?= count($uniqueDeliverables) * 3 ?>" class="text-center">No marks found.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
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
                    <div class="copyright text-center my-auto"><span>Copyright © FYPCollabor8 2025</span></div>
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
    <script>
        $(document).ready(function() {
            // Disable DataTables to prevent interference with fixed columns
            // $('#fixedTable, #scrollableTable').DataTable({
            //     paging: false,
            //     searching: false,
            //     ordering: true,
            //     scrollX: false
            // });
        });
    </script>
</body>
</html>