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

// Fetch the admin's full name and profile picture from the database
$sql = "SELECT full_name, profile_picture FROM admins WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Admin Info Query preparation failed: " . $conn->error);
    die("Prepare failed (Admin Info): " . $conn->error);
}
$stmt->bind_param("i", $adminID);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if (!$admin) {
    error_log("No admin found with ID: $adminID");
    die("Error: No admin found with the provided ID.");
}

$personalInfo = [
    'full_name' => $admin['full_name'] ?? 'N/A',
    'profile_picture' => $admin['profile_picture'] ?? 'img/undraw_profile.svg',
];

// Initialize message
$message = "";

// Get filter from GET parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Fetch lecturers with filter
$lecturersQuery = "
    SELECT id, full_name, username, email, role_id,
           CASE role_id
               WHEN 1 THEN 'Lecturer'
               WHEN 2 THEN 'Assessor'
               WHEN 3 THEN 'Both'
               WHEN 4 THEN 'Supervisor'
               WHEN 5 THEN 'Coordinator'
               ELSE 'Unknown'
           END AS role_name
    FROM lecturers";
if ($filter !== 'all') {
    switch ($filter) {
        case 'lecturer':
            $lecturersQuery .= " WHERE role_id IN (1, 3)";
            break;
        case 'supervisor':
            $lecturersQuery .= " WHERE role_id IN (4, 3)";
            break;
        case 'assessor':
            $lecturersQuery .= " WHERE role_id IN (2, 3)";
            break;
        case 'coordinator':
            $lecturersQuery .= " WHERE role_id = 5";
            break;
    }
}
$lecturersQuery .= " ORDER BY full_name ASC";
$lecturersResult = $conn->query($lecturersQuery) or die("Error in lecturers query: " . htmlspecialchars($conn->error));
$lecturers = $lecturersResult->fetch_all(MYSQLI_ASSOC);

// Count totals
$totalLecturersQuery = "SELECT COUNT(*) as total FROM lecturers WHERE role_id IN (1, 3)";
$totalSupervisorsQuery = "SELECT COUNT(*) as total FROM lecturers WHERE role_id IN (4, 3)";
$totalAssessorsQuery = "SELECT COUNT(*) as total FROM lecturers WHERE role_id IN (2, 3)";
$totalCoordinatorsQuery = "SELECT COUNT(*) as total FROM lecturers WHERE role_id = 5";

$totalLecturersResult = $conn->query($totalLecturersQuery);
$totalSupervisorsResult = $conn->query($totalSupervisorsQuery);
$totalAssessorsResult = $conn->query($totalAssessorsQuery);
$totalCoordinatorsResult = $conn->query($totalCoordinatorsQuery);

$totalLecturers = $totalLecturersResult->fetch_assoc()['total'];
$totalSupervisors = $totalSupervisorsResult->fetch_assoc()['total'];
$totalAssessors = $totalAssessorsResult->fetch_assoc()['total'];
$totalCoordinators = $totalCoordinatorsResult->fetch_assoc()['total'];

