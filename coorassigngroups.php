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

// Fetch the coordinator's details
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (coordinator query): " . $conn->error);
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

// Initialize message
$message = "";

// Fetch the current semester
$sql = "SELECT id, semester_name, start_date FROM semesters WHERE is_current = 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (semester query): " . $conn->error);
}
$stmt->execute();
$result = $stmt->get_result();
$currentSemester = $result->fetch_assoc();
$stmt->close();

// Generate automatic group name
if (!$currentSemester) {
    $groupErrorMessage = "No active semester found. Please contact the administrator to set up a current semester.";
    $autoGroupName = "N/A";
} else {
    $semesterStartDate = new DateTime($currentSemester['start_date']);
    $year = $semesterStartDate->format('Y');
    $monthName = $semesterStartDate->format('F');
    $pattern = $year . $monthName . "%";

    $stmtPattern = $conn->prepare("SELECT COUNT(*) as count FROM groups WHERE name LIKE ?");
    if (!$stmtPattern) {
        die("Prepare failed (group count query): " . $conn->error);
    }
    $stmtPattern->bind_param("s", $pattern);
    $stmtPattern->execute();
    $resultPattern = $stmtPattern->get_result();
    $rowPattern = $resultPattern->fetch_assoc();
    $count = $rowPattern['count'];
    $nextSequence = $count + 1;
    $autoGroupName = $year . $monthName . sprintf("%03d", $nextSequence);
    $stmtPattern->close();
}

// Fetch supervisors for dropdown
$supervisorsQuery = "SELECT id, full_name FROM lecturers WHERE role_id IN (3, 4) ORDER BY full_name ASC";
$supervisorsResult = $conn->query($supervisorsQuery) or die("Error in supervisors query: " . $conn->error);
$supervisors = $supervisorsResult->fetch_all(MYSQLI_ASSOC);

// Fetch students who are not assigned to any group
$unassignedStudentsQuery = "
    SELECT s.id, s.full_name, s.email
    FROM students s
    LEFT JOIN group_members gm ON s.id = gm.student_id
    WHERE gm.student_id IS NULL
    ORDER BY s.full_name ASC";
$unassignedStudentsResult = $conn->query($unassignedStudentsQuery) or die("Error in unassigned students query: " . $conn->error);
$unassignedStudents = $unassignedStudentsResult->fetch_all(MYSQLI_ASSOC);

// Fetch existing groups with student count, excluding groups with 4 students for assignment
$groupsQuery = "
    SELECT g.id, g.name, COUNT(gm.student_id) AS student_count, GROUP_CONCAT(s.full_name SEPARATOR ', ') AS group_members
    FROM groups g
    LEFT JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN students s ON gm.student_id = s.id
    GROUP BY g.id, g.name
    HAVING student_count < 4
    ORDER BY g.name ASC";
$groupsResult = $conn->query($groupsQuery) or die("Error in groups query: " . $conn->error);
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);

// Fetch all groups for display
$allGroupsQuery = "
    SELECT g.id, g.name, COUNT(gm.student_id) AS student_count, GROUP_CONCAT(s.full_name SEPARATOR ', ') AS group_members
    FROM groups g
    LEFT JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN students s ON gm.student_id = s.id
    GROUP BY g.id, g.name
    ORDER BY g.name ASC";
