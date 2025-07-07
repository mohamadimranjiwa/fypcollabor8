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

// Fetch the admin's full name and profile picture
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
    die("Error: No admin found with ID $adminID.");
}

$personalInfo = [
    'full_name' => $admin['full_name'] ?? 'N/A',
    'profile_picture' => $admin['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message
$message = "";

// Fetch announcements
$announcementsQuery = "
    SELECT a.id, a.title, a.details, a.created_at, a.updated_at, COALESCE(ad.full_name, 'Unknown') AS admin_name
    FROM announcements a
    LEFT JOIN admins ad ON a.admin_id = ad.id
    ORDER BY a.created_at DESC";
$announcementsResult = $conn->query($announcementsQuery) or die("Error in announcements query: " . htmlspecialchars($conn->error));
$announcements = $announcementsResult->fetch_all(MYSQLI_ASSOC);
$hasAnnouncements = !empty($announcements);
$announcementsResult->free();

// Handle announcement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_announcement'])) {
    $title = trim($_POST['title']);
    $details = trim($_POST['details']);
    
    if (empty($title) || empty($details)) {
        $message = "<div class='alert alert-danger'>Title and Details are required.</div>";
    } else {
        $sql = "INSERT INTO announcements (admin_id, title, details) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            $message = "<div class='alert alert-danger'>Prepare failed: " . htmlspecialchars($conn->error) . "</div>";
        } else {
            $stmt->bind_param("iss", $adminID, $title, $details);
            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'>Announcement published successfully!</div>";
                // Add timeout to clear message
                header("Refresh:3");
            } else {
                $message = "<div class='alert alert-danger'>Failed to save announcement: " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        }
    }
}

// Handle announcement update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_announcement'])) {
    $announcement_id = intval($_POST['announcement_id']);
    $title = trim($_POST['title']);
    $details = trim($_POST['details']);
    
    if (empty($title) || empty($details)) {
        $message = "<div class='alert alert-danger'>Title and Details are required.</div>";
    } else {
        $sql = "UPDATE announcements SET title = ?, details = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            $message = "<div class='alert alert-danger'>Prepare failed: " . htmlspecialchars($conn->error) . "</div>";
        } else {
            $stmt->bind_param("ssi", $title, $details, $announcement_id);
            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'>Announcement updated successfully!</div>";
                header("Refresh:3");
            } else {
                $message = "<div class='alert alert-danger'>Failed to update announcement: " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        }
    }
}

// Handle announcement deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    $announcement_id = intval($_POST['announcement_id']);
    
    $sql = "DELETE FROM announcements WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        $message = "<div class='alert alert-danger'>Prepare failed: " . htmlspecialchars($conn->error) . "</div>";
    } else {
        $stmt->bind_param("i", $announcement_id);
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Announcement deleted successfully!</div>";
            header("Refresh:3");
        } else {
            $message = "<div class='alert alert-danger'>Failed to delete announcement: " . htmlspecialchars($stmt->error) . "</div>";
        }
        $stmt->close();
    }
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
    <title>Admin - Manage Announcements</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>
<body id="page-top">
    <div id="wrapper">
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="admindashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPManagementSystem</div>
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
            <li class="nav-item">
                <a class="nav-link" href="adminviewstudentdetails.php">
                    <i class="fas fa-fw fa-user"></i>
                    <span>View Student Details</span></a>
            </li>
            <li class="nav-item active">
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
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
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
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Manage Announcements</h1>
                    </div>
                    <?= $message ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Announcements List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Details</th>
                                            <th>Admin</th>
                                            <th>Created At</th>
                                            <th>Updated At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($announcements)): ?>
                                            <?php foreach ($announcements as $announcement): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($announcement['title']) ?></td>
                                                    <td><?= htmlspecialchars($announcement['details']) ?></td>
                                                    <td><?= htmlspecialchars($announcement['admin_name']) ?></td>
                                                    <td><?= htmlspecialchars($announcement['created_at']) ?></td>
                                                    <td><?= htmlspecialchars($announcement['updated_at']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center">No announcements found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Create Announcement</h6></div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <div class="form-group">
                                            <label for="title">Announcement Title</label>
                                            <input type="text" class="form-control" id="title" name="title" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="details">Details</label>
                                            <textarea class="form-control" id="details" name="details" rows="5" required></textarea>
                                        </div>
                                        <button type="submit" name="save_announcement" class="btn btn-primary btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-upload"></i>
                                            </span>
                                            <span class="text">Publish Announcement</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Edit Announcement</h6></div>
                                <div class="card-body">
                                    <?php if ($hasAnnouncements): ?>
                                        <form id="editForm" method="POST" action="">
                                            <div class="form-group">
                                                <label for="announcement_id">Select Announcement</label>
                                                <select class="form-control" id="announcement_id" name="announcement_id" required onchange="populateFields(this)">
                                                    <option value="">-- Select an announcement --</option>
                                                    <?php foreach ($announcements as $announcement): ?>
                                                        <option value="<?= $announcement['id'] ?>" 
                                                                data-title="<?= htmlspecialchars($announcement['title']) ?>" 
                                                                data-details="<?= htmlspecialchars($announcement['details']) ?>">
                                                            <?= htmlspecialchars($announcement['title']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_title">Announcement Title</label>
                                                <input type="text" class="form-control" id="edit_title" name="title" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_details">Details</label>
                                                <textarea class="form-control" id="edit_details" name="details" rows="5" required></textarea>
                                            </div>
                                            <button type="submit" name="update_announcement" class="btn btn-primary btn-icon-split">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-save"></i>
                                                </span>
                                                <span class="text">Update Announcement</span>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-icon-split" data-toggle="modal" data-target="#deleteAnnouncementModal" onclick="setDeleteModalContent()">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-trash"></i>
                                                </span>
                                                <span class="text">Delete Announcement</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p class="text-muted">No announcements available to edit or delete.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End of Page Content -->

                <!-- Delete Confirmation Modal -->
                <div class="modal fade" id="deleteAnnouncementModal" tabindex="-1" role="dialog" aria-labelledby="deleteAnnouncementModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteAnnouncementModalLabel">Confirm Deletion</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                Are you sure you want to delete the announcement <strong id="deleteAnnouncementTitle"></strong>? This action cannot be undone.
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <form id="confirmDeleteAnnouncementForm" method="POST" action="">
                                    <input type="hidden" name="announcement_id" id="confirmDeleteAnnouncementId">
                                    <button type="submit" name="delete_announcement" class="btn btn-danger">Delete</button>
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
                    <div class="copyright text-center my-auto"><span>Copyright © FYPManagementSystem 2025</span></div>
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

    <!-- Custom script for populating edit form fields and modal -->
    <script>
        function populateFields(select) {
            const titleInput = document.getElementById('edit_title');
            const detailsInput = document.getElementById('edit_details');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                titleInput.value = selectedOption.getAttribute('data-title');
                detailsInput.value = selectedOption.getAttribute('data-details');
            } else {
                titleInput.value = '';
                detailsInput.value = '';
            }
        }

        function setDeleteModalContent() {
            const select = document.getElementById('announcement_id');
            const announcementTitle = select.options[select.selectedIndex].dataset.title;
            const announcementId = select.value;
            document.getElementById('deleteAnnouncementTitle').textContent = announcementTitle || 'this announcement';
            document.getElementById('confirmDeleteAnnouncementId').value = announcementId;
        }
    </script>
</body>
</html>