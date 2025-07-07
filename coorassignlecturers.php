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

// Fetch lecturers with counts of groups they supervise and assess, group names, and student names
$lecturersQuery = "
    SELECT 
        l.id,
        l.full_name,
        COUNT(DISTINCT g_sup.id) AS supervisor_count,
        COUNT(DISTINCT g_ass.id) AS assessor_count,
        GROUP_CONCAT(DISTINCT g_sup.name SEPARATOR '|') AS supervised_groups,
        GROUP_CONCAT(DISTINCT g_ass.name SEPARATOR '|') AS assessed_groups,
        GROUP_CONCAT(DISTINCT CASE 
            WHEN g_sup.id IS NOT NULL THEN 
                CONCAT(g_sup.id, ':', g_sup.name, ':', 
                    (SELECT GROUP_CONCAT(s.full_name SEPARATOR ',') 
                     FROM group_members gm 
                     LEFT JOIN students s ON gm.student_id = s.id 
                     WHERE gm.group_id = g_sup.id 
                     LIMIT 4))
            ELSE NULL 
        END SEPARATOR '|') AS supervised_group_students,
        GROUP_CONCAT(DISTINCT CASE 
            WHEN g_ass.id IS NOT NULL THEN 
                CONCAT(g_ass.id, ':', g_ass.name, ':', 
                    (SELECT GROUP_CONCAT(s.full_name SEPARATOR ',') 
                     FROM group_members gm 
                     LEFT JOIN students s ON gm.student_id = s.id 
                     WHERE gm.group_id = g_ass.id 
                     LIMIT 4))
            ELSE NULL 
        END SEPARATOR '|') AS assessed_group_students
    FROM lecturers l
    LEFT JOIN groups g_sup ON l.id = g_sup.lecturer_id
    LEFT JOIN groups g_ass ON l.id = g_ass.assessor_id
    WHERE l.role_id IN (2, 3, 4)
    GROUP BY l.id, l.full_name
    ORDER BY l.full_name ASC";
$lecturersResult = $conn->query($lecturersQuery) or die("Error in lecturers query: " . htmlspecialchars($conn->error));
$lecturers = $lecturersResult->fetch_all(MYSQLI_ASSOC);

// Fetch groups with supervisors, assessors, and student count
$groupsQuery = "
    SELECT 
        g.id, 
        g.name, 
        g.status,
        ls.full_name AS supervisor_name, 
        la.full_name AS assessor_name, 
        COUNT(gm.student_id) AS student_count,
        p.project_id,
        p.title AS project_title,
        p.description AS project_description,
        GROUP_CONCAT(s.full_name SEPARATOR ', ') AS group_members
    FROM groups g
    LEFT JOIN lecturers ls ON g.lecturer_id = ls.id
    LEFT JOIN projects p ON g.id = p.group_id
    LEFT JOIN lecturers la ON g.assessor_id = la.id
    LEFT JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN students s ON gm.student_id = s.id
    GROUP BY g.id, g.name, ls.full_name, la.full_name, p.project_id, p.title, p.description
    ORDER BY g.name ASC";
$groupsResult = $conn->query($groupsQuery) or die("Error in groups query: " . htmlspecialchars($conn->error));
$groups = $groupsResult->fetch_all(MYSQLI_ASSOC);

// Fetch supervisors (role_id = 4 or 3)
$supervisorsQuery = "SELECT id, full_name FROM lecturers WHERE role_id IN (4, 3) ORDER BY full_name ASC";
$supervisorsResult = $conn->query($supervisorsQuery) or die("Error in supervisors query: " . htmlspecialchars($conn->error));
$supervisors = $supervisorsResult->fetch_all(MYSQLI_ASSOC);

