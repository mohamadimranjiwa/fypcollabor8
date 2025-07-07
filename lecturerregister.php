<?php
// Include the database connection
require 'connection.php';

// Initialize a variable to store messages
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and capture input data
    $username = htmlspecialchars(trim($_POST['username']));
    $email = htmlspecialchars(trim($_POST['email']));
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT); // Hash the password

    // Default role assignment (e.g., Lecturer with role_id = 1)
    $role_id = 1; // Adjust this as needed

    // Prepare SQL to insert lecturer data
    $sql = "INSERT INTO lecturers (username, email, password, role_id)
            VALUES (?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    // Check if statement preparation was successful
    if ($stmt) {
        $stmt->bind_param("sssi", $username, $email, $password, $role_id);
        
        // Execute the query and handle success or error
        if ($stmt->execute()) {
            $message = "<div class='success-message'>Registration successful! You can now <a href='lecturerlogin.php'>log in</a>.</div>";
        } else {
            $message = "<div class='error-message'>An error occurred: " . htmlspecialchars($stmt->error) . "</div>";
        }

        $stmt->close(); // Close the statement
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

    <title>Lecturer Register</title>

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

        <div class="row justify-content-center align-items-center" style="height: 100vh;">
            <div class="col-xl-6 col-lg-8 col-md-10">
        <div class="card o-hidden border-0 shadow-lg my-5">
            <div class="card-body p-0">
                <!-- Nested Row within Card Body -->
                <div class="row">
                            <div class="col-lg-12">
                        <div class="p-5">
                            <div class="text-center">
                                <h1 class="h4 text-gray-900 mb-4">Create an Account!</h1>
                            </div>
                            <!-- Display messages -->
                            <?= $message ?>
                            <form class="user" method="POST" action="">
                                <div class="form-group">
                                    <input type="text" name="username" class="form-control form-control-user" id="exampleUsername" placeholder="Username" required>
                                </div>
                                <div class="form-group">
                                    <input type="email" name="email" class="form-control form-control-user" id="exampleInputEmail" placeholder="Email Address" required>
                                </div>
                                <div class="form-group row">
                                    <div class="col-sm-6 mb-3 mb-sm-0">
                                        <input type="password" name="password" class="form-control form-control-user" id="exampleInputPassword" placeholder="Password" required>
                                    </div>
                                    <div class="col-sm-6">
                                        <input type="password" name="repeat_password" class="form-control form-control-user" id="exampleRepeatPassword" placeholder="Repeat Password" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-user btn-block">
                                    Register Account
                                </button>
                                <hr>
                                <div class="text-center">
                                    <a class="small" href="lecturerlogin.php">Already have an account? Login!</a>
                                </div>
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