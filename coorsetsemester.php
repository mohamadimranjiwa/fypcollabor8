<?php
session_start();

include 'connection.php';

// Ensure the coordinator is logged in
if (isset($_SESSION['user_id'])) {
    $coordinatorID = $_SESSION['user_id'];
} else {
    header("Location: index.html"); // Redirect to login if not authenticated
    exit();
}

// Fetch the coordinator's full name and profile picture from the database
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $coordinatorID);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();
$stmt->close();

// Check if coordinator exists
if (!$coordinator) {
    die("Error: No coordinator found with the provided ID.");
}

$personalInfo = [
    'full_name' => $coordinator['full_name'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message
$message = "";

// Fetch semesters
$semestersQuery = "
    SELECT id, semester_name, start_date, is_current, created_at, updated_at
    FROM semesters
    ORDER BY created_at DESC";
$semestersResult = $conn->query($semestersQuery) or die("Error in semesters query: " . htmlspecialchars($conn->error));
$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);
$hasSemesters = !empty($semesters);

// Handle semester creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_semester'])) {
    $startDate = trim($_POST['start_date']);
    
    if (empty($startDate)) {
        $message = "<div class='alert alert-danger'>Start date is required.</div>";
    } else {
        // Auto-generate semester name based on start date (e.g., "September 2024")
        $start = new DateTime($startDate);
        $semesterName = $start->format('F Y'); // F = Full month name, Y = Four-digit year

        // Begin transaction to ensure only one semester is current
        $conn->begin_transaction();
        try {
            // Set all semesters to not current
            $updateQuery = "UPDATE semesters SET is_current = 0";
            if (!$conn->query($updateQuery)) {
                throw new Exception("Error updating semesters: " . $conn->error);
            }

            // Insert new semester as current
            $insertQuery = "INSERT INTO semesters (semester_name, start_date, is_current) VALUES (?, ?, 1)";
            $stmt = $conn->prepare($insertQuery);
            if (!$stmt) {
                throw new Exception("Error preparing insert statement: " . $conn->error);
            }
            $stmt->bind_param("ss", $semesterName, $startDate);
            if (!$stmt->execute()) {
                throw new Exception("Error executing insert statement: " . $stmt->error);
            }
            $stmt->close();

            $conn->commit();
            $message = "<div class='alert alert-success'>Semester set successfully!</div>";
            header("Refresh:0");
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Failed to Manage Semester: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Handle semester deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_semester'])) {
    $semester_id = intval($_POST['semester_id']);
    
    $sql = "DELETE FROM semesters WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $semester_id);
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Semester deleted successfully!</div>";
        header("Refresh:0");
    } else {
        $message = "<div class='alert alert-danger'>Failed to delete semester: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
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
    <title>Coordinator - Manage Semester</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
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
                        <h6 class="collapse-header">Staff and Student <br>Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors &<br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                    </div>
                </div>
            </li>
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseUtilities"
                    aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities"
                    data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">FYP Evaluation:</h6>
                        <a class="collapse-item" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item" href="coorviewstudentdetails.php">View Student Details</a>
                        <a class="collapse-item" href="coormanagerubrics.php">Manage Rubrics</a>
                        <a class="collapse-item active" href="coorassignassessment.php">Assign Assessment</a>
                        <!-- <a class="collapse-item" href="coorevaluatestudent.php">Evaluate Students</a> -->
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages"
                    aria-expanded="true" aria-controls="laboratory">
                        <i class="fas fa-fw fa-folder"></i>
                        <span>Resources & Communication</span>
                    </a>
                    <div id="collapsePages" class="collapse" aria-labelledby="headingLab" data-parent="#accordionSidebar">
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
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['full_name']) ?>" onerror="this.src='img/undraw_profile.svg';">
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
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Assign Assessment > Manage Semester</h1>
                        <a href="coorassignassessment.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back to Assign Assessment
                        </a>
                    </div>
                    <?= $message ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Semesters List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Semester Name</th>
                                            <th>Start Date</th>
                                            <th>Created At</th>
                                            <th>Updated At</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($semesters)): ?>
                                            <?php foreach ($semesters as $semester): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($semester['semester_name']) ?></td>
                                                    <td><?= htmlspecialchars($semester['start_date']) ?></td>
                                                    <td><?= htmlspecialchars($semester['created_at']) ?></td>
                                                    <td><?= htmlspecialchars($semester['updated_at']) ?></td>
                                                    <td>
                                                        <?php if (isset($semester['is_current']) && $semester['is_current']): ?>
                                                            <span style="color: #28a745;">Current</span>
                                                        <?php else: ?>
                                                            <span style="color: #6c757d;">Past</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center">No semesters found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Set New Semester</h6></div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <div class="form-group">
                                            <label for="start_date">Start Date</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                                        </div>
                                        <button type="submit" name="set_semester" class="btn btn-primary btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-calendar-plus"></i>
                                            </span>
                                            <span class="text">Manage Semester</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Delete Semester</h6></div>
                                <div class="card-body">
                                    <?php if ($hasSemesters): ?>
                                        <form id="deleteSemesterForm" method="POST" action="">
                                            <div class="form-group">
                                                <label for="semester_id">Select Semester</label>
                                                <select class="form-control" id="semester_id" name="semester_id" required>
                                                    <option value="">-- Select a semester --</option>
                                                    <?php foreach ($semesters as $semester): ?>
                                                        <option value="<?= $semester['id'] ?>" data-name="<?= htmlspecialchars($semester['semester_name']) ?>">
                                                            <?= htmlspecialchars($semester['semester_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button type="button" class="btn btn-danger btn-icon-split" data-toggle="modal" data-target="#deleteSemesterModal" onclick="setDeleteModalContent()">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-trash"></i>
                                                </span>
                                                <span class="text">Delete Semester</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p class="text-muted">No semesters available to delete.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End of Page Content -->

                <!-- Delete Confirmation Modal -->
                <div class="modal fade" id="deleteSemesterModal" tabindex="-1" role="dialog" aria-labelledby="deleteSemesterModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteSemesterModalLabel">Confirm Deletion</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                Are you sure you want to delete the semester <strong id="deleteSemesterName"></strong>? This action cannot be undone.
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <form id="confirmDeleteSemesterForm" method="POST" action="">
                                    <input type="hidden" name="semester_id" id="confirmDeleteSemesterId">
                                    <button type="submit" name="delete_semester" class="btn btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
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
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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

    <script>
        function setDeleteModalContent() {
            const select = document.getElementById('semester_id');
            const semesterName = select.options[select.selectedIndex].dataset.name;
            const semesterId = select.value;
            document.getElementById('deleteSemesterName').textContent = semesterName || 'this semester';
            document.getElementById('confirmDeleteSemesterId').value = semesterId;
        }
    </script>
</body>
</html>