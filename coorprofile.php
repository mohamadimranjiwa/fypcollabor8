<?php
session_start();
include 'connection.php';

// Ensure coordinator is logged in
if (!isset($_SESSION['user_id'])) {
    die("Error: No coordinator logged in. Please log in to access your profile.");
}
$coordinatorID = $_SESSION['user_id'];

// Initialize messages
$_SESSION['profile_message'] = $_SESSION['profile_message'] ?? '';
$_SESSION['password_message'] = $_SESSION['password_message'] ?? '';
$message = $_SESSION['profile_message'];
$passwordMessage = $_SESSION['password_message'];

// Handle profile update form submission (including picture upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = $_POST['name'] ?? null;
    $phone = $_POST['phone'] ?? null;
    $ic_number = $_POST['no_ic'] ?? null;
    $email = $_POST['email'] ?? null;
    $username = $_POST['username'] ?? null; // Added username

    $updates = [];
    $params = [];
    $types = '';

    if ($name) {
        $updates[] = "full_name = ?";
        $params[] = $name;
        $types .= 's';
    }
    if ($phone) {
        $updates[] = "no_tel = ?";
        $params[] = $phone;
        $types .= 's';
    }
    if ($ic_number) {
        $updates[] = "no_ic = ?";
        $params[] = $ic_number;
        $types .= 's';
    }
    if ($email) {
        $updates[] = "email = ?";
        $params[] = $email;
        $types .= 's';
    }
    if ($username) {
        // Check if username is unique
        $checkSql = "SELECT id FROM coordinators WHERE username = ? AND id != ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("si", $username, $coordinatorID);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $_SESSION['profile_message'] = 'Username already taken. Please choose a different one.';
            $checkStmt->close();
            header("Location: coorprofile.php");
            exit();
        }
        $checkStmt->close();
        $updates[] = "username = ?";
        $params[] = $username;
        $types .= 's';
    }

    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
        $fileName = $_FILES['profile_picture']['name'];
        $fileSize = $_FILES['profile_picture']['size'];
        $fileType = $_FILES['profile_picture']['type'];

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 10 * 1024 * 1024; // 10MB
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $newFileName = $coordinatorID . '_profile.' . $fileExt;
        $uploadDir = 'uploads/coordinators/';
        $uploadPath = $uploadDir . $newFileName;

        if (!in_array($fileType, $allowedTypes)) {
            $_SESSION['profile_message'] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
        } elseif ($fileSize > $maxSize) {
            $_SESSION['profile_message'] = 'File size exceeds 10MB limit.';
        } else {
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            if (move_uploaded_file($fileTmpPath, $uploadPath)) {
                $updates[] = "profile_picture = ?";
                $params[] = $uploadPath;
                $types .= 's';
            } else {
                $_SESSION['profile_message'] = 'Failed to upload profile picture.';
            }
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE coordinators SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $coordinatorID;
        $types .= 'i';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $_SESSION['profile_message'] = 'Profile updated successfully!';
        } else {
            $_SESSION['profile_message'] = 'Failed to update profile: ' . htmlspecialchars($stmt->error);
        }
        $stmt->close();
    } else {
        $_SESSION['profile_message'] = 'No changes were made.';
    }

    header("Location: coorprofile.php");
    exit();
}

// Handle change password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword && $newPassword && $confirmPassword) {
        $sql = "SELECT password FROM coordinators WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $coordinatorID);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($currentPassword, $user['password'])) {
            if ($newPassword === $confirmPassword) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $sql = "UPDATE coordinators SET password = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $hashedPassword, $coordinatorID);

                if ($stmt->execute()) {
                    $_SESSION['password_message'] = 'Password updated successfully!';
                } else {
                    $_SESSION['password_message'] = 'Failed to update password: ' . htmlspecialchars($stmt->error);
                }
                $stmt->close();
            } else {
                $_SESSION['password_message'] = 'New passwords do not match.';
            }
        } else {
            $_SESSION['password_message'] = 'Current password is incorrect.';
        }
    } else {
        $_SESSION['password_message'] = 'Please fill in all fields.';
    }
    header("Location: coorprofile.php");
    exit();
}

// Fetch coordinator data
$sql = "SELECT full_name, email, no_tel, no_ic, profile_picture, username 
        FROM coordinators 
        WHERE id = ?";
$stmt = $conn->prepare($sql);
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
    'email' => $coordinator['email'] ?? 'N/A',
    'no_tel' => $coordinator['no_tel'] ?? 'N/A',
    'no_ic' => $coordinator['no_ic'] ?? 'N/A',
    'profile_picture' => $coordinator['profile_picture'] ?? 'img/undraw_profile.svg',
    'username' => $coordinator['username'] ?? 'N/A', // Added username
];

