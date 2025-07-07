<?php
session_start();
include 'connection.php';

// Ensure the student is logged in
if (!isset($_SESSION['user_id'])) {
    die("Error: No student logged in. Please log in to access your profile.");
}
$studentID = $_SESSION['user_id'];

// Define a predefined color palette
$colorPalette = [
    '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
    '#6610f2', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997'
];

// Fetch all group IDs and assign colors dynamically
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

// Fetch the student's full name and profile picture
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
    'full_name'       => $student['full_name'] ?? 'N/A',
    'profile_picture' => $student['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Check if the student belongs to an approved group and get leader status
$groupCheckQuery = "
    SELECT g.id AS group_id, g.status, g.lecturer_id, g.leader_id, l.full_name AS supervisor_name
    FROM groups g 
    JOIN group_members gm ON g.id = gm.group_id 
    LEFT JOIN lecturers l ON g.lecturer_id = l.id
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

// Determine group status and leader status
$isGroupApproved = ($groupData && $groupData['status'] === 'Approved' && !empty($groupData['lecturer_id']));
$isGroupLeader = ($isGroupApproved && $groupData['leader_id'] == $studentID);
$supervisorID = ($isGroupApproved) ? $groupData['lecturer_id'] : null;
$supervisorName = ($isGroupApproved) ? $groupData['supervisor_name'] : 'N/A';
$groupID = ($isGroupApproved) ? $groupData['group_id'] : null;

// Debug: Log group data
error_log("Student ID: $studentID, isGroupApproved: " . ($isGroupApproved ? 'true' : 'false') . 
         ", isGroupLeader: " . ($isGroupLeader ? 'true' : 'false') . 
         ", supervisorID: " . ($supervisorID ?? 'null') . 
         ", groupID: " . ($groupID ?? 'null'));

// Initialize messages
$scheduleSuccessMessage = '';
$scheduleErrorMessage = '';
$editSuccessMessage = '';
$editErrorMessage = '';

// Handle meeting scheduling and editing (only for group leader)
if ($isGroupApproved && $isGroupLeader && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'schedule') {
        $meetingDate    = $_POST['meeting_date'] ?? '';
        $meetingTime    = $_POST['meeting_time'] ?? '';
        $meetingDetails = htmlspecialchars(trim($_POST['meeting_details'] ?? ''));

        if (empty($meetingDate) || empty($meetingTime) || empty($meetingDetails)) {
            $scheduleErrorMessage = "All fields are required.";
        } elseif (strtotime($meetingDate) < strtotime(date('Y-m-d'))) {
            $scheduleErrorMessage = "Meeting date cannot be in the past.";
        } else {
            if (empty($supervisorID)) {
                $scheduleErrorMessage = "Error: No supervisor assigned to your group. Please contact admin.";
            } else {
                // Verify the lecturer exists
                $stmt = $conn->prepare("SELECT id FROM lecturers WHERE id = ?");
                if (!$stmt) {
                    die("Prepare failed (Verify Lecturer): " . $conn->error);
                }
                $stmt->bind_param("i", $supervisorID);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows == 0) {
                    $scheduleErrorMessage = "Error: Supervisor not found. Please contact admin.";
                } else {
                    $stmt->close();
                    // Check for scheduling conflicts
                    $conflictQuery = "
                        SELECT id FROM meetings 
                        WHERE lecturer_id = ? 
                        AND meeting_date = ? 
                        AND meeting_time = ? 
                        AND status != 'Cancelled'
                    ";
                    $stmt = $conn->prepare($conflictQuery);
                    if (!$stmt) {
                        die("Prepare failed (Conflict Check): " . $conn->error);
                    }
                    $stmt->bind_param("iss", $supervisorID, $meetingDate, $meetingTime);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $scheduleErrorMessage = "Supervisor is already booked at this time. Please choose a different time.";
                    } else {
                        $stmt->close();
                        // Insert meeting with group_id
                        $stmt = $conn->prepare("
                            INSERT INTO meetings (student_id, lecturer_id, meeting_date, meeting_time, title, topic, status, group_id)
                            VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)
                        ");
                        if (!$stmt) {
                            die("Prepare failed (Insert Meeting): " . $conn->error);
                        }
                        $title = "Supervisor Meeting";
                        $stmt->bind_param("iissssi", $studentID, $supervisorID, $meetingDate, $meetingTime, $title, $meetingDetails, $groupID);
                        if ($stmt->execute()) {
                            $scheduleSuccessMessage = "Meeting scheduled successfully!";
                            header("Location: " . $_SERVER['PHP_SELF']);
                            exit();
                        } else {
                            $scheduleErrorMessage = "Failed to schedule meeting: " . $stmt->error;
                        }
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($_POST['action'] === 'edit') {
        $meetingID = intval($_POST['meeting_id'] ?? 0);
        $newDate   = $_POST['edit_meeting_date'] ?? '';
        $newTime   = $_POST['edit_meeting_time'] ?? '';

        if (empty($meetingID) || empty($newDate) || empty($newTime)) {
            $editErrorMessage = "All fields are required.";
        } elseif (strtotime($newDate) < strtotime(date('Y-m-d'))) {
            $editErrorMessage = "New meeting date cannot be in the past.";
        } else {
            // Check for scheduling conflicts on edit
            $conflictQuery = "
                SELECT id FROM meetings 
                WHERE lecturer_id = ? 
                AND meeting_date = ? 
                AND meeting_time = ? 
                AND status != 'Cancelled'
                AND id != ?
            ";
            $stmt = $conn->prepare($conflictQuery);
            if (!$stmt) {
                die("Prepare failed (Edit Conflict Check): " . $conn->error);
            }
            $stmt->bind_param("issi", $supervisorID, $newDate, $newTime, $meetingID);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $editErrorMessage = "Supervisor is already booked at this time. Please choose a different time.";
            } else {
                $stmt->close();
                // Update meeting
                $stmt = $conn->prepare("
                    UPDATE meetings 
                    SET meeting_date = ?, meeting_time = ?, status = 'Pending' 
                    WHERE id = ? AND student_id = ?
                ");
                if (!$stmt) {
                    die("Prepare failed (Edit Meeting): " . $conn->error);
                }
                $stmt->bind_param("ssii", $newDate, $newTime, $meetingID, $studentID);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $editSuccessMessage = "Meeting updated successfully! Awaiting supervisor approval.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $editErrorMessage = "No meeting found with the provided ID or no changes made.";
                    }
                } else {
                    $editErrorMessage = "Failed to update meeting: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Check if group_id column exists in meetings table
$columnCheckQuery = "SHOW COLUMNS FROM meetings LIKE 'group_id'";
$result = $conn->query($columnCheckQuery);
if ($result->num_rows == 0) {
    die("Error: The 'group_id' column is missing in the 'meetings' table. Please add it using: ALTER TABLE meetings ADD group_id INT NULL, ADD FOREIGN KEY (group_id) REFERENCES groups(id);");
}

// Fetch meetings for the student's group (for Meeting History modal and Edit Meeting form)
$groupMeetings = [];
if ($isGroupApproved && $groupID) {
    $groupMeetingsQuery = "
        SELECT m.id, m.title, m.meeting_date, m.meeting_time, m.topic, m.status, m.lecturer_id, 
               l.full_name AS lecturer_name, s.full_name AS student_name, m.student_id, 
               m.group_id, COALESCE(g.name, 'Unknown') AS group_name
        FROM meetings m
        INNER JOIN lecturers l ON m.lecturer_id = l.id
        INNER JOIN students s ON m.student_id = s.id
        LEFT JOIN groups g ON m.group_id = g.id
        WHERE m.group_id = ? AND m.status != 'Cancelled'
        ORDER BY m.meeting_date ASC
    ";
    $stmt = $conn->prepare($groupMeetingsQuery);
    if (!$stmt) {
        die("Prepare failed (Group Meetings Query): " . $conn->error);
    }
    $stmt->bind_param("i", $groupID);
    if (!$stmt->execute()) {
        die("Execute failed (Group Meetings Query): " . $stmt->error);
    }
    $result = $stmt->get_result();
    $groupMeetings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch all meetings for the supervisor (for Calendar)
$supervisorMeetings = [];
if ($isGroupApproved && $supervisorID) {
    $supervisorMeetingsQuery = "
        SELECT m.id, m.title, m.meeting_date, m.meeting_time, m.topic, m.status, m.lecturer_id, 
               l.full_name AS lecturer_name, s.full_name AS student_name, m.student_id, 
               m.group_id, COALESCE(g.name, 'Unknown') AS group_name
        FROM meetings m
        INNER JOIN lecturers l ON m.lecturer_id = l.id
        INNER JOIN students s ON m.student_id = s.id
        LEFT JOIN groups g ON m.group_id = g.id
        WHERE m.lecturer_id = ? AND m.status != 'Cancelled'
        ORDER BY m.meeting_date ASC
    ";
    $stmt = $conn->prepare($supervisorMeetingsQuery);
    if (!$stmt) {
        die("Prepare failed (Supervisor Meetings Query): " . $conn->error);
    }
    $stmt->bind_param("i", $supervisorID);
    if (!$stmt->execute()) {
        die("Execute failed (Supervisor Meetings Query): " . $stmt->error);
    }
    $result = $stmt->get_result();
    $supervisorMeetings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

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

    <title>Student - Meeting Schedule</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        #meetingDate, #editMeetingDate {
            cursor: pointer;
        }
        /* Ensure modal table matches history modal styling */
        #calendarEventTable th, #calendarEventTable td {
            vertical-align: middle;
        }
        #calendarEventTable .confirmed { color: #28a745; }
        #calendarEventTable .pending { color: #ffc107; }
        #calendarEventTable .cancelled { color: #dc3545; }
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
                        <a class="collapse-item" href="studdiaryprogress.php">Diary Progress</a>
                        <a class="collapse-item" href="studteachingmaterials.php">Teaching Materials</a>
                        <a class="collapse-item active" href="studmeetingschedule.php">Meeting Schedule</a>
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
                        <h1 class="h3 mb-0 text-gray-800">Meeting Schedule</h1>
                    </div>
                    <?php if ($isGroupApproved): ?>
                    <div class="row">
                        <div class="col-xl-12 col-lg-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold text-primary">Meeting Schedule</h6>
                                    <button type="button" class="btn btn-sm btn-primary btn-icon-split" data-toggle="modal" data-target="#meetingHistoryModal">
                                        <span class="icon text-white-50">
                                            <i class="fas fa-history"></i>
                                        </span>
                                        <span class="text">View Meeting History</span>
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div id="calendar"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <div class="col-xl-12 col-lg-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Meeting Schedule</h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">You cannot view the meeting schedule until your group is approved by your supervisor.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Meeting History Modal -->
                    <div class="modal fade" id="meetingHistoryModal" tabindex="-1" role="dialog" aria-labelledby="meetingHistoryModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="meetingHistoryModalLabel">Meeting Details/History</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="meetingHistoryTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Group Name</th>
                                                    <th>Meeting Date</th>
                                                    <th>Meeting Time</th>
                                                    <th>Topic</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($isGroupApproved && !empty($groupMeetings)): ?>
                                                <?php foreach ($groupMeetings as $meeting): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($meeting['student_name']) ?></td>
                                                    <td><?= htmlspecialchars($meeting['group_name']) ?></td>
                                                    <td><?= htmlspecialchars($meeting['meeting_date']) ?></td>
                                                    <td><?= htmlspecialchars($meeting['meeting_time']) ?></td>
                                                    <td><?= htmlspecialchars($meeting['topic']) ?></td>
                                                    <td class="<?= strtolower($meeting['status']) ?>">
                                                        <?php if (strtolower($meeting['status']) === 'confirmed'): ?>
                                                        <span class="text-success"><?= htmlspecialchars($meeting['status']) ?></span>
                                                        <?php elseif (strtolower($meeting['status']) === 'pending'): ?>
                                                        <span class="text-warning"><?= htmlspecialchars($meeting['status']) ?></span>
                                                        <?php elseif (strtolower($meeting['status']) === 'cancelled'): ?>
                                                        <span class="text-danger"><?= htmlspecialchars($meeting['status']) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">No meetings scheduled.</td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- New Calendar Event Modal -->
                    <div class="modal fade" id="calendarEventModal" tabindex="-1" role="dialog" aria-labelledby="calendarEventModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="calendarEventModalLabel">Meeting Details</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="calendarEventTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Group Name</th>
                                                    <th>Meeting Date</th>
                                                    <th>Meeting Time</th>
                                                    <th>Topic</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="calendarEventTableBody">
                                                <!-- Populated dynamically via JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Schedule Meeting</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($isGroupApproved && $isGroupLeader): ?>
                                    <?php if (!empty($scheduleSuccessMessage)): ?>
                                    <p style="color: green;">
                                        <?= htmlspecialchars($scheduleSuccessMessage) ?>
                                    </p>
                                    <?php elseif (!empty($scheduleErrorMessage)): ?>
                                    <p style="color: red;">
                                        <?= htmlspecialchars($scheduleErrorMessage) ?>
                                    </p>
                                    <?php endif; ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="schedule">
                                        <div class="form-group">
                                            <label for="meetingDate">Meeting Date</label>
                                            <input type="text" class="form-control" id="meetingDate" name="meeting_date" placeholder="Click to select a date" readonly required>
                                        </div>
                                        <div class="form-group">
                                            <label for="meetingTime">Meeting Time</label>
                                            <input type="time" class="form-control" id="meetingTime" name="meeting_time" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="meetingWith">Meeting With</label>
                                            <input type="text" class="form-control" id="meetingWith" value="<?= htmlspecialchars($supervisorName) ?>" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label for="meetingDetails">Meeting Details</label>
                                            <textarea class="form-control" id="meetingDetails" name="meeting_details" rows="3" placeholder="Enter meeting details" required></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-calendar-plus"></i>
                                            </span>
                                            <span class="text">Schedule Meeting</span>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <p class="text-muted">Only group leaders can schedule meetings. <?php if (!$isGroupApproved): ?>Your group must be approved by your supervisor first.<?php endif; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($isGroupApproved): ?>
                            <div class="modal fade" id="lecturerCalendarModal" tabindex="-1" role="dialog" aria-labelledby="lecturerCalendarModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-lg" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="lecturerCalendarModalLabel">Supervisor Availability</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <div id="lecturerCalendar"></div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Edit Meeting</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($isGroupApproved && $isGroupLeader && !empty($groupMeetings)): ?>
                                    <?php if (!empty($editSuccessMessage)): ?>
                                    <p style="color: green;">
                                        <?= htmlspecialchars($editSuccessMessage) ?>
                                    </p>
                                    <?php elseif (!empty($editErrorMessage)): ?>
                                    <p style="color: red;">
                                        <?= htmlspecialchars($editErrorMessage) ?>
                                    </p>
                                    <?php endif; ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="edit">
                                        <div class="form-group">
                                            <label for="existingMeeting">Select Meeting</label>
                                            <select class="form-control" id="existingMeeting" name="meeting_id" required>
                                                <?php 
                                                if (is_array($groupMeetings) && !empty($groupMeetings)) {
                                                    foreach ($groupMeetings as $meeting) {
                                                        if ($meeting['student_id'] == $studentID) {
                                                            $meetingId = htmlspecialchars($meeting['id'] ?? '');
                                                            $meetingDate = htmlspecialchars($meeting['meeting_date'] ?? '');
                                                            $meetingTopic = htmlspecialchars($meeting['topic'] ?? 'Untitled');
                                                            echo "<option value=\"{$meetingId}\" data-lecturer-id=\"{$meeting['lecturer_id']}\">{$meetingDate} - {$meetingTopic}</option>";
                                                        }
                                                    }
                                                } else {
                                                    echo '<option value="">No meetings available</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="editMeetingDate">New Meeting Date</label>
                                            <input type="text" class="form-control" id="editMeetingDate" name="edit_meeting_date" placeholder="Click to select a date" readonly required>
                                        </div>
                                        <div class="form-group">
                                            <label for="editMeetingTime">New Meeting Time</label>
                                            <input type="time" class="form-control" id="editMeetingTime" name="edit_meeting_time" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="editLecturerName">Meeting With</label>
                                            <input type="text" class="form-control" id="editLecturerName" value="<?= htmlspecialchars($supervisorName) ?>" readonly>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-edit"></i>
                                            </span>
                                            <span class="text">Update Meeting</span>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <p class="text-muted">Only group leaders can edit meetings. <?php if (!$isGroupApproved): ?>Your group must be approved by your supervisor first.<?php elseif (empty($groupMeetings)): ?>No meetings available to edit.<?php endif; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($isGroupApproved): ?>
                            <div class="modal fade" id="editLecturerCalendarModal" tabindex="-1" role="dialog" aria-labelledby="editLecturerCalendarModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-lg" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="editLecturerCalendarModalLabel">Supervisor Availability</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <div id="editLecturerCalendar"></div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Your Website 2021</span>
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
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
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
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <!-- Pass groupColors to JavaScript -->
    <script>
        const groupColors = <?php echo json_encode($groupColors); ?>;
    </script>
    <script>
    <?php if ($isGroupApproved): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            height: 'auto',
            events: [
                <?php foreach ($supervisorMeetings as $meeting): ?>{
                    title: '<?= htmlspecialchars($meeting['topic']) ?>',
                    start: '<?= htmlspecialchars($meeting['meeting_date']) ?>T<?= htmlspecialchars($meeting['meeting_time']) ?>',
                    extendedProps: {
                        studentName: '<?= htmlspecialchars($meeting['student_name']) ?>',
                        groupName: '<?= htmlspecialchars($meeting['group_name']) ?>',
                        meetingDate: '<?= htmlspecialchars($meeting['meeting_date']) ?>',
                        meetingTime: '<?= htmlspecialchars($meeting['meeting_time']) ?>',
                        topic: '<?= htmlspecialchars($meeting['topic']) ?>',
                        status: '<?= htmlspecialchars($meeting['status']) ?>'
                    },
                    backgroundColor: groupColors['<?= $meeting['group_id'] ?>'] || groupColors['default'],
                    borderColor: groupColors['<?= $meeting['group_id'] ?>'] || groupColors['default']
                },
                <?php endforeach; ?>
            ],
            eventClick: function(info) {
                // Clear previous table content
                const tableBody = document.getElementById('calendarEventTableBody');
                tableBody.innerHTML = '';

                // Create table row
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${info.event.extendedProps.studentName}</td>
                    <td>${info.event.extendedProps.groupName}</td>
                    <td>${info.event.extendedProps.meetingDate}</td>
                    <td>${info.event.extendedProps.meetingTime}</td>
                    <td>${info.event.extendedProps.topic}</td>
                    <td class="${info.event.extendedProps.status.toLowerCase()}">
                        <span class="${info.event.extendedProps.status.toLowerCase() === 'confirmed' ? 'text-success' : 
                                      info.event.extendedProps.status.toLowerCase() === 'pending' ? 'text-warning' : 'text-danger'}">
                            ${info.event.extendedProps.status}
                        </span>
                    </td>
                `;
                tableBody.appendChild(row);

                // Show the modal
                $('#calendarEventModal').modal('show');
            }
        });
        calendar.render();
    });
    <?php endif; ?>
    </script>
    <script>
    <?php if ($isGroupApproved): ?>
    $(document).ready(function() {
        const meetingDateInput = $("#meetingDate");
        let lecturerCalendar = null;

        meetingDateInput.on("click", function() {
            console.log('Meeting date input clicked, supervisorID: <?php echo json_encode($supervisorID); ?>');
            $("#lecturerCalendarModal").modal("show");
        });

        function renderLecturerCalendar(lecturerId) {
            const lecturerCalendarEl = document.getElementById('lecturerCalendar');
            if (lecturerCalendar) {
                lecturerCalendar.destroy();
            }
            lecturerCalendar = new FullCalendar.Calendar(lecturerCalendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                events: function(fetchInfo, successCallback, failureCallback) {
                    console.log('Fetching meetings for lecturer_id: ' + lecturerId);
                    $.ajax({
                        url: 'lectmanagemeetings.php',
                        method: 'POST',
                        data: { lecturer_id: lecturerId },
                        dataType: 'json',
                        success: function(data) {
                            console.log('AJAX response:', data);
                            const events = data.map(meeting => ({
                                title: meeting.topic + ' (' + meeting.meeting_time + ')',
                                start: meeting.meeting_date,
                                backgroundColor: groupColors[meeting.group_id] || groupColors['default'],
                                borderColor: groupColors[meeting.group_id] || groupColors['default']
                            }));
                            successCallback(events);
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error:', status, error, xhr.responseText);
                            alert('Failed to load supervisor availability. Please try again.');
                            failureCallback();
                        }
                    });
                },
                selectable: true,
                select: function(info) {
                    const selectedDate = info.startStr;
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    if (new Date(selectedDate) >= today) {
                        meetingDateInput.val(selectedDate);
                        $("#lecturerCalendarModal").modal("hide");
                    } else {
                        alert('Cannot select a past date.');
                    }
                },
                eventClick: function(info) {
                    alert('Supervisor Meeting: ' + info.event.title + '\nDate: ' + info.event.startStr);
                }
            });
            lecturerCalendar.render();
        }

        $('#lecturerCalendarModal').on('shown.bs.modal', function () {
            const lecturerId = <?php echo json_encode($supervisorID); ?>;
            console.log('Lecturer calendar modal opened, lecturerId: ' + lecturerId);
            if (lecturerId) {
                renderLecturerCalendar(lecturerId);
            } else {
                console.error('No lecturerId provided');
                alert('Error: No supervisor assigned. Please contact admin.');
            }
        });
    });
    <?php endif; ?>
    </script>
    <script>
    <?php if ($isGroupApproved): ?>
    $(document).ready(function() {
        const editMeetingDateInput = $("#editMeetingDate");
        let editLecturerCalendar = null;

        const meetings = <?php echo json_encode($groupMeetings); ?>;
        if (meetings.length > 0) {
            $("#editLecturerName").val(meetings[0].lecturer_name || 'N/A');
            $("#existingMeeting").val(meetings[0].id || '');
        }

        $("#existingMeeting").on("change", function() {
            const selectedId = $(this).val();
            const selected = meetings.find(m => m.id == selectedId);
            if (selected) {
                $("#editLecturerName").val(selected.lecturer_name || 'N/A');
                const lecturerId = $(this).find('option:selected').data('lecturer-id');
                if (lecturerId && editLecturerCalendar) {
                    renderEditLecturerCalendar(lecturerId);
                }
            } else {
                $("#editLecturerName").val('');
            }
        });

        editMeetingDateInput.on("click", function() {
            console.log('Edit meeting date input clicked');
            $("#editLecturerCalendarModal").modal("show");
        });

        function renderEditLecturerCalendar(lecturerId) {
            const editCalendarEl = document.getElementById('editLecturerCalendar');
            if (editLecturerCalendar) {
                editLecturerCalendar.destroy();
            }
            editLecturerCalendar = new FullCalendar.Calendar(editCalendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                events: function(fetchInfo, successCallback, failureCallback) {
                    console.log('Fetching meetings for edit, lecturer_id: ' + lecturerId);
                    $.ajax({
                        url: 'lectmanagemeetings.php',
                        method: 'POST',
                        data: { lecturer_id: lecturerId },
                        dataType: 'json',
                        success: function(data) {
                            console.log('Edit AJAX response:', data);
                            const events = data.map(meeting => ({
                                title: meeting.topic + ' (' + meeting.meeting_time + ')',
                                start: meeting.meeting_date,
                                backgroundColor: groupColors[meeting.group_id] || groupColors['default'],
                                borderColor: groupColors[meeting.group_id] || groupColors['default']
                            }));
                            successCallback(events);
                        },
                        error: function(xhr, status, error) {
                            console.error('Edit AJAX error:', status, error, xhr.responseText);
                            alert('Failed to load supervisor availability. Please try again.');
                            failureCallback();
                        }
                    });
                },
                selectable: true,
                select: function(info) {
                    const selectedDate = info.startStr;
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    if (new Date(selectedDate) >= today) {
                        editMeetingDateInput.val(selectedDate);
                        $("#editLecturerCalendarModal").modal("hide");
                    } else {
                        alert('Cannot select a past date.');
                    }
                },
                eventClick: function(info) {
                    alert('Supervisor Meeting: ' + info.event.title + '\nDate: ' + info.event.startStr);
                }
            });
            editLecturerCalendar.render();
        }

        $('#editLecturerCalendarModal').on('shown.bs.modal', function () {
            const selectedId = $('#existingMeeting').val();
            const selected = meetings.find(m => m.id == selectedId);
            console.log('Edit lecturer calendar modal opened, selected meeting ID: ' + selectedId);
            if (selected && selected.lecturer_id) {
                renderEditLecturerCalendar(selected.lecturer_id);
            } else {
                console.error('No lecturer_id for selected meeting');
                alert('Error: No supervisor assigned for this meeting. Please contact admin.');
            }
        });
    });
    <?php endif; ?>
    </script>
</body>
</html>