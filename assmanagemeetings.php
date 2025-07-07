<?php
session_start();

include 'connection.php';

// Ensure the lecturer is logged in
if (isset($_SESSION['user_id'])) {
    $lecturerID = $_SESSION['user_id'];
} else {
    header("Location: index.html"); // Redirect to login if not authenticated
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $meetingID = intval($_POST['meeting_id']);
    $action = $_POST['action'];

    if ($action === 'confirm') {
        $stmt = $conn->prepare("UPDATE meetings SET status = 'Confirmed' WHERE id = ? AND lecturer_id = ?");
        $stmt->bind_param("ii", $meetingID, $lecturerID);
    } elseif ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE meetings SET status = 'Rejected' WHERE id = ? AND lecturer_id = ?");
        $stmt->bind_param("ii", $meetingID, $lecturerID);
    }

    if ($stmt->execute()) {
        $successMessage = $action === 'confirm' ? "Meeting confirmed successfully!" : "Meeting rejected successfully!";
    } else {
        $errorMessage = "Failed to update meeting: " . $stmt->error;
    }

    $stmt->close();
}

// Fetch all meetings for the lecturer
$meetingsQuery = "
    SELECT m.id, s.full_name AS student_name, m.meeting_date, m.meeting_time, m.topic, m.status
    FROM meetings m
    JOIN students s ON m.student_id = s.id
    WHERE m.lecturer_id = ?
    ORDER BY m.meeting_date ASC
";
$stmt = $conn->prepare($meetingsQuery);
$stmt->bind_param("i", $lecturerID);
$stmt->execute();
$result = $stmt->get_result();
$meetings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Filter confirmed meetings for the "Upcoming Meetings" section
$confirmedMeetings = array_filter($meetings, fn($meeting) => $meeting['status'] === 'Confirmed');
$upcomingMeetingsCount = count($confirmedMeetings);