// Clear messages after display
$_SESSION['profile_message'] = '';
$_SESSION['password_message'] = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <title>Coordinator Profile - FYPCollabor8</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
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
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo"
                    aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student <br>Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors &<br>Assessors</a>
                        <a class="collapse-item" href="coormanagestudents.php">Manage Students</a>
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

                <div class="container-fluid">
                    <h1 class="h3 mb-4 text-gray-800">Coordinator Profile</h1>
                    <div class="row">
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Profile Picture</h6></div>
                                <div class="card-body text-center">
                                    <div class="position-relative d-inline-block mb-3">
                                        <img class="img-profile rounded-circle" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" alt="Profile Picture" width="160" height="160" onerror="this.src='img/undraw_profile.svg';">
                                        <div class="overlay rounded-circle" data-toggle="modal" data-target="#profilePictureModal"><span class="overlay-text">Edit</span></div>
                                    </div>
                                    <p><strong>Name:</strong> <?= htmlspecialchars($personalInfo['full_name']) ?></p>
                                    <p><strong>Username:</strong> <?= htmlspecialchars($personalInfo['username']) ?></p> <!-- Added username -->
                                    <p><strong>Email:</strong> <?= htmlspecialchars($personalInfo['email']) ?></p>
                                    <p><strong>Phone:</strong> <?= htmlspecialchars($personalInfo['no_tel']) ?></p>
                                    <p><strong>IC Number:</strong> <?= htmlspecialchars($personalInfo['no_ic']) ?></p>
                                    <?php if (!empty($message)): ?>
                                        <p class="mt-3" style="color: <?= strpos($message, 'successfully') !== false ? 'green' : 'red' ?>"><?= htmlspecialchars($message) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="modal fade" id="profilePictureModal" tabindex="-1" role="dialog" aria-labelledby="profilePictureModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-lg" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="profilePictureModalLabel">Update Profile Picture</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
                                        </div>
                                        <div class="modal-body text-center">
                                            <form method="POST" enctype="multipart/form-data" id="profilePictureForm">
                                                <input type="hidden" name="update_profile" value="1">
                                                <div class="form-group">
                                                    <img id="previewImage" class="img-fluid mb-3" src="<?= htmlspecialchars($personalInfo['profile_picture']) ?>" alt="Preview" style="max-width: 100%; max-height: 400px;" onerror="this.src='img/undraw_profile.svg';">
                                                    <input type="file" class="form-control-file" id="profilePicture" name="profile_picture" accept="image/jpeg,image/png,image/gif">
                                                </div>
                                            </form>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                            <button type="button" class="btn btn-primary" id="uploadPicture">Upload</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Profile Details</h6></div>
                                <div class="card-body">
                                    <form method="POST" action="coorprofile.php">
                                        <input type="hidden" name="update_profile" value="1">
                                        <div class="form-group">
                                            <label for="coordinatorName">Name</label>
                                            <input type="text" class="form-control" id="coordinatorName" name="name" value="<?= htmlspecialchars($personalInfo['full_name']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="coordinatorUsername">Username</label>
                                            <input type="text" class="form-control" id="coordinatorUsername" name="username" value="<?= htmlspecialchars($personalInfo['username']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="coordinatorEmail">Email</label>
                                            <input type="email" class="form-control" id="coordinatorEmail" name="email" value="<?= htmlspecialchars($personalInfo['email']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="coordinatorPhone">Phone</label>
                                            <input type="text" class="form-control" id="coordinatorPhone" name="phone" value="<?= htmlspecialchars($personalInfo['no_tel']) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="coordinatorIC">IC Number</label>
                                            <input type="text" class="form-control" id="coordinatorIC" name="no_ic" value="<?= htmlspecialchars($personalInfo['no_ic']) ?>">
                                        </div>
                                        <button type="submit" class="btn btn-primary">Update Profile</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-4"></div>
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Change Password</h6></div>
                                <div class="card-body">
                                    <?php if (!empty($passwordMessage)): ?>
                                        <p style="color: <?= strpos($passwordMessage, 'successfully') !== false ? 'green' : 'red' ?>"><strong><?= htmlspecialchars($passwordMessage) ?></strong></p>
                                    <?php endif; ?>
                                    <form method="POST" action="coorprofile.php">
                                        <input type="hidden" name="change_password" value="1">
                                        <div class="form-group">
                                            <label for="current-password">Current Password</label>
                                            <input type="password" class="form-control" id="current-password" name="current_password" placeholder="Enter current password" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="new-password">New Password</label>
                                            <input type="password" class="form-control" id="new-password" name="new_password" placeholder="Enter new password" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="confirm-password">Confirm New Password</label>
                                            <input type="password" class="form-control" id="confirm-password" name="confirm_password" placeholder="Confirm new password" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Change Password</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto"><span>Copyright © Your Website 2021</span></div>
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

    <script>
    let cropper;
    document.getElementById('profilePicture').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            const maxSize = 10 * 1024 * 1024;
            if (!allowedTypes.includes(file.type)) {
                alert('Only JPG, PNG, and GIF are allowed.');
                e.target.value = '';
                return;
            }
            if (file.size > maxSize) {
                alert('File size must be under 10MB.');
                e.target.value = '';
                return;
            }
            if (cropper) cropper.destroy();
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImage = document.getElementById('previewImage');
                previewImage.src = e.target.result;
                cropper = new Cropper(previewImage, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 0.8,
                    movable: false,
                    zoomable: false,
                    scalable: false,
                    cropBoxResizable: true,
                });
            };
            reader.readAsDataURL(file);
        }
    });

    $('#profilePictureModal').on('show.bs.modal', function() {
        const previewImage = document.getElementById('previewImage');
        previewImage.src = '<?= htmlspecialchars($personalInfo['profile_picture']) ?>';
        document.getElementById('profilePicture').value = '';
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
    });

    document.getElementById('uploadPicture').addEventListener('click', function() {
        if (cropper) {
            const canvas = cropper.getCroppedCanvas({ width: 160, height: 160 });
            canvas.toBlob(function(blob) {
                const formData = new FormData();
                formData.append('profile_picture', blob, 'cropped_profile.jpg');
                formData.append('update_profile', '1');
                fetch('coorprofile.php', {
                    method: 'POST',
                    body: formData,
                }).then(() => window.location.reload()).catch(error => {
                    console.error('Upload failed:', error);
                    alert('Failed to upload cropped image.');
                });
            }, 'image/jpeg');
        } else {
            document.getElementById('profilePictureForm').submit();
        }
    });
    </script>
</body>
</html>