// Handle CSV file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
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
            $normalizedHeaders = array_filter($normalizedHeaders, function($header) {
                return !empty($header);
            });
            $expectedHeaders = array_map('strtolower', ['lecturer id', 'full name', 'email', 'role', 'password']);

            // Validate headers
            if (count($normalizedHeaders) !== 5 || array_slice($normalizedHeaders, 0, 5) !== $expectedHeaders) {
                error_log("Received headers: " . implode(', ', $headers));
                fclose($handle);
                $_SESSION['upload_message'] = ['error' => "Invalid CSV format. Expected exactly 5 headers: Lecturer ID, Full Name, Email, Role, Password"];
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

                    $username = $row[0] ?? ''; // Lecturer ID as username
                    $fullName = $row[1] ?? '';
                    $email = $row[2] ?? '';
                    $role = $row[3] ?? '';
                    $rawPassword = $row[4] ?? '';

                    // Validate data
                    if (empty($username) || empty($fullName) || empty($email) || empty($role) || empty($rawPassword)) {
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

                    // Map role to role_id
                    $role_id = null;
                    switch (strtolower($role)) {
                        case 'lecturer':
                            $role_id = 1;
                            break;
                        case 'assessor':
                            $role_id = 2;
                            break;
                        case 'both':
                            $role_id = 3;
                            break;
                        case 'supervisor':
                            $role_id = 4;
                            break;
                        case 'coordinator':
                            $role_id = 5;
                            break;
                        default:
                            $failed++;
                            $errors[] = "Row $rowNumber: Invalid role ($role). Must be Lecturer, Assessor, Both, Supervisor, or Coordinator.";
                            continue 2; // Skip to next row
                    }

                    // Check for duplicate email within CSV
                    if (in_array($email, $emailList)) {
                        $skipped++;
                        $errors[] = "Row $rowNumber: Duplicate email ($email) within CSV.";
                        continue;
                    }
                    $emailList[] = $email;

                    // Check for duplicate username or email in database
                    $checkQuery = "SELECT COUNT(*) AS count FROM lecturers WHERE username = ? OR email = ?";
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

                    // Insert lecturer
                    $insertQuery = "
                        INSERT INTO lecturers (username, full_name, email, role_id, password)
                        VALUES (?, ?, ?, ?, ?)
                    ";
                    $stmt = $conn->prepare($insertQuery);
                    if ($stmt === false) {
                        $failed++;
                        $errors[] = "Row $rowNumber: Failed to prepare insert query. Error: " . $conn->error;
                        error_log("Failed to prepare insert query: $insertQuery. Error: " . $conn->error);
                        continue;
                    }
                    $stmt->bind_param("sssis", $username, $fullName, $email, $role_id, $hashedPassword);

                    if ($stmt->execute()) {
                        $added++;
                        $errors[] = "Row $rowNumber: Added lecturer ($username) with role ($role).";
                    } else {
                        $failed++;
                        $errors[] = "Row $rowNumber: Failed to insert lecturer ($username). Error: " . $stmt->error;
                    }
                    $stmt->close();
                }

                fclose($handle);
                $_SESSION['upload_message'] = [
                    'success' => true,
                    'message' => "Processed $added lecturers successfully, $skipped skipped (duplicates), $failed failed.",
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
    header("Location: adminmanagelecturers.php?filter=$filter");
    exit();
}

// Handle lecturer addition/editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lecturer'])) {
    $lecturer_id = intval($_POST['lecturer_id']);
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role_id = intval($_POST['role_id']);
    $password = trim($_POST['password']);
    
    if ($lecturer_id == 0) {
        // New lecturer
        if (empty($password)) {
            $message = "<div class='alert alert-danger'>Password is required for new lecturers.</div>";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO lecturers (full_name, username, email, role_id, password) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssis", $full_name, $username, $email, $role_id, $hashed_password);
            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'>Lecturer added successfully!</div>";
                header("Refresh:1");
            } else {
                $message = "<div class='alert alert-danger'>Failed to add lecturer: " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        }
    } else {
        // Update existing lecturer
        $sql = "UPDATE lecturers SET full_name = ?, username = ?, email = ?, role_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssisi", $full_name, $username, $email, $role_id, $lecturer_id);
        if ($stmt->execute()) {
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql_pw = "UPDATE lecturers SET password = ? WHERE id = ?";
                $stmt_pw = $conn->prepare($sql_pw);
                $stmt_pw->bind_param("si", $hashed_password, $lecturer_id);
                $stmt_pw->execute();
                $stmt_pw->close();
            }
            $message = "<div class='alert alert-success'>Lecturer updated successfully!</div>";
            header("Refresh:1");
        } else {
            $message = "<div class='alert alert-danger'>Failed to update lecturer: " . htmlspecialchars($stmt->error) . "</div>";
        }
        $stmt->close();
    }
}

// Handle lecturer deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_lecturer'])) {
    $lecturer_id = intval($_POST['delete_lecturer_id']);
    
    // Check if lecturer exists
    $checkSql = "SELECT id FROM lecturers WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $lecturer_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        // Delete related records to avoid foreign key constraints
        $conn->query("DELETE FROM student_supervisor_assignments WHERE lecturer_id = $lecturer_id");
        $conn->query("DELETE FROM student_assessments WHERE lecturer_id = $lecturer_id");

        // Delete the lecturer
        $deleteSql = "DELETE FROM lecturers WHERE id = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $lecturer_id);
        
        if ($deleteStmt->execute()) {
            $message = "<div class='alert alert-success'>Lecturer deleted successfully!</div>";
            header("Refresh:1");
        } else {
            $message = "<div class='alert alert-danger'>Failed to delete lecturer: " . htmlspecialchars($deleteStmt->error) . "</div>";
        }
        $deleteStmt->close();
    } else {
        $message = "<div class='alert alert-danger'>Lecturer not found!</div>";
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
    <title>Admin - Manage Lecturers</title>

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
        /* Confirmation modal styling */
        #confirmActionModal .modal-body {
            font-size: 1rem;
            line-height: 1.5;
        }
    </style>
