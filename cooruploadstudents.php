<?php
session_start();
include 'connection.php';

// Ensure the coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}

// Fetch coordinator's details
$coordinatorID = $_SESSION['user_id'];
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

// Fetch active semester
$semesterQuery = "SELECT start_date FROM semesters WHERE is_current = 1 LIMIT 1";
$semesterResult = $conn->query($semesterQuery);
if ($semesterResult === false || $semesterResult->num_rows === 0) {
    die("Error: No active semester found. Please set an active semester in coorsetsemester.php.");
}
$semester = $semesterResult->fetch_assoc();
$startDate = new DateTime($semester['start_date']);
$intakeYear = $startDate->format('Y'); // e.g., 2025
$intakeMonth = $startDate->format('F'); // e.g., April
$semesterResult->free();

// Handle file upload
$uploadResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadResult = ['error' => "File upload failed with error code: " . $file['error']];
    } elseif ($fileExtension !== 'csv') {
        $uploadResult = ['error' => "Invalid file type. Only .csv files are allowed."];
    } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        $uploadResult = ['error' => "File size exceeds 5MB limit."];
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
                $uploadResult = ['error' => "Invalid CSV format. Expected exactly 4 headers: Student ID, Full Name, Email, Password"];
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
                    // Optional: Add password strength validation
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
                $uploadResult = [
                    'success' => true,
                    'message' => "Processed $added students successfully, $skipped skipped (duplicates), $failed failed.",
                    'errors' => $errors
                ];
            }
        } catch (Exception $e) {
            if (isset($handle) && $handle !== false) {
                fclose($handle);
            }
            $uploadResult = ['error' => "Error processing file: " . $e->getMessage()];
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
    <title>Coordinator - Upload Student Details</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
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
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo"
                    aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student <br> Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors &<br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
                        <a class="collapse-item" href="coormanagelecturers.php">Manage Lecturers</a>
                        <a class="collapse-item active" href="cooruploadstudents.php">Upload Student Details</a>
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

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
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

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Upload Student Details</h1>
                    </div>

                    <!-- Upload Result -->
                    <?php if ($uploadResult): ?>
                        <div class="alert <?= isset($uploadResult['error']) ? 'alert-danger' : 'alert-success' ?>">
                            <?= htmlspecialchars(isset($uploadResult['error']) ? $uploadResult['error'] : $uploadResult['message']) ?>
                            <?php if (!empty($uploadResult['errors'])): ?>
                                <ul>
                                    <?php foreach ($uploadResult['errors'] as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Upload Form -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Upload CSV File</h6>
                        </div>
                        <div class="card-body">
                            <p>Upload a CSV file containing student details. The file must have the following columns: <strong>Student ID, Full Name, Email, Password</strong>. Intake Year and Month will be automatically assigned based on the active semester.</p>
                            <p><a href="templates/student.csv" class="btn btn-info btn-sm"><i class="fas fa-download"></i> Download Template</a></p>
                            <form method="POST" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label for="csv_file">Select CSV File</label>
                                    <input type="file" class="form-control-file" id="csv_file" name="csv_file" accept=".csv" required>
                                </div>
                                <button type="submit" class="btn btn-primary">Upload and Process</button>
                            </form>
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

    <!-- Bootstrap core JavaScript -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
</body>
</html>