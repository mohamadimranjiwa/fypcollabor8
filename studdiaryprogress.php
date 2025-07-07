<?php
// Start session to store temporary messages
session_start();

include 'connection.php';

// Ensure the student is logged in
if (isset($_SESSION['user_id'])) {
    $studentID = $_SESSION['user_id'];
} else {
    die("Error: No student logged in. Please log in to access your profile.");
}

// Fetch the student's full name and profile picture from the database
$sql = "SELECT full_name, profile_picture FROM students WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (Student Info): " . $conn->error);
}
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

// Check if the student belongs to an approved group
$groupCheckQuery = "
    SELECT g.status, g.id 
    FROM groups g 
    JOIN group_members gm ON g.id = gm.group_id 
    WHERE gm.student_id = ?
";
$stmt = $conn->prepare($groupCheckQuery);
if (!$stmt) {
    die("Prepare failed (Group Check): " . $conn->error);
}
$stmt->bind_param("i", $studentID);
$stmt->execute();
$groupResult = $stmt->get_result();
$groupData = $groupResult->num_rows > 0 ? $groupResult->fetch_assoc() : null;
$stmt->close();

$isGroupApproved = ($groupData && $groupData['status'] === 'Approved');
$groupID = $groupData ? $groupData['id'] : null;

// Fetch the supervisor assigned to the student (only if group is approved)
$supervisorID = null;
if ($isGroupApproved) {
    $supervisorIDQuery = "
        SELECT groups.lecturer_id AS SupervisorID
        FROM groups 
        JOIN group_members ON groups.id = group_members.group_id 
        WHERE group_members.student_id = ? AND groups.status = 'Approved'
    ";
    $stmt = $conn->prepare($supervisorIDQuery);
    if (!$stmt) {
        die("Prepare failed (Supervisor ID): " . $conn->error);
    }
    $stmt->bind_param("i", $studentID);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $supervisorID = $row['SupervisorID'];
    }
    $stmt->close();
}

// Fetch the current semester's start date
$semesterQuery = "SELECT start_date FROM semesters WHERE is_current = 1 LIMIT 1";
$result = $conn->query($semesterQuery);
if (!$result) {
    die("Query failed (Fetch Semester): " . $conn->error);
}
$semester = $result->fetch_assoc();
$semesterStartDate = $semester ? $semester['start_date'] : null;

