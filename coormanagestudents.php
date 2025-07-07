<?php
session_start();
include 'connection.php';

// Ensure the coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}

$coordinatorID = $_SESSION['user_id'];

// Fetch the coordinator's full name and profile picture from the database
$sql = "SELECT full_name, profile_picture FROM coordinators WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Error preparing coordinator query: " . $conn->error);
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

// Fetch active semester for CSV upload
$semesterQuery = "SELECT start_date FROM semesters WHERE is_current = 1 LIMIT 1";
$semesterResult = $conn->query($semesterQuery);
$semesterError = null; // Initialize error variable
$intakeYear = null;
$intakeMonth = null;

if ($semesterResult === false || $semesterResult->num_rows === 0) {
    $semesterError = "Error: No active semester found. Please set an active semester in <a href='coorsetsemester.php'>coorsetsemester.php</a>.";
} else {
    $semester = $semesterResult->fetch_assoc();
    $startDate = new DateTime($semester['start_date']);
    $intakeYear = $startDate->format('Y'); // e.g., 2025
    $intakeMonth = $startDate->format('F'); // e.g., May
    $semesterResult->free();
}

// Handle CSV file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && !$semesterError) {
    $file = $_FILES['csv_file'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['upload_message'] = ['error' => "File upload failed with error code: " . $file['error']];
    } elseif ($fileExtension !== 'csv') {
        $_SESSION['upload_message'] = ['error' => "Invalid file type. Only .csv files are allowed."];
    } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        $_SESSION['upload_message'] = ['error' => "File size exceeds 5MB limit."];
    } else {
        try {
            // Open and read the CSV file
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                throw new Exception("Failed to open CSV file.");
            }

            // Read the header row
            $headers = fgetcsv($handle, 1000, ',');
            if ($headers === false || empty($headers)) {
                fclose($handle);
                throw new Exception("Empty or invalid CSV file.");
            }

            // Normalize headers (trim, lowercase, remove extra spaces)
            $normalizedHeaders = array_map(function($header) {
                return strtolower(preg_replace('/\s+/', ' ', trim($header)));
            }, $headers);
            // Filter out empty headers
            $normalizedHeaders = array_filter($normalizedHeaders, function($header) {
                return !empty($header);
            });
            $expectedHeaders = array_map('strtolower', ['student id', 'full name', 'email', 'password']);

            // Validate headers
            if (count($normalizedHeaders) !== 4 || array_slice($normalizedHeaders, 0, 4) !== $expectedHeaders) {
                error_log("Received headers: " . implode(', ', $headers));
                fclose($handle);
                $_SESSION['upload_message'] = ['error' => "Invalid CSV format. Expected exactly 4 headers: Student ID, Full Name, Email, Password"];
            } else {
                $added = 0;
                $skipped = 0;
                $failed = 0;
                $errors = [];
                $rowNumber = 1;
                $emailList = [];

                // Process data rows
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $rowNumber++;
                    $row = array_map('trim', $row);

                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        continue;
                    }

                    $username = $row[0] ?? '';
                    $fullName = $row[1] ?? '';
                    $email = $row[2] ?? '';
                    $rawPassword = $row[3] ?? '';

                    // Validate data
                    if (empty($username) || empty($fullName) || empty($email) || empty($rawPassword)) {
                        $failed++;
                        $errors[] = "Row $rowNumber: Missing required fields.";
                        continue;
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $failed++;
                        $errors[] = "Row $rowNumber: Invalid email format ($email).";
                        continue;
                    }
                    if (strlen($rawPassword) < 8) {
                        $failed++;
                        $errors[] = "Row $rowNumber: Password must be at least 8 characters long.";
                        continue;
                    }

                    // Check for duplicate email within CSV
                    if (in_array($email, $emailList)) {
                        $skipped++;
                        $errors[] = "Row $rowNumber: Duplicate email ($email) within CSV.";
                        continue;
                    }
                    $emailList[] = $email;

                    // Check for duplicate username or email in database
                    $checkQuery = "SELECT COUNT(*) AS count FROM students WHERE username = ? OR email = ?";
                    $stmt = $conn->prepare($checkQuery);
                    if ($stmt === false) {
                        $failed++;
                        $errors[] = "Row $rowNumber: Failed to prepare query. Error: " . $conn->error;
                        error_log("Failed to prepare query: $checkQuery. Error: " . $conn->error);
                        continue;
                    }
                    $stmt->bind_param("ss", $username, $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $count = $result->fetch_assoc()['count'];
                    $stmt->close();

                    if ($count > 0) {
                        $skipped++;
                        $errors[] = "Row $rowNumber: Username ($username) or email ($email) already exists in database.";
                        continue;
                    }

                    // Hash the provided password
                    $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);

                    // Insert student
                    $insertQuery = "
                        INSERT INTO students (username, full_name, email, password, intake_year, intake_month, profile_picture, no_ic, no_tel)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)
                    ";
                    $stmt = $conn->prepare($insertQuery);
                    if ($stmt === false) {
                        $failed++;
                        $errors[] = "Row $rowNumber: Failed to prepare insert query. Error: " . $conn->error;
                        error_log("Failed to prepare insert query: $insertQuery. Error: " . $conn->error);
                        continue;
                    }
                    $profilePicture = 'img/undraw_profile.svg';
                    $stmt->bind_param("ssssiss", $username, $fullName, $email, $hashedPassword, $intakeYear, $intakeMonth, $profilePicture);

                    if ($stmt->execute()) {
                        $added++;
                        $errors[] = "Row $rowNumber: Added student ($username) with provided password.";
                    } else {
                        $failed++;
                        $errors[] = "Row $rowNumber: Failed to insert student ($username). Error: " . $stmt->error;
                    }
                    $stmt->close();
                }

                fclose($handle);
                $_SESSION['upload_message'] = [
                    'success' => true,
                    'message' => "Processed $added students successfully, $skipped skipped (duplicates), $failed failed.",
                    'errors' => $errors
                ];
            }
        } catch (Exception $e) {
            if (isset($handle) && $handle !== false) {
                fclose($handle);
            }
            $_SESSION['upload_message'] = ['error' => "Error processing file: " . $e->getMessage()];
        }
    }

    // Redirect after CSV processing to prevent form resubmission
    header("Location: coormanagestudents.php");
    exit();
}