// Filter recent meetings (e.g., within the last 30 days)
$recentMeetings = array_filter($meetings, function($meeting) {
    $meetingDate = strtotime($meeting['meeting_date']);
    $thirtyDaysAgo = strtotime('-30 days');
    return $meetingDate >= $thirtyDaysAgo && $meeting['status'] === 'Confirmed';
});
$recentMeetingsCount = count($recentMeetings);

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

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet"> <!-- Ensure this includes .disabled styles -->

    <!-- FullCalendar dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <!-- Custom styles for this page -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <!-- Sidebar - Brand -->
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="lecturerdashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>

            <!-- Divider -->
            <hr class="sidebar-divider my-0">

            <!-- Nav Item - Dashboard -->
            <li class="nav-item">
                <a class="nav-link" href="lecturerdashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Heading -->
            <div class="sidebar-heading">Supervisor Portal</div>

            <!-- Nav Item - Academic Oversight -->
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

            <!-- Nav Item - Mentorship Tools -->
            <li class="nav-item">
                <a class="nav-link <?= !$isSupervisor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
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

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Heading -->
            <div class="sidebar-heading">Assessor Portal</div>

            <!-- Nav Item - Oversight Panel -->
            <li class="nav-item active">
                <a class="nav-link collapsed <?= !$isAssessor ? 'disabled' : '' ?>" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Oversight Panel</span>
                </a>
                <div id="collapsePages" class="collapse show" aria-labelledby="headingPages" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Performance Review:</h6>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assevaluatestudent.php">Evaluate Students</a>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assviewstudentdetails.php">View Student Details</a>
                        <div class="collapse-divider"></div>
                        <h6 class="collapse-header">Component Analysis:</h6>
                        <a class="collapse-item <?= !$isAssessor ? 'disabled' : '' ?>" href="assfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item active <?= !$isAssessor ? 'disabled' : '' ?>" href="assmanagemeetings.php">Manage Meetings</a>
                    </div>
                </div>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider d-none d-md-block">

            <!-- Sidebar Toggler (Sidebar) -->
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
                    <form class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search">
                        <div class="input-group">
                            <input type="text" class="form-control bg-light border-0 small" placeholder="Search for..." aria-label="Search" aria-describedby="basic-addon2">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button">
                                    <i class="fas fa-search fa-sm"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow d-sm-none">
                            <a class="nav-link dropdown-toggle" href="#" id="searchDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-search fa-fw"></i>
                            </a>
                            <div class="dropdown-menu dropdown-menu-right p-3 shadow animated--grow-in" aria-labelledby="searchDropdown">
                                <form class="form-inline mr-auto w-100 navbar-search">
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light border-0 small" placeholder="Search for..." aria-label="Search" aria-describedby="basic-addon2">
                                        <div class="input-group-append">
                                            <button class="btn btn-primary" type="button">
                                                <i class="fas fa-search fa-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </li>
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-bell fa-fw"></i>
                                <span class="badge badge-danger badge-counter">3+</span>
                            </a>
                            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="alertsDropdown">
                                <h6 class="dropdown-header">Alerts Center</h6>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="mr-3">
                                        <div class="icon-circle bg-primary">
                                            <i class="fas fa-file-alt text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-gray-500">December 12, 2019</div>
                                        <span class="font-weight-bold">A new monthly report is ready to download!</span>
                                    </div>
                                </a>
                                <a class="dropdown-item text-center small text-gray-500" href="#">Show All Alerts</a>
                            </div>
                        </li>
                        <div class="topbar-divider d-none d-sm-block"></div>
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
                        <h1 class="h3 mb-0 text-gray-800">Manage Meetings</h1>
                        <a href="#" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-download fa-sm text-white-50"></i> Generate Report
                        </a>
                    </div>

                    <!-- Success/Error Messages -->
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
                    <?php endif; ?>
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
                    <?php endif; ?>

                    <!-- Content Row -->
                    <div class="row">
                        <!-- Upcoming Meetings Card -->
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

                        <!-- Recent Meetings Card -->
                        <div class="col-lg-6 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Recent Meetings</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($recentMeetingsCount) . " Meetings" ?>
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

                    <!-- Content Row -->
                    <div class="row">
                        <!-- Meeting Schedule (Calendar) -->
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

                        <!-- Meeting Details Table -->
                        <div class="col-lg-6">
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
                                            <tfoot>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Meeting Date</th>
                                                    <th>Meeting Time</th>
                                                    <th>Topic</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </tfoot>
                                            <tbody>
                                                <?php if (!empty($meetings)): ?>
                                                    <?php foreach ($meetings as $meeting): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($meeting['student_name']) ?></td>
                                                            <td><?= htmlspecialchars($meeting['meeting_date']) ?></td>
                                                            <td><?= htmlspecialchars($meeting['meeting_time']) ?></td>
                                                            <td><?= htmlspecialchars($meeting['topic']) ?></td>
                                                            <td class="<?= strtolower($meeting['status']) ?>">
                                                                <?= htmlspecialchars($meeting['status']) ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($meeting['status'] === 'Pending'): ?>
                                                                    <form method="POST" style="display:inline;">
                                                                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                                                                        <button type="submit" name="action" value="confirm" class="btn btn-success btn-sm">Confirm</button>
                                                                        <button type="submit" name="action" value="cancel" class="btn btn-danger btn-sm">Reject</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span>No actions available</span>
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
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright © Your Website 2021</span>
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

    <!-- FullCalendar script -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            height: 'auto',
            events: [
                <?php foreach ($meetings as $meeting): ?>{
                    title: '<?= htmlspecialchars($meeting['student_name']) ?> - <?= htmlspecialchars($meeting['topic']) ?>',
                    start: '<?= htmlspecialchars($meeting['meeting_date']) ?>T<?= htmlspecialchars($meeting['meeting_time']) ?>',
                    description: 'Time: <?= htmlspecialchars($meeting['meeting_time']) ?>, Status: <?= htmlspecialchars($meeting['status']) ?>'
                },
                <?php endforeach; ?>
            ],
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
    });
    </script>
</body>

</html>