// Fetch assessors (role_id = 2 or 3)
$assessorsQuery = "SELECT id, full_name FROM lecturers WHERE role_id IN (2, 3) ORDER BY full_name ASC";
$assessorsResult = $conn->query($assessorsQuery) or die("Error in assessors query: " . htmlspecialchars($conn->error));
$assessors = $assessorsResult->fetch_all(MYSQLI_ASSOC);

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_role'])) {
    $group_id = intval($_POST['group_id']);
    $lecturer_id = intval($_POST['lecturer_id']);
    $role = $_POST['role']; // 'supervisor' or 'assessor'

    $column = $role === 'supervisor' ? 'lecturer_id' : 'assessor_id';
    $sql = "UPDATE groups SET $column = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $lecturer_id, $group_id);
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>" . ucfirst($role) . " assigned successfully!</div>";
        header("Refresh:0");
    } else {
        $message = "<div class='alert alert-danger'>Failed to assign " . ucfirst($role) . ": " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
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
    <title>Coordinator - Assign Supervisors & Assessors</title>

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
        #assignModal .modal-body {
            font-size: 1rem;
            line-height: 1.5;
        }
        #groupListModal .modal-body .table {
            table-layout: fixed;
        }
        #groupListModal .modal-body .table th.group-name {
            width: 20%;
        }
        #groupListModal .modal-body .table th.student-name {
            width: 20%;
        }
        #groupDetailsModal .modal-body .table th {
            width: 30%;
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
                        <h6 class="collapse-header">Staff and Student <br>Oversight:</h6>
                        <a class="collapse-item active" href="coorassignlecturers.php">Assign Supervisors & <br>Assessors</a>
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
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" onerror="this.src='img/undraw_profile.svg';">
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
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Assign Supervisors & Assessors</h1>
                        <a href="coorassigngroups.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-download fa-sm text-white-50"></i> Assign Groups
                        </a>
                    </div>
                    <?= $message ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Lecturers Assignment Overview</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Lecturer Name</th>
                                            <th>Supervisor</th>
                                            <th>Assessor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($lecturers)): ?>
                                            <?php foreach ($lecturers as $lecturer): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($lecturer['full_name']) ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($lecturer['supervisor_count']) ?>
                                                        <?php if ($lecturer['supervisor_count'] > 0): ?>
                                                            <i class="fas fa-info-circle info-icon" 
                                                               data-toggle="modal" 
                                                               data-target="#groupListModal"
                                                               data-role="Supervisor"
                                                               data-lecturer-name="<?= htmlspecialchars($lecturer['full_name']) ?>"
                                                               data-groups="<?= htmlspecialchars($lecturer['supervised_group_students'] ?? 'None') ?>"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($lecturer['assessor_count']) ?>
                                                        <?php if ($lecturer['assessor_count'] > 0): ?>
                                                            <i class="fas fa-info-circle info-icon" 
                                                               data-toggle="modal" 
                                                               data-target="#groupListModal"
                                                               data-role="Assessor"
                                                               data-lecturer-name="<?= htmlspecialchars($lecturer['full_name']) ?>"
                                                               data-groups="<?= htmlspecialchars($lecturer['assessed_group_students'] ?? 'None') ?>"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="3" class="text-center">No lecturers found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Group Assignments</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="groupsTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Group Name</th>
                                            <th>Student Count</th>
                                            <th>Supervisor</th>
                                            <th>Assessor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($groups)): ?>
                                            <?php foreach ($groups as $group): ?>
                                                <tr>
                                                    <td>
                                                        <?= htmlspecialchars($group['name']) ?>
                                                        <i class="fas fa-info-circle info-icon group-details-icon" 
                                                           data-toggle="modal" 
                                                           data-target="#groupDetailsModal"
                                                           data-group-name="<?= htmlspecialchars($group['name'] ?? 'N/A') ?>"
                                                           data-status="<?= htmlspecialchars($group['status'] ?? 'N/A') ?>"
                                                           data-project-title="<?= htmlspecialchars($group['project_title'] ?? 'N/A') ?>"
                                                           data-project-description="<?= htmlspecialchars($group['project_description'] ?? 'N/A') ?>"
                                                           data-members="<?= htmlspecialchars($group['group_members'] ?? 'None') ?>"
                                                           data-supervisor="<?= htmlspecialchars($group['supervisor_name'] ?? 'Not Assigned') ?>"
                                                           data-assessor="<?= htmlspecialchars($group['assessor_name'] ?? 'Not Assigned') ?>"></i>
                                                    </td>
                                                    <td><?= htmlspecialchars($group['student_count']) ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($group['supervisor_name'] ?? 'Not Assigned') ?>
                                                        <?php if (!$group['supervisor_name']): ?>
                                                            <button class="btn btn-primary btn-sm assign-button" 
                                                                    data-toggle="modal" 
                                                                    data-target="#assignModal"
                                                                    data-group-id="<?= $group['id'] ?>"
                                                                    data-group-name="<?= htmlspecialchars($group['name']) ?>"
                                                                    data-role="supervisor">
                                                                Assign
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($group['assessor_name'] ?? 'Not Assigned') ?>
                                                        <?php if (!$group['assessor_name']): ?>
                                                            <button class="btn btn-primary btn-sm assign-button" 
                                                                    data-toggle="modal" 
                                                                    data-target="#assignModal"
                                                                    data-group-id="<?= $group['id'] ?>"
                                                                    data-group-name="<?= htmlspecialchars($group['name']) ?>"
                                                                    data-role="assessor">
                                                                Assign
                                                            </button>
                                                        <?php endif; ?>
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

    <!-- Group List Modal -->
    <div class="modal fade" id="groupListModal" tabindex="-1" role="dialog" aria-labelledby="groupListModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupListModalLabel">Group List</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p><strong>Lecturer:</strong> <span id="modal-lecturer-name"></span></p>
                    <p><strong>Role:</strong> <span id="modal-role"></span></p>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th class="group-name">Group Name</th>
                                    <th class="student-name">Student 1</th>
                                    <th class="student-name">Student 2</th>
                                    <th class="student-name">Student 3</th>
                                    <th class="student-name">Student 4</th>
                                </tr>
                            </thead>
                            <tbody id="modal-groups-table"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Group Details Modal -->
    <div class="modal fade" id="groupDetailsModal" tabindex="-1" role="dialog" aria-labelledby="groupDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupDetailsModalLabel">Group Details</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <table class="table table-bordered">
                        <tbody>
                            <tr>
                                <th>Group Name</th>
                                <td id="modal-group-name"></td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td id="modal-status"></td>
                            </tr>
                            <tr>
                                <th>Project Title</th>
                                <td id="modal-project-title"></td>
                            </tr>
                            <tr>
                                <th>Project Description</th>
                                <td id="modal-project-description"></td>
                            </tr>
                            <tr>
                                <th>Group Members</th>
                                <td id="modal-members"></td>
                            </tr>
                            <tr>
                                <th>Supervisor</th>
                                <td id="modal-supervisor"></td>
                            </tr>
                            <tr>
                                <th>Assessor</th>
                                <td id="modal-assessor"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assignment Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignModalLabel">Assign Role</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="assignForm" method="POST">
                        <div class="form-group">
                            <label for="group_name">Group</label>
                            <input type="text" class="form-control" id="group_name" readonly>
                            <input type="hidden" name="group_id" id="group_id">
                            <input type="hidden" name="role" id="role">
                        </div>
                        <div class="form-group">
                            <label for="lecturer_id">Select Lecturer</label>
                            <select class="form-control" id="lecturer_id" name="lecturer_id" required>
                                <option value="">-- Select Lecturer --</option>
                                <!-- Options will be populated dynamically -->
                            </select>
                        </div>
                        <input type="hidden" name="assign_role" value="1">
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" id="submitAssignButton">Assign</button>
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
        $(document).ready(function() {
            // Initialize DataTable for lecturers table
            if (!$.fn.DataTable.isDataTable('#dataTable')) {
                console.log('Initializing DataTable for #dataTable');
                $('#dataTable').DataTable({
                    "columnDefs": [
                        { "orderable": false, "targets": [1, 2] } // Disable sorting on SV and Ass columns
                    ]
                });
            } else {
                console.log('DataTable already initialized for #dataTable');
            }

            // Initialize DataTable for groups table
            if (!$.fn.DataTable.isDataTable('#groupsTable')) {
                console.log('Initializing DataTable for #groupsTable');
                $('#groupsTable').DataTable({
                    "columnDefs": [
                        { "orderable": false, "targets": [0, 2, 3] } // Disable sorting on Group Name, Supervisor, Assessor
                    ]
                });
            } else {
                console.log('DataTable already initialized for #groupsTable');
            }

            // Handle group list modal for lecturers
            $(document).on('click', '.info-icon[data-target="#groupListModal"]', function() {
                var lecturerName = $(this).data('lecturer-name');
                var role = $(this).data('role');
                var groupsData = $(this).data('groups').split('|');

                $('#groupListModalLabel').text(role + ' Groups for ' + lecturerName);
                $('#modal-lecturer-name').text(lecturerName);
                $('#modal-role').text(role);

                // Populate groups table
                var groupsTable = $('#modal-groups-table');
                groupsTable.empty();

                if (groupsData[0] !== 'None' && groupsData[0] !== '') {
                    groupsData.forEach(function(groupInfo) {
                        var parts = groupInfo.split(':');
                        var groupName = parts[1] || '';
                        var students = parts[2] ? parts[2].split(',') : [];
                        while (students.length < 4) {
                            students.push('');
                        }
                        var row = '<tr>' +
                            '<td>' + (groupName ? groupName : 'N/A') + '</td>' +
                            '<td>' + (students[0] ? students[0] : '') + '</td>' +
                            '<td>' + (students[1] ? students[1] : '') + '</td>' +
                            '<td>' + (students[2] ? students[2] : '') + '</td>' +
                            '<td>' + (students[3] ? students[3] : '') + '</td>' +
                            '</tr>';
                        groupsTable.append(row);
                    });
                } else {
                    groupsTable.append('<tr><td colspan="5" class="text-center">No groups assigned.</td></tr>');
                }

                console.log('Opening group list modal for ' + lecturerName + ' (' + role + ')');
            });

            // Handle group details modal for groups
            $(document).on('click', '.group-details-icon[data-target="#groupDetailsModal"]', function() {
                var groupName = $(this).data('group-name') || 'N/A';
                var status = $(this).data('status') || 'N/A';
                var projectTitle = $(this).data('project-title') || 'N/A';
                var projectDescription = $(this).data('project-description') || 'N/A';
                var members = $(this).data('members') || 'None';
                var supervisor = $(this).data('supervisor') || 'Not Assigned';
                var assessor = $(this).data('assessor') || 'Not Assigned';

                console.log('Populating group details modal:', {
                    groupName: groupName,
                    status: status,
                    projectTitle: projectTitle,
                    projectDescription: projectDescription,
                    members: members,
                    supervisor: supervisor,
                    assessor: assessor
                });

                $('#modal-group-name').text(groupName);
                $('#modal-status').text(status);
                $('#modal-project-title').text(projectTitle);
                $('#modal-project-description').text(projectDescription);
                $('#modal-members').text(members);
                $('#modal-supervisor').text(supervisor);
                $('#modal-assessor').text(assessor);

                $('#groupDetailsModal').modal('show');
            });

            // Populate assignment modal
            $(document).on('click', '.assign-button', function() {
                var groupId = $(this).data('group-id');
                var groupName = $(this).data('group-name');
                var role = $(this).data('role');

                $('#assignModalLabel').text('Assign ' + (role === 'supervisor' ? 'Supervisor' : 'Assessor') + ' for ' + groupName);
                $('#group_name').val(groupName);
                $('#group_id').val(groupId);
                $('#role').val(role);

                // Populate lecturer dropdown based on role
                var lecturerSelect = $('#lecturer_id');
                lecturerSelect.empty();
                lecturerSelect.append('<option value="">-- Select ' + (role === 'supervisor' ? 'Supervisor' : 'Assessor') + ' --</option>');

                var lecturers = role === 'supervisor' ? <?= json_encode($supervisors) ?> : <?= json_encode($assessors) ?>;
                lecturers.forEach(function(lecturer) {
                    lecturerSelect.append('<option value="' + lecturer.id + '">' + lecturer.full_name + '</option>');
                });

                console.log('Opening assign modal for group: ' + groupName + ', role: ' + role);
            });

            // Handle assignment submission
            $('#submitAssignButton').on('click', function() {
                var form = $('#assignForm');
                var lecturerId = $('#lecturer_id').val();

                if (!lecturerId) {
                    alert('Please select a lecturer.');
                    return;
                }

                form.submit();
                $('#assignModal').modal('hide');
            });
        });
    </script>
</body>
</html>