// Initialize message for add/edit/delete
$message = "";

// Fetch students with intake_year, intake_month, and group information
// CORRECTED: Using g.name instead of g.group_name
$studentsQuery = "
    SELECT 
        s.id, 
        s.full_name, 
        s.username, 
        s.email, 
        s.intake_year, 
        s.intake_month,
        g.name AS group_name
    FROM students s
    LEFT JOIN group_members gm ON s.id = gm.student_id
    LEFT JOIN groups g ON gm.group_id = g.id
    ORDER BY s.full_name ASC";
$studentsResult = $conn->query($studentsQuery) or die("Error in students query: " . htmlspecialchars($conn->error));
$students = $studentsResult->fetch_all(MYSQLI_ASSOC);
$studentsResult->free();

// Count totals with error handling
// Total Students in Current Semester
$totalStudentsQuery = "
    SELECT COUNT(*) as total 
    FROM students 
    WHERE intake_year = ? AND intake_month = ?";
$totalStudentsStmt = $conn->prepare($totalStudentsQuery);
if ($totalStudentsStmt === false) {
    $totalStudents = 0;
} else {
    $totalStudentsStmt->bind_param("is", $intakeYear, $intakeMonth);
    $totalStudentsStmt->execute();
    $totalStudentsResult = $totalStudentsStmt->get_result();
    $totalStudents = $totalStudentsResult ? $totalStudentsResult->fetch_assoc()['total'] : 0;
    $totalStudentsStmt->close();
}

// Total Groups in Current Semester
$totalGroupsQuery = "
    SELECT COUNT(DISTINCT g.id) as total
    FROM groups g
    INNER JOIN group_members gm ON g.id = gm.group_id
    INNER JOIN students s ON gm.student_id = s.id
    WHERE s.intake_year = ? AND s.intake_month = ?";
