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

// Fetch teaching materials
$materialsQuery = "
    SELECT tm.id, tm.title, tm.description, tm.file_path, tm.uploaded_at, tm.updated_at, c.full_name AS coordinator_name
    FROM teaching_materials tm
    JOIN coordinators c ON tm.coordinator_id = c.id
    ORDER BY tm.uploaded_at DESC";
$materialsResult = $conn->query($materialsQuery) or die("Error in materials query: " . htmlspecialchars($conn->error));
$materials = $materialsResult->fetch_all(MYSQLI_ASSOC);
$hasMaterials = !empty($materials);

// Handle material upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_material'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']) ?: null;
    $filePath = null;

    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
        $uploadDir = 'Uploads/teaching_materials/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $fileName = basename($_FILES['file']['name']);
        $filePath = $uploadDir . time() . '_' . $fileName;
        
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
            $message = "<div class='alert alert-danger'>Failed to upload file.</div>";
            $filePath = null;
        }
    }

    $sql = "INSERT INTO teaching_materials (coordinator_id, title, description, file_path) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $coordinatorID, $title, $description, $filePath);
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Teaching material uploaded successfully!</div>";
        header("Refresh:0");
    } else {
        $message = "<div class='alert alert-danger'>Failed to save material: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
}

// Handle material update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_material'])) {
    $material_id = intval($_POST['material_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']) ?: null;
    
    $sql = "UPDATE teaching_materials SET title = ?, description = ? WHERE id = ? AND coordinator_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $title, $description, $material_id, $coordinatorID);
    
    if ($stmt->execute()) {
        if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
            $uploadDir = 'Uploads/teaching_materials/';
            $fileName = basename($_FILES['file']['name']);
            $filePath = $uploadDir . time() . '_' . $fileName;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                // Fetch and delete old file
                $fetchSql = "SELECT file_path FROM teaching_materials WHERE id = ? AND coordinator_id = ?";
                $fetchStmt = $conn->prepare($fetchSql);
                $fetchStmt->bind_param("ii", $material_id, $coordinatorID);
                $fetchStmt->execute();
                $fetchResult = $fetchStmt->get_result();
                $material = $fetchResult->fetch_assoc();
                $fetchStmt->close();
                
                if ($material && $material['file_path'] && file_exists($material['file_path'])) {
                    unlink($material['file_path']);
                }
                
                // Update file path
                $updateSql = "UPDATE teaching_materials SET file_path = ? WHERE id = ? AND coordinator_id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("sii", $filePath, $material_id, $coordinatorID);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
        $message = "<div class='alert alert-success'>Teaching material updated successfully!</div>";
        header("Refresh:0");
    } else {
        $message = "<div class='alert alert-danger'>Failed to update material: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
}

// Handle material deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_material'])) {
    $material_id = intval($_POST['material_id']);
    
    // Fetch and delete the file from the server
    $fetchSql = "SELECT file_path FROM teaching_materials WHERE id = ? AND coordinator_id = ?";
    $fetchStmt = $conn->prepare($fetchSql);
    $fetchStmt->bind_param("ii", $material_id, $coordinatorID);
    $fetchStmt->execute();
    $fetchResult = $fetchStmt->get_result();
    $material = $fetchResult->fetch_assoc();
    $fetchStmt->close();
    
    if ($material && $material['file_path'] && file_exists($material['file_path'])) {
        unlink($material['file_path']);
    }
    
    $sql = "DELETE FROM teaching_materials WHERE id = ? AND coordinator_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $material_id, $coordinatorID);
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Teaching material deleted successfully!</div>";
        header("Refresh:0");
    } else {
        $message = "<div class='alert alert-danger'>Failed to delete material: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
}