</head>
<body id="page-top">
    <div id="wrapper">
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="admindashboard.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">FYPCollabor<sup>8</sup></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="admindashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">Admin Portal</div>
            <li class="nav-item active">
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

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Manage Lecturers</h1>
                    </div>
                    <?= $message ?>

                    <div class="row">
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Total Lecturers</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalLecturers) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Total Supervisors</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalSupervisors) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-tie fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Total Assessors</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalAssessors) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clipboard-check fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Total Coordinators</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?= htmlspecialchars($totalCoordinators) ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-cog fa-2x text-gray-300"></i>
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
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Lecturers List</h6>
                            <div class="form-group mb-0">
                                <select class="form-control" id="roleFilter" onchange="applyFilter()">
                                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Roles</option>
                                    <option value="lecturer" <?= $filter === 'lecturer' ? 'selected' : '' ?>>Lecturers</option>
                                    <option value="supervisor" <?= $filter === 'supervisor' ? 'selected' : '' ?>>Supervisors</option>
                                    <option value="assessor" <?= $filter === 'assessor' ? 'selected' : '' ?>>Assessors</option>
                                    <option value="coordinator" <?= $filter === 'coordinator' ? 'selected' : '' ?>>Coordinators</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($lecturers)): ?>
                                            <?php foreach ($lecturers as $lecturer): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($lecturer['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($lecturer['username']) ?></td>
                                                    <td><?= htmlspecialchars($lecturer['email']) ?></td>
                                                    <td><?= htmlspecialchars($lecturer['role_name']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center">No lecturers found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Add/Edit Lecturer</h6></div>
                                <div class="card-body">
                                    <form id="addEditLecturerForm" method="POST" action="">
                                        <div class="form-group">
                                            <label for="lecturer_id">Select Lecturer (Leave 0 for new)</label>
                                            <select class="form-control" id="lecturer_id" name="lecturer_id" onchange="populateFields(this)">
                                                <option value="0">-- New Lecturer --</option>
                                                <?php foreach ($lecturers as $lecturer): ?>
                                                    <option value="<?= $lecturer['id'] ?>" 
                                                            data-name="<?= htmlspecialchars($lecturer['full_name']) ?>" 
                                                            data-username="<?= htmlspecialchars($lecturer['username']) ?>" 
                                                            data-email="<?= htmlspecialchars($lecturer['email']) ?>"
                                                            data-role-id="<?= htmlspecialchars($lecturer['role_id']) ?>">
                                                        <?= htmlspecialchars($lecturer['full_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="full_name">Full Name</label>
                                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="username">Username</label>
                                            <input type="text" class="form-control" id="username" name="username" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="email">Email</label>
                                            <input type="email" class="form-control" id="email" name="email" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="role_id">Role</label>
                                            <select class="form-control" id="role_id" name="role_id" required>
                                                <option value="">-- Select Role --</option>
                                                <option value="1">Lecturer</option>
                                                <option value="2">Assessor</option>
                                                <option value="3">Both</option>
                                                <option value="4">Supervisor</option>
                                                <option value="5">Coordinator</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="password">Password (Leave blank to keep unchanged)</label>
                                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter new password if changing">
                                        </div>
                                        <button type="button" class="btn btn-primary btn-icon-split save-lecturer-button">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-upload"></i>
                                            </span>
                                            <span class="text">Save</span>
                                        </button>
                                        <input type="hidden" name="save_lecturer" value="1">
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Delete Lecturer</h6></div>
                                <div class="card-body">
                                    <form id="deleteLecturerForm" method="POST" action="">
                                        <div class="form-group">
                                            <label for="delete_lecturer_id">Select Lecturer to Delete</label>
                                            <select class="form-control" id="delete_lecturer_id" name="delete_lecturer_id" required>
                                                <option value="">-- Select Lecturer --</option>
                                                <?php foreach ($lecturers as $lecturer): ?>
                                                    <option value="<?= $lecturer['id'] ?>" data-name="<?= htmlspecialchars($lecturer['full_name']) ?>">
                                                        <?= htmlspecialchars($lecturer['full_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-icon-split" data-toggle="modal" data-target="#deleteConfirmModal" onclick="setDeleteModalContent()">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-trash"></i>
                                            </span>
                                            <span class="text">Delete Lecturer</span>
                                        </button>
                                    </form>
                                    <p class="mt-3 mb-0 text-muted">Note: Deleting a lecturer will remove their account and may affect related records (e.g., supervision, assessments). Ensure this is intended.</p>
                                </div>
                            </div>
                            <!-- Upload Lecturer Details -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Upload Lecturer Details</h6>
                                </div>
                                <div class="card-body">
                                    <p>Upload a CSV file containing lecturer details. The file must have the following columns: <strong>Lecturer ID, Full Name, Email, Role, Password</strong>. Role must be one of: Lecturer, Assessor, Both, Supervisor, Coordinator.</p>
                                    <p><a href="templates/lecturer.csv" class="btn btn-info btn-sm"><i class="fas fa-download"></i> Download Template</a></p>
                                    <form id="csvUploadForm" method="POST" enctype="multipart/form-data">
                                        <div class="form-group">
                                            <label for="csv_file">Select CSV File</label>
                                            <div>
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#csvUploadModal">
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
                                                    <th>Lecturer ID</th>
                                                    <th>Full Name</th>
                                                    <th>Email</th>
                                                    <th>Role</th>
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
                                    Are you sure you want to delete <strong id="deleteLecturerName"></strong>? This action cannot be undone and may affect related records.
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <form id="confirmDeleteForm" method="POST" action="">
                                        <input type="hidden" name="delete_lecturer_id" id="confirmDeleteLecturerId">
                                        <button type="submit" name="delete_lecturer" class="btn btn-danger">Delete</button>
                                    </form>
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
                </div>
            </div>

            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright © FYPCollabor8 2025</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

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

        // Populate fields for Add/Edit Lecturer form
        function populateFields(select) {
            const nameField = document.getElementById('full_name');
            const usernameField = document.getElementById('username');
            const emailField = document.getElementById('email');
            const roleField = document.getElementById('role_id');
            const passwordField = document.getElementById('password');
            if (select.value === '0') {
                nameField.value = '';
                usernameField.value = '';
                emailField.value = '';
                roleField.value = '';
                passwordField.value = '';
            } else {
                const option = select.options[select.selectedIndex];
                nameField.value = option.dataset.name;
                usernameField.value = option.dataset.username;
                emailField.value = option.dataset.email;
                roleField.value = option.dataset.roleId || '';
                passwordField.value = '';
            }
        }

        // Apply role filter
        function applyFilter() {
            const filter = document.getElementById('roleFilter').value;
            window.location.href = 'adminmanagelecturers.php?filter=' + filter;
        }

        // Set content for Delete Confirmation Modal
        function setDeleteModalContent() {
            const select = document.getElementById('delete_lecturer_id');
            const lecturerName = select.options[select.selectedIndex].dataset.name;
            const lecturerId = select.value;
            document.getElementById('deleteLecturerName').textContent = lecturerName || 'this lecturer';
            document.getElementById('confirmDeleteLecturerId').value = lecturerId;
        }

        // Confirmation Modal Logic
        let targetForm = null;
        let actionType = null;

        // Handle Save Lecturer button
        $(document).on('click', '.save-lecturer-button', function() {
            const form = $('#addEditLecturerForm');
            const lecturerId = form.find('#lecturer_id').val();
            const fullName = form.find('#full_name').val().trim();
            const username = form.find('#username').val().trim();
            const email = form.find('#email').val().trim();
            const roleId = form.find('#role_id').val();
            const password = form.find('#password').val().trim();

            // Validate inputs
            if (!fullName || !username || !email || !roleId) {
                alert('Please fill in all required fields (Full Name, Username, Email, Role).');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address.');
                return;
            }
            if (lecturerId === '0' && !password) {
                alert('Password is required for new lecturers.');
                return;
            }
            if (password && password.length < 8) {
                alert('Password must be at least 8 characters long.');
                return;
            }

            // Set confirmation message with bold lecturer name
            const action = lecturerId === '0' ? 'add' : 'update';
            $('#confirmActionMessage').html(
                `Are you sure you want to ${action} lecturer <strong>${htmlspecialchars(fullName)}</strong>?`
            );

            targetForm = form;
            actionType = 'save-lecturer';
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
                            const expectedHeaders = ['lecturer id', 'full name', 'email', 'role', 'password'];
                            const headersValid = headers.length === 5 && headers.every((header, index) => header === expectedHeaders[index]);

                            if (!headersValid) {
                                csvValidationMessage.innerHTML = '<span class="text-danger">Invalid CSV format. Expected headers: Lecturer ID, Full Name, Email, Role, Password.</span>';
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
                if (row.length >= 5) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${row[0] ? htmlspecialchars(row[0]) : ''}</td>
                        <td>${row[1] ? htmlspecialchars(row[1]) : ''}</td>
                        <td>${row[2] ? htmlspecialchars(row[2]) : ''}</td>
                        <td>${row[3] ? htmlspecialchars(row[3]) : ''}</td>
                        <td>${row[4] ? htmlspecialchars(row[4]) : ''}</td>
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
                     .replace(/>/g, '&gt;')
                     .replace(/"/g, '&quot;')
                     .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>