$totalGroupsStmt = $conn->prepare($totalGroupsQuery);
if ($totalGroupsStmt === false) {
    $totalGroups = 0;
} else {
    $totalGroupsStmt->bind_param("is", $intakeYear, $intakeMonth);
    $totalGroupsStmt->execute();
    $totalGroupsResult = $totalGroupsStmt->get_result();
    $totalGroups = $totalGroupsResult ? $totalGroupsResult->fetch_assoc()['total'] : 0;
    $totalGroupsStmt->close();
}

// Total Students without Groups in Current Semester
$studentsWithoutGroupsQuery = "
    SELECT COUNT(DISTINCT s.id) as total
    FROM students s
    LEFT JOIN group_members gm ON s.id = gm.student_id
    WHERE s.intake_year = ? AND s.intake_month = ? AND gm.student_id IS NULL";
$studentsWithoutGroupsStmt = $conn->prepare($studentsWithoutGroupsQuery);
if ($studentsWithoutGroupsStmt === false) {
    $studentsWithoutGroups = 0;
} else {
    $studentsWithoutGroupsStmt->bind_param("is", $intakeYear, $intakeMonth);
    $studentsWithoutGroupsStmt->execute();
    $studentsWithoutGroupsResult = $studentsWithoutGroupsStmt->get_result();
    $studentsWithoutGroups = $studentsWithoutGroupsResult ? $studentsWithoutGroupsResult->fetch_assoc()['total'] : 0;
    $studentsWithoutGroupsStmt->close();
}

// Handle student addition (only adding, no editing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student']) && !$semesterError) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['student_username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['student_password']);
    
    // Only new student addition
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO students (full_name, username, email, password, intake_year, intake_month) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssis", $full_name, $username, $email, $hashed_password, $intakeYear, $intakeMonth);
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Student added successfully!</div>";
        header("Refresh:1");
    } else {
        $message = "<div class='alert alert-danger'>Failed to save student: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student']) && $semesterError) {
    $message = "<div class='alert alert-danger'>Cannot save student: No active semester set.</div>";
}

// Handle student editing from modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $student_id = intval($_POST['edit_student_id']);
    $full_name = trim($_POST['edit_full_name']);
    $username = trim($_POST['edit_username']);
    $email = trim($_POST['edit_email']);
    $password = trim($_POST['edit_password']);
    
    $sql = "UPDATE students SET full_name = ?, username = ?, email = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $full_name, $username, $email, $student_id);
    
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql_pw = "UPDATE students SET password = ? WHERE id = ?";
        $stmt_pw = $conn->prepare($sql_pw);
        $stmt_pw->bind_param("si", $hashed_password, $student_id);
        $stmt_pw->execute();
        $stmt_pw->close();
    }
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Student updated successfully!</div>";
        header("Refresh:1");
    } else {
        $message = "<div class='alert alert-danger'>Failed to update student: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
}