$allGroupsResult = $conn->query($allGroupsQuery) or die("Error in all groups query: " . $conn->error);
$allGroups = $allGroupsResult->fetch_all(MYSQLI_ASSOC);

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    if (!$currentSemester) {
        $message = "<div class='alert alert-danger'>Cannot create group: No active semester found.</div>";
    } else {
        $group_name = $autoGroupName;
        $lecturer_id = intval($_POST['lecturer_id']);
        $sql = "INSERT INTO groups (name, status, coordinator_id, lecturer_id) VALUES (?, 'Pending', ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $message = "<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>";
        } else {
            $stmt->bind_param("sii", $group_name, $coordinatorID, $lecturer_id);
            if ($stmt->execute()) {
                $group_id = $stmt->insert_id;
                // Always create a project row for the group
                $insertProjectStmt = $conn->prepare("INSERT INTO projects (group_id, title, description) VALUES (?, '', '')");
                if ($insertProjectStmt) {
                    $insertProjectStmt->bind_param("i", $group_id);
                    $insertProjectStmt->execute();
                    $insertProjectStmt->close();
                }
                $message = "<div class='alert alert-success'>Group '$group_name' created successfully! Awaiting supervisor approval.</div>";
                header("Refresh:0");
            } else {
                $message = "<div class='alert alert-danger'>Failed to create group: " . $stmt->error . "</div>";
            }
            $stmt->close();
        }
    }
}

// Handle assigning a student to an existing group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_group'])) {
    $student_id = intval($_POST['student_id']);
    $group_id = intval($_POST['group_id']);

    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE student_id = ?");
    if (!$checkStmt) {
        $message = "<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>";
    } else {
    $checkStmt->bind_param("i", $student_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $checkRow = $checkResult->fetch_assoc();
    $checkStmt->close();

    $groupCheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE group_id = ?");
        if (!$groupCheckStmt) {
            $message = "<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>";
        } else {
    $groupCheckStmt->bind_param("i", $group_id);
    $groupCheckStmt->execute();
    $groupCheckResult = $groupCheckStmt->get_result();
    $groupCheckRow = $groupCheckResult->fetch_assoc();
    $groupCheckStmt->close();

    if ($checkRow['count'] > 0) {
        $message = "<div class='alert alert-danger'>Student is already assigned to a group.</div>";
    } elseif ($groupCheckRow['count'] >= 4) {
        $message = "<div class='alert alert-danger'>Selected group already has 4 students.</div>";
    } else {
        $sql = "INSERT INTO group_members (group_id, student_id) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $message = "<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>";
                } else {
        $stmt->bind_param("ii", $group_id, $student_id);
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Student assigned to group successfully!</div>";
                        // Automatically assign as leader if group has no leader yet
                        $leaderCheckStmt = $conn->prepare("SELECT leader_id FROM groups WHERE id = ?");
                        if ($leaderCheckStmt) {
                            $leaderCheckStmt->bind_param("i", $group_id);
                            $leaderCheckStmt->execute();
                            $leaderCheckResult = $leaderCheckStmt->get_result();
                            $leaderRow = $leaderCheckResult->fetch_assoc();
                            $leaderCheckStmt->close();

                            if ($leaderRow && (empty($leaderRow['leader_id']) || $leaderRow['leader_id'] == 0)) {
                                $setLeaderStmt = $conn->prepare("UPDATE groups SET leader_id = ? WHERE id = ?");
                                if ($setLeaderStmt) {
                                    $setLeaderStmt->bind_param("ii", $student_id, $group_id);
                                    $setLeaderStmt->execute();
                                    $setLeaderStmt->close();
                                }
                            }
                        }
            // Ensure a project row exists for the group
            $projectCheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM projects WHERE group_id = ?");
            if ($projectCheckStmt) {
                $projectCheckStmt->bind_param("i", $group_id);
                $projectCheckStmt->execute();
                $projectCheckResult = $projectCheckStmt->get_result();
                $projectCheckRow = $projectCheckResult->fetch_assoc();
                $projectCheckStmt->close();

                if ($projectCheckRow['count'] == 0) {
                    $insertProjectStmt = $conn->prepare("INSERT INTO projects (group_id, title, description) VALUES (?, '', '')");
                    if ($insertProjectStmt) {
                        $insertProjectStmt->bind_param("i", $group_id);
                        $insertProjectStmt->execute();
                        $insertProjectStmt->close();
                    }
                }
            }
            header("Refresh:0");
        } else {
                        $message = "<div class='alert alert-danger'>Failed to assign student: " . $stmt->error . "</div>";
        }
        $stmt->close();
                }
            }
        }
    }
}

