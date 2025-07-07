<?php
session_start();
include 'connection.php';

// Define a predefined color palette
$colorPalette = [
    '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
    '#6610f2', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997'
];

// Fetch all group IDs and assign colors dynamically (for calendar coloring)
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
$selectedStatus = isset($_GET['status']) && trim($_GET['status']) !== '' ? trim($_GET['status']) : null;

// Handle AJAX request for lecturer meetings (for FullCalendar)
if (isset($_POST['lecturer_id']) && !isset($_POST['action'])) {
    header('Content-Type: application/json');
    $lecturerID = intval($_POST['lecturer_id']);
    $selectedSemester = isset($_POST['semester']) && trim($_POST['semester']) !== '' ? trim($_POST['semester']) : $currentSemesterName;
    // MODIFIED: Only apply status filter for 'Confirmed'
    $selectedStatus = isset($_POST['status']) && trim($_POST['status']) === 'Confirmed' ? 'Confirmed' : null;
    $meetings = [];

    $meetingsConditions = ["m.lecturer_id = ?"];
    $meetingsParams = [$lecturerID];
    $meetingsParamTypes = "i";

    if ($selectedSemester) {
        $meetingsConditions[] = "s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date) AND sem.semester_name = ?";
        $meetingsParams[] = $selectedSemester;
        $meetingsParamTypes .= "s";
    }

    // MODIFIED: Apply status filter only for 'Confirmed'; ignore for 'Pending' or null
    if ($selectedStatus) {
        $meetingsConditions[] = "m.status = ?";
        $meetingsParams[] = $selectedStatus;
        $meetingsParamTypes .= "s";
    } else {
        $meetingsConditions[] = "m.status != 'Cancelled'";
    }

    $meetingsQuery = "
        SELECT m.meeting_date, m.meeting_time, m.topic, m.group_id
        FROM meetings m
        JOIN students s ON m.student_id = s.id
        LEFT JOIN group_members gm ON s.id = gm.student_id
        LEFT JOIN groups g ON gm.group_id = g.id
        LEFT JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
        WHERE " . implode(" AND ", $meetingsConditions);
    
    $stmt = $conn->prepare($meetingsQuery);
    if ($stmt) {
        $stmt->bind_param($meetingsParamTypes, ...$meetingsParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $meetings = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        error_log("Prepare failed (AJAX Meetings): " . $conn->error);
    }

    // Debug: Log AJAX response
    error_log("AJAX response for lecturer_id $lecturerID, semester $selectedSemester, status " . ($selectedStatus ?? 'null') . ": " . json_encode($meetings));

    echo json_encode($meetings);
    $conn->close();
    exit;
}

// Ensure the lecturer is logged in
if (isset($_SESSION['user_id'])) {
    $lecturerID = $_SESSION['user_id'];
} else {
    header("Location: index.html");
    exit();
}

// Fetch the lecturer's full name, profile picture, and role from the database
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

// Initialize messages
$successMessage = '';
$errorMessage = '';

// Handle meeting status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $meetingID = intval($_POST['meeting_id']);
    $action = $_POST['action'];

    if ($action === 'confirm') {
        $stmt = $conn->prepare("UPDATE meetings SET status = 'Confirmed' WHERE id = ? AND lecturer_id = ?");
        $stmt->bind_param("ii", $meetingID, $lecturerID);
    } elseif ($action === 'cancel') {
        $stmt = $conn->prepare("DELETE FROM meetings WHERE id = ? AND lecturer_id = ?");
        $stmt->bind_param("ii", $meetingID, $lecturerID);
    }

    if ($stmt->execute()) {
        $successMessage = $action === 'confirm' ? "Meeting confirmed successfully!" : "Meeting rejected and removed successfully!";
    } else {
        $errorMessage = "Failed to process meeting: " . $stmt->error;
    }

    $stmt->close();
}

// Fetch all meetings for the lecturer (for Meeting Details table)
$meetingsConditions = ["m.lecturer_id = ?"];
$meetingsParams = [$lecturerID];
$meetingsParamTypes = "i";