// Handle form submission for adding, editing, or deleting a diary entry
if ($isGroupApproved && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_edit') {
        $entryID = $_POST['entry_id'] ?? null;
        $date = $_POST['date'] ?? null;
        $title = $_POST['title'] ?? null;
        $description = $_POST['description'] ?? null;

        if ($supervisorID && !empty($title) && !empty($description)) {
            if ($entryID == '0') {
                // Add new entry
                if (empty($date)) {
                    $_SESSION['errorMessage'] = "Date is required for a new diary entry.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }

                // Calculate week number for the submitted date
                $weekNumber = null;
                if ($semesterStartDate) {
                    $entryDate = new DateTime($date);
                    $startDate = new DateTime($semesterStartDate);
                    if ($entryDate >= $startDate) {
                        $daysDifference = $startDate->diff($entryDate)->days;
                        $weekNumber = floor($daysDifference / 7) + 1;
                    } else {
                        $_SESSION['errorMessage'] = "Diary entry date must be after the semester start date.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    }
                } else {
                    $_SESSION['errorMessage'] = "No current semester defined. Cannot add diary entry.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }

                // Check if an entry already exists for this week
                if ($weekNumber) {
                    $weekStart = clone $startDate;
                    $weekStart->modify("+ " . (($weekNumber - 1) * 7) . " days");
                    $weekEnd = clone $weekStart;
                    $weekEnd->modify("+6 days");
                    $weekStartStr = $weekStart->format('Y-m-d');
                    $weekEndStr = $weekEnd->format('Y-m-d');

                    $checkWeekQuery = "
                        SELECT COUNT(*) AS entry_count 
                        FROM diary 
                        WHERE student_id = ? AND entry_date BETWEEN ? AND ?
                    ";
                    $stmt = $conn->prepare($checkWeekQuery);
                    if (!$stmt) {
                        die("Prepare failed (Check Week): " . $conn->error);
                    }
                    $stmt->bind_param("iss", $studentID, $weekStartStr, $weekEndStr);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $entryCount = $row['entry_count'];
                    $stmt->close();

                    if ($entryCount > 0) {
                        $_SESSION['errorMessage'] = "You have already submitted a diary entry for Week $weekNumber.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    }
                }

                // Insert new diary entry
                $insertDiaryQuery = "
                    INSERT INTO diary (student_id, lecturer_id, entry_date, title, diary_content)
                    VALUES (?, ?, ?, ?, ?)
                ";
                $stmt = $conn->prepare($insertDiaryQuery);
                if (!$stmt) {
                    die("Prepare failed (Insert Diary): " . $conn->error);
                }
                $stmt->bind_param("iisss", $studentID, $supervisorID, $date, $title, $description);

                if ($stmt->execute()) {
                    $_SESSION['successMessage'] = "Diary entry added successfully!";
                } else {
                    $_SESSION['errorMessage'] = "Failed to add diary entry. Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                // Edit existing entry
                $updateDiaryQuery = "
                    UPDATE diary 
                    SET title = ?, diary_content = ?, updated_at = NOW()
                    WHERE student_id = ? AND entry_date = ?
                ";
                $stmt = $conn->prepare($updateDiaryQuery);
                if (!$stmt) {
                    die("Prepare failed (Update Diary): " . $conn->error);
                }
                $stmt->bind_param("ssis", $title, $description, $studentID, $entryID);

                if ($stmt->execute()) {
                    $_SESSION['successMessage'] = "Diary entry updated successfully!";
                } else {
                    $_SESSION['errorMessage'] = "Failed to update diary entry. Error: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $_SESSION['errorMessage'] = "Title and description are required, and a supervisor must be assigned to your group.";
        }
    } elseif ($action === 'delete') {
        $entryDate = $_POST['entry_id'] ?? null;

        if ($entryDate) {
            $deleteDiaryQuery = "
                DELETE FROM diary 
                WHERE student_id = ? AND entry_date = ?
            ";
            $stmt = $conn->prepare($deleteDiaryQuery);
            if (!$stmt) {
                die("Prepare failed (Delete Diary): " . $conn->error);
            }
            $stmt->bind_param("is", $studentID, $entryDate);

            if ($stmt->execute()) {
                $_SESSION['successMessage'] = "Diary entry deleted successfully!";
            } else {
                $_SESSION['errorMessage'] = "Failed to delete diary entry. Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['errorMessage'] = "Please select a diary entry to delete.";
        }
    }

    // Redirect to avoid form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch diary entries (display regardless of group status)
$fetchDiaryQuery = "
    SELECT entry_date, title, diary_content, status
    FROM diary
    WHERE student_id = ?
    ORDER BY entry_date DESC
";
$stmt = $conn->prepare($fetchDiaryQuery);
if (!$stmt) {
    die("Prepare failed (Fetch Diary): " . $conn->error);
}
$stmt->bind_param("i", $studentID);
$stmt->execute();
$result = $stmt->get_result();
$diaryEntries = [];
while ($row = $result->fetch_assoc()) {
    // Calculate week number based on semester start date
    $weekNumber = 'N/A';
    if ($semesterStartDate) {
        $entryDate = new DateTime($row['entry_date']);
        $startDate = new DateTime($semesterStartDate);
        if ($entryDate >= $startDate) {
            $daysDifference = $startDate->diff($entryDate)->days;
            $weekNumber = floor($daysDifference / 7) + 1;
        } else {
            $weekNumber = 'Pre-Semester';
        }
    }
    $row['week_number'] = $weekNumber;
    $diaryEntries[] = $row;
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

    <title>Student - Diary Progress</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- Custom styles for this page -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>

<body id="page-top">
    <!-- Page Wrapper -->
    <div id="wrapper">
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="studentdashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="studentdashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">
                Student Portal
            </div>
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
            <li class="nav-item active">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Documentation</span>
                </a>
                <div id="collapseUtilities" class="collapse show" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Support Resources:</h6>
                        <a class="collapse-item active" href="studdiaryprogress.php">Diary Progress</a>
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
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="studprofile.php" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
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
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Diary Progress</h1>
                    </div>

                    <!-- Success/Error Messages -->
                    <?php if (isset($_SESSION['successMessage'])): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['successMessage']) ?></div>
                        <?php unset($_SESSION['successMessage']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['errorMessage'])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['errorMessage']) ?></div>
                        <?php unset($_SESSION['errorMessage']); ?>
                    <?php endif; ?>

                    <!-- Diary Entries Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Diary Entries</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Week</th>
                                            <th>Date</th>
                                            <th>Title</th>
                                            <th>Entry</th>
                                            <th style="width: 200px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tfoot>
                                        <tr>
                                            <th>Week</th>
                                            <th>Date</th>
                                            <th>Title</th>
                                            <th>Entry</th>
                                            <th style="width: 200px;">Action</th>
                                        </tr>
                                    </tfoot>
                                    <tbody>
                                        <?php if (!empty($diaryEntries)): ?>
                                            <?php foreach ($diaryEntries as $entry): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($entry['week_number']); ?></td>
                                                    <td><?= htmlspecialchars($entry['entry_date']); ?></td>
                                                    <td><?= htmlspecialchars($entry['title']); ?></td>
                                                    <td><?= nl2br(htmlspecialchars($entry['diary_content'])); ?></td>
                                                    <td>
                                                        <?php if ($isGroupApproved): ?>
                                                            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this diary entry?');">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="entry_id" value="<?= htmlspecialchars($entry['entry_date']); ?>">
                                                                <button type="submit" class="btn btn-danger btn-icon-split btn-sm">
                                                                    <span class="icon text-white-50">
                                                                        <i class="fas fa-trash"></i>
                                                                    </span>
                                                                    <span class="text">Delete</span>
                                                                </button>
                                                            </form>
                                                            <button type="button" class="btn btn-info btn-icon-split btn-sm" 
                                                                    onclick="openEditModal('<?= htmlspecialchars($entry['entry_date']); ?>', 
                                                                                         '<?= htmlspecialchars(addslashes($entry['title'])); ?>', 
                                                                                         '<?= htmlspecialchars(addslashes($entry['diary_content'])); ?>')">
                                                                <span class="icon text-white-50">
                                                                    <i class="fas fa-edit"></i>
                                                                </span>
                                                                <span class="text">Edit</span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No diary entries found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Card Row -->
                    <div class="row">
                        <div class="col-lg-6">
                            <!-- Add Diary Entry -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Add Diary Entry</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($isGroupApproved): ?>
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="add_edit">
                                            <input type="hidden" name="entry_id" value="0">
                                            <div class="form-group">
                                                <label for="date">Date</label>
                                                <input type="date" class="form-control" id="date" name="date" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="addTitle">Title</label>
                                                <input type="text" class="form-control" id="addTitle" name="title" placeholder="Enter diary title" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="addDescription">Diary Entry</label>
                                                <textarea class="form-control" id="addDescription" name="description" rows="3" placeholder="Enter your diary entry" required></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-icon-split">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-upload"></i>
                                                </span>
                                                <span class="text">Add Entry</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p class="text-muted">You cannot add diary entries until your group is approved by your supervisor.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Diary Modal -->
                    <div class="modal fade" id="editDiaryModal" tabindex="-1" role="dialog" aria-labelledby="editDiaryModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="editDiaryModalLabel">Edit Diary Entry</h5>
                                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </div>
                                <form method="POST" action="">
                                    <div class="modal-body">
                                        <input type="hidden" name="action" value="add_edit">
                                        <input type="hidden" name="entry_id" id="editEntryId">
                                        <div class="form-group">
                                            <label for="editTitle">Title</label>
                                            <input type="text" class="form-control" id="editTitle" name="title" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="editDescription">Diary Entry</label>
                                            <textarea class="form-control" id="editDescription" name="description" rows="6" required></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-info btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-edit"></i>
                                            </span>
                                            <span class="text">Update Entry</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <script>
                    function openEditModal(entryDate, title, content) {
                        document.getElementById('editEntryId').value = entryDate;
                        document.getElementById('editTitle').value = title;
                        document.getElementById('editDescription').value = content;
                        $('#editDiaryModal').modal('show');
                    }
                    </script>
                </div>
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

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Logout Modal-->
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

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <!-- Page level plugins -->
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

    <!-- Page level custom scripts -->
    <script src="js/demo/datatables-demo.js"></script>
</body>
</html>