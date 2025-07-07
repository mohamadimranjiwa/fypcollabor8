<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['user_id'])) {
    die("Error: No student logged in. Please log in to access your profile.");
}
$studentID = $_SESSION['user_id'];

// Verify database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Fetch the student's full name and profile picture
$sql = "SELECT full_name, profile_picture, intake_month, intake_year FROM students WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (student query): " . $conn->error);
}
$stmt->bind_param("i", $studentID);
$stmt->execute();
$result = $stmt->get_result();
$studentDetails = $result->fetch_assoc();
$stmt->close();

if (!$studentDetails) {
    die("Error: No student found with the provided ID.");
}

$personalInfo = [
    'full_name' => $studentDetails['full_name'] ?? 'N/A',
    'profile_picture' => $studentDetails['profile_picture'] ?? 'img/undraw_profile.svg',
];
$loggedInStudentId = $_SESSION['user_id'];
$loggedInStudentIntakeMonth = $studentDetails['intake_month'] ?? null;
$loggedInStudentIntakeYear = $studentDetails['intake_year'] ?? null;

// Fetch the current semester based on is_current
$sql = "SELECT id, semester_name, start_date 
        FROM semesters 
        WHERE is_current = 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (semester query): " . $conn->error . " | SQL: " . $sql);
}
$stmt->execute();
$result = $stmt->get_result();
$currentSemester = $result->fetch_assoc();
$stmt->close();

// Fallback if no active semester is found
if (!$currentSemester) {
    $groupErrorMessage = "No active semester found. Please contact the coordinator to set up a current semester.";
    $autoGroupName = "N/A"; // Placeholder for form display
} else {
    // Generate group name based on semester's start_date
    $semesterStartDate = new DateTime($currentSemester['start_date']);
    $year = $semesterStartDate->format('Y');
    $monthName = $semesterStartDate->format('F'); // e.g., April
    $pattern = $year . $monthName . "%"; // e.g., 2025April%

    // Count existing groups for the current semester to determine the next sequence
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
    $autoGroupName = $year . $monthName . sprintf("%03d", $nextSequence); // e.g., 2025April001
    $stmtPattern->close();
}

$groupSuccessMessage = null;
$groupErrorMessage = isset($groupErrorMessage) ? $groupErrorMessage : null;
$projectSuccessMessage = null;
$projectErrorMessage = null;

