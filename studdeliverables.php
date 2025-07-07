<?php
session_start();
include 'connection.php';

// Ensure the student is logged in
if (isset($_SESSION['user_id'])) {
    $studentID = $_SESSION['user_id'];
} else {
    die("Error: No student logged in. Please log in to access your profile.");
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Fetch group details, including group id, status, and leader_id
$groupQuery = "SELECT g.id, g.status, g.leader_id 
               FROM groups g 
               JOIN group_members gm ON g.id = gm.group_id 
               WHERE gm.student_id = ?";
$stmt = $conn->prepare($groupQuery);
if (!$stmt) {
    die("Prepare failed (Group Info): " . $conn->error);
}
$stmt->bind_param("i", $studentID);
$stmt->execute();
$result = $stmt->get_result();
$groupData = $result->fetch_assoc();
$stmt->close();

$isGroupApproved = false;
$groupID = null;
$isGroupLeader = false;
if ($groupData) {
    $groupID = $groupData['id'];
    $groupStatus = $groupData['status'];
    $isGroupApproved = ($groupStatus === 'Approved');
    $isGroupLeader = ($groupData['leader_id'] === $studentID);
}

// Fetch the student's intake semester
$studentIntakeSemester = '';
$sql_student_semester = "SELECT intake_month, intake_year FROM students WHERE id = ?";
$stmt_student_semester = $conn->prepare($sql_student_semester);
if ($stmt_student_semester) {
    $stmt_student_semester->bind_param("i", $studentID);
    $stmt_student_semester->execute();
    $result_student_semester = $stmt_student_semester->get_result();
    if ($row_student_semester = $result_student_semester->fetch_assoc()) {
        if (!empty($row_student_semester['intake_month']) && !empty($row_student_semester['intake_year'])) {
            $studentIntakeSemester = $row_student_semester['intake_month'] . ' ' . $row_student_semester['intake_year'];
        }
    }
    $stmt_student_semester->close();
} else {
    error_log("Prepare failed (Fetch Student Intake Semester): " . $conn->error);
}

// Fetch available deliverables for the student's intake semester, including submission_type
$availableDeliverables = [];
if (!empty($studentIntakeSemester)) {
    $deliverablesQuery = "SELECT d.id, d.name, d.semester, d.submission_type 
                         FROM deliverables d 
                         WHERE d.semester = ?";
    $stmt_deliverables = $conn->prepare($deliverablesQuery);
    if ($stmt_deliverables) {
        $stmt_deliverables->bind_param("s", $studentIntakeSemester);
        $stmt_deliverables->execute();
        $result_deliverables = $stmt_deliverables->get_result();
        if (!$result_deliverables) {
            die("Query failed (Fetch Deliverables): " . $conn->error);
        }
        while ($row = $result_deliverables->fetch_assoc()) {
            $availableDeliverables[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'semester' => $row['semester'],
                'submission_type' => $row['submission_type']
            ];
        }
        $stmt_deliverables->close();
    } else {
        error_log("Prepare failed (Fetch Deliverables for Student Semester): " . $conn->error);
    }
} else {
    // Handle case where student intake semester couldn't be determined
    // You might want to set an error message or log this
    $_SESSION['error_message'] = "Could not determine your registration semester. Please contact support.";
}

// Handle form submissions (only if group is approved)
if ($isGroupApproved && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $deliverableID = $_POST['deliverable_id'] ?? null;
    $deliverableName = $_POST['deliverable_name'] ?? null;

    // Validate deliverable exists and get submission_type
    if ($deliverableID) {
        $checkDeliverableQuery = "SELECT name, semester, submission_type FROM deliverables WHERE id = ?";
        $checkStmt = $conn->prepare($checkDeliverableQuery);
        if (!$checkStmt) {
            die("Prepare failed (Validate Deliverable): " . $conn->error);
        }
        $checkStmt->bind_param("i", $deliverableID);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
            $_SESSION['error_message'] = "Invalid deliverable selected.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        $deliverable = $checkResult->fetch_assoc();
        $deliverableName = $deliverable['name'];
        $submissionType = $deliverable['submission_type'];
        $checkStmt->close();
    } else {
        $_SESSION['error_message'] = "No deliverable selected.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Check if action is allowed (only leader for group deliverables)
    if ($submissionType === 'group' && !$isGroupLeader) {
        $_SESSION['error_message'] = "Only the group leader can perform actions on group deliverables.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $file = $_FILES['file'] ?? null;

    // --- Upload or Edit Action ---
    if ($action === 'upload' || $action === 'edit') {
        // For upload: Check if submission is allowed
        if ($action === 'upload') {
            $checkQuery = $submissionType === 'individual'
                ? "SELECT * FROM deliverable_submissions WHERE deliverable_id = ? AND student_id = ?"
                : "SELECT * FROM deliverable_submissions WHERE deliverable_id = ? AND group_id = ?";
            $checkStmt = $conn->prepare($checkQuery);
            if (!$checkStmt) {
                die("Prepare failed (Check Existing Submission): " . $conn->error);
            }
            if ($submissionType === 'individual') {
                $checkStmt->bind_param("ii", $deliverableID, $studentID);
            } else {
                $checkStmt->bind_param("ii", $deliverableID, $groupID);
            }
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            if ($checkResult->num_rows > 0) {
                $_SESSION['error_message'] = $submissionType === 'individual'
                    ? "You have already submitted '$deliverableName'."
                    : "Deliverable '$deliverableName' has already been submitted by your group.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
            $checkStmt->close();
        } else { // action === 'edit'
            // Validate that the submission exists
            $checkQuery = $submissionType === 'individual'
                ? "SELECT file_path FROM deliverable_submissions WHERE deliverable_id = ? AND student_id = ?"
                : "SELECT file_path FROM deliverable_submissions WHERE deliverable_id = ? AND group_id = ?";
            $checkStmt = $conn->prepare($checkQuery);
            if (!$checkStmt) {
                die("Prepare failed (Check Existing Submission for Edit): " . $conn->error);
            }
            if ($submissionType === 'individual') {
                $checkStmt->bind_param("ii", $deliverableID, $studentID);
            } else {
                $checkStmt->bind_param("ii", $deliverableID, $groupID);
            }
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            if ($checkResult->num_rows === 0) {
                $_SESSION['error_message'] = "No submission found for '$deliverableName' to update.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
            // Delete the old file
            $oldFileRow = $checkResult->fetch_assoc();
            $oldFilePath = $oldFileRow['file_path'];
            if (file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }
            $checkStmt->close();
        }

        // Check file upload
        if ($file && file_exists($file['tmp_name'])) {
            $targetDir = "Uploads/";
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = $deliverableName . '_' . ($submissionType === 'individual' ? $studentID : $groupID) . '_' . time() . '.' . $fileExtension;
            $filePath = $targetDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                if ($action === 'upload') {
                    // Insert the new submission
                    $stmt = $conn->prepare("INSERT INTO deliverable_submissions 
                                            (student_id, deliverable_id, deliverable_name, file_path, group_id) 
                                            VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        die("Prepare failed (Insert Submission): " . $conn->error);
                    }
                    $stmt->bind_param("iissi", $studentID, $deliverableID, $deliverableName, $filePath, $groupID);
                } else { // action === 'edit'
                    // Update the submission
                    $updateQuery = $submissionType === 'individual'
                        ? "UPDATE deliverable_submissions SET file_path = ?, submitted_at = NOW(), student_id = ? WHERE deliverable_id = ? AND student_id = ?"
                        : "UPDATE deliverable_submissions SET file_path = ?, submitted_at = NOW(), student_id = ? WHERE deliverable_id = ? AND group_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    if (!$stmt) {
                        die("Prepare failed (Update Submission): " . $conn->error);
                    }
                    if ($submissionType === 'individual') {
                        $stmt->bind_param("siii", $filePath, $studentID, $deliverableID, $studentID);
                    } else {
                        $stmt->bind_param("siii", $filePath, $studentID, $deliverableID, $groupID);
                    }
                }

                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Deliverable '$deliverableName' " . ($action === 'upload' ? 'submitted' : 'updated') . " successfully!";
                } else {
                    $_SESSION['error_message'] = "Failed to save submission to the database.";
                }
                $stmt->close();
            } else {
                $_SESSION['error_message'] = "Failed to upload file.";
            }
        } else {
            $_SESSION['error_message'] = "No file selected or file upload error.";
        }
    }
    // --- Delete Action ---
    elseif ($action === 'delete') {
        $deleteQuery = $submissionType === 'individual'
            ? "DELETE FROM deliverable_submissions WHERE deliverable_id = ? AND student_id = ?"
            : "DELETE FROM deliverable_submissions WHERE deliverable_id = ? AND group_id = ?";
        $stmt = $conn->prepare($deleteQuery);
        if (!$stmt) {
            die("Prepare failed (Delete Submission): " . $conn->error);
        }
        if ($submissionType === 'individual') {
            $stmt->bind_param("ii", $deliverableID, $studentID);
        } else {
            $stmt->bind_param("ii", $deliverableID, $groupID);
        }
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Deliverable '$deliverableName' deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to delete submission from the database.";
        }
        $stmt->close();
    }

    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch existing submissions for the group and student
$fetchQuery = "SELECT ds.deliverable_id, ds.deliverable_name, ds.file_path, ds.submitted_at, ds.student_id, d.submission_type 
               FROM deliverable_submissions ds 
               JOIN deliverables d ON ds.deliverable_id = d.id 
               WHERE ds.group_id = ?";
$stmt = $conn->prepare($fetchQuery);
if (!$stmt) {
    die("Prepare failed (Fetching Submissions): " . $conn->error);
}
$stmt->bind_param("i", $groupID);
$stmt->execute();
$result = $stmt->get_result();

$submissions = [];
while ($row = $result->fetch_assoc()) {
    if ($row['submission_type'] === 'individual' && $row['student_id'] !== $studentID) {
        continue; // Skip other students' individual submissions
    }
    $submissions[$row['deliverable_id']][] = $row;
}
$stmt->close();

// Prepare the deliverables array for display
$deliverables = [];
if (empty($availableDeliverables)) {
    $deliverables = []; // Will trigger "No deliverables found" message
} else {
    foreach ($availableDeliverables as $deliverable) {
        $submissionData = $submissions[$deliverable['id']] ?? [];
        if ($deliverable['submission_type'] === 'individual') {
            // Show only the logged-in student's submission
            $studentSubmission = null;
            foreach ($submissionData as $submission) {
                if ($submission['student_id'] === $studentID) {
                    $studentSubmission = $submission;
                    break;
                }
            }
            $deliverables[] = [
                'id' => $deliverable['id'],
                'deliverable_id' => $deliverable['id'],
                'deliverable_name' => $deliverable['name'],
                'file_path' => $studentSubmission['file_path'] ?? null,
                'submitted_at' => $studentSubmission['submitted_at'] ?? null,
                'semester' => $deliverable['semester'],
                'submission_type' => $deliverable['submission_type']
            ];
        } else {
            // Show the group's submission (first submission, if any)
            $groupSubmission = !empty($submissionData) ? $submissionData[0] : null;
            $deliverables[] = [
                'id' => $deliverable['id'],
                'deliverable_id' => $deliverable['id'],
                'deliverable_name' => $deliverable['name'],
                'file_path' => $groupSubmission['file_path'] ?? null,
                'submitted_at' => $groupSubmission['submitted_at'] ?? null,
                'semester' => $deliverable['semester'],
                'submission_type' => $deliverable['submission_type']
            ];
        }
    }
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

    <title>Student - Deliverables</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link
        href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i"
        rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <style>
        .drag-drop-area {
            border: 2px dashed #ccc;
            padding: 20px;
            text-align: center;
            background-color: #f8f9fc;
            border-radius: 5px;
            margin-bottom: 15px;
            transition: border-color 0.3s;
        }
        .drag-drop-area.dragover {
            border-color: #4e73df;
            background-color: #e3e6fc;
        }
        .drag-drop-area p {
            margin: 0;
            color: #858796;
        }
        .file-preview {
            margin-top: 10px;
            color: #4e73df;
        }
    </style>
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseTwo"
                    aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Project Management</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo"
                    data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Core Elements:</h6>
                        <a class="collapse-item" href="studprojectoverview.php">Project Overview</a>
                        <a class="collapse-item active" href="studdeliverables.php">Deliverables</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities"
                    aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Documentation</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities"
                    data-parent="#accordionSidebar">
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
                                <span
                                    class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle"
                                    src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>"
                                    onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
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
                        <h1 class="h3 mb-0 text-gray-800">Deliverables</h1>
                        
                    </div>

                    <!-- Success/Error Messages -->
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error_message']) ?></div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>

                    <!-- Deliverables Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Deliverables Displayed</h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($deliverables)): ?>
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Deliverable</th>
                                            <th>Semester</th>
                                            <th>Submission Type</th>
                                            <th>File Name</th>
                                            <th>Status</th>
                                            <th>Submission Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($deliverables as $deliverable): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($deliverable['deliverable_name']); ?></td>
                                                <td><?= htmlspecialchars($deliverable['semester']); ?></td>
                                                <td><?= htmlspecialchars(ucfirst($deliverable['submission_type'])); ?></td>
                                                <td>
                                                    <?php if ($deliverable['file_path']): ?>
                                                        <a href="<?= htmlspecialchars($deliverable['file_path']); ?>" target="_blank">
                                                            <?= htmlspecialchars(basename($deliverable['file_path'])); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td class="<?= $deliverable['file_path'] ? 'text-success' : 'text-danger'; ?>">
                                                    <?= $deliverable['file_path'] ? 'Submitted' : 'Not Submitted'; ?>
                                                </td>
                                                <td>
                                                    <?= $deliverable['file_path'] ? htmlspecialchars($deliverable['submitted_at']) : 'N/A'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p>No deliverables have been set by the coordinator for the current semester.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Forms Row: Three cards in one row for Upload, Update, and Delete -->
                    <div class="row">
                        <!-- Upload Card -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Upload Deliverables</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($isGroupApproved): ?>
                                        <?php
                                        // Check for unsubmitted deliverables
                                        $canUpload = false;
                                        $unsubmittedDeliverables = [];
                                        foreach ($availableDeliverables as $deliverable) {
                                            // Skip group deliverables for non-leaders
                                            if ($deliverable['submission_type'] === 'group' && !$isGroupLeader) {
                                                continue;
                                            }
                                            $hasSubmitted = false;
                                            if ($deliverable['submission_type'] === 'individual') {
                                                foreach ($submissions[$deliverable['id']] ?? [] as $submission) {
                                                    if ($submission['student_id'] === $studentID) {
                                                        $hasSubmitted = true;
                                                        break;
                                                    }
                                                }
                                            } else {
                                                $hasSubmitted = !empty($submissions[$deliverable['id']]);
                                            }
                                            if (!$hasSubmitted) {
                                                $canUpload = true;
                                                $unsubmittedDeliverables[] = $deliverable;
                                            }
                                        }
                                        ?>
                                        <?php if (empty($availableDeliverables)): ?>
                                            <p class="text-muted">No deliverables have been set by the coordinator.</p>
                                        <?php elseif (!$canUpload): ?>
                                            <p class="text-muted">All available deliverables have been submitted.</p>
                                        <?php else: ?>
                                            <form id="uploadForm" method="POST" action="" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="upload">
                                                <div class="form-group">
                                                    <label for="deliverableType">Select Deliverable</label>
                                                    <select class="form-control" id="deliverableType" name="deliverable_id">
                                                        <?php foreach ($unsubmittedDeliverables as $deliverable): ?>
                                                            <option
                                                                value="<?= htmlspecialchars($deliverable['id']); ?>"
                                                                data-name="<?= htmlspecialchars($deliverable['name']); ?>">
                                                                <?= htmlspecialchars($deliverable['name']); ?>
                                                                (<?= htmlspecialchars(ucfirst($deliverable['submission_type'])); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="hidden" name="deliverable_name" id="deliverableName">
                                                </div>
                                                <div class="form-group">
                                                    <label for="newDeliverableFile">Select File</label>
                                                    <div>
                                                        <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#uploadModal">
                                                            <i class="fas fa-upload"></i> Choose File
                                                        </button>
                                                        <span id="selectedFileName" class="ml-2 text-muted">No file selected</span>
                                                    </div>
                                                    <input type="file" id="newDeliverableFile" name="file" style="display: none;" accept=".pdf,.doc,.docx" required>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-icon-split">
                                                    <span class="icon text-white-50"><i class="fas fa-upload"></i></span>
                                                    <span class="text">Upload</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="text-muted">Your group is not yet approved by your supervisor.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Update Card -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Update Deliverables</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($isGroupApproved): ?>
                                        <?php
                                        $updatableSubmissions = [];
                                        foreach ($submissions as $deliverableID => $submissionList) {
                                            foreach ($submissionList as $submission) {
                                                if ($submission['submission_type'] === 'individual' && $submission['student_id'] === $studentID) {
                                                    $updatableSubmissions[$deliverableID] = $submission;
                                                } elseif ($submission['submission_type'] === 'group' && $isGroupLeader) {
                                                    $updatableSubmissions[$deliverableID] = $submission;
                                                }
                                            }
                                        }
                                        ?>
                                        <?php if (!empty($updatableSubmissions)): ?>
                                            <form id="editForm" method="POST" action="" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="edit">
                                                <div class="form-group">
                                                    <label for="existingDeliverable">Select Deliverable</label>
                                                    <select class="form-control" id="existingDeliverable" name="deliverable_id">
                                                        <?php foreach ($updatableSubmissions as $deliverableID => $submission): ?>
                                                            <option
                                                                value="<?= htmlspecialchars($submission['deliverable_id']); ?>"
                                                                data-name="<?= htmlspecialchars($submission['deliverable_name']); ?>">
                                                                <?= htmlspecialchars($submission['deliverable_name']); ?>
                                                                (<?= htmlspecialchars(ucfirst($submission['submission_type'])); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="hidden" name="deliverable_name" id="editDeliverableName">
                                                </div>
                                                <div class="form-group">
                                                    <label for="updateDeliverableFile">Select New File</label>
                                                    <div>
                                                        <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#editUploadModal">
                                                            <i class="fas fa-upload"></i> Choose File
                                                        </button>
                                                        <span id="editSelectedFileName" class="ml-2 text-muted">No file selected</span>
                                                    </div>
                                                    <input type="file" id="updateDeliverableFile" name="file" style="display: none;" accept=".pdf,.doc,.docx" required>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-icon-split">
                                                    <span class="icon text-white-50"><i class="fas fa-pencil-alt"></i></span>
                                                    <span class="text">Update</span>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <p class="text-muted">No deliverables submitted yet to update<?php if (!$isGroupLeader) echo " (group deliverables require leader access)"; ?>.</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="text-muted">Your group is not yet approved by your supervisor.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Card -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Delete Deliverables</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($isGroupApproved): ?>
                                        <?php if (!empty($updatableSubmissions)): ?>
                                            <form id="deleteForm" method="POST" action="">
                                                <input type="hidden" name="action" value="delete">
                                                <div class="form-group">
                                                    <label for="deleteDeliverable">Select Deliverable</label>
                                                    <select class="form-control" id="deleteDeliverable" name="deliverable_id">
                                                        <?php foreach ($updatableSubmissions as $deliverableID => $submission): ?>
                                                            <option value="<?= htmlspecialchars($submission['deliverable_id']); ?>" data-name="<?= htmlspecialchars($submission['deliverable_name']); ?>">
                                                                <?= htmlspecialchars($submission['deliverable_name']); ?>
                                                                (<?= htmlspecialchars(ucfirst($submission['submission_type'])); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="button" class="btn btn-danger btn-icon-split" data-toggle="modal" data-target="#deleteConfirmModal">
                                                    <span class="icon text-white-50"><i class="fas fa-trash"></i></span>
                                                    <span class="text">Delete</span>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <p class="text-muted">No deliverables submitted yet to delete<?php if (!$isGroupLeader) echo " (group deliverables require leader access)"; ?>.</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="text-muted">Your group is not yet approved by your supervisor.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- End of Forms Row -->
                </div>
                <!-- /.container-fluid -->
            </div>
            <!-- End of Main Content -->

            <!-- Upload Modal (for Upload Deliverables) -->
            <div class="modal fade" id="uploadModal" tabindex="-1" role="dialog" aria-labelledby="uploadModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="uploadModalLabel">Choose File</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="drag-drop-area" id="dragDropArea">
                                <p>Drag and drop your file here</p>
                                <p>or</p>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('modalFileInput').click()">
                                    <i class="fas fa-plus"></i> Add File
                                </button>
                                <input type="file" id="modalFileInput" style="display: none;" accept=".pdf,.doc,.docx" onchange="handleFileSelect(event, 'upload')">
                            </div>
                            <div id="filePreview" class="file-preview"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirmUploadBtn" disabled onclick="confirmUpload('upload')">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Upload Modal (for Update Deliverables) -->
            <div class="modal fade" id="editUploadModal" tabindex="-1" role="dialog" aria-labelledby="editUploadModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editUploadModalLabel">Choose File</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="drag-drop-area" id="editDragDropArea">
                                <p>Drag and drop your file here</p>
                                <p>or</p>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('editModalFileInput').click()">
                                    <i class="fas fa-plus"></i> Add File
                                </button>
                                <input type="file" id="editModalFileInput" style="display: none;" accept=".pdf,.doc,.docx" onchange="handleFileSelect(event, 'edit')">
                            </div>
                            <div id="editFilePreview" class="file-preview"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="editConfirmUploadBtn" disabled onclick="confirmUpload('edit')">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            Are you sure you want to delete the deliverable "<strong id="deleteDeliverableName"></strong>"? This action cannot be undone.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                        </div>
                    </div>
                </div>
            </div>

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

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <!-- Custom JavaScript for Deliverables -->
    <script>
        // Set deliverable_name for upload form
        document.getElementById('deliverableType')?.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            document.getElementById('deliverableName').value = selectedOption.getAttribute('data-name');
        });
        // Initialize deliverable_name on page load for upload form
        document.getElementById('deliverableName') && (document.getElementById('deliverableName').value = document.getElementById('deliverableType')?.options[0]?.getAttribute('data-name') || '');

        // Set deliverable_name for edit form
        document.getElementById('existingDeliverable')?.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            document.getElementById('editDeliverableName').value = selectedOption.getAttribute('data-name');
        });
        // Initialize deliverable_name on page load for edit form
        if (document.getElementById('existingDeliverable') && document.getElementById('editDeliverableName')) {
            const selectedOption = document.getElementById('existingDeliverable').options[document.getElementById('existingDeliverable').selectedIndex];
            document.getElementById('editDeliverableName').value = selectedOption ? selectedOption.getAttribute('data-name') : '';
        }

        // Drag and Drop functionality
        let selectedFile = null;
        let editSelectedFile = null;

        // For Upload Deliverables
        const dragDropArea = document.getElementById('dragDropArea');
        const fileInput = document.getElementById('modalFileInput');
        const confirmUploadBtn = document.getElementById('confirmUploadBtn');
        const filePreview = document.getElementById('filePreview');
        const formFileInput = document.getElementById('newDeliverableFile');
        const selectedFileName = document.getElementById('selectedFileName');

        // For Update Deliverables
        const editDragDropArea = document.getElementById('editDragDropArea');
        const editFileInput = document.getElementById('editModalFileInput');
        const editConfirmUploadBtn = document.getElementById('editConfirmUploadBtn');
        const editFilePreview = document.getElementById('editFilePreview');
        const editFormFileInput = document.getElementById('updateDeliverableFile');
        const editSelectedFileName = document.getElementById('editSelectedFileName');

        // Drag and Drop for Upload Modal
        dragDropArea?.addEventListener('dragover', (e) => {
            e.preventDefault();
            dragDropArea.classList.add('dragover');
        });

        dragDropArea?.addEventListener('dragleave', () => {
            dragDropArea.classList.remove('dragover');
        });

        dragDropArea?.addEventListener('drop', (e) => {
            e.preventDefault();
            dragDropArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect({ target: { files: files } }, 'upload');
            }
        });

        // Drag and Drop for Edit Upload Modal
        editDragDropArea?.addEventListener('dragover', (e) => {
            e.preventDefault();
            editDragDropArea.classList.add('dragover');
        });

        editDragDropArea?.addEventListener('dragleave', () => {
            editDragDropArea.classList.remove('dragover');
        });

        editDragDropArea?.addEventListener('drop', (e) => {
            e.preventDefault();
            editDragDropArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect({ target: { files: files } }, 'edit');
            }
        });

        function handleFileSelect(event, formType) {
            const files = event.target.files;
            if (files.length > 0) {
                const file = files[0];
                const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only PDF, DOC, and DOCX files are allowed.');
                    return;
                }
                if (formType === 'upload') {
                    selectedFile = file;
                    filePreview.innerHTML = `Selected file: <strong>${selectedFile.name}</strong>`;
                    confirmUploadBtn.disabled = false;
                } else if (formType === 'edit') {
                    editSelectedFile = file;
                    editFilePreview.innerHTML = `Selected file: <strong>${editSelectedFile.name}</strong>`;
                    editConfirmUploadBtn.disabled = false;
                }
            } else {
                if (formType === 'upload') {
                    filePreview.innerHTML = '';
                    confirmUploadBtn.disabled = true;
                    selectedFile = null;
                } else if (formType === 'edit') {
                    editFilePreview.innerHTML = '';
                    editConfirmUploadBtn.disabled = true;
                    editSelectedFile = null;
                }
            }
        }

        function confirmUpload(formType) {
            if (formType === 'upload' && selectedFile) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(selectedFile);
                formFileInput.files = dataTransfer.files;
                selectedFileName.textContent = selectedFile.name;
                $('#uploadModal').modal('hide');
            } else if (formType === 'edit' && editSelectedFile) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(editSelectedFile);
                editFormFileInput.files = dataTransfer.files;
                editSelectedFileName.textContent = editSelectedFile.name;
                $('#editUploadModal').modal('hide');
            }
        }

        // Reset modal state when Upload Modal is closed
        $('#uploadModal').on('hidden.bs.modal', function () {
            selectedFile = null;
            filePreview.innerHTML = '';
            confirmUploadBtn.disabled = true;
            fileInput.value = '';
        });

        // Reset modal state when Edit Upload Modal is closed
        $('#editUploadModal').on('hidden.bs.modal', function () {
            editSelectedFile = null;
            editFilePreview.innerHTML = '';
            editConfirmUploadBtn.disabled = true;
            editFileInput.value = '';
        });

        // Delete Confirmation Modal Logic
        $('#deleteConfirmModal').on('show.bs.modal', function () {
            const select = document.getElementById('deleteDeliverable');
            const selectedOption = select.options[select.selectedIndex];
            const deliverableName = selectedOption.getAttribute('data-name');
            document.getElementById('deleteDeliverableName').textContent = deliverableName;
        });

        document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
            document.getElementById('deleteForm').submit();
        });
    </script>
</body>

</html>