// Handle student deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    $student_id = intval($_POST['delete_student_id']);
    
    // Check if student exists
    $checkSql = "SELECT id FROM students WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $student_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        // Delete the student
        $deleteSql = "DELETE FROM students WHERE id = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $student_id);
        
        if ($deleteStmt->execute()) {
            $message = "<div class='alert alert-success'>Student deleted successfully!</div>";
            header("Refresh:1");
        } else {
            $message = "<div class='alert alert-danger'>Failed to delete student: " . htmlspecialchars($deleteStmt->error) . "</div>";
        }
        $deleteStmt->close();
    } else {
        $message = "<div class='alert alert-danger'>Student not found!</div>";
    }
    $checkStmt->close();
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
    <title>Coordinator - Manage Students</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <style>
        /* Add padding to table cells for better spacing */
        #dataTable th, #dataTable td {
            padding: 12px 15px;
        }
        /* Ensure consistent table container spacing */
        .table-responsive {
            margin-bottom: 1rem;
        }
        /* Drag and drop styles */
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
        /* Preview table styles */
        .csv-preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .csv-preview-table th, .csv-preview-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .csv-preview-table th {
            background-color: #f8f9fc;
            font-weight: bold;
        }
        .csv-preview-table tr:nth-child(even) {
            background-color: #f8f9fc;
        }
        .preview-container {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .validation-message {
            margin-top: 10px;
            font-size: 0.9em;
        }
        /* Pagination styles */
        .pagination-controls {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pagination-controls .btn {
            margin: 0 5px;
        }
        .pagination-info {
            font-size: 0.9em;
            color: #858796;
        }
        /* Updated Students List table column widths with Group Name column */
        #dataTable th, #dataTable td {
            text-align: left;
        }
        #dataTable th:nth-child(1), #dataTable td:nth-child(1) { /* Name */
            width: 18%;
        }
        #dataTable th:nth-child(2), #dataTable td:nth-child(2) { /* Username */
            width: 14%;
        }
        #dataTable th:nth-child(3), #dataTable td:nth-child(3) { /* Email */
            width: 18%;
        }
        #dataTable th:nth-child(4), #dataTable td:nth-child(4) { /* Group Name */
            width: 15%;
        }
        #dataTable th:nth-child(5), #dataTable td:nth-child(5) { /* Intake Year */
            width: 10%;
        }
        #dataTable th:nth-child(6), #dataTable td:nth-child(6) { /* Intake Month */
            width: 10%;
        }
        #dataTable th:nth-child(7), #dataTable td:nth-child(7) { /* Actions */
            width: 15%;
            text-align: left;
        }
        /* Action buttons spacing */
        .action-buttons .btn {
            margin: 0 2px;
        }
        /* Confirmation modal styling */
        #confirmActionModal .modal-body {
            font-size: 1rem;
            line-height: 1.5;
        }
        /* Group name styling */
        .group-name {
            font-weight: 500;
            color: #5a5c69;
        }
        .no-group {
            color: #858796;
            font-style: italic;
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
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseTwo"
                    aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student<br> Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors & <br>Assessors</a>
                        <a class="collapse-item active" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities"
                    aria-expanded="true" aria-controls="collapseUtilities">
                    <i class="fas fa-fw fa-wrench"></i>
                    <span>Project & Assessment</span>
                </a>
                <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities"
                    data-parent="#accordionSidebar">
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
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages"
                    aria-expanded="true" aria-controls="collapsePages">
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

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($personalInfo['full_name']) ?></span>
                                <img class="img-profile rounded-circle"
                                    src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>"
                                    onerror="this.src='img/undraw_profile.svg';">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
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

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Manage Students</h1>
                    </div>
                    <?= $message ?>
                    <?php if ($semesterError): ?>
                        <div class="alert alert-danger">
                            <?= $semesterError ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Total Students Card -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Total Students (Current Semester)</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalStudents) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-users fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Groups Card -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Total Groups (Current Semester)</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalGroups) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-friends fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Students without Groups Card -->
                        <div class="col-lg-4 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Students without Groups</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($studentsWithoutGroups) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-slash fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Result -->
                    <?php if (isset($_SESSION['upload_message'])): ?>
                        <div class="alert <?= isset($_SESSION['upload_message']['error']) ? 'alert-danger' : 'alert-success' ?>">
                            <?= htmlspecialchars(isset($_SESSION['upload_message']['error']) ? $_SESSION['upload_message']['error'] : $_SESSION['upload_message']['message']) ?>
                            <?php if (!empty($_SESSION['upload_message']['errors'])): ?>
                                <ul>
                                    <?php foreach ($_SESSION['upload_message']['errors'] as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <?php unset($_SESSION['upload_message']); ?>
                    <?php endif; ?>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Students List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Group Name</th>
                                            <th>Intake Year</th>
                                            <th>Intake Month</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($students)): ?>
                                            <?php foreach ($students as $student): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($student['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($student['username']) ?></td>
                                                    <td><?= htmlspecialchars($student['email']) ?></td>
                                                    <td>
                                                        <?php if (!empty($student['group_name'])): ?>
                                                            <span class="group-name"><?= htmlspecialchars($student['group_name']) ?></span>
                                                        <?php else: ?>
                                                            <span class="no-group">No Group</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($student['intake_year'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($student['intake_month'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button type="button" 
                                                                    class="btn btn-primary btn-sm" 
                                                                    onclick="openEditModal(<?= $student['id'] ?>, '<?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($student['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($student['email'], ENT_QUOTES) ?>')"
                                                                    title="Edit Student">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" 
                                                                    class="btn btn-danger btn-sm" 
                                                                    onclick="confirmDeleteStudent(<?= $student['id'] ?>, '<?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?>')"
                                                                    title="Delete Student">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="7" class="text-center">No students found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Add Student</h6></div>
                                <div class="card-body">
                                    <form id="addStudentForm" method="POST" action="">
                                        <div class="form-group">
                                            <label for="full_name">Full Name</label>
                                            <input type="text" class="form-control" id="full_name" name="full_name" required <?= $semesterError ? 'disabled' : '' ?>>
                                        </div>
                                        <div class="form-group">
                                            <label for="student_username">Username</label>
                                            <input type="text" class="form-control" id="student_username" name="student_username" required autocomplete="off" <?= $semesterError ? 'disabled' : '' ?>>
                                        </div>
                                        <div class="form-group">
                                            <label for="email">Email</label>
                                            <input type="email" class="form-control" id="email" name="email" required <?= $semesterError ? 'disabled' : '' ?>>
                                        </div>
                                        <div class="form-group">
                                            <label for="student_password">Password</label>
                                            <input type="password" class="form-control" id="student_password" name="student_password" required autocomplete="new-password" <?= $semesterError ? 'disabled' : '' ?>>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-icon-split save-student-button" <?= $semesterError ? 'disabled' : '' ?>>
                                            <span class="icon text-white-50">
                                                <i class="fas fa-plus"></i>
                                            </span>
                                            <span class="text">Add Student</span>
                                        </button>
                                        <input type="hidden" name="save_student" value="1">
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <!-- Upload Student Details -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Upload Student Details</h6>
                                </div>
                                <div class="card-body">
                                    <p>Upload a CSV file containing student details. The file must have the following columns: <strong>Student ID, Full Name, Email, Password</strong>. Intake Year and Month will be assigned based on the active semester.</p>
                                    <p><a href="templates/student.csv" class="btn btn-info btn-sm"><i class="fas fa-download"></i> Download Template</a></p>
                                    <form id="csvUploadForm" method="POST" enctype="multipart/form-data">
                                        <div class="form-group">
                                            <label for="csv_file">Select CSV File</label>
                                            <div>
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#csvUploadModal" <?= $semesterError ? 'disabled' : '' ?>>
                                                    <i class="fas fa-upload"></i> Choose File
                                                </button>
                                                <span id="selectedFileName" class="ml-2 text-muted">No file selected</span>
                                            </div>
                                            <input type="file" id="csvFileInput" name="csv_file" accept=".csv" style="display: none;">
                                        </div>
                                        <button type="button" class="btn btn-primary btn-icon-split upload-csv-button" id="uploadButton" disabled>
                                            <span class="icon text-white-50">
                                                <i class="fas fa-upload"></i>
                                            </span>
                                            <span class="text">Upload and Process</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Student Modal -->
            <div class="modal fade" id="editStudentModal" tabindex="-1" role="dialog" aria-labelledby="editStudentModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <form id="editStudentForm" method="POST" action="">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label for="edit_full_name">Full Name</label>
                                    <input type="text" class="form-control" id="edit_full_name" name="edit_full_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_username">Username</label>
                                    <input type="text" class="form-control" id="edit_username" name="edit_username" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_email">Email</label>
                                    <input type="email" class="form-control" id="edit_email" name="edit_email" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_password">Password (Leave blank to keep unchanged)</label>
                                    <input type="password" class="form-control" id="edit_password" name="edit_password" placeholder="Enter new password if changing">
                                </div>
                                <input type="hidden" id="edit_student_id" name="edit_student_id">
                                <input type="hidden" name="edit_student" value="1">
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Update Student</button>
                            </div>
                        </form>
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
                            Are you sure you want to delete <strong id="deleteStudentName"></strong>? This action cannot be undone and may affect related records.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <form id="confirmDeleteForm" method="POST" action="">
                                <input type="hidden" name="delete_student_id" id="confirmDeleteStudentId">
                                <button type="submit" name="delete_student" class="btn btn-danger">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CSV Upload Modal -->
            <div class="modal fade" id="csvUploadModal" tabindex="-1" role="dialog" aria-labelledby="csvUploadModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="csvUploadModalLabel">Choose and Preview CSV File</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="drag-drop-area" id="csvDragDropArea">
                                <p>Drag and drop your .csv file here</p>
                                <p>or</p>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('csvModalFileInput').click()">
                                    <i class="fas fa-plus"></i> Add File
                                </button>
                                <input type="file" id="csvModalFileInput" accept=".csv" style="display: none;" onchange="handleFileSelect(event)">
                            </div>
                            <div id="csvFilePreview" class="file-preview"></div>
                            <div id="csvValidationMessage" class="validation-message"></div>
                            <div id="csvPreviewContainer" class="preview-container" style="display: none;">
                                <table class="csv-preview-table" id="csvPreviewTable">
                                    <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Password</th>
                                        </tr>
                                    </thead>
                                    <tbody id="csvPreviewTableBody"></tbody>
                                </table>
                                <div class="pagination-controls">
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary" id="prevPageBtn" disabled onclick="changePage(-1)">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary" id="nextPageBtn" disabled onclick="changePage(1)">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                    <span id="paginationInfo" class="pagination-info"></span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirmCsvUploadBtn" disabled onclick="confirmUpload()">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Confirmation Modal -->
            <div class="modal fade" id="confirmActionModal" tabindex="-1" role="dialog" aria-labelledby="confirmActionModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmActionModalLabel">Confirm Action</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p id="confirmActionMessage"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirmActionButton">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto"><span>Copyright © FYPCollabor8 2025</span></div>
                </div>
            </footer>
        </div>
    </div>

    <a class="scroll-to-top rounded" href="#page-top"><i class="fas fa-angle-up"></i></a>

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
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

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <script src="js/demo/datatables-demo.js"></script>
    <!-- PapaParse for CSV parsing -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.2/papaparse.min.js"></script>

    <script>
        // Initialize DataTable
        $(document).ready(function() {
            if (!$.fn.DataTable.isDataTable('#dataTable')) {
                console.log('Initializing DataTable for #dataTable');
                $('#dataTable').DataTable({
                    paging: true,
                    searching: true,
                    ordering: true,
                    info: true,
                    autoWidth: false
                });
            } else {
                console.log('DataTable already initialized for #dataTable');
            }
        });

        // Function to open edit modal with student data
        function openEditModal(studentId, fullName, username, email) {
            document.getElementById('edit_student_id').value = studentId;
            document.getElementById('edit_full_name').value = fullName;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_password').value = '';
            $('#editStudentModal').modal('show');
        }

        // Function to handle delete confirmation from table
        function confirmDeleteStudent(studentId, studentName) {
            document.getElementById('deleteStudentName').textContent = studentName;
            document.getElementById('confirmDeleteStudentId').value = studentId;
            $('#deleteConfirmModal').modal('show');
        }

        // Confirmation Modal Logic
        let targetForm = null;
        let actionType = null;

        // Handle Save Student button
        $(document).on('click', '.save-student-button', function() {
            const form = $('#addStudentForm');
            const fullName = form.find('#full_name').val().trim();
            const username = form.find('#student_username').val().trim();
            const email = form.find('#email').val().trim();
            const password = form.find('#student_password').val().trim();

            // Validate inputs
            if (!fullName || !username || !email || !password) {
                alert('Please fill in all required fields.');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address.');
                return;
            }

            // Set confirmation message with bold student name
            $('#confirmActionMessage').html(
                `Are you sure you want to add student <strong>${htmlspecialchars(fullName)}</strong>?`
            );

            targetForm = form;
            actionType = 'save-student';
            $('#confirmActionModal').modal('show');
        });

        // Handle Upload CSV button
        $(document).on('click', '.upload-csv-button', function() {
            const form = $('#csvUploadForm');
            const fileName = $('#selectedFileName').text().trim();

            if (fileName === 'No file selected') {
                alert('Please select a CSV file to upload.');
                return;
            }

            // Set confirmation message with bold file name
            $('#confirmActionMessage').html(
                `Are you sure you want to upload the CSV file <strong>${htmlspecialchars(fileName)}</strong>?`
            );

            targetForm = form;
            actionType = 'upload-csv';
            $('#confirmActionModal').modal('show');
        });

        // Handle Confirm button in confirmation modal
        $('#confirmActionButton').on('click', function() {
            if (targetForm && actionType) {
                targetForm.submit();
                targetForm = null;
                actionType = null;
            }
            $('#confirmActionModal').modal('hide');
        });

        // Reset confirmation modal state when closed
        $('#confirmActionModal').on('hidden.bs.modal', function() {
            targetForm = null;
            actionType = null;
            $('#confirmActionMessage').html('');
        });

        // Drag and Drop functionality for CSV Upload
        let selectedCsvFile = null;
        let csvRows = [];
        let currentPage = 1;
        const rowsPerPage = 10;

        const csvDragDropArea = document.getElementById('csvDragDropArea');
        const csvFileInput = document.getElementById('csvModalFileInput');
        const confirmCsvUploadBtn = document.getElementById('confirmCsvUploadBtn');
        const csvFilePreview = document.getElementById('csvFilePreview');
        const formCsvFileInput = document.getElementById('csvFileInput');
        const selectedFileName = document.getElementById('selectedFileName');
        const uploadButton = document.getElementById('uploadButton');
        const csvValidationMessage = document.getElementById('csvValidationMessage');
        const csvPreviewContainer = document.getElementById('csvPreviewContainer');
        const csvPreviewTableBody = document.getElementById('csvPreviewTableBody');
        const prevPageBtn = document.getElementById('prevPageBtn');
        const nextPageBtn = document.getElementById('nextPageBtn');
        const paginationInfo = document.getElementById('paginationInfo');

        // Drag and Drop events
        csvDragDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            csvDragDropArea.classList.add('dragover');
        });

        csvDragDropArea.addEventListener('dragleave', () => {
            csvDragDropArea.classList.remove('dragover');
        });

        csvDragDropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            csvDragDropArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect({ target: { files: files } });
            }
        });

        function handleFileSelect(event) {
            console.log('handleFileSelect triggered');
            const files = event.target.files;
            csvValidationMessage.innerHTML = '';
            csvPreviewContainer.style.display = 'none';
            csvPreviewTableBody.innerHTML = '';
            confirmCsvUploadBtn.disabled = true;
            csvRows = [];
            currentPage = 1;

            if (files.length > 0) {
                const file = files[0];
                console.log('Selected file:', file.name);
                if (file.name.toLowerCase().endsWith('.csv')) {
                    selectedCsvFile = file;
                    csvFilePreview.innerHTML = `Selected file: <strong>${selectedCsvFile.name}</strong>`;
                    
                    // Read and parse the CSV file
                    Papa.parse(selectedCsvFile, {
                        complete: function(results) {
                            console.log('PapaParse results:', results);
                            const data = results.data;
                            if (data.length === 0) {
                                csvValidationMessage.innerHTML = '<span class="text-danger">Error: Empty CSV file.</span>';
                                return;
                            }

                            // Normalize headers
                            const headers = data[0].map(header => header.toLowerCase().trim());
                            const expectedHeaders = ['student id', 'full name', 'email', 'password'];
                            const headersValid = headers.length === 4 && headers.every((header, index) => header === expectedHeaders[index]);

                            if (!headersValid) {
                                csvValidationMessage.innerHTML = '<span class="text-danger">Invalid CSV format. Expected headers: Student ID, Full Name, Email, Password.</span>';
                                return;
                            }

                            // Store rows (skip header)
                            csvRows = data.slice(1).filter(row => row.some(cell => cell.trim() !== '')); // Skip empty rows
                            if (csvRows.length === 0) {
                                csvValidationMessage.innerHTML = '<span class="text-danger">No valid data rows found in CSV.</span>';
                                return;
                            }

                            csvValidationMessage.innerHTML = '<span class="text-success">CSV format valid. Previewing data.</span>';
                            csvPreviewContainer.style.display = 'block';
                            displayPage(currentPage);
                            confirmCsvUploadBtn.disabled = false;
                        },
                        error: function(error) {
                            console.error('PapaParse error:', error);
                            csvValidationMessage.innerHTML = '<span class="text-danger">Error parsing CSV: ' + error.message + '</span>';
                        },
                        skipEmptyLines: true,
                        header: false
                    });
                } else {
                    csvFilePreview.innerHTML = '<span class="text-danger">Please select a valid .csv file.</span>';
                    selectedCsvFile = null;
                }
            } else {
                csvFilePreview.innerHTML = '<span class="text-danger">No file selected.</span>';
                selectedCsvFile = null;
            }
        }

        function displayPage(page) {
            console.log('Displaying page:', page);
            csvPreviewTableBody.innerHTML = '';
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageRows = csvRows.slice(start, end);

            pageRows.forEach(row => {
                if (row.length >= 4) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${row[0] ? htmlspecialchars(row[0]) : ''}</td>
                        <td>${row[1] ? htmlspecialchars(row[1]) : ''}</td>
                        <td>${row[2] ? htmlspecialchars(row[2]) : ''}</td>
                        <td>${row[3] ? htmlspecialchars(row[3]) : ''}</td>
                    `;
                    csvPreviewTableBody.appendChild(tr);
                }
            });

            const totalPages = Math.ceil(csvRows.length / rowsPerPage);
            prevPageBtn.disabled = page === 1;
            nextPageBtn.disabled = page === totalPages;
            paginationInfo.innerHTML = `Page ${page} of ${totalPages}, showing rows ${start + 1}–${Math.min(end, csvRows.length)} of ${csvRows.length}`;
        }

        function changePage(delta) {
            currentPage += delta;
            displayPage(currentPage);
        }

        function confirmUpload() {
            if (selectedCsvFile) {
                console.log('Confirming upload for file:', selectedCsvFile.name);
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(selectedCsvFile);
                formCsvFileInput.files = dataTransfer.files;
                selectedFileName.textContent = selectedCsvFile.name;
                uploadButton.disabled = false;
                $('#csvUploadModal').modal('hide');
            } else {
                console.error('No file selected for upload');
                csvValidationMessage.innerHTML = '<span class="text-danger">No file selected for upload.</span>';
            }
        }

        // Reset CSV Upload Modal state when closed
        $('#csvUploadModal').on('hidden.bs.modal', function () {
            console.log('CSV Upload Modal closed, resetting state');
            selectedCsvFile = null;
            csvFilePreview.innerHTML = '';
            csvValidationMessage.innerHTML = '';
            csvPreviewContainer.style.display = 'none';
            csvPreviewTableBody.innerHTML = '';
            confirmCsvUploadBtn.disabled = true;
            csvFileInput.value = '';
            paginationInfo.innerHTML = '';
            csvRows = [];
            currentPage = 1;
            prevPageBtn.disabled = true;
            nextPageBtn.disabled = true;
        });

        // Reset CSV Upload Modal state when shown
        $('#csvUploadModal').on('show.bs.modal', function () {
            console.log('CSV Upload Modal opened, initializing state');
            csvFilePreview.innerHTML = 'No file selected.';
            csvValidationMessage.innerHTML = '';
            csvPreviewContainer.style.display = 'none';
            csvPreviewTableBody.innerHTML = '';
            confirmCsvUploadBtn.disabled = true;
            csvFileInput.value = '';
            paginationInfo.innerHTML = '';
            csvRows = [];
            currentPage = 1;
            prevPageBtn.disabled = true;
            nextPageBtn.disabled = true;
            selectedCsvFile = null;
        });

        // HTML escape function
        function htmlspecialchars(str) {
            return str.replace(/&/g, '&amp;')
                     .replace(/</g, '&lt;')
                     .replace(/>/g, '&amp;')
                     .replace(/</g, '&lt;')
                     .replace(/>/g, '&gt;')
                     .replace(/"/g, '&quot;')
                     .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>