// Handle Group Creation with Pending Status and Leader Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_title']) && !isset($_POST['edit_project'])) {
    // Prevent group creation if no active semester
    if (!$currentSemester) {
        $groupErrorMessage = "Cannot create group: No active semester found.";
    } else {
        $groupName = htmlspecialchars($_POST['group_name']);
        $groupMembers = $_POST['group_members'] ?? [];
        $lecturer = intval($_POST['lecturer']);
        $projectTitle = htmlspecialchars($_POST['project_title']);
        $projectDescription = htmlspecialchars($_POST['project_description']);

        $groupMembers[] = $loggedInStudentId;

        if (count($groupMembers) < 2 || count($groupMembers) > 4) {
            $groupErrorMessage = "Group must have 2-4 members (you + 1-3 others).";
        } else {
            $status = 'Pending';
            // Fetch the only coordinator's ID
            $coordinatorResult = $conn->query("SELECT id FROM coordinators LIMIT 1");
            $coordinatorRow = $coordinatorResult ? $coordinatorResult->fetch_assoc() : null;
            $coordinatorId = $coordinatorRow ? $coordinatorRow['id'] : null;
            if (!$coordinatorId) {
                die("No coordinator found in the system. Please contact the administrator.");
            }
            // Include leader_id and coordinator_id in group insertion
            $stmt = $conn->prepare("INSERT INTO groups (name, lecturer_id, coordinator_id, status, leader_id) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                die("Prepare failed (group insert): " . $conn->error);
            }
            $stmt->bind_param("sisis", $groupName, $lecturer, $coordinatorId, $status, $loggedInStudentId);
            if ($stmt->execute()) {
                $groupId = $stmt->insert_id;

                $memberStmt = $conn->prepare("INSERT INTO group_members (group_id, student_id) VALUES (?, ?)");
                if (!$memberStmt) {
                    die("Prepare failed (group members insert): " . $conn->error);
                }
                foreach ($groupMembers as $memberId) {
                    $studentId = intval($memberId);
                    $memberStmt->bind_param("ii", $groupId, $studentId);
                    $memberStmt->execute();
                }
                $memberStmt->close();

                // Insert project without details column
                $projectStmt = $conn->prepare("INSERT INTO projects (group_id, title, description) VALUES (?, ?, ?)");
                if (!$projectStmt) {
                    die("Prepare failed (project insert): " . $conn->error);
                }
                $projectStmt->bind_param("iss", $groupId, $projectTitle, $projectDescription);
                if ($projectStmt->execute()) {
                    $groupSuccessMessage = "Group '$groupName' creation request submitted successfully. Awaiting supervisor approval.";
                } else {
                    $groupErrorMessage = "Group created, but failed to create the project.";
                }
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $groupErrorMessage = "Failed to submit group creation request. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Edit Project Form Submission (Store as Pending, Leader Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_project'])) {
    $groupId = intval($_POST['group_id']);
    $pendingTitle = htmlspecialchars(trim($_POST['project_title']));
    $pendingDescription = htmlspecialchars(trim($_POST['project_description']));

    // Verify the logged-in student is the group leader
    $stmt = $conn->prepare("SELECT leader_id FROM groups WHERE id = ?");
    if (!$stmt) {
        die("Prepare failed (leader check): " . $conn->error);
    }
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();

    if ($group && $group['leader_id'] == $loggedInStudentId) {
        if ($groupId && $pendingTitle && $pendingDescription) {
            $stmt = $conn->prepare("UPDATE projects SET pending_title = ?, pending_description = ? WHERE group_id = ?");
            if (!$stmt) {
                die("Prepare failed (project update): " . $conn->error);
            }
            $stmt->bind_param("ssi", $pendingTitle, $pendingDescription, $groupId);
            if ($stmt->execute()) {
                $projectSuccessMessage = "Project change request submitted successfully. Awaiting lecturer approval.";
            } else {
                $projectErrorMessage = "Failed to submit project change request.";
            }
            $stmt->close();
        } else {
            $projectErrorMessage = "All fields are required.";
        }
    } else {
        $projectErrorMessage = "Only the group leader can submit project change requests.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch Data for Display (Include Leader, Assessor, and Status)
$sql = "SELECT 
            projects.title AS ProjectTitle,
            projects.description AS ProjectDescription,
            projects.pending_title AS PendingTitle,
            projects.pending_description AS PendingDescription,
            groups.name AS GroupName,
            groups.id AS GroupID,
            groups.status AS GroupStatus,
            groups.leader_id AS LeaderID,
            lecturers.full_name AS LecturerName,
            assessor.full_name AS AssessorName,
            roles.role_name AS RoleName,
            students.full_name AS StudentName
        FROM groups
        JOIN group_members ON groups.id = group_members.group_id
        JOIN students ON group_members.student_id = students.id
        LEFT JOIN projects ON groups.id = projects.group_id
        LEFT JOIN lecturers ON groups.lecturer_id = lecturers.id
        LEFT JOIN lecturers AS assessor ON groups.assessor_id = assessor.id
        LEFT JOIN roles ON lecturers.role_id = roles.id
        WHERE students.id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed (project fetch): " . $conn->error);
}
$stmt->bind_param("i", $loggedInStudentId);
$stmt->execute();
$result = $stmt->get_result();

$myGroup = null;
if ($result && $row = $result->fetch_assoc()) { // Student should be in at most one group for this page context
    $myGroup = [
                'ProjectTitle'       => $row['ProjectTitle'],
                'ProjectDescription' => $row['ProjectDescription'],
                'PendingTitle'       => $row['PendingTitle'],
                'PendingDescription' => $row['PendingDescription'],
        'GroupName'          => $row['GroupName'] ?? 'Unnamed Group',
                'GroupID'            => $row['GroupID'],
                'GroupStatus'        => $row['GroupStatus'],
                'LeaderID'           => $row['LeaderID'],
                'LecturerName'       => $row['LecturerName'] ?? 'Not Assigned',
                'AssessorName'       => $row['AssessorName'] ?? 'Not Assigned',
        // 'RoleName'        => $row['RoleName'] ?? 'Not Assigned', // RoleName was from lecturer, not directly needed for myGroup summary
        'Members'            => [] // Initialize members array, to be filled next
    ];
}
$stmt->close();

// If a group was found, fetch all its members
if ($myGroup && isset($myGroup['GroupID'])) {
    $groupId = $myGroup['GroupID'];
    $membersSql = "SELECT s.full_name AS StudentName
                   FROM group_members gm
                   JOIN students s ON gm.student_id = s.id
                   WHERE gm.group_id = ?";
    $membersStmt = $conn->prepare($membersSql);
    if ($membersStmt) {
        $membersStmt->bind_param("i", $groupId);
        $membersStmt->execute();
        $membersResult = $membersStmt->get_result();
        while ($memberRow = $membersResult->fetch_assoc()) {
            $myGroup['Members'][] = $memberRow['StudentName'];
        }
        $membersStmt->close();
    } else {
        error_log("Failed to prepare statement for fetching group members: " . $conn->error);
    }
}

$availableStudents = [];
if ($loggedInStudentIntakeMonth && $loggedInStudentIntakeYear) {
    $sql_available_students = "SELECT s.id, s.full_name 
                               FROM students s
                               LEFT JOIN group_members gm ON s.id = gm.student_id
                               WHERE gm.group_id IS NULL
                               AND s.id != ?
                               AND s.intake_month = ?
                               AND s.intake_year = ?";
    $stmt_available_students = $conn->prepare($sql_available_students);
    if ($stmt_available_students) {
        $stmt_available_students->bind_param("isi", $loggedInStudentId, $loggedInStudentIntakeMonth, $loggedInStudentIntakeYear);
        $stmt_available_students->execute();
        $result_available_students = $stmt_available_students->get_result();
        $availableStudents = $result_available_students->fetch_all(MYSQLI_ASSOC);
        $stmt_available_students->close();
    } else {
        error_log("Prepare failed (available students query): " . $conn->error);
        // Optionally set an error message for the user if this fails
        $groupErrorMessage = $groupErrorMessage ?? "Could not retrieve list of available students due to a database error.";
    }
} else {
    $groupErrorMessage = $groupErrorMessage ?? "Your intake semester is not set. Cannot find available students for group creation.";
}

$groups = $conn->query("SELECT id, name FROM groups")->fetch_all(MYSQLI_ASSOC);
$lecturers = $conn->query("SELECT id, full_name FROM lecturers WHERE role_id IN (3, 4)")->fetch_all(MYSQLI_ASSOC);

function truncateText($text, $charLimit = 30) {
    if (strlen($text) > $charLimit) {
        return substr($text, 0, $charLimit) . '...';
    }
    return $text;
}

// $conn->close(); // Removed from here
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Student - Project Overview</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
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
            <div class="sidebar-heading">Student Portal</div>
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Project Management</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Core Elements:</h6>
                        <a class="collapse-item active" href="studprojectoverview.php">Project Overview</a>
                        <a class="collapse-item" href="studdeliverables.php">Deliverables</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities" aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Documentation</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Support Resources:</h6>
                        <a class="collapse-item" href="studdiaryprogress.php">Diary Progress</a>
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

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
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

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Project Overview</h1>

                    </div>

                    <div class="row">
                        <div class="col-lg-4 xl-3 col-md-6 mb-4">
                            <div class="card border-bottom-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Group Name</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php 
                                                if ($myGroup) {
                                                    echo htmlspecialchars($myGroup['GroupName'] ?? 'No Group Assigned');
                                                    if ($myGroup['GroupStatus'] === 'Pending') {
                                                        echo ' <small class="text-warning">(Pending Approval)</small>';
                                                    }
                                                } else {
                                                    echo 'No Group Assigned';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 xl-3 col-md-6 mb-4">
                            <div class="card border-bottom-info shadow h-100 py-2" data-toggle="modal" data-target="#projectTitleModal" style="cursor: pointer;">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Project Title</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800 truncate">
                                                <?php
                                                if ($myGroup && $myGroup['GroupStatus'] === 'Approved') {
                                                    echo htmlspecialchars(truncateText($myGroup['PendingTitle'] ?? $myGroup['ProjectTitle'] ?? 'No Project Title'));
                                                    if ($myGroup['PendingTitle']) {
                                                        echo '<small class="text-warning">(Pending Approval)</small>';
                                                    }
                                                } else {
                                                    echo htmlspecialchars(truncateText('No Project Title'));
                                                    if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                                                        echo ' <small class="text-warning">(Group Pending Approval)</small>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 xl-3 col-md-6 mb-4">
                            <div class="card border-bottom-info shadow h-100 py-2" data-toggle="modal" data-target="#descriptionModal" style="cursor: pointer;">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Project Description</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800 truncate">
                                                <?php
                                                if ($myGroup && $myGroup['GroupStatus'] === 'Approved') {
                                                    echo htmlspecialchars(truncateText($myGroup['PendingDescription'] ?? $myGroup['ProjectDescription'] ?? 'No Project Description'));
                                                    if ($myGroup['PendingDescription']) {
                                                        echo '<small class="text-warning">(Pending Approval)</small>';
                                                    }
                                                } else {
                                                    echo htmlspecialchars(truncateText('No Project Description'));
                                                    if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                                                        echo ' <small class="text-warning">(Group Pending Approval)</small>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-4 xl-3 col-md-6 mb-4">
                            <div class="card border-bottom-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Supervisor</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php 
                                                if ($myGroup && $myGroup['GroupStatus'] === 'Approved') {
                                                    echo htmlspecialchars($myGroup['LecturerName'] ?? 'Not Assigned');
                                                } else {
                                                    echo 'Not Assigned';
                                                    if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                                                        echo ' <small class="text-warning">(Pending Approval)</small>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-tie fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 xl-3 col-md-6 mb-4">
                            <div class="card border-bottom-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Assessor</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php 
                                                if ($myGroup && $myGroup['GroupStatus'] === 'Approved') {
                                                    echo htmlspecialchars($myGroup['AssessorName'] ?? 'Not Assigned');
                                                } else {
                                                    echo 'Not Assigned';
                                                    if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                                                        echo ' <small class="text-warning">(Pending Approval)</small>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-check fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 xl-3 col-md-6 mb-4">
                            <div class="card border-bottom-info shadow h-100 py-2" data-toggle="modal" data-target="#groupMembersModal" style="cursor: pointer;">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Group Members</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800 truncate">
                                                <?php
                                                if ($myGroup && $myGroup['GroupStatus'] === 'Approved' && !empty($myGroup['Members'])) {
                                                    $membersList = implode(', ', $myGroup['Members']);
                                                    echo htmlspecialchars(truncateText($membersList));
                                                } else {
                                                    echo htmlspecialchars(truncateText('No Members Assigned'));
                                                    if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                                                        echo ' <small class="text-warning">(Pending Approval)</small>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-users fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Create Group</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($groupSuccessMessage) { ?>
                                        <div class="alert alert-success"><?php echo htmlspecialchars($groupSuccessMessage); ?></div>
                                    <?php } ?>
                                    <?php if ($groupErrorMessage) { ?>
                                        <div class="alert alert-danger"><?php echo htmlspecialchars($groupErrorMessage); ?></div>
                                    <?php } ?>
                                    <?php if (!$myGroup && $currentSemester) { ?>
                                        <form method="POST" action="">
                                            <div class="form-group">
                                                <label for="groupName">Group Name</label>
                                                <input type="text" class="form-control" id="groupName" name="group_name" value="<?php echo htmlspecialchars($autoGroupName); ?>" readonly>
                                            </div>
                                            <div class="form-group">
                                                <label for="groupMembers">Select Group Members (1-3 additional students)</label>
                                                <select multiple class="form-control" id="groupMembers" name="group_members[]" required>
                                                    <?php foreach ($availableStudents as $student) { ?>
                                                        <?php if ($student['id'] != $loggedInStudentId) { ?>
                                                            <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['full_name']); ?></option>
                                                        <?php } ?>
                                                    <?php } ?>
                                                </select>
                                                <small class="form-text text-muted">You are automatically included as the leader, so select 1-3 others.</small>
                                            </div>
                                            <div class="form-group">
                                                <label for="supervisor">Select Supervisor</label>
                                                <select class="form-control" id="supervisor" name="lecturer" required>
                                                    <option value="">Select Supervisor</option>
                                                    <?php foreach ($lecturers as $lecturer) { ?>
                                                        <option value="<?php echo $lecturer['id']; ?>"><?php echo htmlspecialchars($lecturer['full_name']); ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="projectTitle">Project Title</label>
                                                <input type="text" class="form-control" id="projectTitle" name="project_title" placeholder="Enter project title" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="projectDescription">Project Description</label>
                                                <textarea class="form-control" id="projectDescription" name="project_description" rows="3" placeholder="Enter project description" required></textarea>
                                            </div>
                                            <button type="submit" name="create_group" class="btn btn-primary btn-icon-split">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-user-plus"></i>
                                                </span>
                                                <span class="text">Submit for Approval</span>
                                            </button>
                                        </form>
                                    <?php } else { ?>
                                        <p class="text-muted">
                                            <?php 
                                            if ($myGroup) {
                                                if ($myGroup['GroupStatus'] === 'Pending') {
                                                    echo "Your group creation request (" . htmlspecialchars($myGroup['GroupName']) . ") is pending supervisor approval.";
                                                } else {
                                                    echo "You are already part of a group (" . htmlspecialchars($myGroup['GroupName']) . "). You cannot create another group.";
                                                }
                                            } else {
                                                echo "Cannot create a group: No active semester found. Please contact the coordinator.";
                                            }
                                            ?>
                                        </p>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Change Project Details</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($projectSuccessMessage) { ?>
                                        <div class="alert alert-success"><?php echo htmlspecialchars($projectSuccessMessage); ?></div>
                                    <?php } ?>
                                    <?php if ($projectErrorMessage) { ?>
                                        <div class="alert alert-danger"><?php echo htmlspecialchars($projectErrorMessage); ?></div>
                                    <?php } ?>
                                    <?php if ($myGroup && $myGroup['LeaderID'] == $loggedInStudentId) { ?>
                                        <form method="POST" action="">
                                            <div class="form-group">
                                                <label for="groupName">Group Name</label>
                                                <input type="text" class="form-control" id="groupName" name="group_name" value="<?php echo htmlspecialchars($myGroup['GroupName'] ?? 'No Group Assigned'); ?>" readonly>
                                            </div>
                                            <div class="form-group">
                                                <label for="editProjectTitle">Project Title</label>
                                                <input type="text" class="form-control" id="editProjectTitle" name="project_title" value="<?php echo htmlspecialchars($myGroup['PendingTitle'] ?? $myGroup['ProjectTitle'] ?? ''); ?>" placeholder="Enter new project title" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="editProjectDescription">Project Description</label>
                                                <textarea class="form-control" id="editProjectDescription" name="project_description" rows="3" placeholder="Enter new project description" required><?php echo htmlspecialchars($myGroup['PendingDescription'] ?? $myGroup['ProjectDescription'] ?? ''); ?></textarea>
                                            </div>
                                            <input type="hidden" name="group_id" value="<?php echo htmlspecialchars($myGroup['GroupID'] ?? ''); ?>">
                                            <button type="submit" name="edit_project" class="btn btn-primary btn-icon-split">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-sync"></i>
                                                </span>
                                                <span class="text">Submit Change Request</span>
                                            </button>
                                        </form>
                                    <?php } else { ?>
                                        <p class="text-muted">
                                            <?php 
                                            if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                                                echo "Your group (" . htmlspecialchars($myGroup['GroupName']) . ") is pending approval. You cannot change project details yet.";
                                            } elseif ($myGroup && $myGroup['LeaderID'] != $loggedInStudentId) {
                                                echo "Only the group leader can change project details.";
                                            } else {
                                                echo "You must be part of an approved group and be the group leader to change project details. Please create a group first.";
                                            }
                                            ?>
                                        </p>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright © Your Website 2021</span>
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

    <div class="modal fade" id="descriptionModal" tabindex="-1" role="dialog" aria-labelledby="descriptionModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="descriptionModalLabel">Project Description</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body" id="fullDescription">
                    <?php
                    if ($myGroup && $myGroup['GroupStatus'] === 'Approved') {
                        echo htmlspecialchars($myGroup['PendingDescription'] ?? $myGroup['ProjectDescription'] ?? 'No Project Description');
                        if ($myGroup['PendingDescription']) {
                            echo '<p class="text-warning mt-2">This description is pending approval.</p>';
                        }
                    } else {
                        echo 'No Project Description';
                        if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                            echo '<p class="text-warning mt-2">Group is pending approval.</p>';
                        }
                    }
                    ?>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="projectTitleModal" tabindex="-1" role="dialog" aria-labelledby="projectTitleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="projectTitleModalLabel">Project Title</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body" id="fullProjectTitle">
                    <?php
                    if ($myGroup && $myGroup['GroupStatus'] === 'Approved') {
                        echo htmlspecialchars($myGroup['PendingTitle'] ?? $myGroup['ProjectTitle'] ?? 'No Project Title');
                        if ($myGroup['PendingTitle']) {
                            echo '<p class="text-warning mt-2">This title is pending approval.</p>';
                        }
                    } else {
                        echo 'No Project Title';
                        if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                            echo '<p class="text-warning mt-2">Group is pending approval.</p>';
                        }
                    }
                    ?>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="groupMembersModal" tabindex="-1" role="dialog" aria-labelledby="groupMembersModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupMembersModalLabel">Group Members</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body" id="fullGroupMembers">
                    <?php
                    if ($myGroup && $myGroup['GroupStatus'] === 'Approved' && !empty($myGroup['Members'])) {
                        echo '<ul class="list-unstyled">';
                        foreach ($myGroup['Members'] as $member) {
                            echo '<li>' . htmlspecialchars($member);
                            if ($myGroup['LeaderID']) { // Check if LeaderID exists
                                $isLeader = false;
                                if ($member === $personalInfo['full_name'] && $myGroup['LeaderID'] == $loggedInStudentId) {
                                    $isLeader = true;
                                } else {
                                    // Fetch leader's name only if necessary and LeaderID is set
                                    $stmt_leader_name = $conn->prepare("SELECT full_name FROM students WHERE id = ?");
                                    if ($stmt_leader_name) {
                                        $stmt_leader_name->bind_param("i", $myGroup['LeaderID']);
                                        $stmt_leader_name->execute();
                                        $result_leader_name = $stmt_leader_name->get_result();
                                        $leader = $result_leader_name->fetch_assoc();
                                        $stmt_leader_name->close();
                                        if ($leader && $member === $leader['full_name']) {
                                            $isLeader = true;
                                        }
                                    } else {
                                        // Log error if prepare fails
                                        error_log("Failed to prepare statement for leader name lookup: " . $conn->error);
                                    }
                                }
                                if ($isLeader) {
                                    echo ' (Leader)';
                                }
                            }
                            echo '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo 'No Members Assigned';
                        if ($myGroup && $myGroup['GroupStatus'] === 'Pending') {
                            echo '<p class="text-warning mt-2">Group is pending approval.</p>';
                        }
                    }
                    ?>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($('#groupMembers').length) { // Check if the element exists
            $('#groupMembers').select2({
                placeholder: "Search and select 1-3 students",
                allowClear: true,
                maximumSelectionLength: 3,
                minimumSelectionLength: 1
            });
            }
        });
    </script>
<?php $conn->close(); ?>
</body>
</html>