// Handle group deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    error_log("Delete group POST data: " . print_r($_POST, true) . " at " . date('Y-m-d H:i:s'));
    
    $group_id = isset($_POST['delete_group_id']) ? intval($_POST['delete_group_id']) : 0;
    
    if ($group_id <= 0) {
        error_log("Invalid group ID received: $group_id at " . date('Y-m-d H:i:s'));
        $message = "<div class='alert alert-danger'>Error: Invalid group ID provided.</div>";
    } else {
        error_log("Attempting to delete group ID: $group_id");

    $conn->begin_transaction();
    try {
            $stmt = $conn->prepare("DELETE FROM meetings WHERE group_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (meetings): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete meetings: " . $stmt->error);
            error_log("Deleted meetings for group ID: $group_id");
        
            $stmt = $conn->prepare("DELETE FROM deliverable_submissions WHERE group_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (submissions): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete submissions: " . $stmt->error);
            error_log("Deleted submissions for group ID: $group_id");
        
            $stmt = $conn->prepare("
                DELETE gers FROM group_evaluation_rubric_scores gers
                                     INNER JOIN group_evaluations ge ON gers.group_evaluation_id = ge.id 
                WHERE ge.group_id = ?
            ");
            if (!$stmt) throw new Exception("Prepare failed (evaluation scores): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete evaluation scores: " . $stmt->error);
            error_log("Deleted evaluation scores for group ID: $group_id");
        
            $stmt = $conn->prepare("DELETE FROM group_evaluations WHERE group_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (evaluations): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete evaluations: " . $stmt->error);
            error_log("Deleted evaluations for group ID: $group_id");
        
            $stmt = $conn->prepare("DELETE FROM projects WHERE group_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (projects): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete projects: " . $stmt->error);
            error_log("Deleted projects for group ID: $group_id");
        
            $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (members): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete members: " . $stmt->error);
            error_log("Deleted members for group ID: $group_id");
        
            $stmt = $conn->prepare("DELETE FROM groups WHERE id = ?");
            if (!$stmt) throw new Exception("Prepare failed (group): " . $conn->error);
        $stmt->bind_param("i", $group_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete group: " . $stmt->error);
            if ($stmt->affected_rows === 0) {
                throw new Exception("Group ID $group_id does not exist or was already deleted");
            }
            error_log("Deleted group ID: $group_id");

        $conn->commit();
            error_log("Transaction committed for group ID: $group_id");
            $message = "<div class='alert alert-success'>Group deleted successfully!</div>";
            header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
        exit();
    } catch (Exception $e) {
        $conn->rollback();
            error_log("Error deleting group ID $group_id: " . $e->getMessage());
            $message = "<div class='alert alert-danger'>Error deleting group: " . htmlspecialchars($e->getMessage()) . "</div>";
        } finally {
            if (isset($stmt) && $stmt) {
                $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <title>Coordinator - Assign Supervisors & Assessors > Assign Groups</title>

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
        .modal-body .table {
            width: 100%;
            margin-bottom: 0;
        }
        .modal-body .table th {
            font-weight: bold;
            vertical-align: middle;
            background-color: #f8f9fc;
            text-align: center;
        }
        .modal-body .table td {
            vertical-align: middle;
            word-wrap: break-word;
            text-align: center;
        }
        .modal-body .table tr {
            border-bottom: 1px solid #e3e6f0;
        }
        #assignGroupModal .modal-body,
        #deleteGroupModal .modal-body {
            font-size: 1rem;
            line-height: 1.5;
        }
        .card-spacing {
            margin-bottom: 2.5rem;
        }
        .delete-group-button {
            pointer-events: auto !important;
            opacity: 1 !important;
            z-index: 100 !important;
            cursor: pointer !important;
        }
        .table-responsive {
            position: relative;
            z-index: 1;
        }
        .delete-group-button:disabled {
            cursor: not-allowed;
            opacity: 0.65;
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student <br> Oversight:</h6>
                        <a class="collapse-item active" href="coorassignlecturers.php">Assign Supervisors &<br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">FYP Evaluation:</h6>
                        <a class="collapse-item" href="coorviewfypcomponents.php">View Student <br>Submissions</a>
                        <a class="collapse-item" href="coorviewstudentdetails.php">View Student Details</a>
                        <a class="collapse-item" href="coormanagerubrics.php">Manage Rubrics</a>
                        <a class="collapse-item" href="coorassignassessment.php">Assign Assessment</a>
                        <!-- <a class="collapse-item" href="coorevaluatestudent.php">Evaluate Students</a> -->
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
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
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo htmlspecialchars($personalInfo['full_name']); ?></span>
                                <img class="img-profile rounded-circle" src="<?php echo htmlspecialchars($personalInfo['profile_picture']); ?>" onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
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
                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Assign Supervisors & Assessors > Assign Groups</h1>
                        <a href="coorassignlecturers.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back to Assign Supervisors & Assessors
                        </a>
                    </div>
                    <?php echo $message; ?>
                    
                    <!-- Create Group Card -->
                    <div class="card shadow mb-4 card-spacing">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Create New Group</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($currentSemester): ?>
                                <form method="POST" action="">
                                    <div class="form-group">
                                        <label for="group_name">Group Name</label>
                                        <input type="text" class="form-control" id="group_name" name="group_name" value="<?php echo htmlspecialchars($autoGroupName); ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="lecturer_id">Assign Supervisor</label>
                                        <select class="form-control" id="lecturer_id" name="lecturer_id" required>
                                            <option value="">Select Supervisor</option>
                                            <?php foreach (
                                                $supervisors as $sup): ?>
                                                <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['full_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" name="create_group" class="btn btn-primary btn-icon-split">
                                        <span class="icon text-white-50">
                                            <i class="fas fa-user-plus"></i>
                                        </span>
                                        <span class="text">Create Group</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <p class="text-muted">Cannot create a group: No active semester found. Please set a current semester in the system.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Unassigned Students Table -->
                    <div class="card shadow mb-4 card-spacing">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Unassigned Students</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="unassignedStudentsTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Email</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($unassignedStudents)): ?>
                                            <?php foreach ($unassignedStudents as $student): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                                    <td>
                                                        <button class="btn btn-primary btn-sm assign-group-button" 
                                                                data-student-id="<?php echo htmlspecialchars($student['id']); ?>"
                                                                data-student-name="<?php echo htmlspecialchars($student['full_name']); ?>">
                                                            Assign Group
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="3" class="text-center">No unassigned students found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Existing Groups Table -->
                    <div class="card shadow mb-4 card-spacing">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Existing Groups</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="groupsTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Group Name</th>
                                            <th>Student Count</th>
                                            <th>Group Members</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($allGroups)): ?>
                                            <?php foreach ($allGroups as $group): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($group['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($group['student_count']); ?> Student(s)</td>
                                                    <td><?php echo htmlspecialchars($group['group_members'] ?? 'None'); ?></td>
                                                    <td>
                                                        <button class="btn btn-danger btn-sm delete-group-button"
                                                                data-group-id="<?php echo htmlspecialchars($group['id']); ?>"
                                                                data-group-name="<?php echo htmlspecialchars($group['name']); ?>">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center">No groups found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End of Content Wrapper -->

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
            <!-- End of Main Content -->
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

    <!-- Assign Group Modal -->
    <div class="modal fade" id="assignGroupModal" tabindex="-1" role="dialog" aria-labelledby="assignGroupModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignGroupModalLabel">Assign Group</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                    <form id="assignGroupForm" method="POST" action="">
                <div class="modal-body">
                        <div class="form-group">
                            <label for="student_name">Student Name</label>
                            <input type="text" class="form-control" id="student_name" readonly>
                            <input type="hidden" name="student_id" id="student_id">
                        </div>
                        <div class="form-group">
                            <label for="group_id">Select Group</label>
                            <select class="form-control" id="group_id" name="group_id" required>
                                    <option value="">Select Group</option>
                                <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo htmlspecialchars($group['id']); ?>">
                                            <?php echo htmlspecialchars($group['name']); ?> (<?php echo htmlspecialchars($group['student_count']); ?> students)
                                        </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="assign_group" value="1">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Assign</button>
                        </div>
                    </form>
            </div>
        </div>
    </div>

    <!-- Delete Group Modal -->
    <div class="modal fade" id="deleteGroupModal" tabindex="-1" role="dialog" aria-labelledby="deleteGroupModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteGroupModalLabel">Confirm Group Deletion</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete group <strong id="deleteGroupName"></strong>?</p>
                        <p class="text-danger">Warning: This action cannot be undone. All associated data (members, projects, submissions, evaluations) will be permanently deleted.</p>
                        <form id="deleteGroupForm" method="POST" action="">
                            <input type="hidden" name="delete_group_id" id="deleteGroupId" value="">
                            <input type="hidden" name="delete_group" value="1">
                </div>
                <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Group</button>
                    </form>
                    </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script>if (!window.jQuery) { document.write('<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"><\/script>'); }</script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
        <script src="vendor/jquery-easing/jquery.easing.min.js" onerror="console.error('jQuery Easing failed to load');"></script>
        <script src="js/sb-admin-2.min.js" onerror="console.error('SB Admin script failed to load');"></script>
        <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Bind events
            $(document).on('click', '.delete-group-button', function(e) {
                e.preventDefault();
                var groupId = $(this).attr('data-group-id');
                var groupName = $(this).attr('data-group-name');
                console.log('Delete button clicked: groupId=' + groupId + ', groupName=' + groupName);
                if (!groupId || isNaN(groupId) || parseInt(groupId) <= 0) {
                    console.error('Invalid group ID: ' + groupId);
                    alert('Error: Invalid group ID. Please try again.');
                    return;
                }
                $('#deleteGroupName').text(groupName);
                $('#deleteGroupId').val(groupId);
                try {
                    $('#deleteGroupModal').modal('show');
                } catch (modalError) {
                    console.error('Error opening modal: ', modalError);
                    alert('Error: Unable to open delete modal. Check console for details.');
                }
            });

            $(document).on('click', '.assign-group-button', function() {
                var studentId = $(this).attr('data-student-id');
                var studentName = $(this).attr('data-student-name');
                console.log('Assign button clicked: studentId=' + studentId + ', studentName=' + studentName);
                $('#assignGroupModalLabel').text('Assign Group for ' + studentName);
                $('#student_name').val(studentName);
                $('#student_id').val(studentId);
                try {
                    $('#assignGroupModal').modal('show');
                } catch (error) {
                    console.error('Error opening assign modal: ', error);
                    alert('Error: Unable to open assign modal.');
                }
            });

            $('#deleteGroupForm').on('submit', function(e) {
                var groupId = $('#deleteGroupId').val();
                console.log('Delete group form submitted: groupId=' + groupId);
                if (!groupId || parseInt(groupId) <= 0) {
                    console.error('Form submission with invalid group ID: ' + groupId);
                    alert('Error: No group selected for deletion.');
                    e.preventDefault();
                }
            });

            // Initialize DataTables
            try {
                if (typeof $.fn.DataTable !== 'undefined') {
                    $('#groupsTable').DataTable({
                        pageLength: 10,
                        searching: true,
                        paging: true,
                        ordering: true
                    });
                    $('#unassignedStudentsTable').DataTable({
                        pageLength: 10,
                        searching: true,
                        paging: true,
                        ordering: true,
                        columnDefs: [{ orderable: false, targets: 2 }]
                    });
                } else {
                    console.error('DataTables not loaded.');
                }
            } catch (error) {
                console.error('Error initializing DataTables: ', error);
            }
        });
    </script>
</body>
</html>