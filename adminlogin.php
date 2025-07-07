<?php
// Start the session
session_start();

// Include the database connection
require 'connection.php';

// Initialize a variable to store messages
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and capture input data
    $password = htmlspecialchars(trim($_POST['password']));

    // Prepare SQL to fetch the single admin record
    $sql = "SELECT * FROM admins LIMIT 1"; // Assumes only one admin record exists
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Fetch the admin data
            $admin = $result->fetch_assoc();

            // Verify the password
            if (password_verify($password, $admin['password'])) {
                session_regenerate_id(); // Regenerate session ID for security
                // Store admin information in the session
                $_SESSION['user_id'] = $admin['id'];
                $_SESSION['username'] = 'admin'; // Static username or omit if not needed
                $_SESSION['user_type'] = 'admin';

                // Redirect to admin dashboard
                header("Location: admindashboard.php");
                exit();
            } else {
                $message = "<div class='error-message'>Invalid password.</div>";
            }
        } else {
            $message = "<div class='error-message'>No admin account found.</div>";
        }

        $stmt->close();
    } else {
        $message = "<div class='error-message'>An error occurred: " . htmlspecialchars($conn->error) . "</div>";
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

    <title>Admin Login</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        body {
            background-image: url('img/unikl.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            height: 100vh; /* Ensure body takes full viewport height */
            margin: 0; /* Remove default body margin */
            position: relative; /* For overlay positioning */
        }
        .bg-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5); /* Adjust opacity as needed */
            z-index: 0; 
        }
        .content-wrapper {
            position: relative;
            z-index: 1;
        }
    </style>
</head>

<body>
    <div class="bg-overlay"></div>
    <div class="content-wrapper">

    <div class="container">

        <!-- Outer Row -->
        <div class="row justify-content-center align-items-center" style="height: 100vh;">
            <div class="col-xl-6 col-lg-8 col-md-10">
                <div class="card o-hidden border-0 shadow-lg my-5">
                    <div class="card-body p-0">
                        <!-- Nested Row within Card Body -->
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="p-5">
                                    <div class="text-center">
                                        <h1 class="h4 text-gray-900 mb-4">Admin Login</h1>
                                    </div>
                                    <!-- Display error message -->
                                    <?= $message ?>
                                    <form class="user" method="POST" action="">
                                        <div class="form-group">
                                            <input type="password" name="password" class="form-control form-control-user" id="exampleInputPassword" placeholder="Password" required>
                                        </div>
                                        <div class="form-group">
                                            <div class="custom-control custom-checkbox small">
                                                <input type="checkbox" class="custom-control-input" id="customCheck">
                                                <label class="custom-control-label" for="customCheck">Remember Me</label>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-user btn-block">
                                            Login
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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

</body>

</html>