// Close database connection
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
    <title>Coordinator - Manage Teaching <br>Materials</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
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
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
                    <i class="fas fa-fw fa-cog"></i>
                    <span>Personnel Management</span>
                </a>
                <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Staff and Student <br>Oversight:</h6>
                        <a class="collapse-item" href="coorassignlecturers.php">Assign Supervisors & <br>Assessors</a>
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
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapsePages" aria-expanded="true" aria-controls="collapsePages">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Resources & Communication</span>
                </a>
                <div id="collapsePages" class="collapse show" aria-labelledby="headingPages" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Support Tools:</h6>
                        <a class="collapse-item" href="coormanageannouncement.php">Manage Announcement</a>
                        <a class="collapse-item active" href="coormanageteachingmaterials.php">Manage Teaching <br>Materials</a>
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
                        <h1 class="h3 mb-0 text-gray-800">Manage Teaching Materials</h1>
                    </div>
                    <?= $message ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Teaching Materials List</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Description</th>
                                            <th>File</th>
                                            <th>Coordinator</th>
                                            <th>Uploaded At</th>
                                            <th>Updated At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($materials)): ?>
                                            <?php foreach ($materials as $material): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($material['title']) ?></td>
                                                    <td><?= htmlspecialchars($material['description'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <?php if ($material['file_path']): ?>
                                                            <a href="<?= htmlspecialchars($material['file_path']) ?>" target="_blank"><?= htmlspecialchars(basename($material['file_path'])) ?></a>
                                                        <?php else: ?>
                                                            N/A
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($material['coordinator_name']) ?></td>
                                                    <td><?= htmlspecialchars($material['uploaded_at']) ?></td>
                                                    <td><?= htmlspecialchars($material['updated_at']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center">No teaching materials found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Upload Teaching Material</h6></div>
                                <div class="card-body">
                                    <form id="uploadForm" method="POST" action="" enctype="multipart/form-data">
                                        <div class="form-group">
                                            <label for="title">Material Title</label>
                                            <input type="text" class="form-control" id="title" name="title" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="description">Description (Optional)</label>
                                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="file">Upload File (Optional)</label>
                                            <div>
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#uploadModal">
                                                    <i class="fas fa-upload"></i> Choose File
                                                </button>
                                                <span id="selectedFileName" class="ml-2 text-muted">No file selected</span>
                                            </div>
                                            <input type="file" id="fileInput" name="file" style="display: none;">
                                        </div>
                                        <button type="submit" name="save_material" class="btn btn-primary btn-icon-split">
                                            <span class="icon text-white-50">
                                                <i class="fas fa-upload"></i>
                                            </span>
                                            <span class="text">Upload Material</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Edit Teaching Material</h6></div>
                                <div class="card-body">
                                    <?php if ($hasMaterials): ?>
                                        <form id="editForm" method="POST" action="" enctype="multipart/form-data">
                                            <div class="form-group">
                                                <label for="material_id">Select Material</label>
                                                <select class="form-control" id="material_id" name="material_id" required onchange="populateFields(this)">
                                                    <option value="">-- Select a material --</option>
                                                    <?php foreach ($materials as $material): ?>
                                                        <option value="<?= $material['id'] ?>" 
                                                                data-title="<?= htmlspecialchars($material['title']) ?>" 
                                                                data-description="<?= htmlspecialchars($material['description'] ?? '') ?>">
                                                            <?= htmlspecialchars($material['title']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_title">Material Title</label>
                                                <input type="text" class="form-control" id="edit_title" name="title" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_description">Description (Optional)</label>
                                                <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit_file">Upload New File (Optional)</label>
                                                <div>
                                                    <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#editUploadModal">
                                                        <i class="fas fa-upload"></i> Choose File
                                                    </button>
                                                    <span id="editSelectedFileName" class="ml-2 text-muted">No file selected</span>
                                                </div>
                                                <input type="file" id="editFileInput" name="file" style="display: none;">
                                            </div>
                                            <button type="submit" name="update_material" class="btn btn-primary btn-icon-split">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-save"></i>
                                                </span>
                                                <span class="text">Update Material</span>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-icon-split" data-toggle="modal" data-target="#deleteMaterialModal" onclick="setDeleteModalContent()">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-trash"></i>
                                                </span>
                                                <span class="text">Delete Material</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <p class="text-muted">No teaching materials available to edit or delete.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End of Page Content -->

                <!-- Upload Modal (for Upload Teaching Material) -->
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
                                    <input type="file" id="modalFileInput" style="display: none;" onchange="handleFileSelect(event, 'upload')">
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

                <!-- Edit Upload Modal (for Edit Teaching Material) -->
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
                                    <input type="file" id="editModalFileInput" style="display: none;" onchange="handleFileSelect(event, 'edit')">
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
                <div class="modal fade" id="deleteMaterialModal" tabindex="-1" role="dialog" aria-labelledby="deleteMaterialModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteMaterialModalLabel">Confirm Deletion</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                Are you sure you want to delete the teaching material <strong id="deleteMaterialTitle"></strong>? This action cannot be undone.
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <form id="confirmDeleteMaterialForm" method="POST" action="">
                                    <input type="hidden" name="material_id" id="confirmDeleteMaterialId">
                                    <button type="submit" name="delete_material" class="btn btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
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
    <div class="modal fade" id="logoutModal" tabindex=-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
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
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                    <a class="btn btn-primary" href="index.html">Logout</a>
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

    <!-- Custom script for populating edit form fields and modal -->
    <script>
        function populateFields(select) {
            const titleInput = document.getElementById('edit_title');
            const descriptionInput = document.getElementById('edit_description');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                titleInput.value = selectedOption.getAttribute('data-title');
                descriptionInput.value = selectedOption.getAttribute('data-description') || '';
            } else {
                titleInput.value = '';
                descriptionInput.value = '';
            }
        }

        function setDeleteModalContent() {
            const select = document.getElementById('material_id');
            const materialTitle = select.options[select.selectedIndex].dataset.title;
            const materialId = select.value;
            document.getElementById('deleteMaterialTitle').textContent = materialTitle || 'this teaching material';
            document.getElementById('confirmDeleteMaterialId').value = materialId;
        }

        // Drag and Drop functionality
        let selectedFile = null;
        let editSelectedFile = null;

        // For Upload Teaching Material
        const dragDropArea = document.getElementById('dragDropArea');
        const fileInput = document.getElementById('modalFileInput');
        const confirmUploadBtn = document.getElementById('confirmUploadBtn');
        const filePreview = document.getElementById('filePreview');
        const formFileInput = document.getElementById('fileInput');
        const selectedFileName = document.getElementById('selectedFileName');

        // For Edit Teaching Material
        const editDragDropArea = document.getElementById('editDragDropArea');
        const editFileInput = document.getElementById('editModalFileInput');
        const editConfirmUploadBtn = document.getElementById('editConfirmUploadBtn');
        const editFilePreview = document.getElementById('editFilePreview');
        const editFormFileInput = document.getElementById('editFileInput');
        const editSelectedFileName = document.getElementById('editSelectedFileName');

        // Drag and Drop for Upload Modal
        dragDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            dragDropArea.classList.add('dragover');
        });

        dragDropArea.addEventListener('dragleave', () => {
            dragDropArea.classList.remove('dragover');
        });

        dragDropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            dragDropArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect({ target: { files: files } }, 'upload');
            }
        });

        // Drag and Drop for Edit Upload Modal
        editDragDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            editDragDropArea.classList.add('dragover');
        });

        editDragDropArea.addEventListener('dragleave', () => {
            editDragDropArea.classList.remove('dragover');
        });

        editDragDropArea.addEventListener('drop', (e) => {
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
                if (formType === 'upload') {
                    selectedFile = files[0];
                    filePreview.innerHTML = `Selected file: <strong>${selectedFile.name}</strong>`;
                    confirmUploadBtn.disabled = false;
                } else if (formType === 'edit') {
                    editSelectedFile = files[0];
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
                // Transfer the selected file to the upload form's file input
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(selectedFile);
                formFileInput.files = dataTransfer.files;
                selectedFileName.textContent = selectedFile.name;
                $('#uploadModal').modal('hide');
            } else if (formType === 'edit' && editSelectedFile) {
                // Transfer the selected file to the edit form's file input
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
    </script>
</body>
</html>