if ($selectedSemester) {
    $meetingsConditions[] = "s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date) AND sem.semester_name = ?";
    $meetingsParams[] = $selectedSemester;
    $meetingsParamTypes .= "s";
}

// MODIFIED: Apply status filter for both Confirmed and Pending in the table
if ($selectedStatus) {
    $meetingsConditions[] = "m.status = ?";
    $meetingsParams[] = $selectedStatus;
    $meetingsParamTypes .= "s";
} else {
    $meetingsConditions[] = "m.status != 'Cancelled'";
}

$meetingsQuery = "
    SELECT m.id, s.full_name AS student_name, m.meeting_date, m.meeting_time, m.topic, m.status, m.group_id
    FROM meetings m
    JOIN students s ON m.student_id = s.id
    LEFT JOIN group_members gm ON s.id = gm.student_id
    LEFT JOIN groups g ON gm.group_id = g.id
    LEFT JOIN semesters sem ON s.intake_year = YEAR(sem.start_date) AND s.intake_month = MONTHNAME(sem.start_date)
    WHERE " . implode(" AND ", $meetingsConditions) . "
    ORDER BY m.meeting_date ASC";

$stmt = $conn->prepare($meetingsQuery);
if ($stmt) {
    $stmt->bind_param($meetingsParamTypes, ...$meetingsParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $meetings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Prepare failed (Meetings Query): " . $conn->error);
}

// Filter confirmed meetings for the "Upcoming Meetings" section
$confirmedMeetings = array_filter($meetings, fn($meeting) => $meeting['status'] === 'Confirmed');
$upcomingMeetingsCount = count($confirmedMeetings);

// Filter past meetings (meetings before today)
$pastMeetings = array_filter($meetings, function($meeting) {
    $meetingDate = strtotime($meeting['meeting_date']);
    $today = strtotime(date('Y-m-d'));
    return $meetingDate < $today && $meeting['status'] === 'Confirmed';
});
$pastMeetingsCount = count($pastMeetings);

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

    <title>Lecturer - Manage Meetings</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>

<body id="page-top">
    <div id="wrapper">
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
                <a class="nav-link <?= !$isSupervisor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Mentorship Tools</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Guidance Resources:</h6>
                        <a class="collapse-item active <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectmanagemeetings.php">Manage Meetings</a>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewdiary.php">View Student Diary</a>
                        <?php /* <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectevaluatestudent.php">Evaluate Students</a> */ ?>
                        <a class="collapse-item <?= !$isSupervisor ? 'disabled' : '' ?>" href="lectviewstudentdetails.php">View Student Details</a>
                    </div>
                </div>
            </li>
            <hr class="sidebar-divider">
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
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
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
                        <h1 class="h3 mb-0 text-gray-800">Manage Meetings</h1>
                    </div>
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
                    <?php endif; ?>
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
                    <?php endif; ?>
                    <!-- Upcoming and Past Meetings Cards -->
                    <div class="row">
                        <div class="col-lg-6 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Upcoming Meetings</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($upcomingMeetingsCount) . " Meetings" ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Past Meetings</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($pastMeetingsCount) . " Meetings" ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar-check fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Meeting Schedule (Left) and Filter Meetings + Meeting Details (Right) -->
                    <div class="row">
                        <!-- Left Column: Meeting Schedule -->
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
                        <!-- Right Column: Filter Meetings and Meeting Details -->
                        <div class="col-lg-6">
                            <!-- Filter Meetings Card -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Filter Meetings</h6>
                                </div>
                                <div class="card-body">
                                    <form method="GET" action="">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
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
                                            <!-- MODIFIED: Status dropdown with Confirmed and Pending only -->
                                            <div class="col-md-6 mb-3">
                                                <label for="status">Status</label>
                                                <select class="form-control" id="status" name="status">
                                                    <option value="" <?= $selectedStatus === null ? 'selected' : '' ?>>-- Select Status --</option>
                                                    <option value="Confirmed" <?= $selectedStatus === 'Confirmed' ? 'selected' : '' ?>>Confirm</option>
                                                    <option value="Pending" <?= $selectedStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                </select>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Apply Filter</button>
                                        <a href="lectmanagemeetings.php" class="btn btn-secondary">Clear Filter</a>
                                    </form>
                                </div>
                            </div>
                            <!-- Meeting Details Card -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Meeting Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Meeting Date</th>
                                                    <th>Meeting Time</th>
                                                    <th>Topic</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($meetings)): ?>
                                                    <?php foreach ($meetings as $meeting): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($meeting['student_name']) ?></td>
                                                            <td><?= htmlspecialchars($meeting['meeting_date']) ?></td>
                                                            <td><?= htmlspecialchars($meeting['meeting_time']) ?></td>
                                                            <td><?= htmlspecialchars($meeting['topic']) ?></td>
                                                            <td>
                                                                <?php if ($meeting['status'] === 'Confirmed'): ?>
                                                                    <span class="text-success"><?= htmlspecialchars($meeting['status']) ?></span>
                                                                <?php elseif ($meeting['status'] === 'Pending'): ?>
                                                                    <span class="text-warning"><?= htmlspecialchars($meeting['status']) ?></span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($meeting['status'] === 'Pending'): ?>
                                                                    <form method="POST" action="" style="display: inline;">
                                                                        <input type="hidden" name="meeting_id" value="<?= htmlspecialchars($meeting['id']) ?>">
                                                                        <input type="hidden" name="action" value="confirm">
                                                                        <button type="submit" class="btn btn-success btn-sm">
                                                                            <i class="fas fa-check"></i> Confirm
                                                                        </button>
                                                                    </form>
                                                                    <form method="POST" action="" style="display: inline;">
                                                                        <input type="hidden" name="meeting_id" value="<?= htmlspecialchars($meeting['id']) ?>">
                                                                        <input type="hidden" name="action" value="cancel">
                                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                                            <i class="fas fa-times"></i> Reject
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="text-muted">No actions available</span>
                                                                </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center">No meetings found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
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
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script>
        const groupColors = <?php echo json_encode($groupColors); ?>;
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const semesterSelect = document.getElementById('semester');
            const statusSelect = document.getElementById('status');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                events: function(fetchInfo, successCallback, failureCallback) {
                    // Debug: Log AJAX call
                    console.log('Fetching meetings for lecturer_id: <?php echo $lecturerID; ?>, semester: <?php echo htmlspecialchars($selectedSemester); ?>, status: ' + (statusSelect.value || ''));
                    $.ajax({
                        url: 'lectmanagemeetings.php',
                        method: 'POST',
                        data: { 
                            lecturer_id: <?php echo $lecturerID; ?>,
                            semester: semesterSelect.value,
                            // MODIFIED: Send status only if it's Confirmed
                            status: statusSelect.value === 'Confirmed' ? 'Confirmed' : ''
                        },
                        dataType: 'json',
                        success: function(data) {
                            // Debug: Log AJAX response
                            console.log('AJAX response:', data);
                            const events = data.map(meeting => ({
                                title: meeting.topic + ' (' + meeting.meeting_time + ')',
                                start: meeting.meeting_date + 'T' + meeting.meeting_time,
                                backgroundColor: groupColors[meeting.group_id] || groupColors['default'],
                                borderColor: groupColors[meeting.group_id] || groupColors['default'],
                                description: 'Time: ' + meeting.meeting_time
                            }));
                            successCallback(events);
                        },
                        error: function(xhr, status, error) {
                            // Debug: Log AJAX error
                            console.error('AJAX error:', status, error, xhr.responseText);
                            failureCallback();
                        }
                    });
                },
                eventDidMount: function(info) {
                    const tooltip = new bootstrap.Tooltip(info.el, {
                        title: info.event.extendedProps.description,
                        placement: 'top',
                        trigger: 'hover',
                        container: 'body'
                    });
                }
            });
            calendar.render();

            // MODIFIED: Refetch calendar events when semester or status changes
            semesterSelect.addEventListener('change', function() {
                calendar.refetchEvents();
            });

            statusSelect.addEventListener('change', function() {
                calendar.refetchEvents();
            });
        });
    </script>
</body>

</html>